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

  if (!birthInput || !weightInput || !categoryInput) {
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

  let timeout;
  const debounce = (fn, delay = 400) => {
    clearTimeout(timeout);
    timeout = setTimeout(fn, delay);
  };

  const getValue = (input) =>
    input && typeof input.value !== "undefined" ? input.value.trim() : "";

  const shouldCompute = () => getValue(birthInput) !== "";

  const applyCategory = (label) => {
    if (!label) {
      return;
    }
    if (categoryInput.tagName === "SELECT") {
      const option = Array.from(categoryInput.options).find(
        (opt) => opt.value === label
      );
      if (option) {
        categoryInput.value = label;
      }
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
      birth_date: getValue(birthInput),
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

      const data = await response.json();
      if (!data || !data.success) {
        setStatus(data?.data?.message || config.labels?.error || "", "error");
        setWeightStatus(data?.data?.message || config.labels?.weightMissing || "", "error");
        return;
      }

      const label = data.data?.label || "";
      const weightClass = data.data?.weight_class || "";
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
  };

  bindInput(birthInput);
  bindInput(weightInput);
  bindInput(sexInput);
  bindInput(levelInput);

  if (getValue(birthInput)) {
    debounce(computeCategory, 50);
  }
})();
