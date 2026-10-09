/**
 * Quissly Quick widget: body-level autocomplete dropdown + voice & image controls.
 *
 * The dropdown is appended to <body> and positioned over the resolved search input via
 * getBoundingClientRect (repositioned on scroll/resize), so theme overflow/transform/
 * z-index can't clip it. It never renders search RESULTS — Enter / "See all results" /
 * row clicks navigate, letting the theme + the search interception render natively.
 *
 * Voice and image are captured client-side, encoded (16 kHz mono WAV / <=1024px JPEG),
 * sent to the public proxy, and the returned short-lived token is carried to the results
 * page in a dedicated query var.
 *
 * Pure encode/resize helpers are exposed on window.quisslyTest for deterministic testing.
 */
( function () {
	'use strict';

	var CFG = window.quisslyQuick || {};

	// ---------------------------------------------------------------------------------
	// Pure helpers (testable): WAV encode + image resize.
	// ---------------------------------------------------------------------------------

	/**
	 * Resample mono Float32 PCM to 16 kHz (linear interpolation).
	 */
	function resampleTo16kMono( input, inputRate ) {
		var outRate = 16000;
		if ( inputRate === outRate ) {
			return input;
		}
		var ratio = inputRate / outRate;
		var outLength = Math.floor( input.length / ratio );
		var out = new Float32Array( outLength );
		for ( var i = 0; i < outLength; i++ ) {
			var pos = i * ratio;
			var left = Math.floor( pos );
			var right = Math.min( left + 1, input.length - 1 );
			var frac = pos - left;
			out[ i ] = input[ left ] * ( 1 - frac ) + input[ right ] * frac;
		}
		return out;
	}

	/**
	 * Encode mono Float32 PCM to a 16 kHz, 16-bit, mono WAV ArrayBuffer.
	 */
	function encodeWav( samples, inputRate ) {
		var pcm = resampleTo16kMono( samples, inputRate );
		var sampleRate = 16000;
		var bytesPerSample = 2;
		var dataSize = pcm.length * bytesPerSample;
		var buffer = new ArrayBuffer( 44 + dataSize );
		var view = new DataView( buffer );

		function writeString( offset, str ) {
			for ( var i = 0; i < str.length; i++ ) {
				view.setUint8( offset + i, str.charCodeAt( i ) );
			}
		}

		writeString( 0, 'RIFF' );
		view.setUint32( 4, 36 + dataSize, true );
		writeString( 8, 'WAVE' );
		writeString( 12, 'fmt ' );
		view.setUint32( 16, 16, true ); // fmt chunk size
		view.setUint16( 20, 1, true ); // PCM
		view.setUint16( 22, 1, true ); // mono
		view.setUint32( 24, sampleRate, true );
		view.setUint32( 28, sampleRate * bytesPerSample, true ); // byte rate
		view.setUint16( 32, bytesPerSample, true ); // block align
		view.setUint16( 34, 16, true ); // bits per sample
		writeString( 36, 'data' );
		view.setUint32( 40, dataSize, true );

		var offset = 44;
		for ( var i = 0; i < pcm.length; i++ ) {
			var s = Math.max( -1, Math.min( 1, pcm[ i ] ) );
			view.setInt16( offset, s < 0 ? s * 0x8000 : s * 0x7fff, true );
			offset += 2;
		}
		return buffer;
	}

	/**
	 * Resize an image source (HTMLImageElement/canvas/ImageBitmap) so its longest edge is
	 * <= maxEdge, and export JPEG. Returns { dataUrl, width, height }.
	 */
	function resizeImageToJpeg( source, maxEdge, quality ) {
		maxEdge = maxEdge || 1024;
		quality = quality || 0.85;
		var sw = source.naturalWidth || source.width;
		var sh = source.naturalHeight || source.height;
		var scale = Math.min( 1, maxEdge / Math.max( sw, sh ) );
		var w = Math.max( 1, Math.round( sw * scale ) );
		var h = Math.max( 1, Math.round( sh * scale ) );
		var canvas = document.createElement( 'canvas' );
		canvas.width = w;
		canvas.height = h;
		canvas.getContext( '2d' ).drawImage( source, 0, 0, w, h );
		return { dataUrl: canvas.toDataURL( 'image/jpeg', quality ), width: w, height: h };
	}

	window.quisslyTest = {
		resampleTo16kMono: resampleTo16kMono,
		encodeWav: encodeWav,
		resizeImageToJpeg: resizeImageToJpeg
	};

	// ---------------------------------------------------------------------------------
	// Small utilities.
	// ---------------------------------------------------------------------------------

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments, self = this;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( self, args ); }, ms );
		};
	}

	function el( tag, cls ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		return n;
	}

	function post( path, body ) {
		// The page's language (a multilingual store): the rows come back in it.
		if ( CFG.lang ) { body.lang = CFG.lang; }
		return fetch( CFG.restUrl + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
			body: JSON.stringify( body )
		} ).then( function ( r ) { return r.json(); } );
	}

	function base64FromArrayBuffer( buffer ) {
		var bytes = new Uint8Array( buffer ), bin = '';
		for ( var i = 0; i < bytes.length; i++ ) { bin += String.fromCharCode( bytes[ i ] ); }
		return btoa( bin );
	}

	// ---------------------------------------------------------------------------------
	// Dropdown.
	// ---------------------------------------------------------------------------------

	var dropdown, currentInput, rows = [], activeRow = -1;

	function ensureDropdown() {
		if ( dropdown ) { return dropdown; }
		dropdown = el( 'div', 'quissly-quick' );
		dropdown.setAttribute( 'role', 'listbox' );
		dropdown.style.display = 'none';
		if ( 'horizontal' === CFG.layout ) { dropdown.classList.add( 'quissly-quick--horizontal' ); }
		document.body.appendChild( dropdown );
		return dropdown;
	}

	function position() {
		if ( ! dropdown || ! currentInput || 'none' === dropdown.style.display ) { return; }
		if ( dropdown.classList.contains( 'quissly-quick--in-overlay' ) ) { return; }
		var r = currentInput.getBoundingClientRect();
		dropdown.style.left = ( window.scrollX + r.left ) + 'px';
		dropdown.style.top = ( window.scrollY + r.bottom ) + 'px';
		dropdown.style.minWidth = r.width + 'px';
	}

	/**
	 * Put the dropdown where the shopper is typing. In the search overlay's bar
	 * (assets/js/overlay.js) it moves into the overlay's results slot and becomes a
	 * full-width grid card under the bar, laid out by the stylesheet rather than
	 * measured. Anywhere else it is the floating dropdown under the field.
	 */
	function place() {
		var slot = currentInput && currentInput.classList && currentInput.classList.contains( 'quissly-overlay__input' ) ?
			document.querySelector( '[data-quissly-overlay-results]' ) : null;
		if ( slot ) {
			if ( dropdown.parentNode !== slot ) { slot.appendChild( dropdown ); }
			dropdown.classList.add( 'quissly-quick--in-overlay' );
			dropdown.style.left = '';
			dropdown.style.top = '';
			dropdown.style.minWidth = '';
			dropdown.style.display = 'grid';
			return;
		}
		if ( dropdown.parentNode !== document.body ) { document.body.appendChild( dropdown ); }
		dropdown.classList.remove( 'quissly-quick--in-overlay' );
		position();
	}

	function hide() {
		if ( dropdown ) { dropdown.style.display = 'none'; }
		activeRow = -1;
	}

	// Only http(s) and same-origin-relative URLs - never javascript:, data:, etc.
	// Quissly's response is untrusted input by the time it reaches this widget: it round-
	// trips merchant-authored product data, so a stored title/url is exactly as reachable
	// here as a reflected one.
	function isSafeUrl( url ) {
		return typeof url === 'string' && /^(https?:\/\/|\/(?!\/))/i.test( url );
	}

	function appendPrice( parent, row ) {
		var price = Number( row.price ), original = Number( row.original_price );
		if ( row.original_price != null && ! isNaN( original ) && ! isNaN( price ) && original > price ) {
			var del = el( 'del' );
			del.textContent = original;
			parent.appendChild( del );
			parent.appendChild( document.createTextNode( ' ' ) );
		}
		if ( row.price != null && ! isNaN( price ) ) {
			var span = el( 'span' );
			span.textContent = price;
			parent.appendChild( span );
		}
	}

	function render( suggestions ) {
		ensureDropdown();
		dropdown.innerHTML = '';
		rows = [];
		activeRow = -1;

		if ( ! suggestions || ! suggestions.length ) {
			var empty = el( 'div', 'quissly-quick__empty' );
			empty.textContent = '—';
			dropdown.appendChild( empty );
		} else {
			suggestions.slice( 0, CFG.maxRows || 10 ).forEach( function ( s ) {
				var row = el( 'a', 'quissly-quick__row' );
				row.setAttribute( 'role', 'option' );
				row.href = isSafeUrl( s.url ) ? s.url : '#';

				if ( s.image_url && isSafeUrl( s.image_url ) ) {
					var thumb = el( 'img', 'quissly-quick__thumb' );
					thumb.src = s.image_url;
					thumb.alt = '';
					row.appendChild( thumb );
				} else {
					row.appendChild( el( 'span', 'quissly-quick__thumb' ) );
				}

				var title = el( 'span', 'quissly-quick__title' );
				title.textContent = s.title || '';
				row.appendChild( title );

				var priceEl = el( 'span', 'quissly-quick__price' );
				appendPrice( priceEl, s );
				row.appendChild( priceEl );

				dropdown.appendChild( row );
				rows.push( row );
			} );
		}

		var seeAll = el( 'button', 'quissly-quick__seeall' );
		seeAll.type = 'button';
		seeAll.textContent = CFG.seeAllLabel || 'See all results';
		seeAll.addEventListener( 'click', function () { submitSearch(); } );
		dropdown.appendChild( seeAll );

		dropdown.style.display = 'block';
		place();
	}

	function submitSearch() {
		if ( ! currentInput ) { return; }
		if ( currentInput.form ) {
			currentInput.form.submit();
			return;
		}
		// No form to submit - the search overlay's input (assets/js/overlay.js) is one: it
		// runs the search on Enter. Hand "See all" to that same path rather than doing
		// nothing; clear the highlighted row first so onKeydown doesn't open that product.
		activeRow = -1;
		currentInput.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Enter', bubbles: true, cancelable: true } ) );
	}

	function moveActive( delta ) {
		if ( ! rows.length ) { return; }
		if ( activeRow >= 0 && rows[ activeRow ] ) { rows[ activeRow ].classList.remove( 'is-active' ); }
		activeRow = ( activeRow + delta + rows.length ) % rows.length;
		rows[ activeRow ].classList.add( 'is-active' );
		rows[ activeRow ].focus();
	}

	var fetchSuggestions = debounce( function ( term ) {
		post( 'quick', { q: term } ).then( function ( data ) {
			render( data && data.suggestions ? data.suggestions : [] );
		} ).catch( function () { hide(); } );
	}, CFG.debounceMs || 200 );

	function onInput( e ) {
		currentInput = e.target;
		var term = ( e.target.value || '' ).trim();
		if ( term.length < ( CFG.minChars || 2 ) ) { hide(); return; }
		fetchSuggestions( term );
	}

	function onKeydown( e ) {
		if ( ! dropdown || 'none' === dropdown.style.display ) { return; }
		if ( 'ArrowDown' === e.key ) { e.preventDefault(); moveActive( 1 ); }
		else if ( 'ArrowUp' === e.key ) { e.preventDefault(); moveActive( -1 ); }
		else if ( 'Escape' === e.key ) { hide(); }
		else if ( 'Enter' === e.key && activeRow >= 0 && rows[ activeRow ] ) {
			// Let a highlighted row navigate; otherwise the form submits natively.
			window.location.href = rows[ activeRow ].href;
			e.preventDefault();
		}
	}

	// ---------------------------------------------------------------------------------
	// Voice & image controls.
	// ---------------------------------------------------------------------------------

	function navigateWithToken( queryVar, token, term ) {
		if ( ! token ) { return; }
		var url = new URL( window.location.origin + '/' );
		url.searchParams.set( 'post_type', 'product' );
		url.searchParams.set( 's', term || ' ' );
		url.searchParams.set( queryVar, token );
		window.location.href = url.toString();
	}

	/**
	 * Build a stroked icon matching the overlay's magnifier (the Magento plugin's
	 * quissly-voice.js/quissly-image.js buildIcon()), so every control reads as one set
	 * instead of mixed emoji.
	 *
	 * @param {string[]} paths   SVG path "d" values.
	 * @param {string[]} circles "cx,cy,r" triples.
	 * @return {SVGElement}
	 */
	function buildIcon( paths, circles ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '1.9' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		( circles || [] ).forEach( function ( c ) {
			var parts = c.split( ',' );
			var circle = document.createElementNS( ns, 'circle' );
			circle.setAttribute( 'cx', parts[ 0 ] );
			circle.setAttribute( 'cy', parts[ 1 ] );
			circle.setAttribute( 'r', parts[ 2 ] );
			svg.appendChild( circle );
		} );
		( paths || [] ).forEach( function ( d ) {
			var path = document.createElementNS( ns, 'path' );
			path.setAttribute( 'd', d );
			svg.appendChild( path );
		} );
		return svg;
	}

	function micIcon() {
		return buildIcon(
			[ 'M12 3.5a2.6 2.6 0 0 1 2.6 2.6v5a2.6 2.6 0 0 1-5.2 0v-5A2.6 2.6 0 0 1 12 3.5z',
				'M5.5 11.2a6.5 6.5 0 0 0 13 0', 'M12 17.7V20.5', 'M9 20.5h6' ],
			[]
		);
	}

	function cameraIcon() {
		return buildIcon(
			[ 'M3.5 8.8h3.2l1.5-2.3h7.6l1.5 2.3h3.2v9.7H3.5z' ],
			[ '12,13.4,3.1' ]
		);
	}

	/**
	 * @param {function(string):void} [say]    Status callback (e.g. "Listening…"); no-op
	 *                                          if the caller has nowhere to show one.
	 * @param {Element}               [button] Toggled with a "recording" class while
	 *                                          capturing, for a visual (pulsing) cue.
	 */
	function startVoice( say, button ) {
		say = say || function () {};
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			// Browsers disable getUserMedia entirely on a non-secure origin (anything
			// that isn't https or localhost) - navigator.mediaDevices is undefined
			// there, not just permission-denied, so this is the ONE case worth telling
			// the merchant apart from a declined mic prompt (handled below).
			var secure = window.isSecureContext !== false;
			say( secure
				? ( ( CFG.i18n && CFG.i18n.micUnsupported ) || 'Voice search is not supported in this browser.' )
				: ( ( CFG.i18n && CFG.i18n.micInsecure ) || 'Voice search needs a secure (https) connection.' ) );
			return;
		}
		navigator.mediaDevices.getUserMedia( { audio: true } ).then( function ( stream ) {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			var ctx = new Ctx();
			var source = ctx.createMediaStreamSource( stream );
			var processor = ctx.createScriptProcessor( 4096, 1, 1 );
			var chunks = [];
			processor.onaudioprocess = function ( ev ) {
				chunks.push( new Float32Array( ev.inputBuffer.getChannelData( 0 ) ) );
			};
			source.connect( processor );
			processor.connect( ctx.destination );
			if ( button ) { button.classList.add( 'recording' ); }
			say( ( CFG.i18n && CFG.i18n.listening ) || 'Listening…' );

			var stop = function () {
				processor.disconnect();
				source.disconnect();
				stream.getTracks().forEach( function ( t ) { t.stop(); } );
				if ( button ) { button.classList.remove( 'recording' ); }
				var total = chunks.reduce( function ( n, c ) { return n + c.length; }, 0 );
				var merged = new Float32Array( total ), o = 0;
				chunks.forEach( function ( c ) { merged.set( c, o ); o += c.length; } );
				var wav = encodeWav( merged, ctx.sampleRate );
				ctx.close();
				say( ( CFG.i18n && CFG.i18n.searching ) || 'Searching…' );
				post( 'voice', { audio: base64FromArrayBuffer( wav ) } ).then( function ( data ) {
					// A failed/empty transcription still needs SOMETHING to show as the
					// results page's search term, not a blank string (matches
					// the Magento plugin's fallbackQuery()).
					var term = ( data && data.transcription ) || ( ( CFG.i18n && CFG.i18n.voiceFallbackQuery ) || 'your voice search' );
					navigateWithToken( ( data && data.query_var ) || CFG.voiceQueryVar, data && data.token, term );
				} );
			};
			// HARD 5-second cap (auto-stop).
			setTimeout( stop, CFG.voiceMaxMs || 5000 );
		} ).catch( function () {
			say( ( CFG.i18n && CFG.i18n.micDenied ) || 'Microphone unavailable.' );
		} );
	}

	/**
	 * Run a search from one image File — the shared path for both the file-picker
	 * (startImage) and a clipboard paste (initPaste), so a second copy would not drift.
	 *
	 * @param {File}                  file
	 * @param {function(string):void} say Status callback.
	 */
	function useImageFile( file, say ) {
		if ( ! file ) { return; }
		say( ( CFG.i18n && CFG.i18n.searching ) || 'Searching…' );
		var img = new Image();
		img.onload = function () {
			var out = resizeImageToJpeg( img, CFG.imageMaxEdge || 1024 );
			post( 'image', { image: out.dataUrl.split( ',' )[ 1 ] } ).then( function ( data ) {
				// An image search carries no words - the results page still needs
				// SOMETHING to show as the term (matches the Magento plugin's
				// fallbackQuery(), same "image search" wording).
				var term = ( CFG.i18n && CFG.i18n.imageFallbackQuery ) || 'your image search';
				navigateWithToken( ( data && data.query_var ) || CFG.imgQueryVar, data && data.token, term );
			} );
		};
		img.src = URL.createObjectURL( file );
	}

	/**
	 * @param {function(string):void} [say] Status callback; no-op if the caller has
	 *                                       nowhere to show one.
	 */
	function startImage( say ) {
		say = say || function () {};
		var input = el( 'input' );
		input.type = 'file';
		input.accept = 'image/*';
		input.addEventListener( 'change', function () {
			var file = input.files && input.files[ 0 ];
			input.value = ''; // allow re-picking the same file.
			useImageFile( file, say );
		} );
		input.click();
	}

	/**
	 * Whether a paste landed somewhere we own: one of our own search inputs, the
	 * overlay's own field, or nothing focused at all (the shopper is just on the page).
	 * Scoped so pasting into anything else - a coupon box, a review, an address - is
	 * never hijacked.
	 *
	 * @param {EventTarget} target
	 * @param {Element[]}   inputs The theme's own search inputs, from findInputs().
	 * @return {boolean}
	 */
	function ownsPasteTarget( target, inputs ) {
		if ( ! target || target === document || target === document.body ) {
			return true;
		}
		if ( inputs.indexOf( target ) !== -1 ) {
			return true;
		}
		return !! ( target.classList && target.classList.contains( 'quissly-overlay__input' ) );
	}

	/**
	 * Paste an image straight in (a screenshot, or "copy image" from another page).
	 * Only acts when the clipboard actually carries an image: pasting ordinary text
	 * must still type into the search box, so a clipboard without an image is left
	 * entirely alone - no preventDefault, no notice, nothing.
	 */
	function initPaste() {
		document.addEventListener( 'paste', function ( event ) {
			if ( ! CFG.enableImage ) { return; }
			if ( ! ownsPasteTarget( event.target, findInputs() ) ) { return; }
			var items = ( event.clipboardData || window.clipboardData || {} ).items;
			if ( ! items ) { return; }
			var file = null;
			for ( var i = 0; i < items.length && ! file; i++ ) {
				if ( items[ i ].kind === 'file' && String( items[ i ].type ).indexOf( 'image/' ) === 0 ) {
					file = items[ i ].getAsFile();
				}
			}
			if ( ! file ) { return; }
			event.preventDefault();
			var notice = document.querySelector( '.quissly-overlay__actions .quissly-image-notice' );
			var say = notice ? function ( text ) { notice.textContent = text; } : function () {};
			say( '' );
			useImageFile( file, say );
		} );
	}

	function addControls( inputEl ) {
		// Where the search overlay exists it is the search surface: voice/image live in its
		// own bar (attachOverlayControls), not beside a box that only opens it - and not
		// beside the overlay's own input, which the generic selectors also match.
		if ( document.querySelector( '[data-quissly-overlay-actions]' ) ) { return; }
		if ( inputEl.dataset.quisslyControls ) { return; }
		inputEl.dataset.quisslyControls = '1';
		var holder = el( 'span', 'quissly-controls' );
		if ( CFG.enableVoice ) {
			var mic = el( 'button', 'quissly-control quissly-control--voice' );
			mic.type = 'button';
			mic.setAttribute( 'aria-label', ( CFG.i18n && CFG.i18n.placeholderVoice ) || 'Voice search' );
			mic.appendChild( micIcon() );
			mic.addEventListener( 'click', function () { startVoice(); } );
			holder.appendChild( mic );
		}
		if ( CFG.enableImage ) {
			var cam = el( 'button', 'quissly-control quissly-control--image' );
			cam.type = 'button';
			cam.setAttribute( 'aria-label', ( CFG.i18n && CFG.i18n.placeholderImage ) || 'Search by image' );
			cam.appendChild( cameraIcon() );
			cam.addEventListener( 'click', function () { startImage(); } );
			holder.appendChild( cam );
		}
		if ( holder.childNodes.length && inputEl.parentNode ) {
			inputEl.parentNode.insertBefore( holder, inputEl.nextSibling );
		}
	}

	// ---------------------------------------------------------------------------------
	// Selector resolution + attach.
	// ---------------------------------------------------------------------------------

	function findInputs() {
		var found = [];
		( CFG.selectors || [] ).forEach( function ( sel ) {
			try {
				document.querySelectorAll( sel ).forEach( function ( n ) {
					if ( found.indexOf( n ) === -1 ) { found.push( n ); }
				} );
			} catch ( e ) {}
		} );
		return found;
	}

	/**
	 * The first priority selector that matches anything on this page (for onboarding cache).
	 */
	function firstMatchingSelector() {
		var sels = CFG.selectors || [];
		for ( var i = 0; i < sels.length; i++ ) {
			try {
				if ( document.querySelector( sels[ i ] ) ) { return sels[ i ]; }
			} catch ( e ) {}
		}
		return '';
	}

	/**
	 * Onboarding probe: when loaded with ?quissly_detect=1, resolve the search-box selector
	 * and cache it server-side. The FSE theme (TwentyTwentyFive) exposes its search box only
	 * on the results page, so the wizard points the probe at the search-results URL.
	 */
	function maybeDetect() {
		if ( ! /[?&]quissly_detect=1/.test( window.location.search ) ) { return; }
		var sel = firstMatchingSelector();
		if ( sel ) {
			post( 'selectors', { desktop: sel, mobile: sel } );
		}
	}

	/**
	 * On a voice/image results page, the `s` in the URL is a routing placeholder, not a
	 * query the shopper typed - so don't leave it sitting in the search box. Image search
	 * always carries the placeholder; voice carries the real transcription (which the
	 * shopper did say, so it stays) unless transcription came back empty. Only the box is
	 * touched: the URL, and so the token hand-off and results, are unchanged.
	 * (the Magento plugin blanks the box the same way.)
	 *
	 * @param {Element} inputEl
	 */
	function clearPlaceholderTerm( inputEl ) {
		var params = new URLSearchParams( window.location.search );
		var i18n   = CFG.i18n || {};
		var isImage = params.has( CFG.imgQueryVar || 'quissly_img' );
		var isVoicePlaceholder = params.has( CFG.voiceQueryVar || 'quissly_voice' )
			&& inputEl.value === ( i18n.voiceFallbackQuery || 'your voice search' );
		if ( isImage || isVoicePlaceholder ) {
			inputEl.value = '';
		}
	}

	function attach( inputEl ) {
		if ( inputEl.dataset.quisslyAttached ) { return; }
		inputEl.dataset.quisslyAttached = '1';
		clearPlaceholderTerm( inputEl );
		inputEl.setAttribute( 'autocomplete', 'off' );
		inputEl.addEventListener( 'input', onInput );
		inputEl.addEventListener( 'keydown', onKeydown );
		inputEl.addEventListener( 'focus', function ( e ) { currentInput = e.target; } );
		addControls( inputEl );
	}

	/**
	 * Mount the voice/image buttons into the search overlay's actions slot
	 * (assets/js/overlay.js's `.quissly-overlay__actions`, present only when the overlay
	 * built itself - i.e. whenever the overlay is the search surface: it took over the
	 * theme's box, or the theme has none). They are styled for that bar there. Mutually
	 * exclusive with addControls(), which stands down whenever this slot exists.
	 * startVoice()/startImage() do not depend on a dropdown or currentInput, so mounting
	 * them here needs no other wiring.
	 */
	function attachOverlayControls() {
		var actions = document.querySelector( '[data-quissly-overlay-actions]' );
		if ( ! actions ) { return; }
		// The scripts load in either order: header-style controls added before the overlay
		// built itself would duplicate these beside a box that now just opens the overlay.
		document.querySelectorAll( '.quissly-controls' ).forEach( function ( holder ) { holder.remove(); } );
		if ( actions.dataset.quisslyControls ) { return; }
		actions.dataset.quisslyControls = '1';
		if ( CFG.enableVoice ) {
			var mic = el( 'button', 'quissly-voice-button' );
			mic.type = 'button';
			mic.setAttribute( 'aria-label', ( CFG.i18n && CFG.i18n.placeholderVoice ) || 'Voice search' );
			mic.appendChild( micIcon() );
			var voiceNotice = el( 'span', 'quissly-voice-notice' );
			voiceNotice.setAttribute( 'role', 'status' );
			mic.addEventListener( 'click', function () {
				voiceNotice.textContent = '';
				startVoice( function ( text ) { voiceNotice.textContent = text; }, mic );
			} );
			actions.appendChild( mic );
			actions.appendChild( voiceNotice );
		}
		if ( CFG.enableImage ) {
			var cam = el( 'button', 'quissly-image-button' );
			cam.type = 'button';
			cam.setAttribute( 'aria-label', ( CFG.i18n && CFG.i18n.placeholderImage ) || 'Search by image' );
			cam.appendChild( cameraIcon() );
			var imageNotice = el( 'span', 'quissly-image-notice' );
			imageNotice.setAttribute( 'role', 'status' );
			cam.addEventListener( 'click', function () {
				imageNotice.textContent = '';
				startImage( function ( text ) { imageNotice.textContent = text; } );
			} );
			actions.appendChild( cam );
			actions.appendChild( imageNotice );
		}
	}

	function attachAll() {
		findInputs().forEach( attach );
		// The overlay may not have built its actions slot yet on the first pass (script
		// load order isn't guaranteed either way) - the existing MutationObserver below
		// already re-runs attachAll() on every DOM change, including the overlay
		// appending itself, so this naturally picks it up whenever it appears.
		attachOverlayControls();
	}

	function init() {
		if ( ! CFG.restUrl ) { return; }
		attachAll();
		maybeDetect();
		initPaste();

		// NARROW observer: re-scan for late-appearing inputs (drawer/popup search), but
		// only for our known selectors — not a broad body-wide watcher.
		if ( window.MutationObserver ) {
			var obs = new MutationObserver( function () { attachAll(); } );
			obs.observe( document.body, { childList: true, subtree: true } );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( dropdown && ! dropdown.contains( e.target ) && e.target !== currentInput ) { hide(); }
		} );
		window.addEventListener( 'scroll', position, true );
		window.addEventListener( 'resize', position );

		// Expose a tiny runtime hook for tests.
		window.quisslyTest.findInputs = findInputs;
		window.quisslyTest.attachedCount = function () { return findInputs().filter( function ( n ) { return n.dataset.quisslyAttached; } ).length; };
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
