/* global UFSCFFSTClubSearch */
(function () {
    'use strict';

    if (!document.querySelectorAll || typeof UFSCFFSTClubSearch === 'undefined') {
        return;
    }

    function option(value, label, selected) {
        var element = document.createElement('option');
        element.value = String(value);
        element.textContent = String(label);
        if (selected) {
            element.selected = true;
        }
        return element;
    }

    function init(wrapper) {
        var input = wrapper.querySelector('.ufsc-ffst-remote-club-search');
        var select = wrapper.querySelector('select');
        var feedback = wrapper.querySelector('.ufsc-ffst-club-feedback');
        if (!input || !select || !feedback) {
            return;
        }

        // The first 500 results are retained as a no-JS fallback.
        var original = Array.prototype.map.call(select.options, function (item) {
            return { value: item.value, name: item.textContent };
        });
        var requestNumber = 0;
        var timeoutId = null;
        var controller = null;

        function notify(message) {
            feedback.textContent = message;
        }

        function display(clubs) {
            var selectedId = select.value;
            var current = select.options[select.selectedIndex];
            var selectedName = current ? current.textContent : '';
            var listed = {};
            var fragment = document.createDocumentFragment();
            fragment.appendChild(option('', 'Sélectionner le club UFSC…', !selectedId));

            // Preserve a user-selected club even if a new search omits it.
            if (selectedId && !clubs.some(function (club) {
                return String(club.id) === selectedId || String(club.value) === selectedId;
            })) {
                fragment.appendChild(option(selectedId, selectedName, true));
                listed[selectedId] = true;
            }
            clubs.forEach(function (club) {
                var id = String(club.id !== undefined ? club.id : club.value);
                if (!id || listed[id]) {
                    return;
                }
                listed[id] = true;
                fragment.appendChild(option(id, club.name, id === selectedId));
            });

            while (select.firstChild) {
                select.removeChild(select.firstChild);
            }
            select.appendChild(fragment);
            select.value = selectedId || '';
        }

        function restore() {
            display(original.map(function (club) {
                return { id: club.value, name: club.name };
            }).filter(function (club) {
                return club.id !== '';
            }));
            notify('');
        }

        input.addEventListener('input', function () {
            window.clearTimeout(timeoutId);
            requestNumber += 1;
            var requestId = requestNumber;
            if (controller && controller.abort) {
                controller.abort();
            }
            var query = (input.value || '').trim();
            if (!query) {
                restore();
                return;
            }
            if (query.length < 2) {
                notify('Saisissez au moins 2 caractères.');
                return;
            }
            notify('Recherche des clubs UFSC…');
            timeoutId = window.setTimeout(function () {
                controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                var payload = new URLSearchParams();
                payload.append('action', 'ufsc_lc_ffst_club_search');
                payload.append('nonce', UFSCFFSTClubSearch.nonce);
                payload.append('term', query);
                var settings = {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: payload.toString()
                };
                if (controller) {
                    settings.signal = controller.signal;
                }
                fetch(UFSCFFSTClubSearch.ajaxurl, settings)
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('La recherche de clubs est indisponible.');
                        }
                        return response.json();
                    })
                    .then(function (result) {
                        if (requestId !== requestNumber) {
                            return;
                        }
                        if (!result.success || !result.data || !Array.isArray(result.data.clubs)) {
                            notify('Recherche indisponible : utilisez la liste existante.');
                            return;
                        }
                        var clubs = result.data.clubs;
                        display(clubs);
                        notify(clubs.length ? clubs.length + ' club(s) trouvé(s). Choisissez le bon club dans la liste.' :
                            (result.data.message || 'Aucun club trouvé.'));
                    })
                    .catch(function (error) {
                        if (requestId !== requestNumber || error.name === 'AbortError') {
                            return;
                        }
                        notify('Recherche indisponible : utilisez la liste existante.');
                    });
            }, 260);
        });
    }

    function start() {
        Array.prototype.forEach.call(document.querySelectorAll('.ufsc-ffst-club-search'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
