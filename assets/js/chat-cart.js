/**
 * QChat "Add to Cart" -> the WooCommerce cart (see frontend/class-quissly-chat-cart.php).
 *
 * The chat widget's generic cart only counts inside the widget and announces each
 * change as `quissly:generic-cart-sync` (detail = { quisslyProductId: quantity }).
 * This applies the difference from the previous event to the store's cart:
 *  - Quissly ids are translated with ?wc-ajax=quissly_cart_resolve; an id the store
 *    cannot translate is left alone (never guessed); a plain number is taken as a
 *    WooCommerce product id;
 *  - increases use WooCommerce's own ?wc-ajax=add_to_cart. When WooCommerce refuses
 *    (a variable product needs its options chosen) and names the product page, the
 *    shopper is taken there;
 *  - decreases and removals use ?wc-ajax=quissly_cart_adjust;
 *  - then the mini-cart is refreshed: classic themes via WooCommerce's fragments
 *    events, block themes via the Mini-Cart block's own events.
 *
 * Config: window.quisslyChatCart (wp_localize_script). No build step.
 */
( function () {
	'use strict';

	var CFG = window.quisslyChatCart;
	if ( ! CFG || ! CFG.ajaxUrl ) {
		return;
	}

	var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
	var last = {};
	var resolved = {};
	var queue = Promise.resolve();

	function endpoint( action ) {
		return CFG.ajaxUrl.replace( '%%endpoint%%', action );
	}

	function post( action, fields ) {
		return fetch( endpoint( action ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams( fields ).toString()
		} ).then( function ( r ) { return r.json(); } ).catch( function () { return null; } );
	}

	/** Tell the theme the cart changed, whichever mini-cart it uses. */
	function refreshMiniCart( added, data ) {
		var $ = window.jQuery;
		if ( $ ) {
			if ( added && data && data.fragments ) {
				$( document.body ).trigger( 'added_to_cart', [ data.fragments, data.cart_hash, null ] );
			} else {
				$( document.body ).trigger( 'wc_fragment_refresh' );
			}
		}
		document.body.dispatchEvent( new CustomEvent( added ? 'wc-blocks_added_to_cart' : 'wc-blocks_removed_from_cart', { bubbles: true } ) );
	}

	function add( productId, quantity ) {
		return post( CFG.addToCart, { product_id: productId, quantity: quantity } ).then( function ( data ) {
			if ( data && data.error && data.product_url ) {
				window.location.href = data.product_url; // options to choose first
				return;
			}
			refreshMiniCart( true, data );
		} );
	}

	function reduce( productId, quantity ) {
		return post( CFG.adjust, { product_id: productId, delta: -quantity } ).then( function () {
			refreshMiniCart( false, null );
		} );
	}

	function resolve( ids ) {
		var unknown = ids.filter( function ( id ) { return UUID.test( id ) && ! ( id.toLowerCase() in resolved ); } );
		if ( ! unknown.length ) {
			return Promise.resolve();
		}
		var url = endpoint( CFG.resolve ) + ( endpoint( CFG.resolve ).indexOf( '?' ) === -1 ? '?' : '&' ) + 'ids=' + encodeURIComponent( unknown.join( ',' ) );
		return fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( map ) {
				unknown.forEach( function ( id ) { resolved[ id.toLowerCase() ] = ( map && map[ id.toLowerCase() ] ) || null; } );
			}, function () {} );
	}

	function productId( id ) {
		if ( /^[0-9]+$/.test( id ) ) {
			return id;
		}
		var found = resolved[ String( id ).toLowerCase() ];
		return found ? String( found ) : null;
	}

	window.addEventListener( 'quissly:generic-cart-sync', function ( event ) {
		var now = ( event && event.detail ) || {};
		var deltas = {};
		Object.keys( last ).concat( Object.keys( now ) ).forEach( function ( id ) {
			var delta = ( parseInt( now[ id ], 10 ) || 0 ) - ( parseInt( last[ id ], 10 ) || 0 );
			if ( delta !== 0 ) {
				deltas[ id ] = delta;
			}
		} );
		last = {};
		Object.keys( now ).forEach( function ( id ) { last[ id ] = parseInt( now[ id ], 10 ) || 0; } );

		// One change at a time, in order: the cart lives in the WooCommerce session.
		queue = queue.then( function () { return resolve( Object.keys( deltas ) ); } ).then( function () {
			return Object.keys( deltas ).reduce( function ( chain, id ) {
				var pid = productId( id );
				if ( ! pid ) {
					if ( window.console ) { console.warn( '[quissly] chat product ' + id + ' is not known to this store' ); }
					return chain;
				}
				return chain.then( function () { return deltas[ id ] > 0 ? add( pid, deltas[ id ] ) : reduce( pid, -deltas[ id ] ); } );
			}, Promise.resolve() );
		} );
	} );
} )();
