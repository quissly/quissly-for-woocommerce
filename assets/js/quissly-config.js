/**
 * Configuration's suggestion lists, drawn as the Quissly Shopify app's Settings draws them:
 *
 *  - data-q-list="rows" (Search Examples): one row per entry with a Remove button, an "Add a
 *    suggestion" field with an Add button, "N of max" and, when Quissly generated a list,
 *    "Reset to generated";
 *  - data-q-list="fields" (Search Suggestions, Manual): one field per entry and one empty
 *    field after them - "fill one and the next field appears", up to max.
 *
 * Each list is a <textarea data-q-list> holding one entry per line - the value the settings
 * form saves. This script hides it and draws the list; every change is written back to it.
 * Without the script the textarea stays. Its data-config (JSON): {max, maxLength, generated,
 * text: {...}}. Vanilla, no framework.
 */
(function () {
	'use strict';

	function key(query) {
		return query.trim().replace(/\s+/g, ' ').toLowerCase();
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	}

	function fill(template, n, max) {
		return (template || '').split('%1').join(n).split('%2').join(max);
	}

	function readList(area) {
		return area.value.split(/\r\n|\r|\n/).map(function (q) {
			return q.trim().replace(/\s+/g, ' ');
		}).filter(function (q) {
			return q !== '';
		});
	}

	/** Search Examples: rows with Remove, an add field, the count, Reset to generated. */
	function rows(area, config) {
		var text = config.text || {};
		var generated = config.generated || [];
		var list = readList(area);

		var root = el('div', 'q-list');
		var items = el('div', 'q-list__rows');
		var addLabel = el('label', 'q-list__add-label', text.addLabel || '');
		var addRow = el('div', 'q-list__add');
		var input = el('input');
		input.type = 'text';
		input.className = 'regular-text';
		input.placeholder = text.placeholder || '';
		input.maxLength = config.maxLength;
		input.id = area.id + '_new';
		addLabel.htmlFor = input.id;
		var add = el('button', 'button', text.add || 'Add');
		add.type = 'button';
		addRow.appendChild(input);
		addRow.appendChild(add);
		var count = el('p', 'description q-list__count');
		var reset = el('button', 'button-link q-list__reset', text.reset || 'Reset to generated');
		reset.type = 'button';
		var help = el('p', 'description', text.help || '');

		root.appendChild(items);
		root.appendChild(addLabel);
		root.appendChild(addRow);
		root.appendChild(count);
		if (generated.length) {
			root.appendChild(reset);
		}
		if (text.help) {
			root.appendChild(help);
		}
		area.hidden = true;
		area.parentNode.insertBefore(root, area);

		function render() {
			items.textContent = '';
			list.forEach(function (query, index) {
				var row = el('div', 'q-list__row');
				row.appendChild(el('span', 'q-list__text', query));
				var remove = el('button', 'button-link q-list__remove', text.remove || 'Remove');
				remove.type = 'button';
				remove.setAttribute('aria-label', (text.remove || 'Remove') + ': ' + query);
				remove.addEventListener('click', function () {
					list.splice(index, 1);
					render();
					input.focus();
				});
				row.appendChild(remove);
				items.appendChild(row);
			});
			if (!list.length) {
				items.appendChild(el('p', 'q-list__empty', text.empty || ''));
			}
			count.textContent = fill(text.count || '%1 of %2', list.length, config.max);
			count.classList.toggle('is-over', list.length > config.max);
			add.disabled = list.length >= config.max;
			reset.hidden = !generated.length || JSON.stringify(generated) === JSON.stringify(list);
			area.value = list.join('\n');
		}

		function addQuery() {
			var query = input.value.trim().replace(/\s+/g, ' ');
			if (query === '' || list.length >= config.max) {
				return;
			}
			// A repeat is dropped, as the Shopify app does.
			if (!list.some(function (q) { return key(q) === key(query); })) {
				list.push(query);
			}
			input.value = '';
			render();
			input.focus();
		}

		add.addEventListener('click', addQuery);
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				// Enter adds the suggestion; it must not submit the whole Configuration form.
				event.preventDefault();
				addQuery();
			}
		});
		reset.addEventListener('click', function () {
			list = generated.slice();
			render();
		});
		render();
	}

	/** Search Suggestions (Manual): a field per entry and one empty one after them. */
	function fields(area, config) {
		var text = config.text || {};
		var list = readList(area).slice(0, config.max);

		var root = el('div', 'q-list q-list--fields');
		var box = el('div', 'q-list__fields');
		var help = el('p', 'description', fill(text.fieldsHelp || '', '', config.max));
		root.appendChild(box);
		root.appendChild(help);
		area.hidden = true;
		area.parentNode.insertBefore(root, area);

		function store() {
			area.value = list.filter(function (q) { return q.trim() !== ''; }).map(function (q) {
				return q.trim().replace(/\s+/g, ' ');
			}).join('\n');
		}

		function field(index) {
			var wrap = el('div', 'q-list__field');
			var input = el('input');
			input.type = 'text';
			input.className = 'regular-text';
			input.maxLength = config.maxLength;
			input.id = area.id + '_' + index;
			input.value = list[index] || '';
			if (index === 0) {
				input.placeholder = text.placeholder || '';
			}
			input.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
				}
			});
			input.addEventListener('input', function () {
				var position = Array.prototype.indexOf.call(box.children, wrap);
				list[position] = input.value;
				// The trailing empty ones go, as the Shopify app's fields do.
				while (list.length && !list[list.length - 1].trim()) {
					list.pop();
				}
				sync();
				store();
			});
			var label = el('label', 'q-list__field-label', fill(text.fieldLabel || '%1', index + 1, config.max));
			label.htmlFor = input.id;
			wrap.appendChild(label);
			wrap.appendChild(input);
			return wrap;
		}

		// Every filled field plus one empty one, up to max.
		function sync() {
			var wanted = Math.min(list.length + 1, config.max);
			while (box.children.length < wanted) {
				box.appendChild(field(box.children.length));
			}
			while (box.children.length > wanted) {
				box.removeChild(box.lastChild);
			}
		}

		sync();
		store();
	}

	function init(area) {
		var config;
		try {
			config = JSON.parse(area.getAttribute('data-config') || '{}');
		} catch (e) {
			return;
		}
		if (area.getAttribute('data-q-list') === 'fields') {
			fields(area, config);
		} else {
			rows(area, config);
		}
	}

	/**
	 * A multilingual store's lists ([data-q-languages]): the Language select shows its
	 * language's list ([data-q-language]) and hides the others - all of them are saved.
	 */
	function languages(root) {
		var select = root.querySelector('[data-q-language-select]');
		if (!select) {
			return;
		}
		select.addEventListener('change', function () {
			Array.prototype.forEach.call(root.querySelectorAll('[data-q-language]'), function (list) {
				list.hidden = list.getAttribute('data-q-language') !== select.value;
			});
		});
	}

	/**
	 * Search Suggestions' "Suggestions" select (Automatic / Manual): Manual's lists show only
	 * for Manual, and the note under the select says what the choice means.
	 */
	function mode(select) {
		var manual = document.getElementById('quissly_overlay_suggestions_manual_row');
		var automatic = document.getElementById('quissly_overlay_suggestions_auto');
		var note = document.getElementById('quissly_overlay_suggestions_mode_note');
		function sync() {
			if (manual) {
				manual.hidden = select.value !== 'manual';
			}
			if (automatic) {
				automatic.hidden = select.value === 'manual';
			}
			if (note) {
				note.textContent = note.getAttribute('data-' + select.value) || '';
			}
		}
		select.addEventListener('change', sync);
		sync();
	}

	/**
	 * The page's dropdowns drawn as WooCommerce draws its own settings' (selectWoo), instead of
	 * the operating system's menu - when WooCommerce provides it; else they stay native. Each is
	 * as wide as its longest option. selectWoo reports a choice as a jQuery event, which the
	 * native listeners above never see, so it is passed on as a native one.
	 */
	function wooSelects() {
		var $ = window.jQuery;
		if (!$ || !$.fn || !$.fn.selectWoo) {
			return;
		}
		Array.prototype.forEach.call(document.querySelectorAll('.quissly-config select'), function (select) {
			var longest = 0;
			Array.prototype.forEach.call(select.options, function (option) {
				longest = Math.max(longest, option.textContent.length);
			});
			$(select).selectWoo({
				minimumResultsForSearch: Infinity,
				width: Math.max(240, Math.min(520, longest * 8 + 60)) + 'px'
			}).on('change', function (event) {
				if (!event.originalEvent) {
					select.dispatchEvent(new Event('change'));
				}
			});
		});
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('textarea[data-q-list]'), init);
		Array.prototype.forEach.call(document.querySelectorAll('[data-q-languages]'), languages);
		var select = document.getElementById('quissly_overlay_suggestions_mode');
		if (select) {
			mode(select);
		}
		wooSelects();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
