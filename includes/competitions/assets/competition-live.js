(function () {
	'use strict';

	var roots = document.querySelectorAll('[data-ufsc-live]');
	if (!roots.length || typeof window.fetch !== 'function') {
		return;
	}

	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function corner(participant) {
		participant = participant || {};
		return '<div class="ufsc-live__corner">' +
			'<span class="ufsc-live__fighter"><span class="ufsc-live__fighter-name">' + esc(participant.name || 'À déterminer') + '</span>' +
			(participant.club ? '<span class="ufsc-live__fighter-club">' + esc(participant.club) + '</span>' : '') + '</span>' +
			(participant.winner ? '<span class="ufsc-live__winner">✓ Vainqueur</span>' : '') +
			'</div>';
	}

	function fight(item, current) {
		item = item || {};
		return '<article class="ufsc-live__fight' + (current ? ' ufsc-live__fight--current' : '') + '">' +
			'<div class="ufsc-live__fight-top"><span>Combat ' + esc(item.number || item.id || '') + ' · ' + esc(item.label || '') + '</span><span class="ufsc-live__fight-category">' + esc(item.category || '') + '</span></div>' +
			corner(item.red) + corner(item.blue) +
			(item.result ? '<div class="ufsc-live__result">Résultat : ' + esc(item.result) + '</div>' : '') +
			'</article>';
	}

	function list(items, emptyText) {
		if (!items || !items.length) {
			return '<p class="ufsc-live__empty">' + esc(emptyText) + '</p>';
		}
		return items.map(function (item) { return fight(item, false); }).join('');
	}

	function render(root, data) {
		var competition = data.competition || {};
		var surfaces = data.surfaces || [];
		var meta = [competition.discipline, competition.type, competition.location].filter(Boolean).join(' · ');
		var generated = data.generatedAt ? new Date(data.generatedAt) : null;
		var synced = generated && !isNaN(generated.getTime()) ? generated.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '';

		var html = '<header class="ufsc-live__hero"><div><p class="ufsc-live__eyebrow">UFSC LIVE</p><h1 class="ufsc-live__title">' + esc(competition.name || 'Compétition') + '</h1>' +
			(meta ? '<p class="ufsc-live__meta">' + esc(meta) + '</p>' : '') + '</div><div class="ufsc-live__sync">Mise à jour automatique' + (synced ? ' · ' + esc(synced) : '') + '</div></header>';

		if (!surfaces.length) {
			html += '<div class="ufsc-live__loading">Aucun combat public à afficher pour le moment.</div>';
			root.innerHTML = html;
			return;
		}

		html += '<div class="ufsc-live__grid">';
		surfaces.forEach(function (surface) {
			var hasCurrent = !!surface.current;
			html += '<section class="ufsc-live__surface"><header class="ufsc-live__surface-head"><h2 class="ufsc-live__surface-title">' + esc(surface.name) + '</h2>' +
				'<span class="ufsc-live__pill' + (hasCurrent ? ' ufsc-live__pill--running' : '') + '">' + (hasCurrent ? 'En direct' : 'Programme') + '</span></header>' +
				'<div class="ufsc-live__section"><h3 class="ufsc-live__section-title">Combat en cours</h3>' + (hasCurrent ? fight(surface.current, true) : '<p class="ufsc-live__empty">Aucun combat en cours.</p>') + '</div>' +
				'<div class="ufsc-live__section"><h3 class="ufsc-live__section-title">À suivre</h3>' + list(surface.upcoming, 'Aucun combat annoncé.') + '</div>' +
				'<div class="ufsc-live__section"><h3 class="ufsc-live__section-title">Derniers résultats</h3>' + list(surface.recent, 'Aucun résultat publié.') + '</div></section>';
		});
		html += '</div>';
		root.innerHTML = html;
	}

	function boot(root) {
		var endpoint = root.getAttribute('data-endpoint');
		if (!endpoint) {
			return;
		}

		var controller = null;
		var timer = null;
		var refreshSeconds = 10;

		function schedule() {
			window.clearTimeout(timer);
			timer = window.setTimeout(load, document.hidden ? Math.max(30, refreshSeconds) * 1000 : refreshSeconds * 1000);
		}

		function load() {
			if (controller && typeof controller.abort === 'function') {
				controller.abort();
			}
			controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
			var options = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
			if (controller) {
				options.signal = controller.signal;
			}

			window.fetch(endpoint, options)
				.then(function (response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					return response.json();
				})
				.then(function (data) {
					refreshSeconds = Math.max(10, parseInt(data.refresh, 10) || 10);
					render(root, data);
				})
				.catch(function (error) {
					if (error && error.name === 'AbortError') {
						return;
					}
					if (!root.querySelector('.ufsc-live__hero')) {
						root.innerHTML = '<div class="ufsc-live-error">Le direct est momentanément indisponible. Nouvelle tentative automatique.</div>';
					}
				})
				.then(schedule);
		}

		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				window.clearTimeout(timer);
				load();
			}
		});
		load();
	}

	roots.forEach(boot);
}());
