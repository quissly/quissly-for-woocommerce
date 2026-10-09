/**
 * Configuration's suggestion lists as removable pills, the Magento plugin's "Search bar
 * suggestions" editor (the Shopify app's Settings): the list, "Add a suggestion", "N of max" and,
 * when Quissly generated one, "Reset to generated".
 *
 * Each list is a <textarea data-q-pills> holding one suggestion per line - the value the
 * settings form saves, as before. This script hides it and draws the pills; every change is
 * written back to it. Without the script the textarea stays. Its data-config (JSON):
 * {max, maxLength, generated: [..], text: {...}}. Vanilla, no framework.
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

	function init(area) {
		var config;
		try {
			config = JSON.parse(area.getAttribute('data-config') || '{}');
		} catch (e) {
			return;
		}
		var text = config.text || {};
		var generated = config.generated || [];
		var list = area.value.split(/\r\n|\r|\n/).map(function (q) {
			return q.trim().replace(/\s+/g, ' ');
		}).filter(function (q) {
			return q !== '';
		});

		var root = el('div', 'q-suggestions');
		var pills = el('div', 'q-pills');
		var addRow = el('div', 'q-suggestions__add');
		var input = el('input');
		input.type = 'text';
		input.className = 'regular-text';
		input.placeholder = text.placeholder || '';
		input.setAttribute('aria-label', text.placeholder || '');
		var add = el('button', 'button', text.add || 'Add');
		add.type = 'button';
		addRow.appendChild(input);
		addRow.appendChild(add);
		var error = el('p', 'q-suggestions__error');
		error.hidden = true;
		var count = el('p', 'description q-suggestions__count');
		var reset = el('button', 'button q-suggestions__reset', text.reset || 'Reset to generated');
		reset.type = 'button';
		var help = el('p', 'description', text.help || '');

		root.appendChild(pills);
		root.appendChild(addRow);
		root.appendChild(error);
		root.appendChild(count);
		if (generated.length) {
			root.appendChild(reset);
		}
		root.appendChild(help);
		area.hidden = true;
		area.parentNode.insertBefore(root, area);

		function render() {
			pills.textContent = '';
			list.forEach(function (query, index) {
				var pill = el('span', 'q-pill');
				pill.appendChild(el('span', 'q-pill__text', query));
				var remove = el('button', 'q-pill__remove', '×');
				remove.type = 'button';
				remove.setAttribute('aria-label', (text.remove || 'Remove') + ': ' + query);
				remove.addEventListener('click', function () {
					list.splice(index, 1);
					render();
					input.focus();
				});
				pill.appendChild(remove);
				pills.appendChild(pill);
			});
			if (!list.length) {
				pills.appendChild(el('span', 'q-pills__empty', text.empty || ''));
			}
			count.textContent = (text.count || '%1 of %2').split('%1').join(list.length).split('%2').join(config.max);
			count.classList.toggle('is-over', list.length > config.max);
			add.disabled = list.length >= config.max;
			reset.hidden = !generated.length || JSON.stringify(generated) === JSON.stringify(list);
			error.hidden = true;
			area.value = list.join('\n');
		}

		function addQuery() {
			var query = input.value.trim().replace(/\s+/g, ' ');
			if (query === '' || list.length >= config.max) {
				return;
			}
			if (query.length > config.maxLength) {
				error.textContent = (text.tooLong || '').split('%1').join(config.maxLength);
				error.hidden = false;
				return;
			}
			if (list.some(function (q) { return key(q) === key(query); })) {
				error.textContent = text.duplicate || '';
				error.hidden = false;
				return;
			}
			list.push(query);
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

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('textarea[data-q-pills]'), init);
		Array.prototype.forEach.call(document.querySelectorAll('[data-q-languages]'), languages);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
