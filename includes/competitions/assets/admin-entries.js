(() => {
  "use strict";

  const config = window.ufscEntriesSearch;
  if (!config) {
    return;
  }

  const form = document.querySelector(".ufsc-competitions-form");
  if (!form) {
    return;
  }

  const nameInput = document.getElementById("ufsc_entry_licensee_search_nom");
  const firstNameInput = document.getElementById("ufsc_entry_licensee_search_prenom");
  const birthdateInput = document.getElementById("ufsc_entry_licensee_search_birthdate");
  const searchButton = document.getElementById("ufsc_entry_licensee_search_button");
  const resultsContainer = document.getElementById("ufsc_entry_licensee_search_results");
  const messageContainer = document.getElementById("ufsc_entry_licensee_search_message");
  const useButton = document.getElementById("ufsc_entry_use_licensee");
  const selectedLabel = document.getElementById("ufsc_entry_licensee_selected");
  const selectedHidden = document.getElementById("ufsc_entry_selected_licensee");
  const licenseeInput = document.getElementById("ufsc_entry_licensee");
  const clubInput = document.getElementById("ufsc_entry_club");
  const categorySelect = document.getElementById("ufsc_entry_category");
  const autoCategoryPreview = document.getElementById("ufsc_entry_auto_category_preview");
  const competitionSelect = document.getElementById("ufsc_entry_competition");

  if (
    !nameInput ||
    !firstNameInput ||
    !birthdateInput ||
    !searchButton ||
    !resultsContainer ||
    !messageContainer ||
    !useButton ||
    !selectedLabel ||
    !selectedHidden ||
    !licenseeInput ||
    !clubInput ||
    !categorySelect ||
    !autoCategoryPreview ||
    !competitionSelect
  ) {
    return;
  }

  let lastSelected = null;
  let searchTimeout = null;

  function setMessage(text, type = "") {
    messageContainer.textContent = text || "";
    messageContainer.dataset.type = type;
  }

  function clearResults() {
    resultsContainer.innerHTML = "";
    useButton.disabled = true;
  }

  function updateAutoCategory(data) {
    if (categorySelect.value !== "0") {
      autoCategoryPreview.textContent = "";
      autoCategoryPreview.dataset.visible = "false";
      return;
    }

    if (data && data.category) {
      autoCategoryPreview.textContent = `${config.autoCategoryLabel} ${data.category}`;
      autoCategoryPreview.dataset.visible = "true";
      return;
    }

    autoCategoryPreview.textContent = "";
    autoCategoryPreview.dataset.visible = "false";
  }

  function renderResults(items) {
    clearResults();

    if (!items || !items.length) {
      setMessage(config.searchEmptyMessage, "warning");
      return;
    }

    setMessage("", "");

    const list = document.createElement("div");
    list.className = "ufsc-entry-licensee-results-list";

    items.forEach((item, index) => {
      const radioId = `ufsc-licensee-result-${item.licence_id}-${index}`;
      const label = document.createElement("label");
      label.className = "ufsc-entry-licensee-result";
      label.setAttribute("for", radioId);

      const radio = document.createElement("input");
      radio.type = "radio";
      radio.name = "ufsc_entry_licensee_result";
      radio.id = radioId;
      radio.value = String(item.licence_id || 0);
      radio.dataset.licenceId = String(item.licence_id || 0);
      radio.dataset.clubId = String(item.club_id || 0);
      radio.dataset.clubNom = item.club_nom || "";
      radio.dataset.nom = item.nom || "";
      radio.dataset.prenom = item.prenom || "";
      radio.dataset.birthdate = item.date_naissance || "";
      radio.dataset.birthdateFmt = item.date_naissance_fmt || "";
      radio.dataset.category = item.category || "";

      const info = document.createElement("span");
      info.className = "ufsc-entry-licensee-result-info";

      const displayName = `${item.nom || ""} ${item.prenom || ""}`.trim();
      const birthdate = item.date_naissance_fmt || item.date_naissance || "—";
      const clubLabel = item.club_id
        ? `${item.club_nom || "Club"} (#${item.club_id})`
        : "Club non renseigné";
      const categoryLabel = item.category ? ` · Catégorie : ${item.category}` : "";

      info.textContent = `ID ${item.licence_id} · ${displayName || "—"} · ${birthdate} · ${clubLabel}${categoryLabel}`;

      label.appendChild(radio);
      label.appendChild(info);
      list.appendChild(label);
    });

    resultsContainer.appendChild(list);
    useButton.disabled = false;
  }

  async function postAjax(data) {
    const body = new URLSearchParams();
    Object.entries(data).forEach(([key, value]) => {
      body.append(key, value == null ? "" : String(value));
    });

    const res = await fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: body.toString(),
    });

    const json = await res.json();
    return { ok: res.ok, json };
  }

  function getSearchPayload() {
    return {
      action: "ufsc_lc_search_licence",
      nonce: config.nonce,
      nom: nameInput.value.trim(),
      prenom: firstNameInput.value.trim(),
      date_naissance: birthdateInput.value.trim(),
      competition_id: competitionSelect.value || "0",
    };
  }

  async function performSearch() {
    const payload = getSearchPayload();
    if (!payload.nom && !payload.prenom && !payload.date_naissance) {
      setMessage(config.searchErrorMessage, "warning");
      clearResults();
      return;
    }

    setMessage("", "");

    try {
      const { ok, json } = await postAjax(payload);
      if (!ok || !json) {
        setMessage(config.searchErrorMessage, "error");
        clearResults();
        return;
      }

      if (json.success !== true) {
        const errorMessage =
          json && json.data && json.data.message
            ? json.data.message
            : config.searchErrorMessage;
        setMessage(errorMessage, "error");
        clearResults();
        return;
      }

      renderResults(json.data || []);
    } catch (error) {
      setMessage(config.searchErrorMessage, "error");
      clearResults();
    }
  }

  function scheduleSearch() {
    if (searchTimeout) {
      window.clearTimeout(searchTimeout);
    }
    searchTimeout = window.setTimeout(() => {
      performSearch();
    }, 350);
  }

  function useSelectedLicensee() {
    const selected = resultsContainer.querySelector(
      'input[name="ufsc_entry_licensee_result"]:checked'
    );
    if (!selected) {
      setMessage(config.selectionRequiredMessage, "warning");
      return;
    }

    const data = {
      licence_id: parseInt(selected.dataset.licenceId || "0", 10),
      club_id: parseInt(selected.dataset.clubId || "0", 10),
      club_nom: selected.dataset.clubNom || "",
      nom: selected.dataset.nom || "",
      prenom: selected.dataset.prenom || "",
      date_naissance: selected.dataset.birthdate || "",
      date_naissance_fmt: selected.dataset.birthdateFmt || "",
      category: selected.dataset.category || "",
    };

    if (data.licence_id) {
      licenseeInput.value = String(data.licence_id);
      selectedHidden.value = String(data.licence_id);
      lastSelected = data;
    }

    if (!clubInput.value || clubInput.value === "0") {
      if (data.club_id) {
        clubInput.value = String(data.club_id);
      }
    }

    const displayName = `${data.nom || ""} ${data.prenom || ""}`.trim();
    const birthdate = data.date_naissance_fmt || data.date_naissance || "";
    selectedLabel.textContent = displayName
      ? `Sélectionné : ${displayName}${birthdate ? ` · ${birthdate}` : ""}`
      : "";

    updateAutoCategory(data);
  }

  async function fetchLicenseeById(id) {
    if (!id) {
      selectedLabel.textContent = "";
      selectedHidden.value = "";
      lastSelected = null;
      updateAutoCategory(null);
      return;
    }

    try {
      const { ok, json } = await postAjax({
        action: "ufsc_lc_get_licensee",
        nonce: config.nonce,
        licensee_id: id,
        competition_id: competitionSelect.value || "0",
      });

      if (!ok || !json || json.success !== true) {
        updateAutoCategory(null);
        return;
      }

      const data = json.data || null;
      if (!data) {
        updateAutoCategory(null);
        return;
      }

      lastSelected = data;
      selectedHidden.value = String(data.licence_id || id);
      updateAutoCategory(data);

      if (!clubInput.value || clubInput.value === "0") {
        if (data.club_id) {
          clubInput.value = String(data.club_id);
        }
      }
    } catch (error) {
      updateAutoCategory(null);
    }
  }

  searchButton.addEventListener("click", () => {
    performSearch();
  });

  nameInput.addEventListener("input", scheduleSearch);
  firstNameInput.addEventListener("input", scheduleSearch);
  birthdateInput.addEventListener("change", scheduleSearch);

  useButton.addEventListener("click", useSelectedLicensee);

  licenseeInput.addEventListener("change", () => {
    const id = parseInt(licenseeInput.value || "0", 10);
    fetchLicenseeById(id);
  });

  categorySelect.addEventListener("change", () => {
    updateAutoCategory(lastSelected);
  });

  competitionSelect.addEventListener("change", () => {
    if (lastSelected && lastSelected.licence_id) {
      fetchLicenseeById(lastSelected.licence_id);
    } else {
      updateAutoCategory(lastSelected);
    }
  });

  const initialId = parseInt(licenseeInput.value || "0", 10);
  if (initialId) {
    fetchLicenseeById(initialId);
  }
})();
