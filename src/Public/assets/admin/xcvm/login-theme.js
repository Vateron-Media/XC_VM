/* Read panel preferences without starting the authenticated customizer. */
(function () {
	"use strict";
	var root = document.documentElement;
	try {
		var prefix = "templateCustomizer-vertical-menu-template--";
		var theme = localStorage.getItem(prefix + "Theme");
		if (theme === "system") {
			theme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
		}
		if (theme === "dark" || theme === "light") {
			root.setAttribute("data-bs-theme", theme);
		}
		var color = localStorage.getItem(prefix + "Color");
		if (color && /^#[0-9a-f]{6}$/i.test(color)) {
			root.style.setProperty("--bs-primary", color);
			root.style.setProperty("--bs-primary-rgb", [1, 3, 5].map(function (index) {
				return parseInt(color.slice(index, index + 2), 16);
			}).join(", "));
		}
	} catch (error) {
		// Storage may be unavailable before login; keep the panel's light default.
	}
})();
