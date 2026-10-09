/**
 * Shopping activity for Quissly's analytics: sends this page's one event (a product page view
 * or a search, from Quissly_Events::page_event()) to the store's own ?wc-ajax=quissly_event.
 * Who the shopper is, and whether they consented, is the store's to decide there - this only
 * says what was viewed or searched.
 *
 * A search is sent once per tab: reloading the results page, or coming back to it, is not
 * another search. (The same script as quissly-for-magento's quissly-events.js.)
 */
(function () {
	'use strict';

	var config = window.quisslyEvent;
	var event = config && config.event;
	if (!config || !config.endpoint || !event || !event.type) {
		return;
	}

	if (event.type === 'search') {
		var key = 'quissly_search_sent';
		var id = event.modality + ':' + (event.modality === 'text' ? event.query : location.search);
		try {
			if (sessionStorage.getItem(key) === id) {
				return;
			}
			sessionStorage.setItem(key, id);
		} catch (e) {
			// Storage blocked: send it anyway.
		}
	}

	var body = JSON.stringify(event);
	try {
		if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, new Blob([body], {type: 'application/json'}))) {
			return;
		}
	} catch (e) {
		// Fall through to fetch.
	}
	try {
		fetch(config.endpoint, {
			method: 'POST',
			body: body,
			headers: {'Content-Type': 'application/json'},
			credentials: 'same-origin',
			keepalive: true
		});
	} catch (e) {
		// Analytics never gets in the shopper's way.
	}
}());
