/**
 * Quissly admin dashboard: catalog-sync progress polling + start/re-sync + connection test.
 *
 * Talks to the admin REST endpoints (quissly/v1/sync/*, connection/test) with the cookie
 * nonce. Reads the LIVE progress state (which parses the real status body server-side) and
 * moves a progress bar; never trusts an HTTP code for success — the server reads the body.
 */
( function () {
	var cfg = window.QuisslySync || {};
	function el( id ) {
		return document.getElementById( id );
	}

	function fmtEta( seconds ) {
		if ( ! seconds || seconds <= 0 ) {
			return '';
		}
		var m = Math.ceil( seconds / 60 );
		return m <= 1 ? cfg.i18n.etaMinute : cfg.i18n.etaMinutes.replace( '%d', m );
	}

	function render( p ) {
		var bar = el( 'quissly-progress-bar' );
		if ( ! bar ) {
			return;
		}
		var pct = Math.max( 0, Math.min( 100, parseInt( p.percent, 10 ) || 0 ) );
		bar.value = pct;
		el( 'quissly-progress-percent' ).textContent = pct + '%';

		var status;
		if ( p.refused_code ) {
			status = cfg.i18n.refused.replace( '%d', p.refused_code );
		} else if ( p.running ) {
			var eta = fmtEta( p.eta_seconds );
			status = cfg.i18n.syncing + ( eta ? ' — ' + cfg.i18n.eta + ' ' + eta : '' );
		} else if ( 'complete' === p.status && p.gate_blocked ) {
			status = cfg.i18n.blocked;
		} else if ( 'complete' === p.status ) {
			status = cfg.i18n.complete;
		} else {
			status = cfg.i18n.idle;
		}
		el( 'quissly-progress-status' ).textContent = status;

		var counts = cfg.i18n.synced
			.replace( '%1$d', p.ok || 0 )
			.replace( '%2$d', p.total || 0 );
		if ( p.already ) {
			counts += ' ' + cfg.i18n.already.replace( '%d', p.already );
		}
		if ( p.failed ) {
			counts += ' · ' + cfg.i18n.failed.replace( '%d', p.failed );
		}
		if ( p.pending ) {
			counts += ' · ' + cfg.i18n.pending.replace( '%d', p.pending );
		}
		el( 'quissly-progress-counts' ).textContent = counts;
	}

	var timer = null;
	function poll() {
		fetch( cfg.restUrl + 'sync/progress', { headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( p ) {
				render( p );
				if ( ! p.running && timer ) {
					clearInterval( timer );
					timer = null;
				}
			} );
	}
	function startPolling() {
		if ( ! timer ) {
			timer = setInterval( poll, 1500 );
		}
	}

	function init() {
		poll();

		var startBtn = el( 'quissly-sync-start' );
		if ( startBtn ) {
			startBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				startBtn.disabled = true;
				startBtn.textContent = cfg.i18n.starting;
				fetch( cfg.restUrl + 'sync/start', { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce } } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( p ) {
						render( p );
						startBtn.disabled = false;
						startBtn.textContent = cfg.i18n.resync;
						startPolling();
					} );
			} );
		}

		var testBtn = el( 'quissly-test-connection' );
		if ( testBtn ) {
			testBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				testBtn.disabled = true;
				var out = el( 'quissly-connection-result' );
				out.textContent = cfg.i18n.testing;
				out.className = 'quissly-conn-result';
				fetch( cfg.restUrl + 'connection/test', { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce } } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						out.textContent = res.message;
						out.className = 'quissly-conn-result ' + ( res.ok ? 'is-ok' : 'is-fail' );
						testBtn.disabled = false;
					} );
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
