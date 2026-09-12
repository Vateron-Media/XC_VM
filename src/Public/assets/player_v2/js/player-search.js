/**
 * Web Player V2 — Global Search Controller & Live Dropdown Preview
 *
 * Provides real-time cross-catalog search with autocomplete dropdown,
 * keyboard navigation (Ctrl+K, Esc), and instant category routing.
 */

'use strict';

window.PlayerSearch = (function () {
  let debounceTimer = null;
  let activeRequest = null;

  const escapeHtml = (str) => {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  const getBaseUrl = () => {
    return document.documentElement.getAttribute('data-base-url') || '';
  };

  /**
   * Render autocomplete dropdown items.
   */
  const renderDropdownResults = (data, query, dropdownEl) => {
    if (!dropdownEl) return;

    if (!data || data.total === 0) {
      dropdownEl.innerHTML = `
        <div class="p-4 text-center">
          <div class="avatar avatar-md bg-label-secondary rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center">
            <i class="icon-base bx bx-search fs-4 text-secondary"></i>
          </div>
          <p class="text-body-secondary small mb-2">No matching results found for "${escapeHtml(query)}"</p>
          <small class="text-muted">Try checking for typos or using broader keywords.</small>
        </div>
      `;
      dropdownEl.style.display = 'block';
      return;
    }

    const baseUrl = getBaseUrl();
    const results = data.results || {};
    let html = `
      <div class="p-2 border-bottom bg-body-tertiary d-flex align-items-center justify-content-between">
        <span class="small fw-semibold text-heading">
          <i class="icon-base bx bx-search me-1 text-primary"></i> Top Matches for "${escapeHtml(query)}"
        </span>
        <span class="badge bg-primary rounded-pill">${data.total} results</span>
      </div>
      <div class="dropdown-search-scroll" style="max-height: 420px; overflow-y: auto;">
    `;

    // 1. Live TV Matches
    if (results.live && results.live.length > 0) {
      html += `
        <div class="px-3 py-2 text-uppercase text-xs fw-bold text-body-secondary bg-body-secondary bg-opacity-25 d-flex align-items-center gap-1">
          <i class="icon-base bx bx-broadcast text-primary"></i> Live TV Channels (${results.live.length})
        </div>
      `;
      results.live.slice(0, 4).forEach((item) => {
        html += `
          <a href="${baseUrl}${item.play_url}" class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
              <div class="avatar avatar-sm rounded flex-shrink-0 bg-label-primary d-flex align-items-center justify-content-center">
                ${
                  item.icon
                    ? `<img src="${escapeHtml(item.icon)}" alt="${escapeHtml(item.title)}" class="rounded object-fit-contain w-100 h-100" />`
                    : `<i class="icon-base bx bx-tv text-primary"></i>`
                }
              </div>
              <div class="text-truncate">
                <span class="fw-semibold text-heading d-block text-truncate">${escapeHtml(item.title)}</span>
                <small class="text-body-secondary">${escapeHtml(item.category || 'Live')}</small>
              </div>
            </div>
            <span class="badge bg-label-primary rounded-pill flex-shrink-0">Watch Live</span>
          </a>
        `;
      });
    }

    // 2. Movie Matches
    if (results.movies && results.movies.length > 0) {
      html += `
        <div class="px-3 py-2 text-uppercase text-xs fw-bold text-body-secondary bg-body-secondary bg-opacity-25 d-flex align-items-center gap-1 border-top">
          <i class="icon-base bx bx-film text-success"></i> Movies & Cinema (${results.movies.length})
        </div>
      `;
      results.movies.slice(0, 4).forEach((item) => {
        html += `
          <a href="${baseUrl}${item.play_url}" class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
              <div class="avatar avatar-sm rounded flex-shrink-0 bg-label-success d-flex align-items-center justify-content-center">
                ${
                  item.cover
                    ? `<img src="${escapeHtml(item.cover)}" alt="${escapeHtml(item.title)}" class="rounded object-fit-cover w-100 h-100" />`
                    : `<i class="icon-base bx bx-film text-success"></i>`
                }
              </div>
              <div class="text-truncate">
                <span class="fw-semibold text-heading d-block text-truncate">${escapeHtml(item.title)}</span>
                <small class="text-body-secondary">${escapeHtml(item.year || 'Movie')} &bull; ${escapeHtml(item.category || 'VOD')}</small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
              ${item.rating ? `<span class="badge bg-warning text-dark small">★ ${escapeHtml(String(item.rating))}</span>` : ''}
              <span class="badge bg-label-success rounded-pill">Watch</span>
            </div>
          </a>
        `;
      });
    }

    // 3. TV Series Matches
    if (results.series && results.series.length > 0) {
      html += `
        <div class="px-3 py-2 text-uppercase text-xs fw-bold text-body-secondary bg-body-secondary bg-opacity-25 d-flex align-items-center gap-1 border-top">
          <i class="icon-base bx bx-movie-play text-warning"></i> TV Series (${results.series.length})
        </div>
      `;
      results.series.slice(0, 4).forEach((item) => {
        html += `
          <a href="${baseUrl}${item.details_url}" class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
              <div class="avatar avatar-sm rounded flex-shrink-0 bg-label-warning d-flex align-items-center justify-content-center">
                ${
                  item.cover
                    ? `<img src="${escapeHtml(item.cover)}" alt="${escapeHtml(item.title)}" class="rounded object-fit-cover w-100 h-100" />`
                    : `<i class="icon-base bx bx-movie text-warning"></i>`
                }
              </div>
              <div class="text-truncate">
                <span class="fw-semibold text-heading d-block text-truncate">${escapeHtml(item.title)}</span>
                <small class="text-body-secondary">${escapeHtml(item.genre || 'Series')}</small>
              </div>
            </div>
            <span class="badge bg-label-warning rounded-pill flex-shrink-0">${item.seasons_count || 1} Seasons</span>
          </a>
        `;
      });
    }

    // 4. Specific Episode Matches
    if (results.episodes && results.episodes.length > 0) {
      html += `
        <div class="px-3 py-2 text-uppercase text-xs fw-bold text-body-secondary bg-body-secondary bg-opacity-25 d-flex align-items-center gap-1 border-top">
          <i class="icon-base bx bx-play-circle text-danger"></i> Episodes (${results.episodes.length})
        </div>
      `;
      results.episodes.slice(0, 4).forEach((item) => {
        html += `
          <a href="${baseUrl}${item.play_url}" class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
              <div class="avatar avatar-sm rounded flex-shrink-0 bg-label-danger d-flex align-items-center justify-content-center">
                <i class="icon-base bx bx-play text-danger"></i>
              </div>
              <div class="text-truncate">
                <span class="fw-semibold text-heading d-block text-truncate">${escapeHtml(item.title)}</span>
                <small class="text-body-secondary"><span class="badge bg-danger px-1 py-0 me-1">${escapeHtml(item.badge)}</span> ${escapeHtml(item.series_title)}</small>
              </div>
            </div>
            <span class="badge bg-label-danger rounded-pill flex-shrink-0">Play</span>
          </a>
        `;
      });
    }

    // 5. Radio Stations Matches
    if (results.radio && results.radio.length > 0) {
      html += `
        <div class="px-3 py-2 text-uppercase text-xs fw-bold text-body-secondary bg-body-secondary bg-opacity-25 d-flex align-items-center gap-1 border-top">
          <i class="icon-base bx bx-radio text-info"></i> Radio Stations (${results.radio.length})
        </div>
      `;
      results.radio.slice(0, 3).forEach((item) => {
        html += `
          <a href="${baseUrl}${item.play_url}" class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center gap-2 overflow-hidden me-2">
              <div class="avatar avatar-sm rounded-circle flex-shrink-0 bg-label-info d-flex align-items-center justify-content-center">
                ${
                  item.icon
                    ? `<img src="${escapeHtml(item.icon)}" alt="${escapeHtml(item.title)}" class="rounded-circle object-fit-contain w-100 h-100" />`
                    : `<i class="icon-base bx bx-radio text-info"></i>`
                }
              </div>
              <div class="text-truncate">
                <span class="fw-semibold text-heading d-block text-truncate">${escapeHtml(item.title)}</span>
                <small class="text-body-secondary">${escapeHtml(item.category || 'Radio')}</small>
              </div>
            </div>
            <span class="badge bg-label-info rounded-pill flex-shrink-0">Tune In</span>
          </a>
        `;
      });
    }

    html += `
      </div>
      <div class="p-2 border-top text-center bg-body-tertiary">
        <a href="${baseUrl}search?q=${encodeURIComponent(query)}" class="btn btn-sm btn-primary w-100 py-1 fw-semibold shadow-sm">
          <i class="icon-base bx bx-search me-1"></i> View All ${data.total} Results &rarr;
        </a>
      </div>
    `;

    dropdownEl.innerHTML = html;
    dropdownEl.style.display = 'block';
  };

  /**
   * Initialize Top Navbar Search Input & Autocomplete.
   */
  const initNavbarSearch = () => {
    const input = document.getElementById('global-search-input');
    const dropdown = document.getElementById('global-search-dropdown');
    const clearBtn = document.getElementById('global-search-clear-btn');
    const form = document.getElementById('global-search-form');

    if (!input || !dropdown) return;

    // Input typing handler with debounce
    input.addEventListener('input', () => {
      const q = input.value.trim();

      if (clearBtn) {
        clearBtn.classList.toggle('d-none', q.length === 0);
      }

      if (q.length < 2) {
        dropdown.style.display = 'none';
        if (activeRequest) {
          activeRequest.abort();
        }
        return;
      }

      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(async () => {
        dropdown.innerHTML = `
          <div class="p-3 text-center text-body-secondary">
            <span class="spinner-border spinner-border-sm me-2 text-primary" role="status"></span>
            <span>Searching catalog for "${escapeHtml(q)}"...</span>
          </div>
        `;
        dropdown.style.display = 'block';

        try {
          const baseUrl = getBaseUrl();
          const endpoint = `${baseUrl}search?ajax=1&q=${encodeURIComponent(q)}`;

          const controller = new AbortController();
          activeRequest = controller;

          const res = await fetch(endpoint, {
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller.signal
          });

          if (res.ok) {
            const data = await res.json();
            renderDropdownResults(data, q, dropdown);
          }
        } catch (err) {
          if (err.name !== 'AbortError') {
            console.error('Search request failed:', err);
          }
        }
      }, 260);
    });

    // Clear button
    if (clearBtn) {
      clearBtn.addEventListener('click', (e) => {
        e.preventDefault();
        input.value = '';
        clearBtn.classList.add('d-none');
        dropdown.style.display = 'none';
        input.focus();
      });
    }

    // Close on click outside
    document.addEventListener('click', (e) => {
      if (!input.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });

    // Close on Esc
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        dropdown.style.display = 'none';
      }

      // Shortcut: Ctrl+K or Cmd+K focuses search
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        input.focus();
        input.select();
      }
    });

    // Reopen dropdown on focus if text exists
    input.addEventListener('focus', () => {
      if (input.value.trim().length >= 2 && dropdown.innerHTML.trim() !== '') {
        dropdown.style.display = 'block';
      }
    });
  };

  const init = () => {
    initNavbarSearch();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return { init };
})();
