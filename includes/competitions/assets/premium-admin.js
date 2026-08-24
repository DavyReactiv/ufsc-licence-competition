(() => {
  'use strict';

  function closestRow(element) {
    return element instanceof HTMLElement ? element.closest('tr') : null;
  }

  function initAccessModeExperience() {
    const accessMode = document.querySelector('select[name="access_mode"]');
    if (!(accessMode instanceof HTMLSelectElement)) {
      return;
    }

    const regionField = document.querySelector('select[name="allowed_regions[]"]');
    const disciplineField = document.querySelector('select[name="allowed_disciplines[]"]');
    const clubField = document.querySelector('select[name="allowed_club_ids[]"]');
    const regionRow = closestRow(regionField);
    const disciplineRow = closestRow(disciplineField);
    const clubRow = closestRow(clubField);

    [regionRow, disciplineRow, clubRow].forEach((row) => {
      if (row) {
        row.classList.add('ufsc-access-dependent-row');
      }
    });

    const summary = document.createElement('div');
    summary.className = 'ufsc-live-access-summary';
    summary.setAttribute('role', 'status');
    summary.setAttribute('aria-live', 'polite');
    const accessCell = accessMode.closest('td');
    if (accessCell) {
      accessCell.appendChild(summary);
    }

    function setRowVisible(row, visible) {
      if (!row) return;
      row.classList.toggle('is-ufsc-hidden', !visible);
      row.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function selectedTexts(select) {
      if (!(select instanceof HTMLSelectElement)) return [];
      return Array.from(select.selectedOptions)
        .map((option) => option.textContent ? option.textContent.trim() : '')
        .filter(Boolean);
    }

    function update() {
      const mode = accessMode.value || 'affiliated';
      setRowVisible(regionRow, mode === 'regions' || mode === 'region_discipline');
      setRowVisible(disciplineRow, mode === 'disciplines' || mode === 'region_discipline');
      setRowVisible(clubRow, mode === 'clubs');

      const config = window.ufscPremiumAdmin || {};
      let text = config.affiliatedModeLabel || 'Clubs affiliés.';
      if (mode === 'regions') {
        const regions = selectedTexts(regionField);
        text = config.regionModeLabel || 'Inscriptions limitées par région.';
        if (regions.length) text += ` ${regions.join(', ')}.`;
      } else if (mode === 'region_discipline') {
        const regions = selectedTexts(regionField);
        const disciplines = selectedTexts(disciplineField);
        text = config.regionAndDisciplineModeLabel || 'Région et discipline requises.';
        if (regions.length || disciplines.length) {
          text += ` Région(s) : ${regions.join(', ') || '—'} · Discipline(s) : ${disciplines.join(', ') || '—'}.`;
        }
      } else if (mode === 'clubs') {
        const clubs = selectedTexts(clubField);
        text = config.clubModeLabel || 'Clubs sélectionnés uniquement.';
        text += ` ${clubs.length} club(s) sélectionné(s).`;
      } else if (mode === 'disciplines') {
        const disciplines = selectedTexts(disciplineField);
        text = `Inscriptions par discipline${disciplines.length ? ` : ${disciplines.join(', ')}` : ''}.`;
      } else if (mode === 'all_clubs') {
        text = 'Tous les clubs peuvent accéder selon les autres conditions configurées.';
      }
      summary.textContent = text;
    }

    accessMode.addEventListener('change', update);
    [regionField, disciplineField, clubField].forEach((field) => {
      if (field) field.addEventListener('change', update);
    });
    update();
  }

  function initProgramEditor() {
    const editor = document.querySelector('[data-ufsc-program-editor]');
    if (!(editor instanceof HTMLFormElement)) {
      return;
    }

    const list = editor.querySelector('[data-ufsc-program-blocks]');
    const template = document.querySelector('#ufsc-program-block-template');
    if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function blocks() {
      return Array.from(list.querySelectorAll('[data-ufsc-program-block]'));
    }

    function replaceIndex(element, index) {
      element.querySelectorAll('[name]').forEach((field) => {
        const name = field.getAttribute('name');
        if (!name) return;
        field.setAttribute('name', name.replace(/program_blocks\[(?:__INDEX__|\d+)\]/, `program_blocks[${index}]`));
      });
    }

    function updateHeadings() {
      blocks().forEach((block, index) => {
        replaceIndex(block, index);
        const number = block.querySelector('[data-ufsc-program-number]');
        if (number) number.textContent = String(index + 1);
        const label = block.querySelector('[data-ufsc-field="label"]');
        const title = block.querySelector('[data-ufsc-program-title]');
        if (label instanceof HTMLInputElement && title) {
          title.textContent = label.value.trim() || 'Nouveau bloc';
        }
      });
    }

    function newBlock(mode) {
      const fragment = template.content.cloneNode(true);
      if (!(fragment instanceof DocumentFragment)) return;
      const block = fragment.querySelector('[data-ufsc-program-block]');
      if (!(block instanceof HTMLElement)) return;
      block.classList.remove('is-template');
      const modeField = block.querySelector('[data-ufsc-field="mode"]');
      if (modeField instanceof HTMLSelectElement) {
        modeField.value = mode;
      }
      const labelField = block.querySelector('[data-ufsc-field="label"]');
      const participantField = block.querySelector('[data-ufsc-field="participant_target"]');
      if (labelField instanceof HTMLInputElement) {
        if (mode === 'tournament') labelField.value = 'Mini-tournoi';
        else if (mode === 'pool') labelField.value = 'Poule';
        else if (mode === 'direct_fights') labelField.value = 'Combats directs';
        else labelField.value = 'Bloc manuel';
      }
      if (mode === 'tournament' && participantField instanceof HTMLInputElement) {
        participantField.value = '6';
      }
      list.appendChild(block);
      updateHeadings();
      const firstInput = block.querySelector('input:not([type="hidden"]), select');
      if (firstInput instanceof HTMLElement) firstInput.focus();
    }

    document.querySelectorAll('[data-ufsc-add-program-block]').forEach((button) => {
      button.addEventListener('click', () => {
        const mode = button.getAttribute('data-ufsc-add-program-block') || 'manual';
        newBlock(mode);
      });
    });

    list.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const block = target.closest('[data-ufsc-program-block]');
      if (!(block instanceof HTMLElement)) return;

      if (target.closest('[data-ufsc-remove-program]')) {
        block.remove();
        updateHeadings();
        return;
      }

      const moveButton = target.closest('[data-ufsc-move-program]');
      if (moveButton instanceof HTMLElement) {
        const direction = moveButton.getAttribute('data-ufsc-move-program');
        if (direction === 'up' && block.previousElementSibling) {
          list.insertBefore(block, block.previousElementSibling);
        } else if (direction === 'down' && block.nextElementSibling) {
          list.insertBefore(block.nextElementSibling, block);
        }
        updateHeadings();
      }
    });

    list.addEventListener('input', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.matches('[data-ufsc-field="label"]')) updateHeadings();
    });

    editor.addEventListener('submit', (event) => {
      updateHeadings();
      if (blocks().length === 0) {
        event.preventDefault();
        const message = (window.ufscPremiumAdmin && window.ufscPremiumAdmin.emptyProgramLabel) || 'Ajoutez au moins un bloc au programme.';
        window.alert(message);
      }
    });

    updateHeadings();
  }

  function enhanceLegacyForms() {
    document.querySelectorAll('.ufsc-competitions-admin form > .form-table').forEach((table) => {
      table.classList.add('ufsc-premium-form-table');
    });

    document.querySelectorAll('.ufsc-competitions-admin h2').forEach((heading) => {
      const next = heading.nextElementSibling;
      if (next && next.matches('.form-table')) {
        heading.classList.add('ufsc-premium-section-title');
      }
    });
  }

  function init() {
    enhanceLegacyForms();
    initAccessModeExperience();
    initProgramEditor();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
