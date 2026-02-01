(() => {
  const config = window.ufscCompetitionsFront;
  if (!config) {
    return;
  }

  const form = document.querySelector(".ufsc-competition-entry-form form");
  if (!form) {
    return;
  }

  const birthInput = document.getElementById("ufsc-entry-birth_date");
  const weightInput = document.getElementById("ufsc-entry-weight");
  const weightClassInput = document.getElementById("ufsc-entry-weight_class");
  const sexInput = document.getElementById("ufsc-entry-sex");
  const levelInput = document.getElementById("ufsc-entry-level");
  const categoryInput = document.getElementById("ufsc-entry-category");
  const statusNode = document.querySelector(".ufsc-entry-category-status");
  const weightStatusNode = document.querySelector(".ufsc-entry-weight-status");
  const licenseSearchForm = document.querySelector(".ufsc-license-search-form");
  const licenseSelectForm = document.querySelector(".ufsc-license-select-form");
  const licenseSearchFeedback = document.querySelector(".ufsc-license-search-feedback");

  if (!birthInput || !categoryInput) {
    return;
  }

  const setStatus = (message, type = "") => {
    if (!statusNode) {
      return;
    }
    statusNode.textContent = message || "";
    statusNode.dataset.status = type;
  };

  const setWeightStatus = (message, type = "") => {
    if (!weightStatusNode) {
      return;
    }
    weightStatusNode.textContent = message || "";
    weightStatusNode.dataset.status = type;
  };

  let licenseResults = [];
  let timeout;
  const debounce = (fn, delay = 400) => {
    clearTimeout(timeout);
    timeout = setTimeout(fn, delay);
  };

  const getValue = (input) =>
    input && typeof input.value !== "undefined" ? input.value.trim() : "";

  const normalizeBirthDate = (value) => {
    const trimmed = value.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
      return trimmed;
    }
    if (/^\d{2}\/\d{2}\/\d{4}$/.test(trimmed)) {
      const parts = trimmed.split("/");
      return `${parts[2]}-${parts[1]}-${parts[0]}`;
    }
    return "";
  };

  const shouldCompute = () => normalizeBirthDate(getValue(birthInput)) !== "";

  const applyCategory = (label) => {
    if (!label) {
      return;
    }
    if (categoryInput.tagName === "SELECT") {
      let option = Array.from(categoryInput.options).find(
        (opt) => opt.value === label
      );
      if (!option) {
        option = document.createElement("option");
        option.value = label;
        option.textContent = label;
        categoryInput.appendChild(option);
      }
      categoryInput.value = label;
    } else {
      categoryInput.value = label;
    }
  };

  const applyWeightClass = (label, options = []) => {
    if (!weightClassInput) {
      return;
    }
    if (weightClassInput.tagName === "SELECT") {
      weightClassInput.innerHTML = "";
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = "—";
      weightClassInput.appendChild(placeholder);
      if (options && options.length) {
        options.forEach((optionValue) => {
          const option = document.createElement("option");
          option.value = optionValue;
          option.textContent = optionValue;
          weightClassInput.appendChild(option);
        });
      }
      if (label && !options.includes(label)) {
        const option = document.createElement("option");
        option.value = label;
        option.textContent = label;
        weightClassInput.appendChild(option);
      }
      if (label) {
        weightClassInput.value = label;
      }
      return;
    }
    weightClassInput.value = label || "";
  };

  const computeCategory = async () => {
    if (!shouldCompute()) {
      setStatus(config.labels?.missing || "");
      setWeightStatus(config.labels?.weightMissing || "");
      return;
    }

    setStatus(config.labels?.loading || "", "loading");
    setWeightStatus("", "");

    const payload = new URLSearchParams({
      action: "ufsc_competitions_compute_category",
      nonce: config.nonce || "",
      competition_id: String(config.competitionId || ""),
      birth_date: normalizeBirthDate(getValue(birthInput)),
      weight: getValue(weightInput),
      sex: getValue(sexInput),
      level: getValue(levelInput),
      discipline: config.discipline || "",
    });

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=utf-8",
        },
        body: payload.toString(),
      });

      if (!response.ok) {
        throw new Error("Bad response");
      }

      const data = await response.json();
      if (!data || !data.success) {
        setStatus(data?.data?.message || config.labels?.error || "", "error");
        setWeightStatus(data?.data?.message || config.labels?.weightMissing || "", "error");
        return;
      }

      const label = data.data?.category_age || data.data?.label || "";
      const weightClass = data.data?.suggested_weight_class || data.data?.weight_class || "";
      const weightClasses = data.data?.weight_classes || [];
      applyCategory(label);
      applyWeightClass(weightClass, weightClasses);
      setStatus(label ? label : "", "success");
      if (data.data?.weight_message) {
        setWeightStatus(data.data.weight_message, data.data.weight_status || "");
      } else {
        setWeightStatus(weightClass ? weightClass : "", "success");
      }
    } catch (error) {
      setStatus(config.labels?.error || "", "error");
      setWeightStatus(config.labels?.weightMissing || "", "error");
    }
  };

  const bindInput = (input) => {
    if (!input) {
      return;
    }
    input.addEventListener("input", () => debounce(computeCategory));
    input.addEventListener("change", () => debounce(computeCategory, 100));
    input.addEventListener("blur", () => debounce(computeCategory, 100));
  };

  bindInput(birthInput);
  bindInput(weightInput);
  bindInput(sexInput);
  bindInput(levelInput);

  if (getValue(birthInput)) {
    debounce(computeCategory, 50);
  }

  const applyLicensePayload = (license) => {
    if (!license) {
      return;
    }
    const fieldMap = {
      first_name: "ufsc-entry-first_name",
      last_name: "ufsc-entry-last_name",
      birthdate: "ufsc-entry-birth_date",
      sex: "ufsc-entry-sex",
      license_number: "ufsc-entry-license_number",
      weight: "ufsc-entry-weight",
      weight_class: "ufsc-entry-weight_class",
      level: "ufsc-entry-level",
    };

    Object.entries(fieldMap).forEach(([key, fieldId]) => {
      const field = document.getElementById(fieldId);
      if (!field) {
        return;
      }
      const value = license[key];
      if (typeof value === "undefined" || value === null || value === "") {
        return;
      }
      field.value = String(value);
    });

    const hiddenLicenseId = form.querySelector('input[name="ufsc_license_id"]');
    if (hiddenLicenseId) {
      hiddenLicenseId.value = String(license.id || "");
    }

    debounce(computeCategory, 50);
  };

  const setLicenseFeedback = (message, type = "") => {
    if (!licenseSearchFeedback) {
      return;
    }
    licenseSearchFeedback.textContent = message || "";
    licenseSearchFeedback.dataset.status = type;
  };

  const populateLicenseSelect = (results, selectedId = "") => {
    if (!licenseSelectForm) {
      return;
    }
    const select = licenseSelectForm.querySelector("select[name='ufsc_license_id']");
    if (!select) {
      return;
    }
    select.innerHTML = "";
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Sélectionner un licencié";
    select.appendChild(placeholder);
    results.forEach((result) => {
      const option = document.createElement("option");
      option.value = String(result.id || "");
      option.textContent = result.label || "";
      select.appendChild(option);
    });
    if (selectedId) {
      select.value = String(selectedId);
    }
    licenseSelectForm.style.display = "";
    licenseResults = results;
  };

  if (licenseSearchForm) {
    licenseSearchForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const term = getValue(licenseSearchForm.querySelector("input[name='ufsc_license_term']"));
      const licenseNumber = getValue(
        licenseSearchForm.querySelector("input[name='ufsc_license_number']")
      );
      const birthDate = getValue(
        licenseSearchForm.querySelector("input[name='ufsc_license_birthdate']")
      );

      if (!term && !licenseNumber && !birthDate) {
        setLicenseFeedback(config.labels?.searchEmpty || "", "error");
        return;
      }

      setLicenseFeedback(config.labels?.searching || "", "loading");

      const payload = new URLSearchParams({
        action: "ufsc_competitions_license_search",
        nonce: config.licenseSearchNonce || "",
        term,
        license_number: licenseNumber,
        birth_date: birthDate,
      });

      try {
        const response = await fetch(config.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=utf-8",
          },
          body: payload.toString(),
        });
        const data = await response.json();
        if (!data || !data.success) {
          setLicenseFeedback(config.labels?.searchError || "", "error");
          return;
        }

        const results = data.data?.results || [];
        if (!results.length) {
          setLicenseFeedback(
            data.data?.message || config.labels?.searchNoResult || "",
            "warning"
          );
          if (licenseSelectForm) {
            licenseSelectForm.style.display = "none";
          }
          return;
        }

        licenseResults = results;
        if (results.length === 1) {
          applyLicensePayload(results[0]);
          setLicenseFeedback(config.labels?.searchOne || "", "success");
          if (licenseSelectForm) {
            licenseSelectForm.style.display = "none";
          }
          return;
        }

        populateLicenseSelect(results);
        setLicenseFeedback(config.labels?.searchMultiple || "", "info");
      } catch (error) {
        setLicenseFeedback(config.labels?.searchError || "", "error");
      }
    });
  }

  if (licenseSelectForm) {
    const select = licenseSelectForm.querySelector("select[name='ufsc_license_id']");
    licenseSelectForm.addEventListener("submit", (event) => {
      if (!select || !select.value || !licenseResults.length) {
        return;
      }
      event.preventDefault();
      const selected = licenseResults.find(
        (item) => String(item.id) === String(select.value)
      );
      if (selected) {
        applyLicensePayload(selected);
      }
      setLicenseFeedback(config.labels?.searchOne || "", "success");
    });
  }
})();
