<?php
/**
 * Catalog sync WooCommerce hooks.
 *
 * @package Quissly_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WooCommerce product/variation lifecycle hooks and enqueues dirty product
 * ids (variation changes mark the PARENT dirty). Enqueuing only — the API is never called
 * synchronously here; the Action Scheduler worker flushes the queue.
 *
 * STOCK: we listen to `woocommerce_product_set_stock_status` (the in/out FLIP) but NOT to
 * `woocommerce_product_set_stock` (quantity), so a quantity-only change does not trigger a
 * resync. Out-of-stock enqueues an UPSERT (the worker maps it to `in_stock:false`), never
 * a delete — products are marked unavailable, not removed.
 *
 * ELIGIBILITY: every upsert funnels through enqueue_upsert(), which re-checks the product
 * against is_eligible() (published, and search-visible catalog_visibility) before deciding
 * upsert vs delete. A draft/pending/private product, or one set to "catalog only"/"hidden"
 * visibility, is routed to a DELETE instead - including the publish->draft case, where
 * nothing previously told Quissly the product had left the index. Delete is idempotent, so
 * this is safe even for a product Quissly never actually received.
 */
class Quissly_Sync_Hooks {

	/**
	 * Worker (to (re)schedule a flush after enqueue).
	 *
	 * @var Quissly_Sync_Worker
	 */
	private $worker;

	/**
	 * @param Quissly_Sync_Worker $worker Sync worker.
	 */
	public function __construct( Quissly_Sync_Worker $worker ) {
		$this->worker = $worker;
	}

	/**
	 * Register all listeners.
	 */
	public function register() {
		// Product create/update.
		add_action( 'woocommerce_new_product', array( $this, 'on_product_changed' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_changed' ), 10, 1 );

		// Bulk/CSV/REST edits route through save_post; guard autosaves and revisions.
		add_action( 'save_post_product', array( $this, 'on_save_post' ), 10, 3 );

		// Stock STATUS flip only (NOT quantity — we never hook woocommerce_product_set_stock).
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_variation_stock_status' ), 10, 1 );

		// Deletion / trash -> delete from the index.
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_deleted' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ), 10, 1 );

		// Variation changes -> mark the PARENT dirty (we sync the parent with rolled-up
		// variant data).
		add_action( 'woocommerce_save_product_variation', array( $this, 'on_variation_changed' ), 10, 1 );
		add_action( 'woocommerce_delete_product_variation', array( $this, 'on_variation_changed' ), 10, 1 );
		add_action( 'woocommerce_trash_product_variation', array( $this, 'on_variation_changed' ), 10, 1 );
	}

	/**
	 * Enqueue a product upsert + (re)schedule a flush.
	 *
	 * @param int $product_id Product id.
	 */
	public function on_product_changed( $product_id ) {
		$this->enqueue_upsert( (int) $product_id );
	}

	/**
	 * save_post handler — guard autosaves, revisions, and auto-drafts.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post = null, $update = false ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( $post instanceof WP_Post && 'auto-draft' === $post->post_status ) {
			return;
		}
		$this->enqueue_upsert( (int) $post_id );
	}

	/**
	 * Product stock-status flip.
	 *
	 * @param int $product_id Product id.
	 */
	public function on_stock_status( $product_id ) {
		$this->enqueue_upsert( (int) $product_id );
	}

	/**
	 * Variation stock-status flip -> parent dirty.
	 *
	 * @param int $variation_id Variation id.
	 */
	public function on_variation_stock_status( $variation_id ) {
		$this->enqueue_parent_of( (int) $variation_id );
	}

	/**
	 * Variation saved/deleted/trashed -> parent dirty.
	 *
	 * @param int $variation_id Variation id.
	 */
	public function on_variation_changed( $variation_id ) {
		$this->enqueue_parent_of( (int) $variation_id );
	}

	/**
	 * Product hard-deleted.
	 *
	 * @param int $product_id Product id.
	 */
	public function on_product_deleted( $product_id ) {
		$this->enqueue_delete( (int) $product_id );
	}

	/**
	 * Post trashed — only act on products.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trash_post( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->enqueue_delete( (int) $post_id );
		}
	}

	// ---------------------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------------------

	/**
	 * Resolve a variation's parent and enqueue the parent as an upsert.
	 *
	 * @param int $variation_id Variation id.
	 */
	private function enqueue_parent_of( $variation_id ) {
		$parent_id = (int) wp_get_post_parent_id( $variation_id );
		if ( $parent_id > 0 ) {
			$this->enqueue_upsert( $parent_id );
		}
	}

	/**
	 * Enqueue an upsert - or, if the product is no longer eligible, a delete instead -
	 * and (re)schedule a flush. The single choke point every enqueue path funnels
	 * through, so eligibility is re-checked on every save, not only at first enqueue.
	 *
	 * @param int $product_id Product id.
	 */
	private function enqueue_upsert( $product_id ) {
		if ( $product_id <= 0 ) {
			return;
		}
		if ( ! $this->is_eligible( $product_id ) ) {
			$this->enqueue_delete( $product_id );
			return;
		}
		// Eligible right now, so this upsert replaces a stale pending delete (trash -> restore
		// before the queue flushed: WooCommerce restores the product's previous status).
		( new Quissly_Dirty_Queue() )->enqueue_live( $product_id );
		$this->worker->maybe_schedule();
	}

	/**
	 * Whether a product belongs in the Quissly index: published, and its catalog
	 * visibility includes search (not "catalog only" or "hidden"). Mirrors the status
	 * filter start_full_sync() already applies, plus the visibility check that was
	 * previously only exercised in tests.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	private function is_eligible( $product_id ) {
		if ( 'publish' !== get_post_status( $product_id ) ) {
			return false;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return false;
		}
		return in_array( $product->get_catalog_visibility(), array( 'visible', 'search' ), true );
	}

	/**
	 * Enqueue a delete and (re)schedule a flush.
	 *
	 * @param int $product_id Product id.
	 */
	private function enqueue_delete( $product_id ) {
		if ( $product_id <= 0 ) {
			return;
		}
		( new Quissly_Dirty_Queue() )->enqueue( $product_id, Quissly_Dirty_Queue::OP_DELETE );
		$this->worker->maybe_schedule();
	}
}
