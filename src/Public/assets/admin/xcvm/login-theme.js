/* Read panel preferences without starting the authenticated customizer. */
(function () {
	"use strict";
	var root = document.documentElement;
	var prefix = "templateCustomizer-vertical-menu-template--";
	var themePreference = "light";
	var systemTheme = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;

	function applyTheme() {
		var theme = themePreference;
		if (theme === "system") {
			theme = systemTheme && systemTheme.matches ? "dark" : "light";
		}
		root.setAttribute("data-bs-theme", theme);
	}

	function applyPreferences() {
		var theme = null;
		var color = null;
		try {
			theme = localStorage.getItem(prefix + "Theme");
			color = localStorage.getItem(prefix + "Color");
		} catch (error) {
			// Storage may be unavailable before login; keep the panel defaults.
		}
		themePreference = ["light", "dark", "system"].indexOf(theme) !== -1 ? theme : "light";
		applyTheme();
		if (color && /^#[0-9a-f]{6}$/i.test(color)) {
			root.style.setProperty("--bs-primary", color);
			root.style.setProperty("--bs-primary-rgb", [1, 3, 5].map(function (index) {
				return parseInt(color.slice(index, index + 2), 16);
			}).join(", "));
		} else {
			root.style.removeProperty("--bs-primary");
			root.style.removeProperty("--bs-primary-rgb");
		}
	}

	applyPreferences();
	window.addEventListener("storage", function (event) {
		if (event.key === null || event.key === prefix + "Theme" || event.key === prefix + "Color") {
			applyPreferences();
		}
	});
	if (systemTheme) {
		var onSystemThemeChange = function () {
			if (themePreference === "system") {
				applyTheme();
			}
		};
		if (systemTheme.addEventListener) {
			systemTheme.addEventListener("change", onSystemThemeChange);
		} else if (systemTheme.addListener) {
			systemTheme.addListener(onSystemThemeChange);
		}
	}
})();
