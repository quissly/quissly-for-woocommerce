<?php
/**
 * Action Scheduler sync worker.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flushes batches of dirty products to the catalog sender (the swappable
 * Quissly_Catalog_Client seam) on Action Scheduler.
 *
 * FLUSH TRIGGERS (whichever first): pending count >= 250, OR the oldest pending item has
 * waited >= 5 minutes. Batches are capped at 250. On a recoverable per-item failure the
 * item is retried up to 3 times, then dropped and logged as a permanent failure. A
 * concurrency-limit rejection backs off 5 minutes (distinct from a generic failure). An
 * account refusal (no plan/trial, credentials not accepted) leaves the batch queued with no
 * retry burned and retries every 15 minutes. When the sender is not configured the batch
 * simply stays queued (no churn, no retry burned). The first-sync gate opens only once a
 * full sync has delivered something.
 *
 * The send itself is delegated entirely to the seam, so this class makes NO live call.
 */
class Quissly_Sync_Worker {

	const FLUSH_HOOK    = 'quissly_sync_flush';
	/** Post meta: set once a product has been successfully ingested (drives add-vs-PUT). */
	const INGESTED_META = '_quissly_ingested';
	const BATCH_SIZE    = 250;
	const FLUSH_COUNT   = 250;
	const FLUSH_AGE     = 300; // 5 minutes, in seconds.
	const MAX_ATTEMPTS  = 3;
	const BACKOFF_DELAY = 300; // concurrency-limit backoff, in seconds.
	/** Retry cadence while Quissly refuses the account (no plan/trial, bad credentials). */
	const REFUSAL_RETRY_DELAY = 900;

	/**
	 * While true, flush_batch does NOT self-schedule the next AS flush — the inline drain
	 * loop (drain()) drives continuation, so an async loopback can't race the same rows.
	 *
	 * @var bool
	 */
	private $inline_draining = false;

	/**
	 * Set when a batch hits a state the next batch would hit too (an account refusal or a
	 * concurrency limit). Stops the inline drain loop and the immediate re-schedule, so the
	 * delayed retry that was scheduled is the only one - not a tight loop against Quissly.
	 *
	 * @var bool
	 */
	private $stop_draining = false;

	/**
	 * Register the Action Scheduler action handler.
	 */
	public function register() {
		add_action( self::FLUSH_HOOK, array( $this, 'flush_batch' ) );
	}

	/**
	 * Drain the queue inline, up to $max_batches batches (or until empty). Used by the admin
	 * "start/re-sync" action so a typical catalog finishes immediately and the progress UI
	 * shows movement; any remainder is handed back to Action Scheduler.
	 *
	 * @param int $max_batches Safety cap on inline batches.
	 * @return int Pending items remaining after the inline drain.
	 */
	public function drain( $max_batches = 50 ) {
		$queue = new Quissly_Dirty_Queue();
		$this->inline_draining = true;
		for ( $i = 0; $i < (int) $max_batches && $queue->count_pending() > 0 && ! $this->stop_draining; $i++ ) {
			$this->flush_batch();
		}
		$this->inline_draining = false;

		$pending = $queue->count_pending();
		if ( $pending > 0 && ! $this->stop_draining && $this->scheduler_available() ) {
			$this->schedule_now(); // hand the remainder to Action Scheduler.
		}

		return $pending;
	}

	/**
	 * Pure flush-trigger decision (unit-testable).
	 *
	 * @param int      $pending_count      Items waiting.
	 * @param int|null $oldest_age_seconds Age of the oldest item, or null if empty.
	 * @return bool Whether a flush should run now.
	 */
	public static function should_flush( $pending_count, $oldest_age_seconds ) {
		if ( (int) $pending_count >= self::FLUSH_COUNT ) {
			return true;
		}

		return null !== $oldest_age_seconds && (int) $oldest_age_seconds >= self::FLUSH_AGE;
	}

	/**
	 * Called after an enqueue: flush immediately if the count trigger is hit, otherwise
	 * make sure a single delayed flush is scheduled so the 5-minute age trigger fires.
	 */
	public function maybe_schedule() {
		if ( ! $this->scheduler_available() ) {
			return;
		}

		$queue = new Quissly_Dirty_Queue();
		if ( self::should_flush( $queue->count_pending(), $queue->oldest_pending_age() ) ) {
			$this->schedule_now();

			return;
		}

		// Ensure exactly one delayed flush is pending (so an idle item still flushes in
		// <= FLUSH_AGE). If one is already scheduled, leave it.
		if ( ! as_has_scheduled_action( self::FLUSH_HOOK ) ) {
			as_schedule_single_action( time() + self::FLUSH_AGE, self::FLUSH_HOOK, array(), 'quissly' );
		}
	}

	/**
	 * Process one batch: map upserts, hand the batch to the sender, then settle the queue
	 * by the result. Directly callable in tests (no Action Scheduler runner required).
	 */
	public function flush_batch() {
		$this->stop_draining = false;
		$queue = new Quissly_Dirty_Queue();
		$rows  = $queue->claim_batch( self::BATCH_SIZE );
		if ( empty( $rows ) ) {
			return;
		}

		$upsert_ids = array();
		$delete_ids = array();
		foreach ( $rows as $row ) {
			if ( Quissly_Dirty_Queue::OP_DELETE === $row['operation'] ) {
				$delete_ids[] = $row['product_id'];
			} else {
				$upsert_ids[] = $row['product_id'];
			}
		}

		$client = $this->client();

		if ( ! empty( $upsert_ids ) ) {
			$records = array();
			$missing = array();
			foreach ( $upsert_ids as $pid ) {
				$record = $this->map_product( $pid );
				if ( null === $record ) {
					$missing[] = $pid; // product vanished — can't map; drop so it doesn't wedge the queue.
				} else {
					$records[ $pid ] = $record;
				}
			}
			if ( ! empty( $missing ) ) {
				$queue->remove( $missing );
				Quissly_Sync_Log::log( 'Dropped unmappable (missing) product ids from queue: ' . implode( ', ', $missing ) );
			}
			if ( ! empty( $records ) ) {
				// ADD-vs-PUT routing: /add REJECTS already-ingested ids (duplicates), and PUT
				// fails on a non-existent id. Route by the ingested marker: NEW products -> add,
				// previously-ingested products -> update (PUT).
				$new      = array();
				$existing = array();
				foreach ( $records as $pid => $rec ) {
					if ( $this->is_ingested( (int) $pid ) ) {
						$existing[ $pid ] = $rec;
					} else {
						$new[ $pid ] = $rec;
					}
				}
				if ( ! empty( $new ) ) {
					$this->apply_upsert_result( $queue, $client, $client->send_upserts( $new ), $new, 'add' );
				}
				if ( ! empty( $existing ) ) {
					$this->apply_upsert_result( $queue, $client, $client->send_updates( $existing ), $existing, 'update' );
				}
			}
		}

		if ( ! empty( $delete_ids ) && ! $this->stop_draining ) {
			$this->apply_delete_result( $queue, $client->send_deletes( $delete_ids ), $delete_ids );
		}

		$pending = $queue->count_pending();

		// A running full sync that has drained: mark progress complete, and OPEN the
		// first-sync gate only if something actually reached Quissly. Failed rows are dropped
		// after MAX_ATTEMPTS, so a sync where every batch failed also drains to zero - opening
		// the gate on that would report a finished sync against an empty index. An empty
		// catalog (total 0) opens: there is nothing to deliver. A partial sync opens too - a
		// few failed products must not keep search off for the whole store.
		if ( 0 === $pending && Quissly_Sync_Progress::is_running() ) {
			$progress  = Quissly_Sync_Progress::get();
			$delivered = (int) $progress['ok'] + (int) $progress['already'];
			Quissly_Sync_Progress::complete();
			if ( $delivered > 0 || 0 === (int) $progress['total'] ) {
				Quissly_Sync_State::mark_initial_sync_complete();
				Quissly_Sync_State::clear_gate_blocked();
				Quissly_Sync_Log::log( 'Full sync complete; first-sync gate opened.' );
			} else {
				Quissly_Sync_State::mark_gate_blocked();
				Quissly_Sync_Log::log( 'Full sync finished WITHOUT delivering any product (' . (int) $progress['failed'] . ' failed); first-sync gate kept shut. Fix the cause and re-sync.' );
			}
		}

		// More to do and a real sender? Chain another flush via AS (unless an inline drain
		// loop is already driving continuation, to avoid racing the same rows, or a refusal /
		// concurrency limit already scheduled a delayed retry).
		if ( $pending > 0 && ! $this->inline_draining && ! $this->stop_draining && $client->is_configured() && $this->scheduler_available() ) {
			$this->schedule_now();
		}
	}

	/**
	 * Start a full catalog sync: enqueue every published product and begin progress tracking.
	 * The gate is NOT opened here — it opens when the worker drains the queue (completion).
	 *
	 * @param bool $schedule Schedule the background AS drain (true for the wizard; the admin
	 *                       REST action passes false and drains inline instead).
	 * @return int Number of products enqueued.
	 */
	public function start_full_sync( $schedule = true ) {
		$ids   = $this->catalog_ids();
		$queue = new Quissly_Dirty_Queue();
		foreach ( $ids as $id ) {
			$queue->enqueue( (int) $id, Quissly_Dirty_Queue::OP_UPSERT );
		}

		Quissly_Sync_Progress::start( count( $ids ) );
		Quissly_Sync_State::clear_gate_blocked();
		Quissly_Sync_Log::log( 'Full sync started: ' . count( $ids ) . ' products queued.' );

		if ( $schedule && $this->scheduler_available() ) {
			$this->schedule_now();
		}

		return count( $ids );
	}

	/**
	 * The published products a full sync sends. On a multilingual store, every language's
	 * products are looked at (a multilingual plugin narrows a query to the admin's language
	 * otherwise) and only the main language's go to Quissly, once each; a translation sent
	 * before is queued for deletion (Quissly_Languages::keep_for_sync()).
	 *
	 * @return int[]
	 */
	public function catalog_ids() {
		if ( ! Quissly_Languages::is_multilingual() ) {
			return function_exists( 'wc_get_products' )
				? array_map( 'intval', wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'ids' ) ) )
				: array();
		}
		$all = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'lang' => '' ) );
		$ids = array();
		foreach ( $all as $id ) {
			if ( Quissly_Languages::keep_for_sync( (int) $id ) ) {
				$ids[] = (int) $id;
			} elseif ( $this->is_ingested( (int) $id ) ) {
				( new Quissly_Dirty_Queue() )->enqueue( (int) $id, Quissly_Dirty_Queue::OP_DELETE );
			}
		}

		return $ids;
	}

	/**
	 * Apply an add/update (PUT) result to the queue + the ingested marker.
	 *
	 * On the ADD path an "already exists" duplicate means the product was mis-routed (it is
	 * already in the index) — re-route those ids to PUT so the update actually applies, then
	 * merge the outcome. Successful ids are MARKED ingested (so future edits route to PUT) and
	 * removed; genuine failures retry-then-drop.
	 *
	 * @param Quissly_Dirty_Queue   $queue   Queue.
	 * @param Quissly_Catalog_Client $client Sender (for the re-route).
	 * @param array|null            $result  Sender result (null = not configured/transport).
	 * @param array<int,array>      $records Records sent (keyed by id) — for the re-route.
	 * @param string                $ctx     'add' or 'update'.
	 */
	private function apply_upsert_result( $queue, $client, $result, array $records, $ctx ) {
		if ( null === $result ) {
			return; // Not configured / transport error: leave queued, burn no retry.
		}
		if ( ! empty( $result['account_refused'] ) ) {
			$this->refused( (int) ( $result['refused_code'] ?? 0 ), $ctx );
			return;
		}
		if ( ! empty( $result['concurrency_limited'] ) ) {
			$this->backoff( $ctx );
			return;
		}

		$ok       = array_map( 'intval', (array) ( $result['ok'] ?? array() ) );
		$failed   = array_map( 'intval', (array) ( $result['failed'] ?? array() ) );
		$already  = array_map( 'intval', (array) ( $result['already_exists'] ?? array() ) );

		if ( ! empty( $already ) ) {
			// They ARE in the index -> mark ingested so subsequent edits route to PUT.
			$this->mark_ingested( $already );
			if ( 'add' === $ctx ) {
				// Mis-routed existing products: PUT them so the update applies.
				$reroute = array_intersect_key( $records, array_flip( $already ) );
				$put     = $reroute ? $client->send_updates( $reroute ) : null;
				if ( is_array( $put ) && empty( $put['concurrency_limited'] ) ) {
					$ok     = array_merge( $ok, array_map( 'intval', (array) ( $put['ok'] ?? array() ) ) );
					$failed = array_merge( $failed, array_map( 'intval', (array) ( $put['failed'] ?? array() ) ) );
				}
				// (transport/concurrency on the re-route: leave those ids queued to retry.)
			} else {
				$ok = array_merge( $ok, $already ); // PUT-path already-exists: just accept.
			}
		}

		$ok      = array_values( array_unique( $ok ) );
		$failed  = array_values( array_unique( $failed ) );
		$already = array_values( array_unique( $already ) );
		if ( ! empty( $ok ) ) {
			$this->mark_ingested( $ok );
			Quissly_Sync_State::clear_refusal();
		}

		// Per-batch outcome log (ids/counts only — never payloads/secrets) + progress.
		Quissly_Sync_Log::log( sprintf(
			'catalog %s batch: %d ok, %d failed, %d already-present (of %d).',
			$ctx,
			count( $ok ),
			count( $failed ),
			count( $already ),
			count( $records )
		) );
		Quissly_Sync_Progress::record( count( $ok ), count( $failed ), count( $already ) );

		$this->settle_queue( $queue, $ok, $failed, $ctx );
	}

	/**
	 * Apply a delete result: remove succeeded ids from the queue and CLEAR their ingested
	 * marker (so a future re-add routes to /add, not PUT).
	 *
	 * @param Quissly_Dirty_Queue $queue  Queue.
	 * @param array|null          $result Sender result.
	 * @param int[]               $ids    Attempted delete ids.
	 */
	private function apply_delete_result( $queue, $result, array $ids ) {
		if ( null === $result ) {
			return;
		}
		if ( ! empty( $result['account_refused'] ) ) {
			$this->refused( (int) ( $result['refused_code'] ?? 0 ), 'delete' );
			return;
		}
		if ( ! empty( $result['concurrency_limited'] ) ) {
			$this->backoff( 'delete' );
			return;
		}
		$ok = array_values( array_unique( array_map( 'intval', (array) ( $result['ok'] ?? array() ) ) ) );
		if ( ! empty( $ok ) ) {
			$this->clear_ingested( $ok );
			Quissly_Sync_State::clear_refusal();
		}
		$this->settle_queue( $queue, $ok, array_map( 'intval', (array) ( $result['failed'] ?? array() ) ), 'delete' );
	}

	/**
	 * Shared queue settle: remove the ok ids; bump failed retries and drop+log over-max ones.
	 *
	 * @param Quissly_Dirty_Queue $queue  Queue.
	 * @param int[]               $ok     Succeeded ids.
	 * @param int[]               $failed Failed (retryable) ids.
	 * @param string              $ctx    Context (logging).
	 */
	private function settle_queue( $queue, array $ok, array $failed, $ctx ) {
		if ( ! empty( $ok ) ) {
			$queue->remove( $ok );
		}
		$failed = array_values( array_unique( array_map( 'intval', $failed ) ) );
		if ( ! empty( $failed ) ) {
			$queue->bump_attempts( $failed );
			$permanent = $queue->over_max_attempts( $failed, self::MAX_ATTEMPTS );
			if ( ! empty( $permanent ) ) {
				$queue->remove( $permanent );
				Quissly_Sync_Log::record_failures( $permanent, $ctx );
			}
		}
	}

	/**
	 * Account refusal: leave the batch queued with no attempt burned, record it for the
	 * dashboard, and retry later - once the merchant's plan or credentials are sorted, the
	 * drain resumes where it stopped with nothing lost. Deletes follow the same rule, where
	 * dropping the rows would be worse: removed products would stay searchable for good.
	 *
	 * @param int    $code HTTP status.
	 * @param string $ctx  Context (logging).
	 */
	private function refused( $code, $ctx ) {
		$this->stop_draining = true;
		Quissly_Sync_State::record_refusal( $code );
		// One pending retry is enough; every refused run scheduling another would stack them.
		if ( $this->scheduler_available() && ! as_has_scheduled_action( self::FLUSH_HOOK ) ) {
			as_schedule_single_action( time() + self::REFUSAL_RETRY_DELAY, self::FLUSH_HOOK, array(), 'quissly' );
		}
		Quissly_Sync_Log::log( 'Quissly refused catalog updates (HTTP ' . $code . ', ' . $ctx . '); batch left queued, retrying in ' . self::REFUSAL_RETRY_DELAY . 's.' );
	}

	/**
	 * Concurrency-limit rejection: schedule a backoff flush and leave the batch queued.
	 *
	 * @param string $ctx Context (logging).
	 */
	private function backoff( $ctx ) {
		$this->stop_draining = true;
		if ( $this->scheduler_available() ) {
			as_schedule_single_action( time() + self::BACKOFF_DELAY, self::FLUSH_HOOK, array(), 'quissly' );
		}
		Quissly_Sync_Log::log( 'Concurrency limit hit; backing off ' . self::BACKOFF_DELAY . 's (' . $ctx . ').' );
	}

	/**
	 * Ingested marker (post meta) — whether a product has been successfully ingested into the
	 * Quissly index. Drives add-vs-PUT routing. Set on add/update success, cleared on delete.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	private function is_ingested( $product_id ) {
		return '' !== (string) get_post_meta( (int) $product_id, self::INGESTED_META, true );
	}

	/**
	 * Mark product ids as ingested.
	 *
	 * @param int[] $product_ids Ids.
	 */
	private function mark_ingested( array $product_ids ) {
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $id ) {
			if ( $id > 0 ) {
				update_post_meta( $id, self::INGESTED_META, 1 );
			}
		}
	}

	/**
	 * Clear the ingested marker for product ids (on delete).
	 *
	 * @param int[] $product_ids Ids.
	 */
	private function clear_ingested( array $product_ids ) {
		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $id ) {
			if ( $id > 0 ) {
				delete_post_meta( $id, self::INGESTED_META );
			}
		}
	}

	/**
	 * Resolve the swappable catalog sender (mock in dev/tests, live otherwise).
	 *
	 * @return Quissly_Catalog_Client
	 */
	private function client() {
		$client = apply_filters( 'quissly_catalog_client', null );
		if ( $client instanceof Quissly_Catalog_Client ) {
			return $client;
		}

		return new Quissly_Live_Catalog_Client();
	}

	/**
	 * Schedule an immediate async flush (deduped — one pending async action is enough).
	 */
	private function schedule_now() {
		if ( ! as_has_scheduled_action( self::FLUSH_HOOK ) ) {
			as_enqueue_async_action( self::FLUSH_HOOK, array(), 'quissly' );
		}
	}

	/**
	 * Whether Action Scheduler is loaded (it ships with WooCommerce).
	 *
	 * @return bool
	 */
	private function scheduler_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Map a WooCommerce product id to a Quissly catalog record, or null if it no longer
	 * exists. Bridges WC_Product -> the pure mapper's input shape.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public function map_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return Quissly_Product_Mapper::map( $this->extract( $product ) );
	}

	/**
	 * Extract a WC_Product into the normalized array the mapper consumes.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private function extract( WC_Product $product ) {
		$is_variable = $product->is_type( 'variable' );

		$data = array(
			'id'                => $product->get_id(),
			'type'              => $is_variable ? 'variable' : 'simple',
			'title'             => $product->get_name(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku'               => $product->get_sku(),
			'brand'             => $this->brand( $product ),
			'url'               => get_permalink( $product->get_id() ),
			'images'            => $this->images( $product ),
			'categories'        => $this->categories( $product ),
			'tags'              => $this->term_slugs( $product->get_id(), 'product_tag' ),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'in_stock'          => $product->is_in_stock(),
		);

		if ( $is_variable ) {
			$data['attributes'] = array(); // parent fixes no attribute values; variations carry them.
			$data['variations'] = $this->variations( $product );
		} else {
			// Simple/grouped/external products are LEAF matches: provide their product-level
			// attributes so the mapper can emit metadata.selected_options (attribute faceting).
			$data['attributes'] = $this->product_attributes( $product );
		}
		$data['features'] = $this->catalog_attributes( $product );

		return $data;
	}

	/**
	 * The attributes that go to Quissly, as label => display value(s) ("Material" => "Merino
	 * wool, Linen"): term NAMES, not slugs, because this is text Quissly searches. Which ones
	 * is the Catalog data checklist (Quissly_Catalog_Attributes): what the product page shows
	 * ("Visible on the product page"), unless the merchant ticked or unticked it there. A
	 * variable product's variation attributes are left out — each variation carries its own
	 * value.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,string>
	 */
	private function catalog_attributes( WC_Product $product ) {
		$choices = Quissly_Catalog_Attributes::choices();
		$out     = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
				continue;
			}
			if ( $product->is_type( 'variable' ) && $attribute->get_variation() ) {
				continue;
			}
			$label = wc_attribute_label( $attribute->get_name(), $product );
			if ( ! Quissly_Catalog_Attributes::included( $label, $attribute->get_visible(), $choices ) ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wp_list_pluck( (array) $attribute->get_terms(), 'name' )
				: $attribute->get_options();
			$values = array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' );
			if ( ! empty( $values ) ) {
				$out[ $label ] = implode( ', ', $values );
			}
		}

		return $out;
	}

	/**
	 * Product-level attributes for a NON-variable product, as a raw {key => value} map the mapper
	 * canonicalizes into metadata.selected_options. Taxonomy attribute options (term ids) resolve
	 * to the term SLUG (matching the slug values variations carry); custom attributes use their
	 * option strings. One value per attribute (sample-store attributes are single-option; a
	 * multi-option attribute takes its first value).
	 *
	 * @param WC_Product $product Non-variable product.
	 * @return array<string,string>
	 */
	private function product_attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $key => $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
				continue;
			}
			$values = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_options() as $term_id ) {
					$term = get_term( (int) $term_id );
					if ( $term && ! is_wp_error( $term ) ) {
						$values[] = $term->slug;
					}
				}
			} else {
				$values = array_map( 'strval', $attribute->get_options() );
			}
			if ( ! empty( $values ) ) {
				$out[ (string) $key ] = $values[0];
			}
		}

		return $out;
	}

	/**
	 * Variation rows for a variable product (mapper-input shape).
	 *
	 * @param WC_Product $product Variable product.
	 * @return array<int,array>
	 */
	private function variations( WC_Product $product ) {
		$rows = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$image_id = $variation->get_image_id();
			// WooCommerce's own pre-select params (attribute_pa_color=red...), the same link
			// the results page uses; "any" attributes are left unpinned.
			$url      = add_query_arg(
				Quissly_Variant_Deeplink::attribute_query_args( $variation->get_attributes() ),
				get_permalink( $product->get_id() )
			);
			$rows[]   = array(
				'id'            => $variation->get_id(),
				'regular_price' => $variation->get_regular_price(),
				'sale_price'    => $variation->get_sale_price(),
				'sku'           => $variation->get_sku(),
				'image'         => $image_id ? (string) wp_get_attachment_url( $image_id ) : '',
				'in_stock'      => $variation->is_in_stock(),
				'attributes'    => $variation->get_attributes(), // attribute_pa_color => red, ...
				'url'           => $url,
				// The variant's title on the wire (see Quissly_Product_Mapper).
				'option_summary' => self::option_summary( $variation ),
			);
		}

		return $rows;
	}

	/**
	 * A variation's options in the store's own words - "Color: Red, Size: XS" - from its
	 * attribute labels and term names ("any" attributes have no value and are left out).
	 *
	 * @param WC_Product $variation Variation.
	 * @return string
	 */
	private static function option_summary( WC_Product $variation ) {
		$parts = array();
		foreach ( $variation->get_attributes() as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$term    = taxonomy_exists( $name ) ? get_term_by( 'slug', $value, $name ) : false;
			$parts[] = wc_attribute_label( $name, $variation ) . ': ' . ( $term ? $term->name : $value );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Featured + gallery image URLs (featured first).
	 *
	 * @param WC_Product $product Product.
	 * @return string[]
	 */
	private function images( WC_Product $product ) {
		$ids = array();
		if ( $product->get_image_id() ) {
			$ids[] = $product->get_image_id();
		}
		foreach ( $product->get_gallery_image_ids() as $gid ) {
			$ids[] = $gid;
		}

		$urls = array();
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Category descriptors [{slug, primary}] — first category flagged primary.
	 *
	 * @param WC_Product $product Product.
	 * @return array<int,array{slug:string,primary:bool}>
	 */
	private function categories( WC_Product $product ) {
		$slugs = $this->term_slugs( $product->get_id(), 'product_cat' );
		$out   = array();
		foreach ( $slugs as $i => $slug ) {
			$out[] = array(
				'slug'    => $slug,
				'primary' => 0 === $i,
			);
		}

		return $out;
	}

	/**
	 * Term slugs for a taxonomy on a product.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return string[]
	 */
	private function term_slugs( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( $t ) {
					return $t->slug;
				},
				$terms
			)
		);
	}

	/**
	 * Best-effort brand: the WooCommerce/native product brand taxonomy if present, else a
	 * `brand`/`pa_brand` attribute, else ''. (Brand is advisory metadata in v1.)
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function brand( WC_Product $product ) {
		foreach ( array( 'product_brand', 'pwb-brand', 'product_brands' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$slugs = $this->term_slugs( $product->get_id(), $taxonomy );
				if ( ! empty( $slugs ) ) {
					return $slugs[0];
				}
			}
		}
		$attr = $product->get_attribute( 'brand' );
		if ( '' === $attr ) {
			$attr = $product->get_attribute( 'pa_brand' );
		}

		return is_string( $attr ) ? $attr : '';
	}
}
