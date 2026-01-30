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

	function buildClubOptions(items, emptyLabel) {
		var selectLabel = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings && UFSC_LC_Admin.strings.selectClub)
			? UFSC_LC_Admin.strings.selectClub
			: '';
		var options = '<option value="">' + selectLabel + '</option>';
		if (!items.length && emptyLabel) {
			options += '<option value="">' + emptyLabel + '</option>';
		}
		items.forEach(function(item) {
			options += '<option value="' + item.id + '">' + (item.label || item.text) + '</option>';
		});
		return options;
	}

	function fetchClubs(term, select, options) {
		if (!select) {
			return;
		}
		var settings = options || {};
		if (!term) {
			select.innerHTML = buildClubOptions([]);
			return;
		}
		if (term.length < 2) {
			select.innerHTML = buildClubOptions([]);
			return;
		}

		var url = (window.UFSC_LC_Admin && (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl))
			? (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl)
			: window.ajaxurl;
		var action = settings.action || 'ufsc_lc_club_search';
		var nonceKey = settings.nonceKey || 'clubSearch';
		var nonce = (window.UFSC_LC_Admin && UFSC_LC_Admin.nonces) ? (UFSC_LC_Admin.nonces[nonceKey] || '') : '';
		var queryParam = action === 'ufsc_lc_search_clubs' ? 'q' : 'term';
		var nonceParam = action === 'ufsc_lc_search_clubs' ? 'nonce' : '_ajax_nonce';
		var emptyLabel = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings) ? UFSC_LC_Admin.strings.noResults : '';
		var query = '?action=' + encodeURIComponent(action)
			+ '&' + queryParam + '=' + encodeURIComponent(term)
			+ '&' + nonceParam + '=' + encodeURIComponent(nonce);

		fetch(url + query)
			.then(function(response) { return response.json(); })
			.then(function(response) {
				if (!response || !response.success) {
					select.innerHTML = buildClubOptions([], emptyLabel);
					return;
				}
				select.innerHTML = buildClubOptions(response.data || [], emptyLabel);
			})
			.catch(function() {
				select.innerHTML = buildClubOptions([], emptyLabel);
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
			var action = input.getAttribute('data-ajax-action') || 'ufsc_lc_club_search';
			var nonceKey = input.getAttribute('data-nonce-key') || (action === 'ufsc_lc_search_clubs' ? 'admin' : 'clubSearch');
			var debounceTimer;
			input.addEventListener('input', function() {
				window.clearTimeout(debounceTimer);
				debounceTimer = window.setTimeout(function() {
					fetchClubs(input.value, select, {
						action: action,
						nonceKey: nonceKey
					});
				}, 250);
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

	function bindConfirmActions() {
		document.querySelectorAll('.ufsc-lc-confirm').forEach(function(element) {
			var message = element.getAttribute('data-confirm') || '';
			if (!message) {
				return;
			}
			var handler = function(event) {
				if (!window.confirm(message)) {
					event.preventDefault();
					event.stopPropagation();
				}
			};
			if (element.tagName === 'FORM') {
				element.addEventListener('submit', handler);
			} else {
				element.addEventListener('click', handler);
			}
		});
	}

	function bindLicenceResults() {
		var table = document.querySelector('.ufsc-lc-results-table');
		if (!table) {
			return;
		}

		function selectRow(row) {
			table.querySelectorAll('tbody tr').forEach(function(otherRow) {
				otherRow.classList.remove('is-selected');
			});
			row.classList.add('is-selected');
		}

		table.querySelectorAll('tbody tr').forEach(function(row) {
			var radio = row.querySelector('input[type="radio"][name="matched_licence_id"]');
			if (!radio) {
				return;
			}
			radio.addEventListener('change', function() {
				if (radio.checked) {
					selectRow(row);
				}
			});
			row.addEventListener('click', function(event) {
				if (event.target && event.target.tagName === 'INPUT') {
					return;
				}
				radio.checked = true;
				selectRow(row);
			});
		});
	}

	function initReviewClubSelects() {
		var selects = document.querySelectorAll('.ufsc-lc-club-select');
		if (!selects.length) {
			return;
		}

		var strings = (window.UFSC_LC_Admin && UFSC_LC_Admin.strings) ? UFSC_LC_Admin.strings : {};
		var ajaxUrl = (window.UFSC_LC_Admin && (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl))
			? (UFSC_LC_Admin.ajaxurl || UFSC_LC_Admin.ajaxUrl)
			: window.ajaxurl;
		var nonce = (window.UFSC_LC_Admin && UFSC_LC_Admin.nonces) ? (UFSC_LC_Admin.nonces.admin || UFSC_LC_Admin.nonces.searchClubs || '') : '';

		function toggleSubmit(select) {
			var form = select.closest('form');
			var button = form ? form.querySelector('button[type="submit"], input[type="submit"]') : null;
			if (!button) {
				return;
			}
			button.disabled = !select.value;
		}

		selects.forEach(function(select) {
			select.addEventListener('change', function() {
				toggleSubmit(select);
			});
			toggleSubmit(select);
		});

		if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2)) {
			return;
		}

		window.jQuery(selects).select2({
			placeholder: strings.searchPlaceholder || '',
			minimumInputLength: 2,
			allowClear: true,
			ajax: {
				url: ajaxUrl,
				dataType: 'json',
				delay: 200,
				data: function(params) {
					return {
						action: 'ufsc_lc_search_clubs',
						q: params.term || '',
						nonce: nonce
					};
				},
				processResults: function(response) {
					var results = (response && response.success) ? (response.data || []) : [];
					results = results.map(function(item) {
						return {
							id: item.id,
							text: item.text || item.label || ''
						};
					});
					return { results: results };
				}
			},
			language: {
				noResults: function() {
					return strings.noResults || '';
				}
			}
		}).on('change', function() {
			toggleSubmit(this);
		});
	}

	function updateReviewSelectionCount() {
		var status = document.querySelector('.ufsc-lc-sticky-bar--review .ufsc-lc-sticky-status');
		if (!status) {
			return;
		}
		var total = parseInt(status.getAttribute('data-total'), 10) || 0;
		var selected = document.querySelectorAll('.wp-list-table tbody input[type="checkbox"][name="document[]"]:checked').length;
		var labelLines = status.getAttribute('data-label-lines') || 'Lignes';
		var labelSelected = status.getAttribute('data-label-selected') || 'Sélectionnées';
		status.setAttribute('data-selected', String(selected));
		status.textContent = labelLines + ': ' + total + ' | ' + labelSelected + ': ' + selected;
	}

	function applyPinnedPreviewSelection() {
		var pinnedSelect = document.getElementById('ufsc-lc-pinned-club');
		var pinnedApply = document.querySelector('input[name="ufsc_asptt_pinned_apply"]');
		if (!pinnedSelect || !pinnedApply || !pinnedApply.checked || !pinnedSelect.value) {
			return;
		}

		document.querySelectorAll('.ufsc-lc-preview-table .ufsc-club-select').forEach(function(select) {
			select.value = pinnedSelect.value;
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindPreviewActions);
		document.addEventListener('DOMContentLoaded', initReviewClubSelects);
		document.addEventListener('DOMContentLoaded', updateReviewSelectionCount);
		document.addEventListener('DOMContentLoaded', bindConfirmActions);
		document.addEventListener('DOMContentLoaded', bindLicenceResults);
		document.addEventListener('DOMContentLoaded', function() {
			var pinnedSelect = document.getElementById('ufsc-lc-pinned-club');
			var pinnedApply = document.querySelector('input[name="ufsc_asptt_pinned_apply"]');
			var applyButton = document.querySelector('.ufsc-lc-apply-pinned');
			if (pinnedSelect) {
				pinnedSelect.addEventListener('change', applyPinnedPreviewSelection);
			}
			if (pinnedApply) {
				pinnedApply.addEventListener('change', applyPinnedPreviewSelection);
			}
			if (applyButton) {
				applyButton.addEventListener('click', applyPinnedPreviewSelection);
			}
		});
		document.addEventListener('change', function(event) {
			if (event.target && event.target.matches('.wp-list-table input[type="checkbox"]')) {
				updateReviewSelectionCount();
			}
		});
	} else {
		bindPreviewActions();
		initReviewClubSelects();
		updateReviewSelectionCount();
		bindConfirmActions();
		bindLicenceResults();
		(function() {
			var pinnedSelect = document.getElementById('ufsc-lc-pinned-club');
			var pinnedApply = document.querySelector('input[name="ufsc_asptt_pinned_apply"]');
			var applyButton = document.querySelector('.ufsc-lc-apply-pinned');
			if (pinnedSelect) {
				pinnedSelect.addEventListener('change', applyPinnedPreviewSelection);
			}
			if (pinnedApply) {
				pinnedApply.addEventListener('change', applyPinnedPreviewSelection);
			}
			if (applyButton) {
				applyButton.addEventListener('click', applyPinnedPreviewSelection);
			}
		})();
		document.addEventListener('change', function(event) {
			if (event.target && event.target.matches('.wp-list-table input[type="checkbox"]')) {
				updateReviewSelectionCount();
			}
		});
	}
})();
