/**
 * Web Player V2 — Favorites & Starred Vault Controller
 *
 * Synchronizes client-side bookmarks with the backend and renders
 * categorized lists for Live TV, Movies, TV Series, and Radio.
 * Fully optimized for Seamless SPA Transitions.
 */

'use strict';

window.PlayerFavorites = (function () {
  const STORAGE_KEYS = {
    live: 'xc_player_v2_favs_live',
    movies: 'xc_player_v2_favs_movies',
    series: 'xc_player_v2_favs_series',
    radio: 'xc_player_v2_favs_radio'
  };

  let isFetching = false;

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
    let b = document.documentElement.getAttribute('data-base-url') || '/';
    if (!b.startsWith('/')) b = '/' + b;
    if (!b.endsWith('/')) b = b + '/';
    return b;
  };

  const buildUrl = (path) => {
    const base = getBaseUrl();
    const cleanPath = (path || '').replace(/^\/+/, '');
    return base + cleanPath;
  };

  /**
   * Retrieve favorite IDs for a specific type.
   */
  const getFavoriteIds = (type) => {
    try {
      const key = STORAGE_KEYS[type];
      if (!key) return [];
      const data = localStorage.getItem(key);
      const list = data ? JSON.parse(data) : [];
      return Array.isArray(list) ? list.map((id) => parseInt(id, 10)).filter((id) => !isNaN(id) && id > 0) : [];
    } catch (e) {
      console.error(`Failed to read favorites for ${type}:`, e);
      return [];
    }
  };

  /**
   * Remove an ID from favorites.
   */
  const removeFavoriteId = (type, id) => {
    const key = STORAGE_KEYS[type];
    if (!key) return;
    let list = getFavoriteIds(type);
    list = list.filter((item) => item !== parseInt(id, 10));
    localStorage.setItem(key, JSON.stringify(list));
    updateGlobalNavbarBadges();
  };

  /**
   * Clear all favorites across all 4 categories.
   */
  const clearAllFavorites = () => {
    if (confirm('Are you sure you want to remove all saved favorites?')) {
      Object.values(STORAGE_KEYS).forEach((k) => localStorage.removeItem(k));
      updateGlobalNavbarBadges();
      if (document.getElementById('favorites-content-wrapper')) {
        renderFavoritesPage();
      }
    }
  };

  /**
   * Update top navbar star badge and sidebar badge on any page.
   */
  const updateGlobalNavbarBadges = () => {
    const liveCount = getFavoriteIds('live').length;
    const moviesCount = getFavoriteIds('movies').length;
    const seriesCount = getFavoriteIds('series').length;
    const radioCount = getFavoriteIds('radio').length;
    const total = liveCount + moviesCount + seriesCount + radioCount;

    const navBadge = document.getElementById('nav-fav-badge');
    if (navBadge) {
      navBadge.textContent = total;
      navBadge.classList.toggle('d-none', total === 0);
    }

    const menuBadge = document.querySelector('.nav-fav-menu-badge');
    if (menuBadge) {
      menuBadge.textContent = total;
      menuBadge.style.display = total > 0 ? 'inline-block' : 'none';
    }

    const headerBadge = document.getElementById('fav-header-badge');
    if (headerBadge) {
      headerBadge.textContent = `${total} Items`;
    }

    // Update tab badges if on favorites page
    const countAll = document.getElementById('fav-count-all');
    if (countAll) countAll.textContent = total;
    const countLive = document.getElementById('fav-count-live');
    if (countLive) countLive.textContent = liveCount;
    const countMovies = document.getElementById('fav-count-movies');
    if (countMovies) countMovies.textContent = moviesCount;
    const countSeries = document.getElementById('fav-count-series');
    if (countSeries) countSeries.textContent = seriesCount;
    const countRadio = document.getElementById('fav-count-radio');
    if (countRadio) countRadio.textContent = radioCount;
  };

  /**
   * Ensure tab navigation works reliably across dynamic SPA page insertions.
   */
  const initTabs = () => {
    const tabNav = document.getElementById('favorites-nav-tabs');
    if (!tabNav) return;

    tabNav.querySelectorAll('button[data-bs-toggle="tab"]').forEach((btn) => {
      btn.onclick = (e) => {
        e.preventDefault();

        // 1. Try Bootstrap Tab API
        if (window.bootstrap && typeof window.bootstrap.Tab === 'function') {
          try {
            const tabInst = window.bootstrap.Tab.getOrCreateInstance(btn);
            tabInst.show();
            return;
          } catch (err) {}
        }

        // 2. Fail-safe manual tab activation
        tabNav.querySelectorAll('.nav-link').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');

        const targetId = btn.getAttribute('data-bs-target');
        document.querySelectorAll('#favorites-content-wrapper > .tab-pane').forEach((p) => {
          p.classList.remove('show', 'active');
        });

        const targetPane = document.querySelector(targetId);
        if (targetPane) {
          targetPane.classList.add('show', 'active');
        }
      };
    });
  };

  /**
   * Render cards on the dedicated /favorites page.
   */
  const renderFavoritesPage = async () => {
    const wrapper = document.getElementById('favorites-content-wrapper');
    if (!wrapper) return;

    initTabs();

    if (isFetching) return;

    const loadingState = document.getElementById('fav-loading-state');
    const clearAllBtn = document.getElementById('btn-clear-all-favs');

    const liveIds = getFavoriteIds('live');
    const movieIds = getFavoriteIds('movies');
    const seriesIds = getFavoriteIds('series');
    const radioIds = getFavoriteIds('radio');
    const totalCount = liveIds.length + movieIds.length + seriesIds.length + radioIds.length;

    updateGlobalNavbarBadges();

    if (clearAllBtn) {
      clearAllBtn.style.display = totalCount > 0 ? 'inline-block' : 'none';
      clearAllBtn.onclick = clearAllFavorites;
    }

    if (totalCount === 0) {
      if (loadingState) loadingState.style.display = 'none';
      showEmptyFavoritesState();
      return;
    }

    if (loadingState) loadingState.style.display = 'block';
    isFetching = true;

    try {
      const endpoint = buildUrl('favorites?ajax=1');
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          live_ids: liveIds,
          movie_ids: movieIds,
          series_ids: seriesIds,
          radio_ids: radioIds
        })
      });

      if (!res.ok) throw new Error('Failed to load favorites from server');

      const data = await res.json();
      if (loadingState) loadingState.style.display = 'none';

      const items = data.items || {};
      const actualCount =
        (items.live ? items.live.length : 0) +
        (items.movies ? items.movies.length : 0) +
        (items.series ? items.series.length : 0) +
        (items.radio ? items.radio.length : 0);

      if (actualCount === 0) {
        showEmptyFavoritesState();
      } else {
        populateLists(items);
      }
    } catch (err) {
      console.error('Error loading favorites:', err);
      if (loadingState) {
        loadingState.innerHTML = `
          <div class="alert alert-danger mx-auto max-w-500 text-center">
            <i class="icon-base bx bx-error-circle fs-3 me-2"></i> Failed to load favorites from server.
          </div>
        `;
      }
    } finally {
      isFetching = false;
    }
  };

  const showEmptyFavoritesState = () => {
    const containers = ['fav-list-all', 'fav-list-live', 'fav-list-movies', 'fav-list-series', 'fav-list-radio'];
    containers.forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        el.innerHTML = `
          <div class="col-12 text-center py-6">
            <div class="avatar avatar-xl bg-label-warning rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center shadow-sm">
              <i class="icon-base bx bx-star fs-1 text-warning"></i>
            </div>
            <h5 class="fw-bold mb-2 text-heading">No Favorites Saved Yet</h5>
            <p class="text-body-secondary max-w-500 mx-auto mb-4">
              Click the star icon (<i class="icon-base bx bx-star text-warning"></i>) while browsing Live TV, Movies, TV Series, or Radio to build your personalized vault.
            </p>
            <div class="d-flex align-items-center justify-content-center gap-2 flex-wrap">
              <a href="${buildUrl('movies')}" class="btn btn-sm btn-primary">
                <i class="icon-base bx bx-film me-1"></i> Browse Movies
              </a>
              <a href="${buildUrl('series')}" class="btn btn-sm btn-outline-info">
                <i class="icon-base bx bx-movie-play me-1"></i> Browse Series
              </a>
              <a href="${buildUrl('live')}" class="btn btn-sm btn-outline-danger">
                <i class="icon-base bx bx-broadcast me-1"></i> Live TV
              </a>
            </div>
          </div>
        `;
      }
    });
  };

  /**
   * Populate item cards into each tab pane.
   */
  const populateLists = (items) => {
    const live = items.live || [];
    const movies = items.movies || [];
    const series = items.series || [];
    const radio = items.radio || [];

    // Helper for movie card HTML
    const createMovieCardHtml = (item) => {
      const posterSrc = item.cover || buildUrl('assets/img/pages/profile-banner.png');
      const detailUrl = buildUrl(item.details_url || item.play_url || 'movies');

      return `
        <div class="col-6 col-sm-4 col-md-3 col-xl-2 fav-card-item" id="fav-card-movies-${item.id}">
          <div class="card h-100 border shadow-sm channel-card">
            <div class="position-relative overflow-hidden">
              <img
                src="${posterSrc}"
                alt="${escapeHtml(item.title)}"
                class="card-img-top object-fit-cover media-poster-ratio"
                loading="lazy"
                onerror="this.src='${buildUrl('assets/img/pages/profile-banner.png')}';" />
              <a href="${detailUrl}" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="Watch Movie">
                <div class="avatar avatar-md rounded-circle bg-primary d-flex align-items-center justify-content-center shadow-lg">
                  <i class="icon-base bx bx-play fs-4 text-white"></i>
                </div>
              </a>
              <button
                type="button"
                class="btn btn-sm btn-icon btn-dark bg-opacity-75 position-absolute top-0 start-0 m-2 rounded-circle btn-remove-fav z-3"
                data-type="movies"
                data-id="${item.id}"
                title="Remove Favorite">
                <i class="icon-base bx bxs-star text-warning"></i>
              </button>
              ${
                item.rating
                  ? `<span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-warning shadow-sm z-3 pe-none"><i class="icon-base bx bxs-star text-warning me-1"></i>${escapeHtml(String(item.rating))}</span>`
                  : ''
              }
              <span class="position-absolute bottom-0 start-0 m-2 badge bg-primary bg-opacity-85 text-white shadow-sm small z-3 pe-none">Movie</span>
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div>
                <h6 class="card-title text-truncate mb-1 fw-bold text-heading" title="${escapeHtml(item.title)}">
                  <a href="${detailUrl}" class="text-heading text-decoration-none">${escapeHtml(item.title)}</a>
                </h6>
                <div class="d-flex align-items-center gap-1 mb-2">
                  <span class="badge bg-label-primary rounded-pill small py-0 px-2">${escapeHtml(item.year || 'VOD')}</span>
                  <span class="badge bg-label-secondary rounded-pill small py-0 px-2 text-truncate max-w-100">${escapeHtml(item.category || 'Cinema')}</span>
                </div>
              </div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-auto gap-2">
                <a href="${detailUrl}" class="btn btn-xs btn-primary d-flex align-items-center gap-1 flex-grow-1 justify-content-center">
                  <i class="icon-base bx bx-play"></i> Watch
                </a>
              </div>
            </div>
          </div>
        </div>
      `;
    };

    // Helper for series card HTML
    const createSeriesCardHtml = (item) => {
      const posterSrc = item.cover || buildUrl('assets/img/pages/profile-banner.png');
      const detailUrl = buildUrl(item.details_url || 'series');
      const seasons = item.seasons_count || 1;

      return `
        <div class="col-6 col-sm-4 col-md-3 col-xl-2 fav-card-item" id="fav-card-series-${item.id}">
          <div class="card h-100 border shadow-sm channel-card">
            <div class="position-relative overflow-hidden">
              <img
                src="${posterSrc}"
                alt="${escapeHtml(item.title)}"
                class="card-img-top object-fit-cover media-poster-ratio"
                loading="lazy"
                onerror="this.src='${buildUrl('assets/img/pages/profile-banner.png')}';" />
              <a href="${detailUrl}" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="View Series">
                <div class="avatar avatar-md rounded-circle bg-info d-flex align-items-center justify-content-center shadow-lg">
                  <i class="icon-base bx bx-show fs-4 text-white"></i>
                </div>
              </a>
              <button
                type="button"
                class="btn btn-sm btn-icon btn-dark bg-opacity-75 position-absolute top-0 start-0 m-2 rounded-circle btn-remove-fav z-3"
                data-type="series"
                data-id="${item.id}"
                title="Remove Favorite">
                <i class="icon-base bx bxs-star text-warning"></i>
              </button>
              ${
                item.rating
                  ? `<span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-warning shadow-sm z-3 pe-none"><i class="icon-base bx bxs-star text-warning me-1"></i>${escapeHtml(String(item.rating))}</span>`
                  : ''
              }
              <span class="position-absolute bottom-0 start-0 m-2 badge bg-info bg-opacity-85 text-white shadow-sm small z-3 pe-none">
                ${seasons} ${seasons > 1 ? 'Seasons' : 'Season'}
              </span>
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div>
                <h6 class="card-title text-truncate mb-1 fw-bold text-heading" title="${escapeHtml(item.title)}">
                  <a href="${detailUrl}" class="text-heading text-decoration-none">${escapeHtml(item.title)}</a>
                </h6>
                <div class="d-flex align-items-center gap-1 mb-2">
                  <span class="badge bg-label-info rounded-pill small py-0 px-2">${escapeHtml(item.year || 'Series')}</span>
                  <span class="badge bg-label-secondary rounded-pill small py-0 px-2 text-truncate max-w-100">${escapeHtml(item.genre || item.category || 'Drama')}</span>
                </div>
              </div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-auto gap-2">
                <a href="${detailUrl}" class="btn btn-xs btn-info text-white d-flex align-items-center gap-1 flex-grow-1 justify-content-center">
                  <i class="icon-base bx bx-show"></i> Episodes
                </a>
              </div>
            </div>
          </div>
        </div>
      `;
    };

    // Helper for live card HTML
    const createLiveCardHtml = (item) => {
      const playUrl = buildUrl(item.play_url || 'live');
      return `
        <div class="col-6 col-md-4 col-xl-3 fav-card-item" id="fav-card-live-${item.id}">
          <div class="card h-100 border shadow-sm channel-card">
            <div class="card-body p-3 d-flex align-items-center gap-3">
              <div class="avatar avatar-lg bg-label-primary rounded d-flex align-items-center justify-content-center flex-shrink-0">
                ${
                  item.icon
                    ? `<img src="${escapeHtml(item.icon)}" alt="${escapeHtml(item.title)}" class="w-100 h-100 object-fit-contain rounded" onerror="this.remove();" />`
                    : `<i class="icon-base bx bx-tv fs-2 text-primary"></i>`
                }
              </div>
              <div class="overflow-hidden flex-grow-1 min-w-0">
                <h6 class="mb-1 fw-bold text-truncate text-heading" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</h6>
                <span class="badge bg-label-secondary small text-truncate d-inline-block max-w-150">${escapeHtml(item.category)}</span>
              </div>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <a href="${playUrl}" class="btn btn-sm btn-icon btn-primary" title="Watch Live">
                  <i class="icon-base bx bx-play fs-4"></i>
                </a>
                <button type="button" class="btn btn-sm btn-icon btn-label-warning btn-remove-fav" data-type="live" data-id="${item.id}" title="Remove from favorites">
                  <i class="icon-base bx bxs-star text-warning"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
    };

    // Helper for radio card HTML
    const createRadioCardHtml = (item) => {
      const playUrl = buildUrl(item.play_url || 'radio');
      return `
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3 fav-card-item" id="fav-card-radio-${item.id}">
          <div class="card h-100 border shadow-sm">
            <div class="card-body p-3 d-flex align-items-center gap-3">
              <div class="avatar avatar-lg bg-label-warning rounded-circle d-flex align-items-center justify-content-center flex-shrink-0">
                ${
                  item.icon
                    ? `<img src="${escapeHtml(item.icon)}" alt="${escapeHtml(item.title)}" class="rounded-circle w-100 h-100 object-fit-contain" onerror="this.remove();" />`
                    : `<i class="icon-base bx bx-radio fs-2 text-warning"></i>`
                }
              </div>
              <div class="overflow-hidden flex-grow-1 min-w-0">
                <h6 class="mb-1 fw-bold text-truncate text-heading" title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</h6>
                <span class="badge bg-label-secondary small text-truncate d-inline-block max-w-150">${escapeHtml(item.category)}</span>
              </div>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <a href="${playUrl}" class="btn btn-sm btn-icon btn-primary" title="Tune In">
                  <i class="icon-base bx bx-play fs-4"></i>
                </a>
                <button type="button" class="btn btn-sm btn-icon btn-label-warning btn-remove-fav" data-type="radio" data-id="${item.id}" title="Remove from favorites">
                  <i class="icon-base bx bxs-star text-warning"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
    };

    // 1. Live Tab
    const liveContainer = document.getElementById('fav-list-live');
    if (liveContainer) {
      if (live.length === 0) {
        liveContainer.innerHTML = `<div class="col-12 text-center py-5 text-body-secondary"><i class="icon-base bx bx-broadcast fs-2 mb-2 d-block text-secondary"></i>No favorite live channels saved.</div>`;
      } else {
        liveContainer.innerHTML = live.map(createLiveCardHtml).join('');
      }
    }

    // 2. Movies Tab
    const moviesContainer = document.getElementById('fav-list-movies');
    if (moviesContainer) {
      if (movies.length === 0) {
        moviesContainer.innerHTML = `<div class="col-12 text-center py-5 text-body-secondary"><i class="icon-base bx bx-film fs-2 mb-2 d-block text-secondary"></i>No favorite movies saved.</div>`;
      } else {
        moviesContainer.innerHTML = movies.map(createMovieCardHtml).join('');
      }
    }

    // 3. Series Tab
    const seriesContainer = document.getElementById('fav-list-series');
    if (seriesContainer) {
      if (series.length === 0) {
        seriesContainer.innerHTML = `<div class="col-12 text-center py-5 text-body-secondary"><i class="icon-base bx bx-movie-play fs-2 mb-2 d-block text-secondary"></i>No favorite TV series saved.</div>`;
      } else {
        seriesContainer.innerHTML = series.map(createSeriesCardHtml).join('');
      }
    }

    // 4. Radio Tab
    const radioContainer = document.getElementById('fav-list-radio');
    if (radioContainer) {
      if (radio.length === 0) {
        radioContainer.innerHTML = `<div class="col-12 text-center py-5 text-body-secondary"><i class="icon-base bx bx-radio fs-2 mb-2 d-block text-secondary"></i>No favorite radio stations saved.</div>`;
      } else {
        radioContainer.innerHTML = radio.map(createRadioCardHtml).join('');
      }
    }

    // 5. All Tab (Combined Overview)
    const allContainer = document.getElementById('fav-list-all');
    if (allContainer) {
      let allHtml = '';

      if (live.length > 0) {
        allHtml += `
          <div class="col-12 mb-1">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2">
              <h6 class="fw-bold mb-0 text-heading d-flex align-items-center gap-2">
                <i class="icon-base bx bx-broadcast text-danger"></i> Live Channels (${live.length})
              </h6>
            </div>
          </div>
        `;
        allHtml += live.slice(0, 8).map(createLiveCardHtml).join('');
      }

      if (movies.length > 0) {
        allHtml += `
          <div class="col-12 mt-4 mb-1">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2">
              <h6 class="fw-bold mb-0 text-heading d-flex align-items-center gap-2">
                <i class="icon-base bx bx-film text-primary"></i> Movies (${movies.length})
              </h6>
            </div>
          </div>
        `;
        allHtml += movies.slice(0, 12).map(createMovieCardHtml).join('');
      }

      if (series.length > 0) {
        allHtml += `
          <div class="col-12 mt-4 mb-1">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2">
              <h6 class="fw-bold mb-0 text-heading d-flex align-items-center gap-2">
                <i class="icon-base bx bx-movie-play text-info"></i> TV Series (${series.length})
              </h6>
            </div>
          </div>
        `;
        allHtml += series.slice(0, 12).map(createSeriesCardHtml).join('');
      }

      if (radio.length > 0) {
        allHtml += `
          <div class="col-12 mt-4 mb-1">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2">
              <h6 class="fw-bold mb-0 text-heading d-flex align-items-center gap-2">
                <i class="icon-base bx bx-radio text-warning"></i> Radio Stations (${radio.length})
              </h6>
            </div>
          </div>
        `;
        allHtml += radio.slice(0, 8).map(createRadioCardHtml).join('');
      }

      if (!allHtml) {
        showEmptyFavoritesState();
      } else {
        allContainer.innerHTML = allHtml;
      }
    }

    // Attach 1-click un-favorite removal handlers
    document.querySelectorAll('.btn-remove-fav').forEach((btn) => {
      btn.onclick = (e) => {
        e.preventDefault();
        e.stopPropagation();

        const type = btn.getAttribute('data-type');
        const id = btn.getAttribute('data-id');
        removeFavoriteId(type, id);

        // Animate card removal
        const cardItem = btn.closest('.fav-card-item');
        if (cardItem) {
          cardItem.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
          cardItem.style.opacity = '0';
          cardItem.style.transform = 'scale(0.85)';
          setTimeout(() => {
            cardItem.remove();

            // Check if all favorites are gone
            const remaining =
              getFavoriteIds('live').length +
              getFavoriteIds('movies').length +
              getFavoriteIds('series').length +
              getFavoriteIds('radio').length;

            if (remaining === 0) {
              showEmptyFavoritesState();
            }
          }, 200);
        }
      };
    });
  };

  const init = () => {
    updateGlobalNavbarBadges();
    if (document.getElementById('favorites-content-wrapper')) {
      renderFavoritesPage();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return {
    init,
    getFavoriteIds,
    removeFavoriteId,
    clearAllFavorites,
    updateGlobalNavbarBadges,
    renderFavoritesPage
  };
})();
