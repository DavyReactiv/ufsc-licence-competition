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

  const actionFields = ['ufsc_bulk_action', 'ufsc_bulk_action2'];
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

/* UFSC Competitions - surfaces manager */
(function($) {
  "use strict";

  function initSurfacesManager() {
    const $manager = $(".ufsc-surfaces-manager");
    if (!$manager.length) {
      return;
    }

    if (window.ufscCompetitionAdmin && window.ufscCompetitionAdmin.debug) {
      // Debug-only trace requested for troubleshooting load/bind issues.
      console.info("UFSC surfaces manager loaded");
    }

    $manager.each(function() {
      const $root = $(this);
      const $list = $root.find(".ufsc-surfaces-list").first();
      const $countInput = $("#ufsc_surface_count");
      const $counter = $root.find(".ufsc-surfaces-counter").first();

      const typesOptionsHtml = $list.find(".ufsc-surface-row:first select[name*='[type]'] option")
        .map(function() { return this.outerHTML; })
        .get()
        .join("");

      function rows() { return $list.find(".ufsc-surface-row"); }
      function activeCount() { return rows().find("input[name*='[active]']:checked").length; }
      function nextLabelIndex() { return rows().length + 1; }

      function sync() {
        rows().each(function(i) {
          const $row = $(this);
          $row.find("input,select").each(function() {
            const n = $(this).attr("name");
            if (n) {
              $(this).attr("name", n.replace(/surface_details\[\d+\]/, "surface_details[" + i + "]"));
            }
          });
          $row.find(".ufsc-surface-badge").text("Surface " + (i + 1));
          $row.find(".ufsc-surface-order").val(String(i + 1));
          $row.find(".ufsc-move-surface-up").prop("disabled", i === 0);
          $row.find(".ufsc-move-surface-down").prop("disabled", i === rows().length - 1);
        });
        $countInput.val(String(Math.max(1, rows().length)));
        if ($counter.length) {
          $counter.text(rows().length + " surfaces configurées — " + activeCount() + " actives");
        }
      }

      function buildRow(copyData) {
        const idx = nextLabelIndex();
        const uuid = "ufsc-surface-" + Date.now() + "-" + Math.floor(Math.random() * 100000);
        const baseName = "Surface " + idx;
        const short = "T" + idx;
        return $(
          '<div class="ufsc-competitions-surface-row ufsc-surface-row">' +
            '<div class="ufsc-surface-header"><span class="ufsc-surface-badge">' + baseName + "</span></div>" +
            '<div class="ufsc-surface-fields">' +
              '<label>Nom de la surface <input name="surface_details[0][name]" type="text" class="regular-text"></label>' +
              '<label>Type <select name="surface_details[0][type]" required>' + typesOptionsHtml + "</select></label>" +
              '<label>Code court <input name="surface_details[0][short_label]" type="text" class="small-text"></label>' +
              '<label><input type="checkbox" name="surface_details[0][active]" value="1" checked> Active</label>' +
              '<input type="hidden" name="surface_details[0][uuid]" value="' + uuid + '">' +
              '<input type="hidden" class="ufsc-surface-order" name="surface_details[0][order]" value="' + idx + '">' +
            "</div>" +
            '<div class="ufsc-surface-actions">' +
              '<button type="button" class="button ufsc-duplicate-surface">Dupliquer</button>' +
              '<button type="button" class="button ufsc-remove-surface ufsc-surface-danger-action">Supprimer</button>' +
              '<button type="button" class="button ufsc-move-surface-up ufsc-surface-move-action">Monter</button>' +
              '<button type="button" class="button ufsc-move-surface-down ufsc-surface-move-action">Descendre</button>' +
            "</div>" +
          "</div>"
        );
      }

      function appendRowFrom(sourceRow) {
        const $row = buildRow();
        if (sourceRow && sourceRow.length) {
          $row.find("input[name*='[name]']").val(sourceRow.find("input[name*='[name]']").val() || ("Surface " + nextLabelIndex()));
          $row.find("select[name*='[type]']").val(sourceRow.find("select[name*='[type]']").val() || "tatami");
          $row.find("input[name*='[active]']").prop("checked", sourceRow.find("input[name*='[active]']").is(":checked"));
        } else {
          $row.find("input[name*='[name]']").val("Surface " + nextLabelIndex());
          $row.find("select[name*='[type]']").val("tatami");
          $row.find("input[name*='[active]']").prop("checked", true);
        }
        $row.find("input[name*='[short_label]']").val("T" + nextLabelIndex());
        $list.append($row);
        sync();
      }

      $root.on("click", ".ufsc-add-surface", function(e) { e.preventDefault(); appendRowFrom(null); });
      $root.on("click", ".ufsc-add-five-surfaces", function(e) { e.preventDefault(); for (let i = 0; i < 5; i++) appendRowFrom(null); });
      $root.on("click", ".ufsc-duplicate-last-surface", function(e) { e.preventDefault(); const $r = rows().last(); if ($r.length) appendRowFrom($r); });
      $root.on("click", ".ufsc-duplicate-surface", function(e) { e.preventDefault(); appendRowFrom($(this).closest(".ufsc-surface-row")); });
      $root.on("click", ".ufsc-remove-surface", function(e) {
        e.preventDefault();
        if (rows().length <= 1) { return; }
        $(this).closest(".ufsc-surface-row").remove();
        if (activeCount() === 0) { rows().first().find("input[name*='[active]']").prop("checked", true); }
        sync();
      });
      $root.on("click", ".ufsc-move-surface-up", function(e) { e.preventDefault(); const $r = $(this).closest(".ufsc-surface-row"); $r.prev(".ufsc-surface-row").before($r); sync(); });
      $root.on("click", ".ufsc-move-surface-down", function(e) { e.preventDefault(); const $r = $(this).closest(".ufsc-surface-row"); $r.next(".ufsc-surface-row").after($r); sync(); });
      $root.on("change", "input[name*='[active]']", function() { if (activeCount() === 0) { $(this).prop("checked", true); } sync(); });
      sync();
    });
  }

  $(document).ready(initSurfacesManager);
})(jQuery);

/* UFSC Competitions - admin media uploader */
(() => {
  "use strict";

  function initMediaField() {
    if (typeof window.wp === "undefined" || typeof window.wp.media === "undefined") {
      return;
    }

    const field = document.querySelector(".ufsc-competition-photo-field");
    if (!field) {
      return;
    }

    const input = field.querySelector("#ufsc_photo_evenement_id");
    const preview = field.querySelector("[data-ufsc-photo-preview]");
    const selectBtn = field.querySelector("[data-ufsc-photo-select]");
    const removeBtn = field.querySelector("[data-ufsc-photo-remove]");

    if (!input || !preview || !selectBtn || !removeBtn) {
      return;
    }

    let frame;

    function setPreview(url) {
      preview.innerHTML = url
        ? `<img src="${url}" alt="" />`
        : "";
    }

    function toggleRemove(show) {
      removeBtn.style.display = show ? "" : "none";
    }

    selectBtn.addEventListener("click", (event) => {
      event.preventDefault();

      if (frame) {
        frame.open();
        return;
      }

      frame = window.wp.media({
        title: "Choisir une photo",
        library: { type: "image" },
        button: { text: "Utiliser cette photo" },
        multiple: false,
      });

      frame.on("select", () => {
        const selection = frame.state().get("selection");
        const attachment = selection && selection.first ? selection.first().toJSON() : null;
        if (!attachment || !attachment.id) {
          return;
        }
        input.value = String(attachment.id);
        const url =
          (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) ||
          attachment.url ||
          "";
        setPreview(url);
        toggleRemove(true);
      });

      frame.open();
    });

    removeBtn.addEventListener("click", (event) => {
      event.preventDefault();
      input.value = "";
      setPreview("");
      toggleRemove(false);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMediaField);
  } else {
    initMediaField();
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
