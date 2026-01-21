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
/* UFSC Competitions - admin club snapshot (no-conflict) */
(() => {
  "use strict";

  function qs(sel, root = document) {
    return root.querySelector(sel);
  }

  function setFieldValue(input, value) {
    if (!input) return;
    input.value = value == null ? "" : String(value);
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  async function postAjax(url, data) {
    const body = new URLSearchParams();
    Object.keys(data).forEach((k) => body.append(k, data[k]));

    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: body.toString(),
    });

    const json = await res.json();
    return { ok: res.ok, status: res.status, json };
  }

  function init() {
    // WordPress admin provides ajaxurl
    if (typeof window.ajaxurl === "undefined") return;

    const selectClub = qs('select[name="organizer_club_id"]');
    const nonceEl = qs("#ufsc_get_club_nonce");
    const regionInput = qs("#ufsc_organizer_region");

    // If form not present, do nothing (no-conflict)
    if (!selectClub || !nonceEl || !regionInput) return;

    let lastRequestId = 0;

    async function handleClubChange() {
      const clubId = parseInt(selectClub.value, 10) || 0;

      if (!clubId) {
        setFieldValue(regionInput, "");
        return;
      }

      const requestId = ++lastRequestId;
      regionInput.setAttribute("aria-busy", "true");

      try {
        const { ok, json } = await postAjax(window.ajaxurl, {
          action: "ufsc_get_club",
          nonce: nonceEl.value,
          club_id: String(clubId),
        });

        if (requestId !== lastRequestId) return;

        if (!ok || !json || json.success !== true) {
          setFieldValue(regionInput, "");
          return;
        }

        const data = json.data || {};
        setFieldValue(regionInput, data.region || "");
      } catch (e) {
        if (requestId !== lastRequestId) return;
        setFieldValue(regionInput, "");
      } finally {
        if (requestId === lastRequestId) {
          regionInput.removeAttribute("aria-busy");
        }
      }
    }

    selectClub.addEventListener("change", handleClubChange);

    // Auto-sync on load (edit screen) if needed
    const currentRegion = (regionInput.value || "").trim();
    const currentClubId = parseInt(selectClub.value, 10) || 0;
    if (currentClubId && !currentRegion) {
      handleClubChange();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

/* UFSC Competitions - admin pointers (guarded) */
(() => {
  "use strict";

  function initPointers() {
    if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.pointer !== "function") {
      return;
    }

    const $ = window.jQuery;
    const nodes = document.querySelectorAll("[data-ufsc-pointer-content]");
    if (!nodes.length) {
      return;
    }

    nodes.forEach((node) => {
      const content = node.getAttribute("data-ufsc-pointer-content");
      if (!content) {
        return;
      }
      // Guarded pointer init: only when WP pointer is available.
      $(node).pointer({
        content,
        position: "top",
        close() {
          $(node).pointer("close");
        },
      }).pointer("open");
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPointers);
  } else {
    initPointers();
  }
})();
