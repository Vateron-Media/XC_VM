/**
 * Config
 * -------------------------------------------------------------------------------------
 * ! IMPORTANT: Make sure you clear the browser local storage In order to see the config changes in the template.
 * ! To clear local storage: (https://www.leadshook.com/help/how-to-clear-local-storage-in-google-chrome-browser/).
 */

"use strict";
/* JS global variables
 !Please use the hex color code (#000) here. Don't use rgba(), hsl(), etc
*/
window.config = {
	// global color variables for charts except chartjs
	colors: {
		primary: window.Helpers.getCssVar("primary"),
		secondary: window.Helpers.getCssVar("secondary"),
		success: window.Helpers.getCssVar("success"),
		info: window.Helpers.getCssVar("info"),
		warning: window.Helpers.getCssVar("warning"),
		danger: window.Helpers.getCssVar("danger"),
		dark: window.Helpers.getCssVar("dark"),
		black: window.Helpers.getCssVar("pure-black"),
		white: window.Helpers.getCssVar("white"),
		cardColor: window.Helpers.getCssVar("paper-bg"),
		bodyBg: window.Helpers.getCssVar("body-bg"),
		bodyColor: window.Helpers.getCssVar("body-color"),
		headingColor: window.Helpers.getCssVar("heading-color"),
		textMuted: window.Helpers.getCssVar("secondary-color"),
		borderColor: window.Helpers.getCssVar("border-color"),
	},
	colors_label: {
		primary: window.Helpers.getCssVar("primary-bg-subtle"),
		secondary: window.Helpers.getCssVar("secondary-bg-subtle"),
		success: window.Helpers.getCssVar("success-bg-subtle"),
		info: window.Helpers.getCssVar("info-bg-subtle"),
		warning: window.Helpers.getCssVar("warning-bg-subtle"),
		danger: window.Helpers.getCssVar("danger-bg-subtle"),
		dark: window.Helpers.getCssVar("dark-bg-subtle"),
	},
	fontFamily: window.Helpers.getCssVar("font-family-base"),
	enableMenuLocalStorage: true, // Enable menu state with local storage support
};

window.assetsPath = document.documentElement.getAttribute("data-assets-path");
window.templateName = document.documentElement.getAttribute("data-template");

/**
 * XC_VM: admin UI customizer config (project defaults + per-user persistence).
 * -----------------------------------------------------------------------------------------------
 * - window.XC_VM_UIDefaults  : the DEFAULT look for EVERY panel (edit this block to
 *                              change the out-of-the-box design for all installs).
 * - window.XC_VM_UIPrefs     : the current user's saved settings, injected by the
 *                              AdminUI shell (header.vuexy.php); {} when the user has none.
 * Effective config = defaults overridden by the user's saved prefs. It primes the
 * customizer's localStorage on every load (server-authoritative) and any change is
 * persisted back to ./api?action=save_ui_prefs.
 *
 * >>> To change the default design for ALL panels, edit XC_VM_UIDefaults below. <<<
 */
window.XC_VM_UIDefaults = window.XC_VM_UIDefaults || {
	theme: "light", // 'light' | 'dark' | 'system'
	skin: "default", // 'default', 'bordered'
	color: "#FFAB1D", // primary color (hex)
	semiDark: true, // dark sidebar with light content
	layoutCollapsed: false, // collapsed vertical menu
	navbar: "static", // 'sticky' | 'static' | 'hidden'
	headerType: "fixed",
	contentLayout: "wide", // 'compact' (boxed) | 'wide' (fluid)
	rtl: false,
	lang: "en",
};

// Server pref key -> TemplateCustomizer localStorage suffix.
window.XC_VM_UIPrefsLS = {
	theme: "Theme",
	color: "Color",
	skin: "Skin",
	semiDark: "SemiDark",
	layoutCollapsed: "LayoutCollapsed",
	navbar: "FixedNavbarOption",
	headerType: "HeaderType",
	contentLayout: "contentLayout",
	rtl: "Rtl",
	lang: "Lang",
};

// Effective config = project defaults, overridden by the user's saved prefs.
window.XC_VM_UIEffective = Object.assign({}, window.XC_VM_UIDefaults, window.XC_VM_UIPrefs || {});

// Normalize skin to the localStorage-native NAME ('default'/'bordered'), so legacy
// numeric values (0/1) from older saves round-trip correctly.
(function (eff) {
	if (eff.skin === 1 || eff.skin === "1" || eff.skin === "bordered") eff.skin = "bordered";
	else if (eff.skin !== undefined) eff.skin = "default";
})(window.XC_VM_UIEffective);

(function () {
	var eff = window.XC_VM_UIEffective;
	var LS = window.XC_VM_UIPrefsLS;
	try {
		var base = "templateCustomizer-" + window.templateName + "--";
		Object.keys(LS).forEach(function (k) {
			if (eff[k] !== undefined && eff[k] !== null) {
				localStorage.setItem(base + LS[k], String(eff[k]));
			}
		});
	} catch (e) {
		/* private mode / storage disabled — customizer falls back to defaults */
	}
})();

/**
 * TemplateCustomizer
 * ! You must use(include) template-customizer.js to use TemplateCustomizer settings
 * -----------------------------------------------------------------------------------------------
 */

/**
 * TemplateCustomizer settings
 * -------------------------------------------------------------------------------------
 * displayCustomizer: true(Show customizer), false(Hide customizer)
 * lang: To set default language, Add more languages and set default. Fallback language is 'en'
 * defaultPrimaryColor: '#FFAB1D' | Set default primary color
 * defaultSkin: 0(Default), 1(Bordered)
 * defaultTheme: 'light', 'dark', 'system'
 * defaultSemiDark: true, false (For dark menu only)
 * defaultContentLayout: 'compact', 'wide' (compact=container-xxl, wide=container-fluid)
 * defaultHeaderType: 'static', 'fixed' (for horizontal layout only)
 * defaultMenuCollapsed: true, false (For vertical layout only)
 * defaultNavbarType: 'sticky', 'static', 'hidden' (For vertical layout only)
 * defaultTextDir: 'ltr', 'rtl' (Direction)
 * defaultFooterFixed: true, false (For vertical layout only)
 * defaultShowDropdownOnHover : true, false (for horizontal layout only)
 * controls: [ 'color', 'theme', 'skins', 'semiDark', 'layoutCollapsed', 'layoutNavbarOptions', 'headerType', 'contentLayout', 'rtl' ] | Show/Hide customizer controls
 */

if (typeof TemplateCustomizer !== "undefined") {
	var xcEff = window.XC_VM_UIEffective || {};
	var xcToBool = function (v) {
		return v === true || v === "true";
	};

	// Read the LIVE customizer state from localStorage. The customizer writes every
	// change there (its `settings` object stays at the initial/default values), so
	// localStorage is the source of truth. Values come back as strings.
	var xcReadLive = function () {
		var base = "templateCustomizer-" + window.templateName + "--";
		var LS = window.XC_VM_UIPrefsLS;
		var raw = function (suffix) {
			try {
				return localStorage.getItem(base + suffix);
			} catch (e) {
				return null;
			}
		};
		var bool = function (v) {
			return v === "true" ? true : v === "false" ? false : undefined;
		};
		var out = {};
		var theme = raw(LS.theme);
		if (theme !== null) out.theme = theme;
		var color = raw(LS.color);
		if (color !== null) out.color = color;
		var skin = raw(LS.skin); // customizer stores the skin NAME ('default'/'bordered')
		if (skin !== null) out.skin = skin;
		var semiDark = bool(raw(LS.semiDark));
		if (semiDark !== undefined) out.semiDark = semiDark;
		var collapsed = bool(raw(LS.layoutCollapsed));
		if (collapsed !== undefined) out.layoutCollapsed = collapsed;
		var navbar = raw(LS.navbar);
		if (navbar !== null) out.navbar = navbar;
		var headerType = raw(LS.headerType);
		if (headerType !== null) out.headerType = headerType;
		var contentLayout = raw(LS.contentLayout);
		if (contentLayout !== null) out.contentLayout = contentLayout;
		var rtl = bool(raw(LS.rtl));
		if (rtl !== undefined) out.rtl = rtl;
		var lang = raw(LS.lang);
		if (lang !== null) out.lang = lang;
		return out;
	};

	// Debounced persistence of the LIVE customizer state to the server (per user).
	var xcSaveTimer = null;
	var xcSaveUiPrefs = function () {
		var url = (window.XC_VM && window.XC_VM.uiPrefsUrl) || "";
		if (!url) return;
		var payload = xcReadLive();
		clearTimeout(xcSaveTimer);
		xcSaveTimer = setTimeout(function () {
			fetch(url, {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					"X-Requested-With": "XMLHttpRequest",
				},
				body: JSON.stringify(payload),
			}).catch(function () {
				/* keep localStorage as the offline fallback */
			});
		}, 600);
	};

	window.templateCustomizer = new TemplateCustomizer({
		displayCustomizer: true,
		lang:
			localStorage.getItem("templateCustomizer-" + templateName + "--Lang") ||
			xcEff.lang ||
			"en",
		defaultPrimaryColor: xcEff.color || undefined,
		defaultSkin: xcEff.skin === "bordered" ? 1 : 0,
		defaultTheme: xcEff.theme || undefined,
		defaultSemiDark: xcToBool(xcEff.semiDark),
		defaultContentLayout: xcEff.contentLayout || undefined,
		defaultHeaderType: xcEff.headerType || undefined,
		defaultMenuCollapsed: xcToBool(xcEff.layoutCollapsed),
		defaultNavbarType: xcEff.navbar || undefined,
		defaultTextDir: xcToBool(xcEff.rtl) ? "rtl" : undefined,
		onSettingsChange: xcSaveUiPrefs,
		controls: [
			"color",
			"theme",
			"skins",
			"semiDark",
			"layoutCollapsed",
			"layoutNavbarOptions",
			"headerType",
			"contentLayout",
			"rtl",
		],
	});

	// onSettingsChange only fires for SOME controls (layout/navbar/rtl/lang). Theme,
	// color, skin, semiDark and menu-collapse do NOT trigger it — but every control
	// writes a `templateCustomizer-*` localStorage key, so hook that to reliably
	// persist ALL changes. Installed AFTER construction so priming/init writes don't save.
	try {
		var xcOrigSet = localStorage.setItem.bind(localStorage);
		localStorage.setItem = function (k, v) {
			xcOrigSet(k, v);
			if (
				typeof k === "string" &&
				k.indexOf("templateCustomizer-") === 0 &&
				window.templateCustomizer
			) {
				xcSaveUiPrefs();
			}
		};
	} catch (e) {
		/* storage unavailable — onSettingsChange still covers layout controls */
	}

	// The customizer's "Reset" clears localStorage and reloads — but our priming
	// would immediately restore the saved server prefs on that reload. So make Reset
	// also clear the server-side prefs (synchronously, before the reload) so the
	// panel falls back to XC_VM_UIDefaults.
	if (typeof window.templateCustomizer.clearLocalStorage === "function") {
		var xcOrigClear = window.templateCustomizer.clearLocalStorage.bind(
			window.templateCustomizer
		);
		window.templateCustomizer.clearLocalStorage = function () {
			var url = (window.XC_VM && window.XC_VM.uiPrefsUrl) || "";
			if (url) {
				try {
					var xhr = new XMLHttpRequest();
					xhr.open("POST", url, false); // synchronous: must finish before reload
					xhr.setRequestHeader("Content-Type", "application/json");
					xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
					xhr.send(JSON.stringify({ __reset: true }));
				} catch (e) {
					/* ignore — reset still clears localStorage below */
				}
			}
			return xcOrigClear();
		};
	}
}
