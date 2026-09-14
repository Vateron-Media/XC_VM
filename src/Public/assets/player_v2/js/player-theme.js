/**
 * Web Player V2 — Theme & Day/Night Mode Controller
 *
 * Fully compliant with Sneat Bootstrap 5 and localStorage persistence.
 * Zero-FOUC, smooth CSS transitions, multi-tab sync & Alt+T shortcut.
 */

'use strict';

window.PlayerTheme = (function () {
  const STORAGE_KEY = 'templateCustomizer-vertical-menu-template--Theme';

  const iconMap = {
    light: 'bx-sun',
    dark: 'bx-moon',
    system: 'bx-desktop'
  };

  const labelMap = {
    light: 'Light',
    dark: 'Dark',
    system: 'System'
  };

  const getStoredTheme = () => {
    try {
      return localStorage.getItem(STORAGE_KEY) || 'dark';
    } catch (e) {
      return 'dark';
    }
  };

  const setStoredTheme = (theme) => {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {}
  };

  const resolveTheme = (theme) => {
    if (theme === 'system') {
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    return theme === 'light' ? 'light' : 'dark';
  };

  const showActiveTheme = (theme) => {
    const resolved = resolveTheme(theme);
    const iconClass = iconMap[theme] || 'bx-moon';

    // Update active icon on all theme switcher triggers
    document.querySelectorAll('.theme-icon-active').forEach((iconEl) => {
      iconEl.className = 'icon-base bx ' + iconClass + ' icon-md theme-icon-active text-heading';
      iconEl.classList.add('rotate-180');
      setTimeout(() => iconEl.classList.remove('rotate-180'), 400);
    });

    // Update buttons in dropdowns
    document.querySelectorAll('[data-bs-theme-value]').forEach((btn) => {
      const val = btn.getAttribute('data-bs-theme-value');
      const isActive = val === theme;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');

      const check = btn.querySelector('.theme-check');
      if (check) {
        if (isActive) {
          check.classList.remove('d-none');
        } else {
          check.classList.add('d-none');
        }
      }
    });

    // Update current theme label if exists
    const currentLabelEl = document.getElementById('current-theme-label');
    if (currentLabelEl) {
      currentLabelEl.textContent = labelMap[theme] || theme;
    }
  };

  const setTheme = (theme, withTransition = true) => {
    const root = document.documentElement;

    if (withTransition) {
      root.classList.add('theme-transition');
    }

    const resolved = resolveTheme(theme);
    root.setAttribute('data-bs-theme', resolved);
    setStoredTheme(theme);
    showActiveTheme(theme);

    if (withTransition) {
      setTimeout(() => {
        root.classList.remove('theme-transition');
      }, 400);
    }
  };

  const toggleTheme = () => {
    const current = getStoredTheme();
    const cycle = ['dark', 'light', 'system'];
    const next = cycle[(cycle.indexOf(current) + 1) % cycle.length];
    setTheme(next, true);
  };

  // Initialize
  const init = () => {
    const current = getStoredTheme();
    setTheme(current, false);

    // Click handler for all [data-bs-theme-value]
    document.addEventListener('click', (e) => {
      const toggle = e.target.closest('[data-bs-theme-value]');
      if (!toggle) return;
      e.preventDefault();
      const selected = toggle.getAttribute('data-bs-theme-value');
      if (selected) {
        setTheme(selected, true);
      }
    });

    // Cross-tab synchronization
    window.addEventListener('storage', (e) => {
      if (e.key === STORAGE_KEY && e.newValue) {
        setTheme(e.newValue, true);
      }
    });

    // Dynamic OS color-scheme changes for 'system'
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (getStoredTheme() === 'system') {
        setTheme('system', true);
      }
    });

    // Keyboard shortcut Alt + T
    document.addEventListener('keydown', (e) => {
      if (e.altKey && (e.key === 't' || e.key === 'T' || e.code === 'KeyT')) {
        e.preventDefault();
        toggleTheme();
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {
    getStoredTheme,
    setTheme,
    toggleTheme,
    resolveTheme
  };
})();

/**
 * Global User Client Cache Purge
 * Removes session-specific user data (favorites, continue watching, playback positions)
 * while safely preserving saved accounts and theme settings.
 */
window.clearUserClientData = function () {
  const preservedKeys = ['xc_player_v2_saved_accounts', 'xc_player_v2_theme'];
  try {
    const allKeys = Object.keys(localStorage);
    allKeys.forEach(function (k) {
      if (k.startsWith('xc_player_v2_') && preservedKeys.indexOf(k) === -1) {
        localStorage.removeItem(k);
      }
    });
  } catch (e) {}

  try {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.clear();
    }
  } catch (e) {}
};

// If URL has ?logged_out=1, wipe previous client data automatically
(function () {
  try {
    if (typeof window !== 'undefined' && window.location && window.location.search) {
      const params = new URLSearchParams(window.location.search);
      if (params.has('logged_out')) {
        window.clearUserClientData();
        params.delete('logged_out');
        const cleanQuery = params.toString() ? '?' + params.toString() : '';
        window.history.replaceState({}, '', window.location.pathname + cleanQuery);
      }
    }
  } catch (e) {}
})();

