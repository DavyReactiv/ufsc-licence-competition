(function() {
	'use strict';

	function applyPreviewFilters() {
		var search = document.getElementById('ufsc-asptt-search');
		var errorsOnly = document.getElementById('ufsc-asptt-errors-only');
		var query = search ? search.value.trim().toLowerCase() : '';
		var onlyErrors = errorsOnly ? errorsOnly.checked : false;

		document.querySelectorAll('.ufsc-lc-preview-table tbody tr').forEach(function(row) {
			var haystack = (row.getAttribute('data-search') || '').toLowerCase();
			var hasError = row.getAttribute('data-has-error') === '1';
			var matchesSearch = !query || haystack.indexOf(query) !== -1;
			var matchesErrors = !onlyErrors || hasError;
			row.style.display = (matchesSearch && matchesErrors) ? '' : 'none';
		});
	}

	function buildClubOptions(items) {
		var selectLabel = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings && UFSC_LC_Admin.strings.selectClub)
			? UFSC_LC_Admin.strings.selectClub
			: '';
		var options = '<option value="">' + selectLabel + '</option>';
		items.forEach(function(item) {
			options += '<option value="' + item.id + '">' + item.text + '</option>';
		});
		return options;
	}

	function fetchClubs(term, select) {
		if (!select) {
			return;
		}
		if (!term) {
			select.innerHTML = buildClubOptions([]);
			return;
		}

		var url = (window.UFSC_LC_Admin && (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl))
			? (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl)
			: window.ajaxurl;
		var nonce = (window.UFSC_LC_Admin && UFSC_LC_Admin.nonces) ? UFSC_LC_Admin.nonces.clubSearch : '';
		fetch(url + '?action=ufsc_lc_club_search&term=' + encodeURIComponent(term) + '&_ajax_nonce=' + encodeURIComponent(nonce))
			.then(function(response) { return response.json(); })
			.then(function(response) {
				if (!response.success) {
					return;
				}
				select.innerHTML = buildClubOptions(response.data || []);
			});
	}

	function sendAlias(rowIndex, clubId) {
		var feedback = document.querySelector('.ufsc-alias-feedback[data-row-index="' + rowIndex + '"]');
		var savingLabel = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings) ? UFSC_LC_Admin.strings.saving : '';
		var errorLabel = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings) ? UFSC_LC_Admin.strings.errorDefault : '';
		if (feedback) {
			feedback.textContent = savingLabel;
		}

		var data = new window.FormData();
		data.append('action', 'ufsc_lc_asptt_save_alias');
		data.append('row_index', rowIndex);
		data.append('club_id', clubId);
		if (window.UFSC_LC_Admin && UFSC_LC_Admin.nonces && UFSC_LC_Admin.nonces.saveAlias) {
			data.append('_ajax_nonce', UFSC_LC_Admin.nonces.saveAlias);
		}

		var url = (window.UFSC_LC_Admin && (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl))
			? (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl)
			: window.ajaxurl;
		fetch(url, { method: 'POST', body: data })
			.then(function(response) { return response.json(); })
			.then(function(response) {
				if (response.success) {
					if (feedback) {
						feedback.textContent = response.data.message;
					}
					window.location.reload();
				} else if (feedback) {
					feedback.textContent = (response.data && response.data.message) ? response.data.message : errorLabel;
				}
			});
	}

	function bindPreviewActions() {
		var searchInput = document.getElementById('ufsc-asptt-search');
		if (searchInput) {
			searchInput.addEventListener('input', applyPreviewFilters);
		}
		var errorsOnlyInput = document.getElementById('ufsc-asptt-errors-only');
		if (errorsOnlyInput) {
			errorsOnlyInput.addEventListener('change', applyPreviewFilters);
		}

		document.querySelectorAll('.ufsc-save-alias').forEach(function(button) {
			var rowIndex = button.getAttribute('data-row-index');
			var select = document.querySelector('.ufsc-club-select[data-row-index="' + rowIndex + '"]');

			function toggleButton() {
				var clubId = select ? select.value : '';
				button.disabled = !clubId;
			}

			if (select) {
				select.addEventListener('change', toggleButton);
				toggleButton();
			}

			button.addEventListener('click', function() {
				var clubId = select ? select.value : '';
				var selectFirstLabel = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings)
					? UFSC_LC_Admin.strings.selectFirst
					: '';
				if (!clubId) {
					window.alert(selectFirstLabel);
					return;
				}
				sendAlias(rowIndex, clubId);
			});
		});

		document.querySelectorAll('.ufsc-club-search').forEach(function(input) {
			var rowIndex = input.getAttribute('data-row-index');
			var select = document.querySelector('.ufsc-club-select[data-row-index="' + rowIndex + '"]');
			input.addEventListener('input', function() {
				fetchClubs(input.value, select);
			});
		});

		document.querySelectorAll('.ufsc-confirm-trash').forEach(function(link) {
			link.addEventListener('click', function(event) {
				var message = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings && UFSC_LC_Admin.strings.confirmTrash)
					? UFSC_LC_Admin.strings.confirmTrash
					: '';
				if (!window.confirm(message)) {
					event.preventDefault();
				}
			});
		});

		document.querySelectorAll('.ufsc-confirm-delete').forEach(function(link) {
			link.addEventListener('click', function(event) {
				var message = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings && UFSC_LC_Admin.strings.confirmDelete)
					? UFSC_LC_Admin.strings.confirmDelete
					: '';
				if (!window.confirm(message)) {
					event.preventDefault();
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindPreviewActions);
	} else {
		bindPreviewActions();
	}
})();
