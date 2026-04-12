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
  const licenseNumberInput = document.getElementById("ufsc_entry_licensee_search_license_number");
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
  const weightInput = document.getElementById("ufsc_entry_weight");
  const weightClassSelect = document.getElementById("ufsc_entry_weight_class");
  const weightClassPreview = document.getElementById("ufsc_entry_weight_class_preview");
  const weightMessage = document.getElementById("ufsc_entry_weight_message");
  const participantTypeSelect = document.getElementById("ufsc_entry_participant_type");
  const externalFirstNameInput = document.getElementById("ufsc_entry_external_first_name");
  const externalLastNameInput = document.getElementById("ufsc_entry_external_last_name");
  const externalBirthDateInput = document.getElementById("ufsc_entry_external_birth_date");
  const externalSexInput = document.getElementById("ufsc_entry_external_sex");

  if (
    !nameInput ||
    !firstNameInput ||
    !licenseNumberInput ||
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
    !competitionSelect ||
    !participantTypeSelect
  ) {
    return;
  }

  let lastSelected = null;
  let searchTimeout = null;
  let searchController = null;
  let weightClassTouched = false;
  const categoryOptions = Array.from(categorySelect.options || []).map((option) => ({
    value: option.value,
    label: option.textContent || "",
    competitionId: option.dataset ? option.dataset.competitionId || "" : "",
  }));

  function setMessage(text, type = "") {
    messageContainer.textContent = text || "";
    messageContainer.dataset.type = type;
  }

  function setWeightMessage(text, type = "") {
    if (!weightMessage) {
      return;
    }
    weightMessage.textContent = text || "";
    weightMessage.dataset.type = type;
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

    autoCategoryPreview.textContent = config.autoCategoryEmptyLabel || "";
    autoCategoryPreview.dataset.visible = config.autoCategoryEmptyLabel
      ? "true"
      : "false";
  }

  function updateWeightClassPreview(label) {
    if (!weightClassPreview) {
      return;
    }
    if (label) {
      weightClassPreview.textContent = `${config.weightClassLabel || ""} ${label}`;
      weightClassPreview.dataset.visible = "true";
      return;
    }
    weightClassPreview.textContent = "";
    weightClassPreview.dataset.visible = "false";
  }

  function updateWeightClassOptions(options, selected) {
    if (!weightClassSelect) {
      return;
    }

    const current = weightClassSelect.value;
    const keepValue = weightClassTouched ? current : selected || current;

    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent =
      config.weightClassEmptyLabel || "Auto / non assignée";

    weightClassSelect.innerHTML = "";
    weightClassSelect.appendChild(placeholder);

    if (options && options.length) {
      options.forEach((optionValue) => {
        const option = document.createElement("option");
        option.value = optionValue;
        option.textContent = optionValue;
        weightClassSelect.appendChild(option);
      });
    }

    if (keepValue && (!options || !options.includes(keepValue))) {
      const option = document.createElement("option");
      option.value = keepValue;
      option.textContent = keepValue;
      weightClassSelect.appendChild(option);
    }

    if (keepValue) {
      weightClassSelect.value = keepValue;
    }
  }

  function syncCategoryOptions() {
    const selectedValue = categorySelect.value || "0";
    const competitionId = competitionSelect.value || "0";
    const currentLabel =
      categorySelect.options[categorySelect.selectedIndex]?.textContent || "";

    categorySelect.innerHTML = "";

    categoryOptions.forEach((item) => {
      if (item.value === "0") {
        const option = document.createElement("option");
        option.value = "0";
        option.textContent = item.label;
        categorySelect.appendChild(option);
        return;
      }

      if (competitionId !== "0" && item.competitionId !== competitionId) {
        return;
      }

      const option = document.createElement("option");
      option.value = item.value;
      option.textContent = item.label;
      option.dataset.competitionId = item.competitionId;
      categorySelect.appendChild(option);
    });

    const hasSelectedValue = Array.from(categorySelect.options).some(
      (option) => option.value === selectedValue
    );

    if (selectedValue && hasSelectedValue) {
      categorySelect.value = selectedValue;
      return;
    }

    if (
      selectedValue &&
      selectedValue !== "0" &&
      currentLabel &&
      !hasSelectedValue
    ) {
      const option = document.createElement("option");
      option.value = selectedValue;
      option.textContent = currentLabel;
      option.dataset.competitionId = competitionId;
      categorySelect.appendChild(option);
      categorySelect.value = selectedValue;
      return;
    }

    categorySelect.value = "0";
  }

  function isExternalParticipant() {
    return (participantTypeSelect.value || "licensed_ufsc") === "external_non_licensed";
  }

  function toggleParticipantSections() {
    const isExternal = isExternalParticipant();
    document.querySelectorAll("[data-participant-row]").forEach((row) => {
      const expected = row.getAttribute("data-participant-row") || "";
      row.style.display = expected === (isExternal ? "external_non_licensed" : "licensed_ufsc") ? "" : "none";
    });
    licenseeInput.required = !isExternal;
  }

  async function resolveWeightClass() {
    if (!weightInput || !weightClassSelect) {
      return;
    }

    const payload = {
      action: "ufsc_lc_resolve_weight_class",
      nonce: config.nonce,
      competition_id: competitionSelect.value || "0",
      licensee_id: isExternalParticipant() ? "" : selectedHidden.value || "",
      birth_date: isExternalParticipant()
        ? (externalBirthDateInput ? externalBirthDateInput.value || "" : "")
        : lastSelected?.date_naissance || "",
      sex: isExternalParticipant()
        ? (externalSexInput ? externalSexInput.value || "" : "")
        : lastSelected?.sex || "",
      weight_kg: weightInput.value || "",
    };

    setWeightMessage("", "");

    try {
      const { ok, json } = await postAjax(payload);
      if (!ok || !json || json.success !== true) {
        setWeightMessage(config.weightMissingMessage || "", "warning");
        return;
      }

      const data = json.data || {};
      updateWeightClassOptions(data.classes || [], data.label || "");
      updateWeightClassPreview(data.label || "");
      if (data.message) {
        setWeightMessage(
          data.message,
          data.status === "missing_sex" ? "warning" : "info"
        );
      }
    } catch (error) {
      setWeightMessage(config.weightMissingMessage || "", "warning");
    }
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
      radio.dataset.sex = item.sex || "";
      radio.dataset.weightKg = item.weight_kg || "";

      const info = document.createElement("span");
      info.className = "ufsc-entry-licensee-result-info";

      const displayName = `${item.nom || ""} ${item.prenom || ""}`.trim();
      const birthdate = item.date_naissance_fmt || item.date_naissance || "—";
      const clubLabel = item.club_id
        ? `${item.club_nom || "Club"} (#${item.club_id})`
        : "Club non renseigné";
      const categoryLabel = item.category ? ` · Catégorie : ${item.category}` : "";
      const licenseNumber = item.numero_licence ? ` · Licence ${item.numero_licence}` : "";

      info.textContent = `ID ${item.licence_id}${licenseNumber} · ${displayName || "—"} · ${birthdate} · ${clubLabel}${categoryLabel}`;

      label.appendChild(radio);
      label.appendChild(info);
      list.appendChild(label);
    });

    resultsContainer.appendChild(list);
    useButton.disabled = false;
  }

  async function postAjax(data, { signal } = {}) {
    const body = new URLSearchParams();
    Object.entries(data).forEach(([key, value]) => {
      body.append(key, value == null ? "" : String(value));
    });

    const res = await fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      signal,
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
      numero_licence: licenseNumberInput.value.trim(),
      date_naissance: birthdateInput.value.trim(),
      competition_id: competitionSelect.value || "0",
    };
  }

  async function performSearch() {
    const payload = getSearchPayload();
    if (window.console && typeof window.console.debug === "function") {
      window.console.debug("license_search_request_payload", payload);
    }
    if (
      !payload.nom &&
      !payload.prenom &&
      !payload.numero_licence &&
      !payload.date_naissance
    ) {
      setMessage(config.searchErrorMessage, "warning");
      clearResults();
      return;
    }

    setMessage("", "");

    if (searchController) {
      searchController.abort();
    }
    searchController = new AbortController();

    try {
      const { ok, json } = await postAjax(payload, {
        signal: searchController.signal,
      });
      if (window.console && typeof window.console.debug === "function") {
        window.console.debug("license_search_response_payload", {
          ok,
          json,
        });
      }
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

      const results = json.data && json.data.results ? json.data.results : json.data;
      renderResults(results || []);

      if (json.data && json.data.message && (!results || !results.length)) {
        setMessage(json.data.message, "warning");
      }
    } catch (error) {
      if (error && error.name === "AbortError") {
        return;
      }
      setMessage(config.searchErrorMessage, "error");
      clearResults();
    } finally {
      searchController = null;
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
      sex: selected.dataset.sex || "",
      weight_kg: selected.dataset.weightKg || "",
    };

    if (data.licence_id) {
      licenseeInput.value = String(data.licence_id);
      selectedHidden.value = String(data.licence_id);
      lastSelected = data;
      weightClassTouched = false;
    }

    if (!clubInput.value || clubInput.value === "0") {
      if (data.club_id) {
        clubInput.value = String(data.club_id);
      }
    }

    if (weightInput && !weightInput.value && data.weight_kg) {
      weightInput.value = String(data.weight_kg);
    }

    const displayName = `${data.nom || ""} ${data.prenom || ""}`.trim();
    const birthdate = data.date_naissance_fmt || data.date_naissance || "";
    selectedLabel.textContent = displayName
      ? `Sélectionné : ${displayName}${birthdate ? ` · ${birthdate}` : ""}`
      : "";

    updateAutoCategory(data);
    resolveWeightClass();
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
      weightClassTouched = false;
      updateAutoCategory(data);

      if (!clubInput.value || clubInput.value === "0") {
        if (data.club_id) {
          clubInput.value = String(data.club_id);
        }
      }

      if (weightInput && !weightInput.value && data.weight_kg) {
        weightInput.value = String(data.weight_kg);
      }

      resolveWeightClass();
    } catch (error) {
      updateAutoCategory(null);
    }
  }

  searchButton.addEventListener("click", () => {
    performSearch();
  });

  nameInput.addEventListener("input", scheduleSearch);
  firstNameInput.addEventListener("input", scheduleSearch);
  licenseNumberInput.addEventListener("input", scheduleSearch);
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
    syncCategoryOptions();
    if (lastSelected && lastSelected.licence_id) {
      fetchLicenseeById(lastSelected.licence_id);
    } else {
      updateAutoCategory(lastSelected);
    }
    resolveWeightClass();
  });

  participantTypeSelect.addEventListener("change", () => {
    toggleParticipantSections();
    resolveWeightClass();
  });

  if (externalBirthDateInput) {
    externalBirthDateInput.addEventListener("change", () => {
      if (isExternalParticipant()) {
        resolveWeightClass();
      }
    });
  }

  if (externalSexInput) {
    externalSexInput.addEventListener("change", () => {
      if (isExternalParticipant()) {
        resolveWeightClass();
      }
    });
  }

  if (weightInput) {
    weightInput.addEventListener("input", () => {
      weightClassTouched = false;
      resolveWeightClass();
    });
  }

  if (weightClassSelect) {
    weightClassSelect.addEventListener("change", () => {
      weightClassTouched = true;
      updateWeightClassPreview(weightClassSelect.value);
    });
  }

  form.addEventListener("submit", (event) => {
    if (
      isExternalParticipant() &&
      (
        !externalFirstNameInput ||
        !externalFirstNameInput.value.trim() ||
        !externalLastNameInput ||
        !externalLastNameInput.value.trim() ||
        !externalBirthDateInput ||
        !externalBirthDateInput.value.trim()
      )
    ) {
      event.preventDefault();
      setMessage(config.externalRequiredMessage || "", "error");
      if (externalLastNameInput && !externalLastNameInput.value.trim()) {
        externalLastNameInput.focus();
      }
      return;
    }

    if (!weightInput) {
      return;
    }
    const statusInput = document.getElementById("ufsc_entry_status");
    const status = statusInput ? statusInput.value : "draft";
    if ((status === "submitted" || status === "approved") && !weightInput.value) {
      event.preventDefault();
      setWeightMessage(config.weightRequiredMessage || "", "error");
      weightInput.focus();
    }
  });

  const initialId = parseInt(licenseeInput.value || "0", 10);
  toggleParticipantSections();
  syncCategoryOptions();
  if (initialId) {
    fetchLicenseeById(initialId);
  } else {
    resolveWeightClass();
  }
})();
