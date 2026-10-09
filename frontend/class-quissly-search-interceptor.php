<?php
/**
 * Search interception (pre_get_posts + FSE Query Loop) for text search.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercepts the product search query and swaps in Quissly's ordered IDs.
 *
 * TWO entry points, because block (FSE) themes bypass the main query:
 *  1. pre_get_posts  — authoritative for CLASSIC themes (the main query renders).
 *  2. query_loop_block_query_vars — for BLOCK themes, where WooCommerce's Product
 *     Collection block builds its OWN query and ignores the main query. We hook the same
 *     filter WooCommerce uses (it registers at priority 10) at a later priority and
 *     override the block's query vars.
 *
 * Both paths share one memoized fetch per (term,page,sort), so a page renders exactly
 * one search call. On timeout/error/zero-results: page 1 -> native fallback; page 2+ ->
 * graceful empty (never a native swap mid-browse). found_posts is overridden to Quissly's
 * total so the pager reflects the full set even though post__in carries one page of IDs.
 *
 * Native-filter reaction is not built: an active theme filter is left to WooCommerce. Voice and
 * image results arrive through a token (quissly_voice / quissly_img) handled below.
 */
class Quissly_Search_Interceptor {

	const DEFAULT_PAGE_SIZE = 24;

	/** Sentinel query var: carries Quissly's total into the block's WP_Query. */
	const TOTAL_VAR = 'quissly_total';

	/**
	 * The main query we intercepted (so found_posts/order only affect that one).
	 *
	 * @var WP_Query|null
	 */
	private $intercepted_query = null;

	/**
	 * Total for the intercepted MAIN query.
	 *
	 * @var int
	 */
	private $total = 0;

	/**
	 * Per-request fetch memo, keyed by term|page|sort. Value: parsed result or null.
	 *
	 * @var array<string,array|null>
	 */
	private $cache = array();

	/**
	 * Matched-variant map for THIS request: product_id => WC variation id (top_variant_id),
	 * accumulated from the search response. Drives the result-link variant pre-selection.
	 * Empty when the backend returns no top_variant_id (graceful — links stay plain).
	 *
	 * @var array<int,int>
	 */
	private $top_variants = array();

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'pre_get_posts', array( $this, 'maybe_intercept' ), 999 );
		// Block themes: run AFTER WooCommerce's Product Collection builder (priority 10).
		add_filter( 'query_loop_block_query_vars', array( $this, 'filter_block_query' ), 20, 3 );
		add_filter( 'found_posts', array( $this, 'filter_found_posts' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'enforce_post_in_order' ), 999, 2 );
		// Quissly already ran the search; post__in IS the result. Drop the native keyword
		// WHERE clause so it does not AND-filter our IDs down to keyword matches.
		add_filter( 'posts_search', array( $this, 'neutralize_keyword_search' ), 999, 2 );
		// post__in already holds exactly THIS page's IDs, so the SQL must not offset into
		// it. Force LIMIT 0,page_size; found_posts/max_num_pages still reflect the total.
		add_filter( 'post_limits', array( $this, 'force_single_page_limit' ), 999, 2 );
		// Variant pre-selection: rewrite the result product's permalink to carry the matched
		// variation's attribute params (URL-only; no card-markup change). No-op unless this
		// request's search populated a top_variant map.
		add_filter( 'post_type_link', array( $this, 'filter_product_permalink' ), 10, 2 );
		$this->register_media_search_strings();
	}

	/**
	 * The strings that render a search term wrapped in a colon and/or curly quotes, by
	 * text domain. None of them go through get_the_archive_title() (search pages aren't
	 * archives), so a string-level override is the only hook that reaches them all. Which
	 * one draws the page heading depends on the theme: block themes use the query-title
	 * block, classic themes (e.g. Storefront) use woocommerce_page_title().
	 */
	const MEDIA_SEARCH_STRINGS = array(
		'default'     => array(
			'Search results for: &#8220;%s&#8221;', // core/query-title block heading (block themes).
			'Search Results for &#8220;%s&#8221;',  // wp_get_document_title() (browser tab).
		),
		'woocommerce' => array(
			'Search results for &ldquo;%s&rdquo;',  // WC_Breadcrumb.
			'Search results: &ldquo;%s&rdquo;',     // woocommerce_page_title() heading (classic themes).
		),
	);

	/**
	 * Voice/image results land on the normal search-results page carrying a placeholder
	 * term (there are no real words to search with), which the heading, breadcrumb and
	 * tab title would each show as `: “your voice search”` — reads as broken, not
	 * intentional. Registered only on those hand-offs: gettext fires for every translated
	 * string on the page, so an ordinary request never pays for it.
	 */
	public function register_media_search_strings() {
		if ( isset( $_GET[ Quissly_Proxy::QUERY_VAR_IMG ] ) || isset( $_GET[ Quissly_Proxy::QUERY_VAR_VOICE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display text, no state change.
			add_filter( 'gettext', array( $this, 'filter_media_search_strings' ), 10, 3 );
		}
	}

	/**
	 * Swap the colon/quote-wrapped search-term strings for a plain one.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_media_search_strings( $translation, $text, $domain ) {
		if ( ! isset( self::MEDIA_SEARCH_STRINGS[ $domain ] ) || ! in_array( $text, self::MEDIA_SEARCH_STRINGS[ $domain ], true ) ) {
			return $translation;
		}

		/* translators: %s: the search term (a real voice transcription, or a placeholder for an image search). */
		return __( 'Search results for %s', 'quissly-for-woocommerce' );
	}

	/**
	 * Rewrite a `product` permalink to pre-select the matched variation, when this request's
	 * search returned a top_variant_id for that product. Touches ONLY the URL; the card markup
	 * and image are untouched (the card keeps the product default image — accepted limitation).
	 * Any product not in the map, or any invalid/mismatched variant, links normally.
	 *
	 * @param string  $permalink The product permalink.
	 * @param WP_Post $post      The product post.
	 * @return string
	 */
	public function filter_product_permalink( $permalink, $post ) {
		if ( empty( $this->top_variants ) || ! isset( $post->ID ) || 'product' !== get_post_type( $post ) ) {
			return $permalink;
		}
		if ( ! isset( $this->top_variants[ (int) $post->ID ] ) ) {
			return $permalink;
		}

		return Quissly_Variant_Deeplink::apply( $permalink, $this->top_variants[ (int) $post->ID ], (int) $post->ID );
	}

	/**
	 * On block (FSE) themes the Product Collection block CLONES the global main query when
	 * its query inherits from the template (so query_loop_block_query_vars never fires) —
	 * meaning the main-query path above is authoritative there too. The filter below is
	 * kept for the less common case of a Product Collection block with its OWN
	 * (non-inherit) query on a search page.
	 */

	// ---------------------------------------------------------------------------------
	// Classic theme path: the main query.
	// ---------------------------------------------------------------------------------

	/**
	 * pre_get_posts handler — intercept the main product search query.
	 *
	 * @param WP_Query $query Query being prepared.
	 */
	public function maybe_intercept( $query ) {
		if ( ! $this->should_intercept( $query ) ) {
			return;
		}

		// Voice/image path: a token carries the ids the proxy already fetched — no API call
		// here. Keyed on the token query var explicitly (NOT an empty `s`). The token is
		// re-read on every load, so a re-sort / reload / back keeps the same products.
		$token = $this->token_value();
		if ( '' !== $token ) {
			$ids = Quissly_Proxy::read_ids( $token );
			if ( ! empty( $ids ) ) {
				$ids = self::sort_snapshot_ids( Quissly_Languages::to_current( array_map( 'intval', $ids ) ), $this->resolve_orderby() );
				$this->apply_ids_to_query( $query, $ids );
				// Links pre-select the variation the photo/voice matched, as on a text search.
				$this->top_variants += Quissly_Languages::variants_to_current( Quissly_Proxy::read_variants( $token ) );
				$this->intercepted_query = $query;
				$this->total             = count( $ids );
				$this->emit_debug_marker( 1, $ids, 'token' );
				Quissly_Search_Signal::record_hit( count( $ids ), count( $ids ) );
			} else {
				Quissly_Search_Signal::record_fallback( 'token_expired' );
				// Expired or unknown token: an empty result, never a fall-through. The URL
				// still carries the placeholder `s` ("your image search"), and a native
				// search for those words would show unrelated products under a heading that
				// looks like the photo's results. (Same as the Magento plugin's hand-off.)
				$query->set( 'post_type', 'product' );
				$query->set( 'post__in', array( 0 ) );
			}
			return; // token result is a single snapshot page; no text fetch / pagination.
		}

		$page   = max( 1, (int) $query->get( 'paged' ) );
		$result = $this->fetch( (string) $query->get( 's' ), $page );

		if ( empty( $result['ids'] ) ) {            // null (failure) or zero results
			Quissly_Search_Signal::record_fallback( $result['code'] );
			if ( $page >= 2 ) {
				$this->degrade_gracefully( $query );
			}
			return;                                  // page 1 -> native fallback
		}

		$this->apply_ids_to_query( $query, $result['ids'] );
		$this->intercepted_query = $query;
		$this->total             = $result['total'];
		Quissly_Search_Signal::record_hit( count( $result['ids'] ), $result['total'] );

		$this->emit_debug_marker( $page, $result['ids'], 'main-query' );
	}

	/**
	 * Guard: act ONLY on the main, front-end, product, text-search query.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private function should_intercept( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return false;
		}

		// PRODUCTS-ONLY STORE (product-owner decision): every front-end search is a product
		// search, so act on a `product` search OR a bare/unspecified-post_type search — the
		// theme's core search block submits `/?s=term` with post_type='' (which previously
		// fell through to native). Bail ONLY when the search is explicitly scoped to a
		// NON-product type (e.g. a deliberate post/page search). The result is then scoped to
		// products in apply_ids_to_query (post_type=product), so posts/pages never appear.
		// FUTURE: if non-product content is ever supported, this needs a
		// products-only-vs-all-search setting (not built now).
		if ( ! self::post_type_is_product_or_unspecified( $query->get( 'post_type' ) ) ) {
			return false;
		}

		// Image/voice token path: ids already resolved server-side; no term-length needs.
		if ( '' !== $this->token_value() ) {
			return true;
		}

		// Text search: require a search context AND a term of >= 2 chars (qsearch needs
		// 2-150; an empty or 1-char term skips Quissly and lets native search run).
		if ( ! $query->is_search() ) {
			return false;
		}

		if ( self::mb_len( trim( (string) $query->get( 's' ) ) ) < 2 ) {
			Quissly_Search_Signal::record_skip( 'no-term' );
			return false;
		}

		return true;
	}

	/**
	 * Whether a query's post_type is `product` or unspecified (bare site search) — true for
	 * '', 'product', 'any', an empty array, or an array containing 'product'; false for a
	 * specific non-product type. Pure + unit-testable.
	 *
	 * @param mixed $post_type The query's post_type value.
	 * @return bool
	 */
	public static function post_type_is_product_or_unspecified( $post_type ) {
		if ( is_array( $post_type ) ) {
			return empty( $post_type ) || in_array( 'product', $post_type, true );
		}
		$pt = (string) $post_type;

		return '' === $pt || 'product' === $pt || 'any' === $pt;
	}

	/**
	 * Multibyte-safe string length (falls back to strlen if mbstring is absent).
	 *
	 * @param string $s String.
	 * @return int
	 */
	private static function mb_len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
	}

	/**
	 * The voice/image token from the request, or '' if none. Read-only public query param;
	 * the token itself is the (short-lived, server-side) capability.
	 *
	 * @return string
	 */
	private function token_value() {
		foreach ( array( Quissly_Proxy::QUERY_VAR_IMG, Quissly_Proxy::QUERY_VAR_VOICE ) as $var ) {
			if ( isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, short-lived token IS the capability.
				$value = preg_replace( '/[^0-9a-f-]/i', '', sanitize_text_field( wp_unslash( $_GET[ $var ] ) ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	// ---------------------------------------------------------------------------------
	// Block (FSE) theme path: the Product Collection block's own query.
	// ---------------------------------------------------------------------------------

	/**
	 * Override the Product Collection block's query vars on a product search page.
	 *
	 * @param array    $query_vars Query vars WooCommerce built for the block.
	 * @param WP_Block $block      The block instance.
	 * @param int      $page       Page number the block is rendering.
	 * @return array
	 */
	public function filter_block_query( $query_vars, $block, $page ) {
		$is_product_collection = $block->context['query']['isProductCollectionBlock'] ?? false;
		if ( ! $is_product_collection || is_admin() || ! is_search() ) {
			return $query_vars;
		}

		$page   = max( 1, (int) $page );
		$result = $this->fetch( (string) get_search_query(), $page );

		if ( empty( $result['ids'] ) ) {
			Quissly_Search_Signal::record_fallback( $result['code'] );
			if ( $page >= 2 ) {
				$query_vars['post_type'] = 'product';
				$query_vars['post__in']  = array( 0 ); // graceful empty, not a native swap
				$this->print_load_error();
			}
			return $query_vars; // page 1 -> leave WooCommerce's native block query
		}

		$query_vars['post_type']        = 'product';
		$query_vars['post__in']         = $result['ids'];
		$query_vars['orderby']          = 'post__in';
		$query_vars['posts_per_page']   = $this->page_size();
		$query_vars['no_found_rows']    = false;
		$query_vars[ self::TOTAL_VAR ]  = $result['total']; // read back in filter_found_posts
		Quissly_Search_Signal::record_hit( count( $result['ids'] ), $result['total'] );

		$this->emit_debug_marker( $page, $result['ids'], 'block-query' );

		return $query_vars;
	}

	// ---------------------------------------------------------------------------------
	// Shared.
	// ---------------------------------------------------------------------------------

	/**
	 * Fetch (and memoize) one page of results via the swappable client.
	 *
	 * @param string $term Search term.
	 * @param int    $page Page number.
	 * @return array{ids:int[],total:int}|array{ids:array,code:string} Parsed result; ids
	 *                                                      empty on failure/zero, with why.
	 */
	private function fetch( $term, $page ) {
		$sort = self::map_sort( $this->resolve_orderby() );
		$key  = $term . '|' . $page . '|' . $sort['sort_by'] . '|' . (string) $sort['sort_type'];

		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		$request = array(
			'query'            => $term,
			'user_id'          => Quissly_User_Id::resolve(), // customer:<id> / guest:<uuid>
			'page_number'      => $page,
			'page_size'        => $this->page_size(),
			'sort_by'          => $sort['sort_by'],
			// include_metadata FALSE: the WC post id is at top-level documents[].id again
			// (backend reverted the interim UUID/metadata workaround). IDs-only is enough.
			'include_metadata' => false,
			'channel'          => 'web',
		);
		if ( null !== $sort['sort_type'] ) {
			$request['sort_type'] = $sort['sort_type'];
		}
		// Who is searching, as the Shopify app sends it: the shopper's device and OS.
		$shopper           = Quissly_User_Id::device_and_os( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' );
		$request['device'] = $shopper['device'];
		if ( '' !== $shopper['os'] ) {
			$request['os'] = $shopper['os'];
		}

		$client   = $this->client();
		$response = $client->search( $request );
		if ( null === $response ) {
			// Why, for the X-Quissly-Search diagnostics (the live client knows; others don't).
			$code   = method_exists( $client, 'last_error' ) ? $client->last_error() : '';
			$result = array( 'ids' => array(), 'code' => '' !== $code ? $code : 'error' );
		} else {
			$parsed = Quissly_Response_Parser::parse_search( $response );
			// Quissly holds the main language's products: on another language's page, their
			// translations (a multilingual plugin hides a product of another language).
			$parsed['ids']      = Quissly_Languages::to_current( $parsed['ids'] );
			$parsed['variants'] = Quissly_Languages::variants_to_current( $parsed['variants'] );
			$result             = array( 'ids' => $parsed['ids'], 'total' => $parsed['num_total_results'] );
			if ( empty( $parsed['ids'] ) ) {
				$result['code'] = 'no_results';
			}
			// Accumulate the matched-variant map (product_id => variation_id) so the result-link
			// filter can pre-select the variant on click-through. Page-scoped union; absent field
			// just leaves the map empty (graceful — links stay plain).
			$this->top_variants += $parsed['variants'];
		}

		$this->cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Apply the Quissly IDs + ordering + page size to a WP_Query (classic path).
	 *
	 * @param WP_Query $query Query.
	 * @param int[]    $ids   Ordered product IDs.
	 */
	private function apply_ids_to_query( $query, $ids ) {
		$query->set( 'post_type', 'product' );
		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'posts_per_page', $this->page_size() );
	}

	/**
	 * Page size sent to qsearch and used as posts_per_page (so max_num_pages derives
	 * correctly). Filterable (dev shrinks it to make pagination visible).
	 *
	 * @return int
	 */
	private function page_size() {
		return (int) apply_filters( 'quissly_search_page_size', self::DEFAULT_PAGE_SIZE );
	}

	/**
	 * Resolve the swappable search client (mock in dev, live otherwise).
	 *
	 * @return Quissly_Search_Client
	 */
	private function client() {
		$client = apply_filters( 'quissly_search_client', null );
		if ( $client instanceof Quissly_Search_Client ) {
			return $client;
		}
		return new Quissly_Live_Search_Client();
	}

	/**
	 * The active WooCommerce orderby (the ?orderby= dropdown param).
	 *
	 * @return string
	 */
	private function resolve_orderby() {
		if ( isset( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only sort param
			return sanitize_text_field( wp_unslash( $_GET['orderby'] ) );
		}
		return '';
	}

	/**
	 * Order a voice/image result by the shopper's chosen sort.
	 *
	 * A typed search is re-sorted by Quissly (the sort goes out with the query). A
	 * voice/image result can't be re-asked, so the stored snapshot is sorted here instead,
	 * from WooCommerce's own data — same products, the order the dropdown asked for. The
	 * result still goes out through post__in, so enforce_post_in_order() keeps it.
	 * Relevance/default keeps Quissly's ranking. Ties (and products missing the value,
	 * which go last) keep their relative Quissly order.
	 *
	 * @param int[]  $ids     Snapshot ids in Quissly's order.
	 * @param string $orderby WooCommerce orderby.
	 * @return int[]
	 */
	public static function sort_snapshot_ids( array $ids, $orderby ) {
		switch ( $orderby ) {
			case 'price':
			case 'price-desc':
				$value = static function ( $id ) {
					$product = wc_get_product( $id );
					$price   = $product ? $product->get_price() : '';
					return '' === $price || null === $price ? null : (float) $price;
				};
				break;
			case 'date':
				$value = static function ( $id ) {
					$time = get_post_time( 'U', true, $id );
					return false === $time ? null : (int) $time;
				};
				break;
			case 'popularity':
				$value = static function ( $id ) {
					return (int) get_post_meta( $id, 'total_sales', true );
				};
				break;
			case 'rating':
				$value = static function ( $id ) {
					return (float) get_post_meta( $id, '_wc_average_rating', true );
				};
				break;
			default:
				return $ids;
		}

		$ascending = ( 'price' === $orderby );
		$rows      = array();
		foreach ( array_values( $ids ) as $position => $id ) {
			$rows[] = array( $id, $position, $value( $id ) );
		}

		// Explicit position tiebreak: usort is only guaranteed stable from PHP 8.0, and the
		// plugin supports 7.4.
		usort(
			$rows,
			static function ( $a, $b ) use ( $ascending ) {
				if ( $a[2] !== $b[2] ) {
					if ( null === $a[2] ) {
						return 1;
					}
					if ( null === $b[2] ) {
						return -1;
					}
					$cmp = $a[2] < $b[2] ? -1 : 1;
					return $ascending ? $cmp : -$cmp;
				}
				return $a[1] - $b[1];
			}
		);

		return array_map(
			static function ( $row ) {
				return $row[0];
			},
			$rows
		);
	}

	/**
	 * Map a WooCommerce orderby value to Quissly sort_by / sort_type.
	 *
	 * LIVE sort_by ENUM (reordered — these numbers changed; an old number is still a VALID
	 * number, so a stale value sorts by the WRONG field silently):
	 *   0 relevance/default | 1 on-sale/discount | 2 price (sort_type 1=asc, 2=desc) |
	 *   3 date/newness | 4 popularity
	 * sort_type applies ONLY to price (sort_by 2): 1 ascending, 2 descending.
	 *
	 * @param string $orderby WooCommerce orderby.
	 * @return array{sort_by:int,sort_type:int|null}
	 */
	public static function map_sort( $orderby ) {
		switch ( $orderby ) {
			case 'on-sale':
			case 'discount':
				return array( 'sort_by' => 1, 'sort_type' => null );
			case 'price':
				return array( 'sort_by' => 2, 'sort_type' => 1 );
			case 'price-desc':
				return array( 'sort_by' => 2, 'sort_type' => 2 );
			case 'date':
				return array( 'sort_by' => 3, 'sort_type' => null );
			case 'popularity':
				return array( 'sort_by' => 4, 'sort_type' => null );
			case 'relevance':
			case 'menu_order':
			case '':
			default:
				return array( 'sort_by' => 0, 'sort_type' => null );
		}
	}

	/**
	 * Override found_posts with Quissly's total — for the intercepted main query (by
	 * identity) and for the block query (by the sentinel query var). WP then recomputes
	 * max_num_pages = ceil(found_posts / posts_per_page).
	 *
	 * @param int      $found_posts Default count.
	 * @param WP_Query $query       Query.
	 * @return int
	 */
	public function filter_found_posts( $found_posts, $query ) {
		if ( $query === $this->intercepted_query ) {
			return $this->total;
		}
		$sentinel = $query->get( self::TOTAL_VAR );
		if ( '' !== $sentinel && null !== $sentinel ) {
			return (int) $sentinel;
		}
		return $found_posts;
	}

	/**
	 * Force ORDER BY FIELD(ID, ...) so Quissly's ranking wins over WooCommerce ordering.
	 *
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public function enforce_post_in_order( $clauses, $query ) {
		global $wpdb;
		$is_ours = ( $query === $this->intercepted_query )
			|| ( '' !== $query->get( self::TOTAL_VAR ) && null !== $query->get( self::TOTAL_VAR ) );
		if ( ! $is_ours ) {
			return $clauses;
		}
		$ids = array_map( 'intval', (array) $query->get( 'post__in' ) );
		$ids = array_filter( $ids ); // drop the 0 sentinel used for graceful-empty
		if ( empty( $ids ) ) {
			return $clauses;
		}
		$clauses['orderby'] = "FIELD({$wpdb->posts}.ID, " . implode( ',', $ids ) . ')';
		return $clauses;
	}

	/**
	 * Force the LIMIT to offset 0 for our query: post__in is already the current page's
	 * slice, so WP must not apply a page offset (which would skip past all of it).
	 *
	 * @param string   $limits The LIMIT clause.
	 * @param WP_Query $query  Query.
	 * @return string
	 */
	public function force_single_page_limit( $limits, $query ) {
		$is_ours = ( $query === $this->intercepted_query )
			|| ( '' !== $query->get( self::TOTAL_VAR ) && null !== $query->get( self::TOTAL_VAR ) );
		if ( ! $is_ours ) {
			return $limits;
		}
		$count = count( array_filter( array_map( 'intval', (array) $query->get( 'post__in' ) ) ) );
		return $count > 0 ? 'LIMIT 0, ' . $count : $limits;
	}

	/**
	 * Remove the native keyword search SQL for our intercepted query so post__in is the
	 * sole constraint (Quissly already did the searching). Keeps `s` set, so is_search()
	 * and template selection are unaffected.
	 *
	 * @param string   $search The search SQL.
	 * @param WP_Query $query  Query.
	 * @return string
	 */
	public function neutralize_keyword_search( $search, $query ) {
		$is_ours = ( $query === $this->intercepted_query )
			|| ( '' !== $query->get( self::TOTAL_VAR ) && null !== $query->get( self::TOTAL_VAR ) );
		return $is_ours ? '' : $search;
	}

	/**
	 * Page 2+ failure on the classic path: force empty results (no native swap) + notice.
	 *
	 * @param WP_Query $query Query.
	 */
	private function degrade_gracefully( $query ) {
		$query->set( 'post_type', 'product' );
		$query->set( 'post__in', array( 0 ) );
		$query->set( 'quissly_load_error', true );
		$this->print_load_error();
	}

	/**
	 * Print a graceful "couldn't load more" notice (minimal placement: footer).
	 */
	private function print_load_error() {
		add_action(
			'wp_footer',
			static function () {
				echo '<p class="quissly-load-error" role="alert">'
					. esc_html__( "Couldn't load more results. Please retry.", 'quissly-for-woocommerce' )
					. '</p>';
			}
		);
	}

	/**
	 * Emit a dev-only HTML comment marking interception state (robust test signal).
	 * Emitted when debugging — in dev/mock mode, OR when QUISSLY_DEBUG / WP_DEBUG is on (so
	 * LIVE-mode interception can be verified from page source). Never present on a normal
	 * production page (all three off).
	 *
	 * @param int    $page   Page number.
	 * @param int[]  $ids    Injected IDs.
	 * @param string $source Which path emitted it.
	 */
	private function emit_debug_marker( $page, $ids, $source ) {
		$debug = ( defined( 'QUISSLY_USE_MOCK_SEARCH' ) && QUISSLY_USE_MOCK_SEARCH )
			|| ( defined( 'QUISSLY_DEBUG' ) && QUISSLY_DEBUG )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		if ( ! $debug ) {
			return;
		}
		$total = isset( $this->cache ) ? $this->total : 0;
		// Pull the total from cache for the block path (where $this->total isn't set).
		$total = max( $total, (int) $this->lookup_total_for_marker() );
		add_action(
			'wp_footer',
			static function () use ( $page, $total, $ids, $source ) {
				echo "\n<!-- quissly-search: intercepted via=" . esc_html( $source )
					. ' page=' . (int) $page
					. ' total=' . (int) $total
					. ' ids=' . esc_html( implode( ',', array_map( 'intval', $ids ) ) ) . " -->\n";
			}
		);
	}

	/**
	 * Best-effort total for the marker (max across memoized fetches this request).
	 *
	 * @return int
	 */
	private function lookup_total_for_marker() {
		$max = 0;
		foreach ( $this->cache as $entry ) {
			if ( isset( $entry['total'] ) ) {
				$max = max( $max, (int) $entry['total'] );
			}
		}
		return $max;
	}
}
