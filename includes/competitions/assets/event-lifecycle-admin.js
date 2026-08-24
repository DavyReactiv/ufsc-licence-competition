(function () {
	'use strict';

	function config() {
		return window.ufscCompetitionLifecycle || {};
	}

	function currentParams() {
		return new URLSearchParams(window.location.search || '');
	}

	function buildSelect(name, values, emptyLabel, selected) {
		var select = document.createElement('select');
		select.name = name;
		select.className = 'ufsc-premium-select';

		var empty = document.createElement('option');
		empty.value = '';
		empty.textContent = emptyLabel || '';
		select.appendChild(empty);

		if (Array.isArray(values)) {
			values.forEach(function (value) {
				var option = document.createElement('option');
				option.value = String(value);
				option.textContent = String(value).replace('-', '/');
				option.selected = String(value) === String(selected || '');
				select.appendChild(option);
			});
		} else if (values && typeof values === 'object') {
			Object.keys(values).forEach(function (key) {
				var option = document.createElement('option');
				option.value = key;
				option.textContent = String(values[key]);
				option.selected = key === String(selected || '');
				select.appendChild(option);
			});
		}

		return select;
	}

	function addFilters() {
		var form = document.querySelector('.ufsc-admin-toolbar');
		if (!form || form.querySelector('[data-ufsc-lifecycle-filters]')) {
			return;
		}

		var cfg = config();
		var labels = cfg.labels || {};
		var params = currentParams();
		var wrap = document.createElement('div');
		wrap.className = 'ufsc-lifecycle-filters';
		wrap.setAttribute('data-ufsc-lifecycle-filters', '1');

		wrap.appendChild(buildSelect('ufsc_season', cfg.seasons || [], labels.allSeasons, params.get('ufsc_season') || ''));
		wrap.appendChild(buildSelect('ufsc_status', cfg.statuses || {}, labels.allStatuses, params.get('ufsc_status') || ''));
		wrap.appendChild(buildSelect('ufsc_discipline', cfg.disciplines || {}, labels.allDisciplines, params.get('ufsc_discipline') || ''));

		var submit = document.createElement('button');
		submit.type = 'submit';
		submit.className = 'button button-secondary';
		submit.textContent = labels.filter || 'Filtrer';
		wrap.appendChild(submit);

		var reset = document.createElement('a');
		reset.className = 'button-link ufsc-lifecycle-reset';
		reset.textContent = labels.reset || 'Réinitialiser';
		reset.href = buildAdminUrl('ufsc-competitions', {});
		wrap.appendChild(reset);

		form.insertBefore(wrap, form.firstChild);
		preserveFiltersInBulkForm(params);
	}

	function preserveFiltersInBulkForm(params) {
		var bulkForm = document.querySelector('form[action*="ufsc_competitions_bulk"]');
		if (!bulkForm) {
			return;
		}

		['ufsc_season', 'ufsc_status', 'ufsc_discipline'].forEach(function (name) {
			var value = params.get(name) || '';
			if (!value || bulkForm.querySelector('input[name="' + name + '"]')) {
				return;
			}
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			bulkForm.appendChild(input);
		});
	}

	function buildAdminUrl(page, extra) {
		var cfg = config();
		var url = new URL(cfg.adminUrl || window.location.href, window.location.origin);
		url.search = '';
		url.searchParams.set('page', page);
		Object.keys(extra || {}).forEach(function (key) {
			if (extra[key] !== '' && extra[key] !== null && typeof extra[key] !== 'undefined') {
				url.searchParams.set(key, String(extra[key]));
			}
		});
		return url.toString();
	}

	function buildDuplicateUrl(id) {
		var cfg = config();
		var url = new URL(cfg.adminPostUrl || window.location.href, window.location.origin);
		url.search = '';
		url.searchParams.set('action', 'ufsc_competitions_duplicate_competition');
		url.searchParams.set('id', String(id));
		url.searchParams.set('_wpnonce', String(cfg.nonce || ''));
		return url.toString();
	}

	function appendRowAction(rowActions, key, label, href, onClick) {
		if (!rowActions || rowActions.querySelector('.' + key)) {
			return;
		}

		var span = document.createElement('span');
		span.className = key;
		var link = document.createElement('a');
		link.href = href;
		link.textContent = label;
		if (typeof onClick === 'function') {
			link.addEventListener('click', onClick);
		}
		span.appendChild(link);

		if (rowActions.children.length) {
			rowActions.appendChild(document.createTextNode(' | '));
		}
		rowActions.appendChild(span);
	}

	function addLifecycleActions() {
		var cfg = config();
		var labels = cfg.labels || {};
		var params = currentParams();
		var archived = params.get('ufsc_view') === 'archived';
		var checkboxes = document.querySelectorAll('input[name="ids[]"]');

		checkboxes.forEach(function (checkbox) {
			var id = parseInt(checkbox.value, 10) || 0;
			var row = checkbox.closest('tr');
			var rowActions = row ? row.querySelector('.row-actions') : null;
			if (!id || !rowActions) {
				return;
			}

			appendRowAction(
				rowActions,
				'ufsc-print-shortcut',
				labels.print || 'Afficher / imprimer',
				buildAdminUrl('ufsc-competitions-print', { competition_id: id, print_type: 'entries' })
			);
			appendRowAction(
				rowActions,
				'ufsc-results-shortcut',
				labels.results || 'Résultats',
				buildAdminUrl('ufsc-competitions-results', { competition_id: id })
			);
			appendRowAction(
				rowActions,
				'ufsc-officials-shortcut',
				labels.officials || 'Officiels',
				buildAdminUrl('ufsc-competitions-officials', { competition_id: id })
			);

			if (archived && cfg.canDuplicate) {
				appendRowAction(
					rowActions,
					'ufsc-duplicate-shortcut',
					labels.duplicate || 'Dupliquer en nouvelle édition',
					buildDuplicateUrl(id),
					function (event) {
						if (!window.confirm(labels.duplicateConfirm || 'Créer une nouvelle édition ?')) {
							event.preventDefault();
						}
					}
				);
			}
		});
	}

	function init() {
		if (!document.querySelector('.ufsc-competitions-admin')) {
			return;
		}
		addFilters();
		addLifecycleActions();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
