(function () {
	"use strict";

	document.addEventListener("DOMContentLoaded", function () {
		var details = document.querySelectorAll("details[data-ufsc-storage]");
		if (!details.length) {
			return;
		}

		details.forEach(function (item) {
			var key = item.getAttribute("data-ufsc-storage");
			if (!key) {
				return;
			}

			try {
				var stored = localStorage.getItem(key);
				if (stored === "open") {
					item.setAttribute("open", "open");
				}
			} catch (e) {
				// Ignore storage errors.
			}

			item.addEventListener("toggle", function () {
				try {
					localStorage.setItem(key, item.open ? "open" : "closed");
				} catch (e) {
					// Ignore storage errors.
				}
			});
		});
	});
})();
