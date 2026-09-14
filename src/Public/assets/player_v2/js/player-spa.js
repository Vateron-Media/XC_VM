/**
 * Web Player V2 — Universal Single Page Application (SPA) Engine & Client Router
 *
 * Implements seamless, zero-reload navigation across all Web Player V2 pages:
 * - Dashboard (index), Live TV, Movies, Series, Movie & Series Details,
 *   Cinema Video Player, Radio, Profile, Favorites, and Cross-Catalog Search.
 *
 * Features:
 * - Link & GET form interception with History API pushState/popstate.
 * - Top loading progress bar with Sneat primary gradient styling.
 * - Inserts HTML into live DOM before running scripts so elements are always found.
 * - DOMContentLoaded interception so inline page scripts execute immediately.
 * - Dedicated page-module hooks (PlayerHome, MoviesApp, SeriesApp, LivePlayerApp, RadioApp, PlayerFavorites, PlayerProfile).
 * - Automatic radio closure whenever any movie/video/stream plays.
 * - Persistent audio preservation so live radio streams play uninterrupted while browsing.
 */

'use strict';

window.SPA = (function () {
  const config = {
    contentSelector: '#spa-content-target',
    progressBarId: 'spa-progress-bar',
    menuSelector: '#layout-menu',
    timeout: 15000
  };

  const state = {
    baseUrl: '/',
    currentUrl: window.location.href,
    isNavigating: false,
    loadedScripts: new Set(),
    loadedStyles: new Set(),
    progressTimer: null
  };

  /**
   * Determine base URL from documentElement attribute or fallback.
   */
  const getBaseUrl = () => {
    return document.documentElement.getAttribute('data-base-url') || '/';
  };

  /**
   * Top Progress Bar Controller
   */
  const Progress = {
    getEl: () => document.getElementById(config.progressBarId),
    start: () => {
      const bar = Progress.getEl();
      if (!bar) return;
      clearTimeout(state.progressTimer);
      bar.style.transition = 'width 0.2s ease, opacity 0.2s ease';
      bar.style.opacity = '1';
      bar.style.width = '15%';

      state.progressTimer = setTimeout(() => {
        bar.style.width = '65%';
      }, 150);
    },
    done: () => {
      const bar = Progress.getEl();
      if (!bar) return;
      clearTimeout(state.progressTimer);
      bar.style.width = '100%';
      setTimeout(() => {
        bar.style.opacity = '0';
        setTimeout(() => {
          bar.style.width = '0%';
        }, 300);
      }, 200);
    },
    fail: () => {
      const bar = Progress.getEl();
      if (!bar) return;
      clearTimeout(state.progressTimer);
      bar.classList.add('bg-danger');
      bar.style.width = '100%';
      setTimeout(() => {
        bar.style.opacity = '0';
        setTimeout(() => {
          bar.style.width = '0%';
          bar.classList.remove('bg-danger');
        }, 300);
      }, 400);
    }
  };

  /**
   * Stop and close radio player completely.
   */
  const stopRadio = () => {
    if (window.RadioApp && typeof window.RadioApp.stopPlayer === 'function') {
      window.RadioApp.stopPlayer();
    } else {
      const audio = document.getElementById('radio-audio');
      if (audio) {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
      }
      const bar = document.getElementById('radio-bottom-bar');
      if (bar) {
        bar.classList.add('d-none');
        bar.classList.remove('d-flex');
        bar.style.display = 'none';
      }
    }
  };

  /**
   * Pre-navigation cleanup: stop video playback and remove dangling overlays.
   */
  const cleanupCurrentPage = (targetUrl) => {
    const container = document.querySelector(config.contentSelector);
    if (container) {
      // Dispose Video.js players inside container
      if (window.videojs) {
        container.querySelectorAll('.video-js').forEach((vEl) => {
          try {
            const player = window.videojs(vEl.id || vEl);
            if (player && !player.isDisposed()) {
              player.dispose();
            }
          } catch (e) {}
        });
      }

      // Stop native HTML5 video players inside container (cinema / previews)
      container.querySelectorAll('video').forEach((v) => {
        try {
          v.pause();
          v.removeAttribute('src');
          v.load();
        } catch (e) {}
      });

      // Clear non-persistent audio tags inside container
      container.querySelectorAll('audio').forEach((a) => {
        if (a.id !== 'radio-audio') {
          try {
            a.pause();
            a.removeAttribute('src');
            a.load();
          } catch (e) {}
        }
      });
    }

    // If navigating to a video playback page (Cinema player, Live TV), stop the radio
    try {
      const u = new URL(targetUrl, window.location.origin);
      const pathname = u.pathname.toLowerCase();
      if (pathname.endsWith('/player') || pathname.endsWith('/watch') || pathname.endsWith('/live')) {
        stopRadio();
      }
    } catch (e) {}

    // Clean up modals and tooltips
    document.querySelectorAll('.modal-backdrop, .offcanvas-backdrop').forEach((el) => el.remove());
    document.querySelectorAll('.tooltip, .popover').forEach((el) => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');

    // Close instant search dropdown if open
    const searchDropdown = document.getElementById('global-search-dropdown');
    if (searchDropdown) {
      searchDropdown.style.display = 'none';
    }
  };

  /**
   * Update active menu item in the Sneat sidebar (#layout-menu).
   */
  const updateActiveMenuItem = (page, targetUrl) => {
    const menu = document.querySelector(config.menuSelector);
    if (!menu) return;

    // Normalize page identifiers and aliases
    let activeKey = (page || '').toLowerCase();
    if (activeKey === 'movie') activeKey = 'movies';
    if (activeKey === 'series_detail' || activeKey === 'episodes') activeKey = 'series';
    if (activeKey === 'player' || activeKey === 'watch') {
      try {
        const u = new URL(targetUrl, window.location.origin);
        const type = u.searchParams.get('type');
        if (type === 'movie') activeKey = 'movies';
        else if (type === 'series') activeKey = 'series';
        else if (type === 'live') activeKey = 'live';
      } catch (e) {}
    }

    // Remove active and open classes from all menu items
    menu.querySelectorAll('.menu-item').forEach((item) => {
      item.classList.remove('active', 'open');
    });

    // Find and highlight matching menu item
    let matchedItem = null;
    menu.querySelectorAll('.menu-link').forEach((link) => {
      const href = link.getAttribute('href') || '';
      const hrefPage = href.split('?')[0].split('/').filter(Boolean).pop();
      if (hrefPage === activeKey) {
        matchedItem = link.closest('.menu-item');
      }
    });

    if (matchedItem) {
      matchedItem.classList.add('active');
    }

    // Collapse mobile drawer if open
    if (window.Helpers && typeof window.Helpers.isSmallScreen === 'function' && window.Helpers.isSmallScreen()) {
      window.Helpers.setCollapsed(true);
    }
  };

  /**
   * Dynamically load external scripts sequentially.
   */
  const loadExternalScripts = async (scriptUrls) => {
    for (const src of scriptUrls) {
      if (!src) continue;
      // If already loaded in document, mark and skip
      if (state.loadedScripts.has(src) || document.querySelector(`script[src="${src}"]`)) {
        state.loadedScripts.add(src);
        continue;
      }

      await new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = src;
        s.async = false;
        s.onload = () => {
          state.loadedScripts.add(src);
          resolve();
        };
        s.onerror = () => {
          console.warn('SPA failed to load script:', src);
          resolve();
        };
        document.body.appendChild(s);
      });
    }
  };

  /**
   * Re-initialize page-specific modules (fail-safe for all pages).
   */
  const reinitPageModules = (page, targetUrl) => {
    const pageKey = (page || '').toLowerCase();

    // 1. Dashboard (index)
    if (pageKey === 'index' || pageKey === 'home') {
      if (window.PlayerHome && typeof window.PlayerHome.init === 'function') {
        window.PlayerHome.init();
      }
    }
    // 2. Favorites Vault
    else if (pageKey === 'favorites') {
      if (window.PlayerFavorites && typeof window.PlayerFavorites.init === 'function') {
        window.PlayerFavorites.init();
      }
    }
    // 3. Profile & Account Settings
    else if (pageKey === 'profile') {
      if (window.PlayerProfile && typeof window.PlayerProfile.init === 'function') {
        window.PlayerProfile.init();
      }
    }
    // 4. Movies Catalog (if inline script didn't run)
    else if (pageKey === 'movies') {
      if (window.MoviesApp && typeof window.MoviesApp.start === 'function') {
        const catList = document.getElementById('movies-categories-list');
        if (catList && !catList.dataset.appInitialized) {
          catList.dataset.appInitialized = '1';
          window.MoviesApp.start({ baseUrl: state.baseUrl });
        }
      }
    }
    // 5. TV Series Catalog (if inline script didn't run)
    else if (pageKey === 'series') {
      if (window.SeriesApp && typeof window.SeriesApp.start === 'function') {
        const catList = document.getElementById('series-categories-list');
        if (catList && !catList.dataset.appInitialized) {
          catList.dataset.appInitialized = '1';
          window.SeriesApp.start({ baseUrl: state.baseUrl });
        }
      }
    }
    // 6. Live TV (if inline script didn't run)
    else if (pageKey === 'live') {
      const liveApp = window.LivePlayerApp || window.LiveApp;
      if (liveApp && typeof liveApp.start === 'function') {
        const catList = document.getElementById('live-categories-list');
        if (catList && !catList.dataset.appInitialized) {
          catList.dataset.appInitialized = '1';
          liveApp.start({ baseUrl: state.baseUrl });
        }
      }
    }
    // 7. Radio Stations (if inline script didn't run)
    else if (pageKey === 'radio') {
      if (window.RadioApp && typeof window.RadioApp.start === 'function') {
        const catList = document.getElementById('radio-categories-list');
        if (catList && !catList.dataset.appInitialized) {
          catList.dataset.appInitialized = '1';
          window.RadioApp.start({ baseUrl: state.baseUrl });
        }
      }
    }

    // Always re-align persistent radio bottom bar strictly to .layout-page
    if (window.RadioApp && typeof window.RadioApp.updateBottomBarPosition === 'function') {
      window.RadioApp.updateBottomBarPosition();
    }

    // Trigger theme and helpers
    if (window.Helpers && typeof window.Helpers.initPasswordToggle === 'function') {
      window.Helpers.initPasswordToggle();
    }
    if (window.PlayerFavorites && typeof window.PlayerFavorites.updateGlobalNavbarBadges === 'function') {
      window.PlayerFavorites.updateGlobalNavbarBadges();
    }

    // Trigger synthetic navigation events
    window.dispatchEvent(new CustomEvent('spa:navigated', {
      detail: { page, url: targetUrl }
    }));
    window.dispatchEvent(new Event('resize'));
  };

  /**
   * Main SPA Navigation Router.
   */
  const navigate = async (url, pushState = true) => {
    if (state.isNavigating) return;

    const targetUrl = new URL(url, window.location.origin).href;
    const container = document.querySelector(config.contentSelector);
    if (!container) {
      window.location.href = targetUrl;
      return;
    }

    state.isNavigating = true;
    Progress.start();

    try {
      // Pre-cleanup before fetch
      cleanupCurrentPage(targetUrl);

      // Initiate SPA fetch
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), config.timeout);

      const response = await fetch(targetUrl, {
        method: 'GET',
        headers: {
          'X-SPA-Request': '1',
          'X-Requested-With': 'XMLHttpRequest'
        },
        signal: controller.signal
      });

      clearTimeout(timeoutId);

      // Check if server redirected (e.g., to login)
      if (response.redirected || response.status === 401 || response.status === 403) {
        window.location.href = response.url || targetUrl;
        return;
      }

      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        // Fallback to standard navigation if non-JSON received
        window.location.href = targetUrl;
        return;
      }

      const data = await response.json();
      if (!data || !data.success) {
        throw new Error(data.message || 'SPA request failed');
      }

      // Update Browser History
      if (pushState) {
        window.history.pushState({ spa: true, url: targetUrl }, data.title, targetUrl);
      }
      state.currentUrl = targetUrl;

      // Update Document Title
      if (data.title) {
        document.title = data.title;
      }

      // Update Sidebar Menu Active Item
      updateActiveMenuItem(data.page, targetUrl);

      // Parse incoming HTML
      const temp = document.createElement('div');
      temp.innerHTML = data.html || '';

      // 1. Extract and append new stylesheets
      const links = Array.from(temp.querySelectorAll('link[rel="stylesheet"]'));
      links.forEach((link) => {
        const href = link.getAttribute('href');
        if (href && !document.querySelector(`link[href="${href}"]`)) {
          const newLink = document.createElement('link');
          newLink.rel = 'stylesheet';
          newLink.href = href;
          document.head.appendChild(newLink);
          state.loadedStyles.add(href);
        }
        link.remove();
      });

      // 2. Extract scripts from incoming HTML
      const scriptElements = Array.from(temp.querySelectorAll('script'));
      const externalScriptUrls = [];
      const inlineScriptContents = [];

      scriptElements.forEach((s) => {
        if (s.src) {
          externalScriptUrls.push(s.src);
        } else if (s.type === 'application/json') {
          // Keep json in DOM (e.g. hero slides data)
          return;
        } else {
          inlineScriptContents.push(s.textContent || '');
        }
        s.remove();
      });

      // 3. Smooth View Swap: INSERT CLEAN HTML INTO LIVE DOM FIRST!
      container.style.transition = 'opacity 0.15s ease, transform 0.15s ease';
      container.style.opacity = '0.3';
      container.style.transform = 'translateY(-4px)';

      await new Promise((r) => setTimeout(r, 100));

      // Inset HTML into live document so all elements are found by scripts!
      container.innerHTML = temp.innerHTML;

      // Scroll window to top
      window.scrollTo({ top: 0, behavior: 'instant' });

      // Animate container in
      requestAnimationFrame(() => {
        container.style.opacity = '1';
        container.style.transform = 'translateY(0)';
      });

      // 4. Load any missing external scripts
      await loadExternalScripts(externalScriptUrls);

      // 5. Execute inline scripts with DOMContentLoaded interceptor
      const deferredHandlers = [];
      const originalAddEventListener = document.addEventListener;

      document.addEventListener = function (type, listener, options) {
        if (type === 'DOMContentLoaded') {
          deferredHandlers.push(listener);
          return;
        }
        return originalAddEventListener.call(document, type, listener, options);
      };

      // Execute each inline script
      inlineScriptContents.forEach((code) => {
        if (!code.trim()) return;
        try {
          const fn = new Function(code);
          fn();
        } catch (err) {
          console.warn('SPA inline script execution error:', err);
        }
      });

      // Restore original addEventListener
      document.addEventListener = originalAddEventListener;

      // 6. Execute all captured DOMContentLoaded handlers now that elements exist in live document!
      deferredHandlers.forEach((handler) => {
        try {
          handler();
        } catch (err) {
          console.warn('SPA deferred DOMContentLoaded handler error:', err);
        }
      });

      // 7. Explicitly trigger page modules (Dashboard, Favorites, Profile, etc.)
      reinitPageModules(data.page, targetUrl);

      Progress.done();
    } catch (err) {
      console.error('SPA Navigation error:', err);
      Progress.fail();
      if (err.name !== 'AbortError') {
        window.location.href = targetUrl;
      }
    } finally {
      state.isNavigating = false;
    }
  };

  /**
   * Bind event delegation for internal links, search form, and video auto-stop.
   */
  const bindEvents = () => {
    // 1. Intercept Link Clicks
    document.addEventListener('click', (e) => {
      const link = e.target.closest('a');
      if (!link) return;

      const href = link.getAttribute('href');
      if (!href) return;

      // Ignore special links
      if (
        href === '#' ||
        href.startsWith('javascript:') ||
        href.startsWith('mailto:') ||
        href.startsWith('tel:') ||
        link.target === '_blank' ||
        link.hasAttribute('download') ||
        link.hasAttribute('data-no-spa') ||
        link.hasAttribute('data-bs-toggle') ||
        e.ctrlKey ||
        e.metaKey ||
        e.shiftKey ||
        e.altKey
      ) {
        return;
      }

      // Resolve URL
      const targetUrl = new URL(href, window.location.origin);
      if (targetUrl.origin !== window.location.origin) return;

      // Ensure URL is within player_v2 base path
      const base = state.baseUrl.startsWith('/') ? state.baseUrl : '/' + state.baseUrl;
      if (!targetUrl.pathname.startsWith(base)) return;

      // Ignore logout (logout should always do full server redirect)
      if (targetUrl.pathname.endsWith('/logout') || targetUrl.pathname.endsWith('logout')) {
        return;
      }

      // Valid internal route: intercept and navigate
      e.preventDefault();
      navigate(targetUrl.href, true);
    });

    // 2. Intercept ANY Search Form (GET submit from navbar or search page)
    document.addEventListener('submit', (e) => {
      const form = e.target;
      if (!form || typeof form.getAttribute !== 'function') return;
      const action = form.getAttribute('action') || '';
      const method = (form.getAttribute('method') || 'GET').toUpperCase();
      if ((action.endsWith('/search') || action.endsWith('search') || action.includes('search')) && method === 'GET') {
        e.preventDefault();
        const input = form.querySelector('input[name="q"]');
        const q = input ? encodeURIComponent(input.value.trim()) : '';
        if (q) {
          const targetUrl = action.includes('?') ? `${action}&q=${q}` : `${action}?q=${q}`;
          navigate(targetUrl, true);
        }
      }
    });

    // 3. Browser Back / Forward Button Handling
    window.addEventListener('popstate', () => {
      navigate(window.location.href, false);
    });

    // 4. When ANY video plays anywhere on the site, auto-stop and close the radio!
    document.addEventListener('play', (e) => {
      if (e.target && e.target.tagName === 'VIDEO') {
        stopRadio();
      }
    }, true);

    // 5. Safely catch and suppress third-party extension errors (e.g., Web Vitals reportAllChanges / startTime)
    window.addEventListener('error', (event) => {
      const err = event.error;
      const msg = ((event.message || '') + ' ' + (err && err.message ? err.message : '') + ' ' + (err && err.stack ? err.stack : '')).toLowerCase();
      if (msg.includes('starttime') || msg.includes('reportallchanges')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return true;
      }
    }, true);

    // Index initial scripts in DOM
    document.querySelectorAll('script[src]').forEach((s) => {
      if (s.src) state.loadedScripts.add(s.src);
    });
    document.querySelectorAll('link[rel="stylesheet"]').forEach((l) => {
      if (l.href) state.loadedStyles.add(l.href);
    });
  };

  /**
   * Start the SPA router.
   */
  const start = () => {
    state.baseUrl = getBaseUrl();
    state.currentUrl = window.location.href;
    bindEvents();
  };

  // Auto-start on load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  return {
    start,
    navigate,
    stopRadio,
    Progress
  };
})();
