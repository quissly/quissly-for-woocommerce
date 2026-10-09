<?php
/**
 * Product mapper — WooCommerce product data to Quissly catalog record.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a normalized WooCommerce product array to a Quissly catalog record.
 *
 * Pure logic — no WordPress calls — so it is unit-testable. The WP-coupled extraction
 * of a WC_Product into the input array is a separate, integration-tested step.
 *
 * VARIANT MODEL: ONE record per product (so search returns product IDs and post__in is
 * unchanged). Per-variant detail lives in a TOP-LEVEL `variants[]` (a sibling of
 * id/title/..., NOT under metadata — the backend accepts nested variants there). A variant
 * has the SAME schema as a parent product (id, original_price, discounted_price, images,
 * in_stock, ...) with ONE exception: a variant has no `variants` of its own. Non-documented
 * per-variant data (the concrete attribute values color/size/logo and the sku) goes in the
 * variant's OWN metadata.
 *
 * REQUIRED-vs-SPARSE (LIVE-CONFIRMED): the /add endpoint validates each variant as a full
 * ProductItem and does NOT inherit omitted fields from the parent (a sparse variant 422'd).
 * So the REQUIRED fields — title, description, category, images, original_price — are
 * populated on EVERY variant, copied from the parent when the variant has no own value. The
 * OPTIONAL fields — discounted_price, in_stock, and the metadata sku — stay SPARSE (emitted
 * only when they differ from the parent). /update is lenient, but this one full shape
 * satisfies both methods, so there is no separate add/update payload.
 *
 * There is NO price_range — nothing about a variant is flattened into an aggregate. The
 * top-level original_price/discounted_price for a variable product is the parent's
 * representative "from"/min price (a display headline only). available_sizes /
 * available_colors remain as coarse flat facet helpers in the PARENT metadata.
 *
 * Expected input array:
 *  id, type ('simple'|'variable'), title, description, short_description, sku, brand,
 *  url, images[], categories[{slug,primary}], tags[slug]
 *  simple:   regular_price, sale_price (empty if not on sale), in_stock
 *  variable: regular_price, sale_price, attributes{<raw_key>:value} as the PARENT
 *            baseline, plus variations[{id, regular_price, sale_price, sku, image,
 *            in_stock, attributes{<raw_key>:value}}]. A variation field left empty
 *            inherits the parent.
 *
 * Attribute keys are normalized to canonical names (pa_color/pa_colour -> color,
 * pa_size -> size, etc.).
 *
 * FEATURES: `features` {label: "value, value"} — the attributes the Catalog data checklist
 * sends (Quissly_Catalog_Attributes: what the product page shows unless the merchant
 * changed it there; a variable product's variation attributes excluded, its variants carry
 * them) — go in the parent's metadata keyed by label ("Material" -> material), so "merino"
 * finds a product whose title and description never say it: Quissly captions and
 * keyword-indexes metadata. A feature never overwrites the record's own metadata.
 */
class Quissly_Product_Mapper {

	/**
	 * The top-level ProductItem fields (the Magento plugin's RESERVED_METADATA_KEYS). A
	 * metadata key matching one - compared case-insensitively - becomes attr_<key>: the
	 * backend cannot hold the same attribute name inside and outside metadata
	 * (2026-09-10).
	 */
	const RESERVED_METADATA_KEYS = array( 'id', 'title', 'description', 'category', 'images', 'url', 'in_stock', 'original_price', 'discounted_price', 'discount_percent', 'variants', 'metadata' );

	/**
	 * @param array $product Normalized product data (see class docblock).
	 * @return array Quissly catalog record.
	 */
	public static function map( array $product ) {
		$is_variable = isset( $product['type'] ) && 'variable' === $product['type'];

		$metadata = array(
			'brand'      => isset( $product['brand'] ) ? (string) $product['brand'] : '',
			'sku'        => isset( $product['sku'] ) ? (string) $product['sku'] : '',
			'categories' => self::category_slugs( $product ),
			'tags'       => isset( $product['tags'] ) ? array_values( $product['tags'] ) : array(),
		);

		$variants = null;
		if ( $is_variable ) {
			$variable = self::map_variations( $product );

			$original_price   = $variable['from_regular'];
			$discounted_price = $variable['from_sale'];
			$in_stock         = $variable['in_stock'];

			$metadata = array_merge( $metadata, $variable['attribute_helpers'] );
			$metadata['variation_count'] = $variable['variation_count'];
			$variants                    = $variable['variants'];
		} else {
			$original_price   = self::to_price( $product['regular_price'] ?? null );
			$discounted_price = self::to_price( $product['sale_price'] ?? null );
			$in_stock         = ! empty( $product['in_stock'] );
			// A simple product is a LEAF match (no child variants), so it carries
			// metadata.selected_options built from its OWN product-level attributes — the data
			// the backend uses to build attribute facets. (A variable PARENT does NOT; its
			// variants carry it — added per-variant in map_variations().)
			$metadata['selected_options'] = self::selected_options(
				self::canonical_attributes( $product['attributes'] ?? array() )
			);
		}
		$metadata = self::add_features( $metadata, $product['features'] ?? array() );

		$record = array(
			// LIVE CONTRACT (FACT 1): ids are STRINGS on the Quissly wire. An integer id is
			// silently dropped by ingest; the string form makes catalog Add succeed AND the
			// operation complete. (WooCommerce-side they remain ints — cast back on the way in.)
			'id'               => (string) $product['id'],
			'title'            => isset( $product['title'] ) ? (string) $product['title'] : '',
			'description'      => self::description( $product ),
			// The live catalog API (ProductItem schema) REQUIRES original_price as a number
			// — null is rejected (verified live). Default to 0.0 when a product has no price.
			// discounted_price remains nullable (the API accepts null = "not on sale").
			'original_price'   => null === $original_price ? 0.0 : (float) $original_price,
			'discounted_price' => $discounted_price,
			'category'         => self::primary_category( $product ),
			'in_stock'         => $in_stock,
			'images'           => isset( $product['images'] ) ? array_values( $product['images'] ) : array(),
			'url'              => isset( $product['url'] ) ? (string) $product['url'] : '',
			'metadata'         => self::guard_metadata( $metadata ),
		);

		// `variants` is a TOP-LEVEL field on the parent (a sibling of id/title/...), NOT under
		// metadata. The backend accepts nested variants here and falls back to the parent's
		// value for any field a (sparse) variant omits.
		if ( null !== $variants ) {
			$record['variants'] = $variants;
		}

		return $record;
	}

	/**
	 * Full description, ALL HTML stripped; fall back to short_description if empty.
	 */
	private static function description( array $product ) {
		$full = self::strip_html( $product['description'] ?? '' );
		if ( '' !== $full ) {
			return $full;
		}

		return self::strip_html( $product['short_description'] ?? '' );
	}

	/**
	 * Remove script/style blocks, strip all tags, decode entities, collapse whitespace.
	 */
	private static function strip_html( $html ) {
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$text = strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::strip_shortcodes( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Remove shortcode tags a page builder leaves in descriptions ("[spb_text_block
	 * animation="none"]...[/spb_text_block]"), keeping the text they wrap: a tag that is
	 * closed somewhere ([/name]), and one carrying attributes. A lone "[NEW]" is text and stays.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_shortcodes( $text ) {
		if ( false === strpos( $text, '[' ) ) {
			return $text;
		}
		if ( preg_match_all( '#\[/([A-Za-z][\w-]*)\]#', $text, $closed ) ) {
			foreach ( array_unique( $closed[1] ) as $name ) {
				$text = preg_replace( '#\[/?' . preg_quote( $name, '#' ) . '(?:\s[^\]]*)?\]#', ' ', $text );
			}
		}

		return preg_replace( '#\[[A-Za-z][\w-]*\s+[\w-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\]]+)[^\]]*\]#', ' ', $text );
	}

	/**
	 * Primary category slug (flagged primary, else the first), or null.
	 */
	private static function primary_category( array $product ) {
		if ( empty( $product['categories'] ) ) {
			return null;
		}
		foreach ( $product['categories'] as $cat ) {
			if ( ! empty( $cat['primary'] ) ) {
				return (string) $cat['slug'];
			}
		}

		return (string) $product['categories'][0]['slug'];
	}

	/**
	 * All category slugs, in input order.
	 */
	private static function category_slugs( array $product ) {
		if ( empty( $product['categories'] ) ) {
			return array();
		}

		return array_map(
			static function ( $cat ) {
				return (string) $cat['slug'];
			},
			$product['categories']
		);
	}

	/**
	 * Roll up variations into: the representative "from" prices, overall stock, coarse
	 * attribute helpers, and the sparse per-variant overrides array.
	 *
	 * @param array $product Full variable-product input (parent baseline + variations).
	 * @return array
	 */
	private static function map_variations( array $product ) {
		$parent_regular = self::to_price( $product['regular_price'] ?? null );
		$parent_sale    = self::to_price( $product['sale_price'] ?? null );
		$parent_sku     = isset( $product['sku'] ) ? (string) $product['sku'] : '';
		$parent_attrs   = self::canonical_attributes( $product['attributes'] ?? array() );

		// Parent identity fields, COPIED onto each variant below. The /add endpoint validates
		// every variant as a full ProductItem and does NOT inherit these from the parent
		// (LIVE-CONFIRMED 422), so they are required per variant; /update is lenient but the
		// same full shape satisfies both. Genuinely per-variant fields still override.
		$parent_title       = isset( $product['title'] ) ? (string) $product['title'] : '';
		$parent_description = self::description( $product );
		$parent_category    = self::primary_category( $product );
		$parent_images      = isset( $product['images'] ) ? array_values( $product['images'] ) : array();
		$parent_url         = isset( $product['url'] ) ? (string) $product['url'] : '';

		$variations = $product['variations'] ?? array();

		$resolved_regulars = array();
		$resolved_sales    = array();
		$rollup_in_stock   = ! empty( $product['in_stock'] );
		$attribute_values  = array(); // canonical key => list of resolved values.
		$rows              = array(); // per-variant computed values (built into entries below).

		foreach ( $variations as $variation ) {
			$own_regular  = self::to_price( $variation['regular_price'] ?? null );
			$own_sale     = self::to_price( $variation['sale_price'] ?? null );
			$own_sku      = self::non_empty_string( $variation['sku'] ?? null );
			$own_image    = self::non_empty_string( $variation['image'] ?? null );
			$own_attrs    = self::canonical_attributes( $variation['attributes'] ?? array() );
			$own_in_stock = ! empty( $variation['in_stock'] );

			// Concrete attribute values that DIFFER from the parent (the parent fixes none, so
			// in practice every variant carries its own). These are non-documented fields, so
			// they live in the variant's OWN metadata, not as a top-level variant field.
			$diff_attrs = array();
			foreach ( $own_attrs as $key => $value ) {
				if ( ! array_key_exists( $key, $parent_attrs ) || $parent_attrs[ $key ] !== $value ) {
					$diff_attrs[ $key ] = $value;
				}
			}

			$rows[] = array(
				'id'          => (string) $variation['id'],
				'own_regular' => $own_regular,
				'own_sale'    => $own_sale,
				'own_sku'     => $own_sku,
				'own_image'   => $own_image,
				'own_url'     => self::non_empty_string( $variation['url'] ?? null ),
				'own_title'   => self::non_empty_string( $variation['option_summary'] ?? null ),
				'in_stock'    => $own_in_stock,
				'diff_attrs'  => $diff_attrs,
			);

			// Resolved (own-or-inherited) values for the headline + coarse helpers.
			$effective_regular = ( null !== $own_regular ) ? $own_regular : $parent_regular;
			if ( null !== $effective_regular ) {
				$resolved_regulars[] = $effective_regular;
			}
			$effective_sale = ( null !== $own_sale ) ? $own_sale : $parent_sale;
			if ( null !== $effective_sale ) {
				$resolved_sales[] = $effective_sale;
			}
			if ( $own_in_stock ) {
				$rollup_in_stock = true;
			}

			$resolved_attrs = array_merge( $parent_attrs, $own_attrs );
			foreach ( $resolved_attrs as $key => $value ) {
				$attribute_values[ $key ][] = $value;
			}
		}

		// Build the variant entries. A variant has the SAME schema as the parent (parent field
		// names, no `variants` of its own). The REQUIRED ProductItem fields (title, description,
		// category, images, original_price) are populated on every variant — copied from the
		// parent when the variant has no own value — because /add does NOT inherit them
		// (LIVE-CONFIRMED 422). The OPTIONAL fields (discounted_price, in_stock, sku) stay
		// SPARSE: emitted only when they differ from the parent.
		$variants = array();
		foreach ( $rows as $row ) {
			$effective_regular = ( null !== $row['own_regular'] ) ? $row['own_regular'] : $parent_regular;

			// Variant ids are STRINGS on the wire too (FACT 1), same boundary rule as top-level.
			$entry = array(
				'id'             => $row['id'],
				// Its own options ("Color: Red, Size: XS"), not the parent's name: Quissly
				// stores a variant as "<parent title> - <variant title>", so the parent's name
				// here made every variant "X - X" - identical titles, the colour only in
				// metadata, and the wrong colour matched ("red summit kit" -> orange). Magento
				// sends the child's own name for the same reason. The parent's name only
				// when a variation has no options of its own (title is required on /add).
				'title'          => ( null !== $row['own_title'] ) ? $row['own_title'] : $parent_title,
				'description'    => $parent_description,
				'category'       => $parent_category,
				'images'         => ( null !== $row['own_image'] ) ? array( $row['own_image'] ) : $parent_images,
				'original_price' => ( null !== $effective_regular ) ? (float) $effective_regular : 0.0,
				// The parent page opened on THIS variation (a variation has no page of its own):
				// chat cards and any other consumer of the synced url otherwise land on the
				// default variation (same fix as the Magento plugin).
				'url'            => ( null !== $row['own_url'] ) ? $row['own_url'] : $parent_url,
			);
			if ( null !== $row['own_sale'] && $row['own_sale'] !== $parent_sale ) {
				$entry['discounted_price'] = $row['own_sale'];
			}
			if ( $row['in_stock'] !== $rollup_in_stock ) {
				$entry['in_stock'] = $row['in_stock'];
			}
			// Non-documented per-variant fields go in the variant's own metadata: the concrete
			// attribute values (color/size/logo), the sku (when it differs from the parent), and
			// selected_options — the [{name,value}] form the backend uses to build attribute
			// facets. A variant is a LEAF match, so it ALWAYS carries selected_options (its own
			// variation-defining attribute values; "any"/empty values are skipped).
			$variant_metadata = $row['diff_attrs'];
			if ( null !== $row['own_sku'] && $row['own_sku'] !== $parent_sku ) {
				$variant_metadata['sku'] = $row['own_sku'];
			}
			$variant_metadata['selected_options'] = self::selected_options( $row['diff_attrs'] );
			$entry['metadata'] = self::guard_metadata( $variant_metadata );
			$variants[] = $entry;
		}

		return array(
			'from_regular'      => empty( $resolved_regulars ) ? null : (float) min( $resolved_regulars ),
			'from_sale'         => empty( $resolved_sales ) ? null : (float) min( $resolved_sales ),
			'in_stock'          => $rollup_in_stock,
			'attribute_helpers' => self::attribute_helpers( $attribute_values ),
			'variation_count'   => count( $variations ),
			'variants'          => $variants,
		);
	}

	/**
	 * Add the visible attributes to metadata, keyed by label ("Pack Size" -> pack_size). Blank
	 * values are left out. A key that is taken — by the record's own metadata or by a
	 * top-level ProductItem field (the backend cannot hold one name inside and outside
	 * metadata) — gets an attr_ prefix, unless it holds that very value already.
	 *
	 * @param array                $metadata The record's metadata so far.
	 * @param array<string,string> $features Attribute label => display value(s).
	 * @return array
	 */
	private static function add_features( array $metadata, array $features ) {
		$reserved = self::RESERVED_METADATA_KEYS;
		foreach ( $features as $label => $value ) {
			$value = trim( (string) $value );
			$key   = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $label ) ), '_' );
			if ( '' === $value || '' === $key || ( isset( $metadata[ $key ] ) && $metadata[ $key ] === $value ) ) {
				continue;
			}
			if ( isset( $metadata[ $key ] ) || in_array( $key, $reserved, true ) ) {
				$key = 'attr_' . $key;
			}
			if ( ! isset( $metadata[ $key ] ) ) {
				$metadata[ $key ] = $value;
			}
		}

		return $metadata;
	}

	/**
	 * Prefix every metadata key that collides with a top-level ProductItem field (port of
	 * the Magento plugin's guardMetadata). An attribute may be named anything - a
	 * variation attribute "Category", say - so this runs as the LAST step on every
	 * metadata dict, the parent's and each variant's. Case-insensitive; the key's own
	 * spelling is kept after the prefix; every other key passes through, in order.
	 * Idempotent. PURE.
	 *
	 * @param array $metadata Metadata dict.
	 * @return array
	 */
	public static function guard_metadata( array $metadata ) {
		$out = array();
		foreach ( $metadata as $key => $value ) {
			$name = (string) $key;
			if ( in_array( strtolower( $name ), self::RESERVED_METADATA_KEYS, true ) ) {
				$name = 'attr_' . $name;
			}
			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * Build the coarse flat facet helpers from collected attribute values.
	 * color -> available_colors, size -> available_sizes, anything else -> its key.
	 *
	 * @param array<string,array<int,string>> $attribute_values Canonical key => values.
	 * @return array
	 */
	private static function attribute_helpers( array $attribute_values ) {
		$helpers = array();
		foreach ( $attribute_values as $key => $values ) {
			$values = array_values( array_unique( $values ) );
			sort( $values, SORT_STRING ); // Deterministic, content-independent ordering.

			if ( 'color' === $key ) {
				$helpers['available_colors'] = $values;
			} elseif ( 'size' === $key ) {
				$helpers['available_sizes'] = $values;
			} else {
				$helpers[ $key ] = $values;
			}
		}

		return $helpers;
	}

	/**
	 * Build metadata.selected_options — the [{name, value}] attribute pairs the backend uses to
	 * build attribute facets — from a canonical {name => value} attribute map. PURE +
	 * unit-testable. Skips empty ("any") values. Included ONLY on LEAF entities (variants and
	 * simple products), never on a variable parent (its variants carry it).
	 *
	 * @param array<string,string> $canonical_attrs Canonical attribute name => value.
	 * @return array<int,array{name:string,value:string}>
	 */
	private static function selected_options( array $canonical_attrs ) {
		$options = array();
		foreach ( $canonical_attrs as $name => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue; // "any value" attribute -> not a selected option.
			}
			$options[] = array( 'name' => (string) $name, 'value' => $value );
		}

		return $options;
	}

	/**
	 * Normalize an attribute map to canonical keys with string values.
	 *
	 * @param array $attributes Raw attribute map.
	 * @return array<string,string>
	 */
	private static function canonical_attributes( array $attributes ) {
		$out = array();
		foreach ( $attributes as $raw_key => $value ) {
			$out[ self::canonical_attribute( $raw_key ) ] = (string) $value;
		}

		return $out;
	}

	/**
	 * Normalize an attribute key to a canonical field name.
	 * pa_color / pa_colour -> color, pa_size -> size, etc.
	 */
	private static function canonical_attribute( $raw_key ) {
		$key = strtolower( (string) $raw_key );
		$key = preg_replace( '/^(pa_|attribute_pa_|attribute_)/', '', $key );
		if ( 'colour' === $key ) {
			$key = 'color';
		}

		return $key;
	}

	/**
	 * Cast a WooCommerce price (string|number|empty) to float, or null if not set.
	 */
	private static function to_price( $value ) {
		if ( null === $value || '' === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * A trimmed non-empty string, or null (used to detect "inherit the parent").
	 */
	private static function non_empty_string( $value ) {
		if ( null === $value ) {
			return null;
		}
		$value = (string) $value;

		return '' === trim( $value ) ? null : $value;
	}
}
