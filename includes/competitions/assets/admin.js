document.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) {
    return;
  }

  const confirmLink = target.closest('.ufsc-confirm');
  if (!confirmLink) {
    return;
  }

  const message =
    confirmLink.getAttribute('data-ufsc-confirm') ||
    'Êtes-vous sûr de vouloir effectuer cette action ?';
  if (!window.confirm(message)) {
    event.preventDefault();
  }
});

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  const actionFields = ['action', 'action2'];
  let action = '';
  actionFields.forEach((name) => {
    const field = form.querySelector(`select[name="${name}"]`);
    if (field && field instanceof HTMLSelectElement && field.value && field.value !== '-1') {
      action = field.value;
    }
  });

  if (!action) {
    return;
  }

  if (action === 'trash' || action === 'delete' || action === 'archive') {
    const message =
      action === 'delete'
        ? 'Supprimer définitivement les éléments sélectionnés ?'
        : action === 'archive'
        ? 'Archiver les éléments sélectionnés ?'
        : 'Mettre les éléments sélectionnés à la corbeille ?';
    if (!window.confirm(message)) {
      event.preventDefault();
    }
  }
});
