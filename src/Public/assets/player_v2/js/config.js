/**
 * Config
 * -------------------------------------------------------------------------------------
 * ! IMPORTANT: Make sure you clear the browser local storage In order to see the config changes in the template.
 * ! To clear local storage: (https://www.leadshook.com/help/how-to-clear-local-storage-in-google-chrome-browser/).
 */

'use strict';
/* JS global variables
 !Please use the hex color code (#000) here. Don't use rgba(), hsl(), etc
*/
window.config = {
  // global color variables for charts except chartjs
  colors: {
    primary: window.Helpers.getCssVar('primary'),
    secondary: window.Helpers.getCssVar('secondary'),
    success: window.Helpers.getCssVar('success'),
    info: window.Helpers.getCssVar('info'),
    warning: window.Helpers.getCssVar('warning'),
    danger: window.Helpers.getCssVar('danger'),
    dark: window.Helpers.getCssVar('dark'),
    black: window.Helpers.getCssVar('pure-black'),
    white: window.Helpers.getCssVar('white'),
    cardColor: window.Helpers.getCssVar('paper-bg'),
    bodyBg: window.Helpers.getCssVar('body-bg'),
    bodyColor: window.Helpers.getCssVar('body-color'),
    headingColor: window.Helpers.getCssVar('heading-color'),
    textMuted: window.Helpers.getCssVar('secondary-color'),
    borderColor: window.Helpers.getCssVar('border-color')
  },
  colors_label: {
    primary: window.Helpers.getCssVar('primary-bg-subtle'),
    secondary: window.Helpers.getCssVar('secondary-bg-subtle'),
    success: window.Helpers.getCssVar('success-bg-subtle'),
    info: window.Helpers.getCssVar('info-bg-subtle'),
    warning: window.Helpers.getCssVar('warning-bg-subtle'),
    danger: window.Helpers.getCssVar('danger-bg-subtle'),
    dark: window.Helpers.getCssVar('dark-bg-subtle')
  },
  fontFamily: window.Helpers.getCssVar('font-family-base'),
  enableMenuLocalStorage: true // Enable menu state with local storage support
};

window.assetsPath = document.documentElement.getAttribute('data-assets-path');
window.templateName = document.documentElement.getAttribute('data-template');

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
 * defaultPrimaryColor: '#7367F0' | Set default primary color
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

if (typeof TemplateCustomizer !== 'undefined') {
  window.templateCustomizer = new TemplateCustomizer({
    displayCustomizer: true,
    lang: localStorage.getItem('templateCustomizer-' + templateName + '--Lang') || 'en', // Set default language here
    // defaultPrimaryColor: '#D11BB4',
    // defaultSkin: 1,
    // defaultTheme: 'system',
    // defaultSemiDark: true,
    // defaultContentLayout: 'wide',
    // defaultHeaderType: 'static',
    // defaultMenuCollapsed: true,
    // defaultNavbarType: 'static',
    // defaultTextDir: 'rtl',
    // defaultFooterFixed: false,
    // defaultShowDropdownOnHover: false,
    controls: [
      'color',
      'theme',
      'skins',
      'semiDark',
      'layoutCollapsed',
      'layoutNavbarOptions',
      'headerType',
      'contentLayout',
      'rtl'
    ]
  });
}
