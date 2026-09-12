/**
 * Web Player V2 — Radio Stations Explorer & Studio Audio Engine
 *
 * Implements station catalog browsing with category sidebar pagination,
 * items pagination with page numbers & ellipses, instant search,
 * favorites management, and live studio audio playback.
 */

'use strict';

window.RadioApp = (function () {
  let state = {
    activeCategory: null,
    allStations: [],
    displayedStations: [],
    favorites: [],
    gridCols: 3,
    showLimit: 48,
    currentPage: 1,
    catCurrentPage: 1,
    catPageLimit: 10,
    currentSort: 'default',
    favFilterActive: false,
    baseUrl: '',
    currentStation: null,
    isPlaying: false,
    hls: null
  };

  // Cache DOM Elements
  const els = {};

  const initElements = () => {
    // Views
    els.browserView = document.getElementById('radio-browser-view');
    els.playerView = document.getElementById('radio-player-view');

    // Sidebar & Grid
    els.sidebar = document.getElementById('radio-categories-sidebar');
    els.categoriesList = document.getElementById('radio-categories-list');
    els.categoriesPagination = document.getElementById('radio-categories-pagination');
    els.container = document.getElementById('radio-container');
    els.pagination = document.getElementById('radio-pagination');
    els.categorySearch = document.getElementById('radio-category-search');
    els.radioSearch = document.getElementById('radio-search');
    els.favBtn = document.getElementById('btn-radio-favorites');
    els.sortSelect = document.getElementById('radio-sort-select');
    els.sidebarToggle = document.getElementById('btn-toggle-radio-categories');
    els.countBadge = document.getElementById('radio-count-badge');
    els.toolbar = document.querySelector('.bg-body-tertiary');

    // Player Elements
    els.audio = document.getElementById('radio-audio');
    els.btnBack = document.getElementById('btn-back-radio');
    els.btnMinimize = document.getElementById('btn-radio-minimize');
    els.stationName = document.getElementById('radio-player-station-name');
    els.stationLogo = document.getElementById('radio-player-station-logo');
    els.turntableDisc = document.getElementById('radio-turntable-disc');
    els.centerLogo = document.getElementById('radio-center-logo');
    els.equalizer = document.getElementById('radio-equalizer');
    els.btnPlayPause = document.getElementById('btn-radio-play-pause');
    els.btnMute = document.getElementById('btn-radio-mute');
    els.volumeSlider = document.getElementById('radio-volume-slider');
    els.btnPlayerFav = document.getElementById('btn-radio-player-fav');
    els.miniZapList = document.getElementById('radio-mini-zap-list');
    els.miniZapSearch = document.getElementById('radio-mini-zap-search');

    // Bottom Mini-Player Bar Elements
    els.bottomBar = document.getElementById('radio-bottom-bar');
    els.barLogo = document.getElementById('radio-bar-logo');
    els.barAvatarPlaceholder = document.getElementById('radio-bar-avatar-placeholder');
    els.barTitle = document.getElementById('radio-bar-title');
    els.barBadge = document.getElementById('radio-bar-badge');
    els.barSubtitle = document.getElementById('radio-bar-subtitle');
    els.barPlay = document.getElementById('btn-radio-bar-play');
    els.barPrev = document.getElementById('btn-radio-bar-prev');
    els.barNext = document.getElementById('btn-radio-bar-next');
    els.barStatusText = document.getElementById('radio-bar-status-text');
    els.barIndicatorDot = document.getElementById('radio-bar-indicator-dot');
    els.barMute = document.getElementById('btn-radio-bar-mute');
    els.barVolume = document.getElementById('radio-bar-volume');
    els.barFav = document.getElementById('btn-radio-bar-fav');
    els.barExpand = document.getElementById('btn-radio-bar-expand');
    els.barClose = document.getElementById('btn-radio-bar-close');
  };

  const loadFavorites = () => {
    try {
      state.favorites = JSON.parse(localStorage.getItem('xc_player_v2_favs_radio')) || [];
    } catch (e) {
      state.favorites = [];
    }
  };

  const saveFavorites = () => {
    try {
      localStorage.setItem('xc_player_v2_favs_radio', JSON.stringify(state.favorites));
    } catch (e) {}
  };

  const isFavorite = (id) => state.favorites.includes(Number(id)) || state.favorites.includes(String(id));

  const toggleFavorite = (id) => {
    const numId = Number(id);
    if (isFavorite(numId)) {
      state.favorites = state.favorites.filter((fav) => Number(fav) !== numId);
    } else {
      state.favorites.push(numId);
    }
    saveFavorites();
    updateFavoriteButtons();
    if (state.favFilterActive) {
      state.currentPage = 1;
      renderStations();
    }
    if (state.currentStation && Number(state.currentStation.id) === numId) {
      updatePlayerFavButton();
      updateBottomBarFav();
    }
  };

  const updateFavoriteButtons = () => {
    document.querySelectorAll('[data-fav-radio-id]').forEach((btn) => {
      const sId = btn.getAttribute('data-fav-radio-id');
      const active = isFavorite(sId);
      btn.classList.toggle('text-warning', active);
      const icon = btn.querySelector('i');
      if (icon) {
        icon.className = active ? 'icon-base bx bxs-star text-warning' : 'icon-base bx bx-star text-body-secondary';
      }
    });
  };

  const updatePlayerFavButton = () => {
    if (!els.btnPlayerFav || !state.currentStation) return;
    const active = isFavorite(state.currentStation.id);
    els.btnPlayerFav.classList.toggle('btn-label-warning', active);
    els.btnPlayerFav.classList.toggle('btn-label-secondary', !active);
    const star = els.btnPlayerFav.querySelector('i');
    if (star) {
      star.className = active ? 'icon-base bx bxs-star me-1 text-warning' : 'icon-base bx bx-star me-1';
    }
  };

  const updateBottomBarFav = () => {
    if (!els.barFav || !state.currentStation) return;
    const active = isFavorite(state.currentStation.id);
    els.barFav.classList.toggle('text-warning', active);
    els.barFav.classList.toggle('btn-label-warning', active);
    els.barFav.classList.toggle('btn-label-secondary', !active);
    const star = els.barFav.querySelector('i');
    if (star) {
      star.className = active ? 'icon-base bx bxs-star text-warning' : 'icon-base bx bx-star';
    }
  };

  const getColClasses = (cols) => {
    switch (Number(cols)) {
      case 2:
        return 'col-12 col-sm-6';
      case 4:
        return 'col-6 col-sm-4 col-md-3';
      case 6:
        return 'col-6 col-sm-4 col-md-3 col-lg-2';
      case 3:
      default:
        return 'col-12 col-sm-6 col-md-4';
    }
  };

  /* ─────────────────────────────────────────────────────────────────
   * Category Sidebar Pagination
   * ───────────────────────────────────────────────────────────────── */
  const renderCategoriesPagination = () => {
    if (!els.categoriesList) return;

    const allCatItems = Array.from(els.categoriesList.querySelectorAll('[data-radio-category-id]'));
    const val = els.categorySearch ? els.categorySearch.value.trim().toLowerCase() : '';

    // Filter matching items (pinned "all" stays visible at top)
    const contentCategories = [];
    allCatItems.forEach((item) => {
      const catId = item.getAttribute('data-radio-category-id');
      if (catId === 'all') {
        item.classList.remove('d-none');
      } else {
        const text = item.textContent.toLowerCase();
        const raw = (item.getAttribute('data-category-raw') || '').toLowerCase();
        const matches = !val || text.includes(val) || raw.includes(val);
        if (matches) {
          contentCategories.push(item);
        } else {
          item.classList.add('d-none');
        }
      }
    });

    const totalCats = contentCategories.length;
    const totalPages = Math.ceil(totalCats / state.catPageLimit);

    if (state.catCurrentPage > totalPages) {
      state.catCurrentPage = Math.max(1, totalPages);
    }

    const startIdx = (state.catCurrentPage - 1) * state.catPageLimit;
    const endIdx = state.catCurrentPage * state.catPageLimit;

    contentCategories.forEach((item, idx) => {
      if (idx >= startIdx && idx < endIdx) {
        item.classList.remove('d-none');
      } else {
        item.classList.add('d-none');
      }
    });

    if (!els.categoriesPagination) return;

    if (totalPages <= 1) {
      els.categoriesPagination.classList.add('d-none');
      els.categoriesPagination.innerHTML = '';
      return;
    }

    els.categoriesPagination.classList.remove('d-none');
    const startNum = startIdx + 1;
    const endNum = Math.min(endIdx, totalCats);

    els.categoriesPagination.innerHTML = `
      <span class="text-body-secondary small fw-medium">${startNum}–${endNum} of ${totalCats}</span>
      <div class="d-flex align-items-center gap-1">
        <button type="button" class="btn btn-xs btn-icon btn-label-secondary" id="btn-radio-cat-prev" ${state.catCurrentPage === 1 ? 'disabled' : ''} title="Previous Categories">
          <i class="icon-base bx bx-chevron-left"></i>
        </button>
        <span class="badge bg-label-primary px-2 py-1">${state.catCurrentPage} / ${totalPages}</span>
        <button type="button" class="btn btn-xs btn-icon btn-label-secondary" id="btn-radio-cat-next" ${state.catCurrentPage === totalPages ? 'disabled' : ''} title="Next Categories">
          <i class="icon-base bx bx-chevron-right"></i>
        </button>
      </div>
    `;

    const prevBtn = document.getElementById('btn-radio-cat-prev');
    const nextBtn = document.getElementById('btn-radio-cat-next');

    if (prevBtn) {
      prevBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (state.catCurrentPage > 1) {
          state.catCurrentPage--;
          renderCategoriesPagination();
        }
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', (e) => {
        e.preventDefault();
        if (state.catCurrentPage < totalPages) {
          state.catCurrentPage++;
          renderCategoriesPagination();
        }
      });
    }
  };

  const fetchStationsForCategory = async (catId) => {
    state.activeCategory = catId;
    state.currentPage = 1;
    if (els.container) {
      els.container.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="text-body-secondary mt-2 mb-0">Loading radio stations...</p>
        </div>`;
    }
    if (els.pagination) {
      els.pagination.classList.add('d-none');
    }

    try {
      const url = `${state.baseUrl}radio?ajax=1&category_id=${catId !== null ? encodeURIComponent(catId) : 'all'}`;
      const res = await fetch(url);
      const json = await res.json();

      if (json.status === 'success' && Array.isArray(json.stations)) {
        state.allStations = json.stations;
      } else {
        state.allStations = [];
      }
    } catch (e) {
      state.allStations = [];
    }

    renderStations();
  };

  /* ─────────────────────────────────────────────────────────────────
   * Items Pagination Renderer
   * ───────────────────────────────────────────────────────────────── */
  const renderItemsPagination = (total, limit, totalPages, startIdx, endIdx) => {
    if (!els.pagination) return;

    if (totalPages <= 1 || state.showLimit === 'all' || total === 0) {
      els.pagination.classList.add('d-none');
      els.pagination.innerHTML = '';
      return;
    }

    els.pagination.classList.remove('d-none');

    // Calculate smart page numbers with ellipses
    const pageNums = [];
    if (totalPages <= 5) {
      for (let i = 1; i <= totalPages; i++) pageNums.push(i);
    } else {
      pageNums.push(1);
      if (state.currentPage > 3) pageNums.push('...');

      const lo = Math.max(2, state.currentPage - 1);
      const hi = Math.min(totalPages - 1, state.currentPage + 1);

      for (let i = lo; i <= hi; i++) pageNums.push(i);

      if (state.currentPage < totalPages - 2) pageNums.push('...');
      pageNums.push(totalPages);
    }

    let buttonsHtml = `
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary pagination-page-btn" data-radio-page="prev" ${state.currentPage === 1 ? 'disabled' : ''} title="Previous Page">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
    `;

    pageNums.forEach((p) => {
      if (p === '...') {
        buttonsHtml += `<span class="px-2 text-body-secondary small">•••</span>`;
      } else {
        const activeClass = p === state.currentPage ? 'btn-primary shadow-sm' : 'btn-label-secondary';
        buttonsHtml += `
          <button type="button" class="btn btn-sm btn-icon pagination-page-btn ${activeClass}" data-radio-page="${p}">
            ${p}
          </button>
        `;
      }
    });

    buttonsHtml += `
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary pagination-page-btn" data-radio-page="next" ${state.currentPage === totalPages ? 'disabled' : ''} title="Next Page">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    `;

    els.pagination.innerHTML = `
      <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3 w-100">
        <span class="text-body-secondary small order-2 order-sm-1">
          Showing ${startIdx + 1}–${endIdx} of ${total} Stations
        </span>
        <div class="d-flex align-items-center gap-1 order-1 order-sm-2">
          ${buttonsHtml}
        </div>
      </div>
    `;

    els.pagination.querySelectorAll('[data-radio-page]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const action = btn.getAttribute('data-radio-page');
        let targetPage = state.currentPage;

        if (action === 'prev') {
          targetPage = Math.max(1, state.currentPage - 1);
        } else if (action === 'next') {
          targetPage = Math.min(totalPages, state.currentPage + 1);
        } else {
          targetPage = Number(action);
        }

        if (targetPage !== state.currentPage) {
          state.currentPage = targetPage;
          renderStations();
          if (els.toolbar) {
            els.toolbar.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    });
  };

  const renderStations = () => {
    if (!els.container) return;

    let list = [...state.allStations];

    // Filter by Favorites
    if (state.favFilterActive) {
      list = list.filter((s) => isFavorite(s.id));
    }

    // Filter by Search Query
    const searchVal = els.radioSearch ? els.radioSearch.value.trim().toLowerCase() : '';
    if (searchVal) {
      list = list.filter((s) => s.name && s.name.toLowerCase().includes(searchVal));
    }

    // Sort
    const sortVal = state.currentSort;
    if (sortVal === 'alpha_az') {
      list.sort((a, b) => a.name.localeCompare(b.name));
    } else if (sortVal === 'alpha_za') {
      list.sort((a, b) => b.name.localeCompare(a.name));
    }

    state.displayedStations = list;

    if (els.countBadge) {
      els.countBadge.textContent = `${list.length} Stations`;
    }

    if (list.length === 0) {
      els.container.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="avatar avatar-xl rounded bg-label-secondary mx-auto mb-3">
            <i class="icon-base bx bx-broadcast fs-2"></i>
          </div>
          <h5 class="mb-1">No Radio Stations Found</h5>
          <p class="text-body-secondary mb-0">Try selecting another category or adjusting your search criteria.</p>
        </div>`;
      if (els.pagination) {
        els.pagination.classList.add('d-none');
        els.pagination.innerHTML = '';
      }
      return;
    }

    const total = list.length;
    const limit = state.showLimit === 'all' ? total : Number(state.showLimit);
    const totalPages = Math.ceil(total / limit);

    if (state.currentPage > totalPages) {
      state.currentPage = Math.max(1, totalPages);
    }

    const startIdx = (state.currentPage - 1) * limit;
    const endIdx = Math.min(state.currentPage * limit, total);
    const items = list.slice(startIdx, endIdx);

    const colClass = getColClasses(state.gridCols);
    let html = '';
    items.forEach((s) => {
      const fav = isFavorite(s.id);
      const hasLogo = Boolean(s.logo && s.logo.trim());

      html += `
        <div class="${colClass}">
          <div class="card h-100 radio-station-card channel-card border shadow-sm" data-station-id="${s.id}">
            <div class="position-relative radio-station-logo-box">
              <a href="javascript:void(0);" class="station-play-trigger d-flex align-items-center justify-content-center w-100 h-100" data-station-id="${s.id}">
                ${hasLogo ? `
                  <img
                    src="${s.logo}"
                    alt="${s.name}"
                    class="radio-station-logo-img"
                    loading="lazy"
                    onerror="this.classList.add('d-none'); this.nextElementSibling.classList.remove('d-none');" />
                  <div class="avatar avatar-xl rounded bg-label-primary align-items-center justify-content-center d-none">
                    <i class="icon-base bx bx-broadcast fs-2"></i>
                  </div>` : `
                  <div class="avatar avatar-xl rounded bg-label-primary d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-broadcast fs-2"></i>
                  </div>`
                }
              </a>
              <span class="position-absolute top-0 end-0 m-2 badge bg-success bg-opacity-90">
                <i class="icon-base bx bx-radio me-1"></i>LIVE
              </span>
              <button
                type="button"
                class="btn btn-sm btn-icon btn-text-secondary rounded-pill position-absolute top-0 start-0 m-2 bg-dark bg-opacity-50 text-white"
                data-fav-radio-id="${s.id}"
                title="Toggle Favorite">
                <i class="icon-base ${fav ? 'bx bxs-star text-warning' : 'bx bx-star'}"></i>
              </button>
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div>
                <h6 class="card-title text-truncate mb-1 fw-bold" title="${s.name}">
                  <a href="javascript:void(0);" class="text-heading station-play-trigger" data-station-id="${s.id}">${s.name}</a>
                </h6>
              </div>
              <div class="d-flex align-items-center justify-content-between text-body-secondary small mt-2 pt-2 border-top">
                <span class="badge bg-label-secondary rounded-pill px-2 py-0">Radio Stream</span>
                <button
                  type="button"
                  class="btn btn-xs btn-primary d-flex align-items-center gap-1 station-play-trigger"
                  data-station-id="${s.id}">
                  <i class="icon-base bx bx-play"></i> Listen
                </button>
              </div>
            </div>
          </div>
        </div>`;
    });

    els.container.innerHTML = html;
    renderItemsPagination(total, limit, totalPages, startIdx, endIdx);
  };

  const renderMiniZapList = () => {
    if (!els.miniZapList) return;

    const query = els.miniZapSearch ? els.miniZapSearch.value.trim().toLowerCase() : '';
    let list = state.allStations;
    if (query) {
      list = list.filter((s) => s.name && s.name.toLowerCase().includes(query));
    }

    let html = '';
    list.forEach((s) => {
      const active = state.currentStation && Number(state.currentStation.id) === Number(s.id);
      html += `
        <a
          href="javascript:void(0);"
          class="list-group-item list-group-item-action d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 ${active ? 'active' : ''}"
          data-zap-station-id="${s.id}">
          <div class="d-flex align-items-center text-truncate me-2">
            <i class="icon-base bx bx-broadcast me-2 ${active ? 'text-white' : 'text-primary'}"></i>
            <span class="text-truncate fw-medium">${s.name}</span>
          </div>
          ${active ? `<span class="badge bg-white text-primary rounded-pill px-2">Playing</span>` : ''}
        </a>`;
    });

    els.miniZapList.innerHTML = html || `<p class="text-body-secondary text-center small p-3 mb-0">No stations match search</p>`;
  };

  const updateBottomBarContent = () => {
    if (!state.currentStation) return;
    const s = state.currentStation;
    if (els.barTitle) els.barTitle.textContent = s.name || 'Radio Broadcast';
    if (els.barLogo && els.barAvatarPlaceholder) {
      if (s.logo && s.logo.trim()) {
        els.barLogo.src = s.logo;
        els.barLogo.classList.remove('d-none');
        els.barAvatarPlaceholder.classList.add('d-none');
      } else {
        els.barLogo.classList.add('d-none');
        els.barAvatarPlaceholder.classList.remove('d-none');
      }
    }
    if (els.barVolume && els.audio) {
      els.barVolume.value = els.audio.volume;
    }
    updateBottomBarFav();
  };

  const initBottomBarElements = () => {
    els.bottomBar = document.getElementById('radio-bottom-bar');
    els.audio = document.getElementById('radio-audio');
    if (els.bottomBar) {
      els.barLogo = document.getElementById('radio-bar-logo');
      els.barAvatarPlaceholder = document.getElementById('radio-bar-avatar-placeholder');
      els.barTitle = document.getElementById('radio-bar-title');
      els.barBadge = document.getElementById('radio-bar-badge');
      els.barSubtitle = document.getElementById('radio-bar-subtitle');
      els.barPlay = document.getElementById('btn-radio-bar-play');
      els.barPrev = document.getElementById('btn-radio-bar-prev');
      els.barNext = document.getElementById('btn-radio-bar-next');
      els.barStatusText = document.getElementById('radio-bar-status-text');
      els.barIndicatorDot = document.getElementById('radio-bar-indicator-dot');
      els.barMute = document.getElementById('btn-radio-bar-mute');
      els.barVolume = document.getElementById('radio-bar-volume');
      els.barFav = document.getElementById('btn-radio-bar-fav');
      els.barExpand = document.getElementById('btn-radio-bar-expand');
      els.barClose = document.getElementById('btn-radio-bar-close');
    }
  };

  /**
   * Keep radio bottom bar strictly bounded to the Content container (.container-xxl)
   * so it stays within the content area width and never stretches across the whole screen.
   */
  const updateBottomBarPosition = () => {
    initBottomBarElements();
    if (!els.bottomBar) return;

    const contentContainer = document.getElementById('main-content-container') || document.querySelector('.container-xxl');
    if (contentContainer) {
      const rect = contentContainer.getBoundingClientRect();
      els.bottomBar.style.position = 'fixed';
      els.bottomBar.style.bottom = '16px';
      els.bottomBar.style.left = Math.round(rect.left) + 'px';
      els.bottomBar.style.width = Math.round(rect.width) + 'px';
      els.bottomBar.style.right = 'auto';
      els.bottomBar.style.zIndex = '1030';
    }
  };

  const showBottomBar = () => {
    initBottomBarElements();
    if (!els.bottomBar) return;
    if (!state.currentStation) return;
    updateBottomBarContent();
    updateBottomBarPosition();
    els.bottomBar.classList.remove('d-none');
    els.bottomBar.classList.add('d-flex');
    els.bottomBar.style.setProperty('display', 'flex', 'important');
    if (typeof els.bottomBar.animate === 'function') {
      els.bottomBar.animate([
        { transform: 'translateY(100%)', opacity: 0 },
        { transform: 'translateY(0)', opacity: 1 }
      ], {
        duration: 350,
        easing: 'cubic-bezier(0.16, 1, 0.3, 1)'
      });
    }
    if (els.browserView) {
      els.browserView.classList.add('mb-5', 'pb-4');
    }
  };

  const minimizeToBottomBar = () => {
    initBottomBarElements();
    if (!state.currentStation) {
      if (els.playerView) els.playerView.classList.add('d-none');
      if (els.browserView) els.browserView.classList.remove('d-none');
      return;
    }

    const card = els.playerView ? (els.playerView.querySelector('.card') || els.playerView) : null;
    if (card && typeof card.animate === 'function') {
      const anim = card.animate([
        { transform: 'translateY(0)', opacity: 1 },
        { transform: 'translateY(160px)', opacity: 0 }
      ], {
        duration: 300,
        easing: 'cubic-bezier(0.32, 0, 0.67, 0)'
      });
      anim.onfinish = () => {
        if (els.playerView) els.playerView.classList.add('d-none');
        if (els.browserView) els.browserView.classList.remove('d-none');
        showBottomBar();
      };
    } else {
      if (els.playerView) els.playerView.classList.add('d-none');
      if (els.browserView) els.browserView.classList.remove('d-none');
      showBottomBar();
    }
  };

  const expandToStudio = () => {
    initBottomBarElements();
    if (!els.bottomBar || !state.currentStation) return;
    if (!els.playerView) {
      if (window.SPA) {
        window.SPA.navigate((state.baseUrl || '/') + 'radio');
      } else {
        window.location.href = (state.baseUrl || '/') + 'radio';
      }
      return;
    }
    if (typeof els.bottomBar.animate === 'function') {
      const anim = els.bottomBar.animate([
        { transform: 'translateY(0)', opacity: 1 },
        { transform: 'translateY(100%)', opacity: 0 }
      ], {
        duration: 250,
        easing: 'cubic-bezier(0.7, 0, 0.84, 0)'
      });
      anim.onfinish = () => {
        els.bottomBar.classList.add('d-none');
        els.bottomBar.classList.remove('d-flex');
        els.bottomBar.style.display = 'none';
        openStudioStage();
      };
    } else {
      els.bottomBar.classList.add('d-none');
      els.bottomBar.classList.remove('d-flex');
      els.bottomBar.style.display = 'none';
      openStudioStage();
    }
  };

  const openStudioStage = () => {
    if (els.browserView) {
      els.browserView.classList.add('d-none');
      els.browserView.classList.remove('mb-5', 'pb-4');
    }
    if (els.playerView) {
      els.playerView.classList.remove('d-none');
      const card = els.playerView.querySelector('.card') || els.playerView;
      if (card && typeof card.animate === 'function') {
        card.animate([
          { transform: 'translateY(160px)', opacity: 0 },
          { transform: 'translateY(0)', opacity: 1 }
        ], {
          duration: 350,
          easing: 'cubic-bezier(0.16, 1, 0.3, 1)'
        });
      }
    }
  };

  const closeBottomBar = () => {
    if (!els.bottomBar) return;
    if (typeof els.bottomBar.animate === 'function') {
      const anim = els.bottomBar.animate([
        { transform: 'translateY(0)', opacity: 1 },
        { transform: 'translateY(100%)', opacity: 0 }
      ], {
        duration: 250,
        easing: 'cubic-bezier(0.7, 0, 0.84, 0)'
      });
      anim.onfinish = () => {
        els.bottomBar.classList.add('d-none');
        els.bottomBar.classList.remove('d-flex');
        if (els.browserView) {
          els.browserView.classList.remove('mb-5', 'pb-4');
        }
        stopPlayer();
      };
    } else {
      els.bottomBar.classList.add('d-none');
      els.bottomBar.classList.remove('d-flex');
      if (els.browserView) {
        els.browserView.classList.remove('mb-5', 'pb-4');
      }
      stopPlayer();
    }
  };

  const playPrevStation = () => {
    if (!state.currentStation || !state.allStations.length) return;
    const idx = state.allStations.findIndex((s) => Number(s.id) === Number(state.currentStation.id));
    const prevIdx = idx > 0 ? idx - 1 : state.allStations.length - 1;
    playStation(state.allStations[prevIdx], false);
  };

  const playNextStation = () => {
    if (!state.currentStation || !state.allStations.length) return;
    const idx = state.allStations.findIndex((s) => Number(s.id) === Number(state.currentStation.id));
    const nextIdx = idx < state.allStations.length - 1 ? idx + 1 : 0;
    playStation(state.allStations[nextIdx], false);
  };

  const updateMuteIcons = (muted) => {
    if (els.btnMute) {
      const icon = els.btnMute.querySelector('i');
      if (icon) {
        icon.className = muted ? 'icon-base bx bx-volume-mute text-danger' : 'icon-base bx bx-volume-full';
      }
    }
    if (els.barMute) {
      const icon = els.barMute.querySelector('i');
      if (icon) {
        icon.className = muted ? 'icon-base bx bx-volume-mute text-danger' : 'icon-base bx bx-volume-full';
      }
    }
  };

  const toggleMute = () => {
    if (!els.audio) return;
    els.audio.muted = !els.audio.muted;
    updateMuteIcons(els.audio.muted);
  };

  const highlightActiveStationInGrid = () => {
    document.querySelectorAll('.radio-station-card').forEach((card) => {
      const sId = card.getAttribute('data-station-id');
      const isCurrent = state.currentStation && Number(state.currentStation.id) === Number(sId);
      card.classList.toggle('border-primary', isCurrent);
    });
  };

  const playStation = (station, openStudio = true) => {
    state.currentStation = station;

    // Switch Views based on openStudio parameter
    if (openStudio) {
      if (els.bottomBar) {
        els.bottomBar.classList.add('d-none');
        els.bottomBar.classList.remove('d-flex');
      }
      if (els.browserView) {
        els.browserView.classList.add('d-none');
        els.browserView.classList.remove('mb-5', 'pb-4');
      }
      if (els.playerView) els.playerView.classList.remove('d-none');
    } else {
      if (els.playerView) els.playerView.classList.add('d-none');
      if (els.browserView) {
        els.browserView.classList.remove('d-none');
        els.browserView.classList.add('mb-5', 'pb-4');
      }
      showBottomBar();
    }

    // Update Header Metadata
    if (els.stationName) els.stationName.textContent = station.name;
    if (els.stationLogo) {
      if (station.logo) {
        els.stationLogo.src = station.logo;
        els.stationLogo.classList.remove('d-none');
      } else {
        els.stationLogo.classList.add('d-none');
      }
    }

    // Update Turntable Center Spindle Logo
    if (els.centerLogo) {
      if (station.logo) {
        els.centerLogo.src = station.logo;
        els.centerLogo.classList.remove('d-none');
      } else {
        els.centerLogo.classList.add('d-none');
      }
    }

    // Update Bottom Bar Metadata
    updateBottomBarContent();

    updatePlayerFavButton();
    renderMiniZapList();
    highlightActiveStationInGrid();

    // Start Audio
    if (els.audio) {
      if (state.hls) {
        try {
          state.hls.destroy();
        } catch (e) {}
        state.hls = null;
      }

      els.audio.pause();
      els.audio.removeAttribute('src');
      els.audio.load();

      const streamUrl = station.url || station.direct_source || '';
      const isHls = streamUrl && (streamUrl.includes('.m3u8') || (station.container && station.container.includes('m3u8')));

      if (isHls && typeof Hls !== 'undefined' && Hls.isSupported()) {
        const hls = new Hls({
          enableWorker: true,
          lowLatencyMode: true
        });
        hls.loadSource(streamUrl);
        hls.attachMedia(els.audio);
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
          const playPromise = els.audio.play();
          if (playPromise !== undefined) {
            playPromise
              .then(() => setPlayingState(true))
              .catch((err) => {
                console.warn('HLS Audio play prevented:', err);
                setPlayingState(false);
              });
          }
        });
        hls.on(Hls.Events.ERROR, (event, data) => {
          if (data.fatal) {
            switch (data.type) {
              case Hls.ErrorTypes.NETWORK_ERROR:
                hls.startLoad();
                break;
              case Hls.ErrorTypes.MEDIA_ERROR:
                hls.recoverMediaError();
                break;
              default:
                hls.destroy();
                setPlayingState(false);
                break;
            }
          }
        });
        state.hls = hls;
      } else if (streamUrl) {
        els.audio.src = streamUrl;
        els.audio.load();

        const playPromise = els.audio.play();
        if (playPromise !== undefined) {
          playPromise
            .then(() => {
              setPlayingState(true);
            })
            .catch((err) => {
              console.warn('Audio play prevented:', err);
              setPlayingState(false);
            });
        }
      }
    }
  };

  const setPlayingState = (playing) => {
    state.isPlaying = playing;

    if (els.btnPlayPause) {
      const icon = els.btnPlayPause.querySelector('i');
      if (icon) {
        icon.className = playing ? 'icon-base bx bx-pause fs-4' : 'icon-base bx bx-play fs-4';
      }
    }

    if (els.barPlay) {
      const barIcon = els.barPlay.querySelector('i');
      if (barIcon) {
        barIcon.className = playing ? 'icon-base bx bx-pause fs-4' : 'icon-base bx bx-play fs-4';
      }
    }

    if (els.barStatusText) {
      els.barStatusText.textContent = playing ? 'Playing Live Stream' : 'Paused';
    }

    if (els.barIndicatorDot) {
      els.barIndicatorDot.className = playing ? 'badge-dot bg-success' : 'badge-dot bg-warning';
    }

    if (els.turntableDisc) {
      els.turntableDisc.classList.toggle('playing', playing);
    }

    if (els.equalizer) {
      els.equalizer.classList.toggle('playing', playing);
    }
  };

  const stopPlayer = () => {
    initBottomBarElements();
    if (state.hls) {
      try {
        state.hls.destroy();
      } catch (e) {}
      state.hls = null;
    }
    if (els.audio) {
      els.audio.pause();
      els.audio.removeAttribute('src');
      els.audio.load();
    }
    setPlayingState(false);
    state.currentStation = null;

    if (els.bottomBar) {
      els.bottomBar.classList.add('d-none');
      els.bottomBar.classList.remove('d-flex');
      els.bottomBar.style.display = 'none';
    }
    if (els.playerView) els.playerView.classList.add('d-none');
    if (els.browserView) {
      els.browserView.classList.remove('d-none');
      els.browserView.classList.remove('mb-5', 'pb-4');
    }
    highlightActiveStationInGrid();
  };

  const bindEvents = () => {
    // Category Click
    document.addEventListener('click', (e) => {
      const catBtn = e.target.closest('[data-radio-category-id]');
      if (catBtn) {
        e.preventDefault();
        document.querySelectorAll('[data-radio-category-id]').forEach((el) => el.classList.remove('active'));
        catBtn.classList.add('active');
        state.favFilterActive = false;
        if (els.favBtn) els.favBtn.classList.remove('active');

        const catId = catBtn.getAttribute('data-radio-category-id');
        fetchStationsForCategory(catId === 'all' ? null : catId);
      }
    });

    // Favorite Filter in Toolbar
    if (els.favBtn) {
      els.favBtn.addEventListener('click', (e) => {
        e.preventDefault();
        state.favFilterActive = !state.favFilterActive;
        state.currentPage = 1;
        els.favBtn.classList.toggle('active', state.favFilterActive);
        renderStations();
      });
    }

    // Category Search Filter (with category pagination update)
    if (els.categorySearch) {
      els.categorySearch.addEventListener('input', () => {
        state.catCurrentPage = 1;
        renderCategoriesPagination();
      });
    }

    // Radio Search
    if (els.radioSearch) {
      els.radioSearch.addEventListener('input', () => {
        state.currentPage = 1;
        renderStations();
      });
    }

    // Sort Dropdown
    if (els.sortSelect) {
      els.sortSelect.addEventListener('change', (e) => {
        state.currentSort = e.target.value;
        state.currentPage = 1;
        renderStations();
      });
    }

    // Column Density Selector
    document.querySelectorAll('[data-radio-grid-cols]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-radio-grid-cols]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        state.gridCols = Number(btn.getAttribute('data-radio-grid-cols'));
        renderStations();
      });
    });

    // Show Limit Buttons
    document.querySelectorAll('[data-radio-show-limit]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-radio-show-limit]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        state.showLimit = btn.getAttribute('data-radio-show-limit');
        state.currentPage = 1;
        renderStations();
      });
    });

    // Toggle Categories Sidebar
    if (els.sidebarToggle && els.sidebar) {
      els.sidebarToggle.addEventListener('click', () => {
        els.sidebar.classList.toggle('d-none');
      });
    }

    // Play Station Triggers (Card or Button Click)
    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('.station-play-trigger');
      if (trigger) {
        e.preventDefault();
        const id = trigger.getAttribute('data-station-id');
        const station = state.allStations.find((s) => Number(s.id) === Number(id));
        if (station) {
          playStation(station);
        }
      }
    });

    // Toggle Favorite on Card
    document.addEventListener('click', (e) => {
      const favBtn = e.target.closest('[data-fav-radio-id]');
      if (favBtn) {
        e.preventDefault();
        e.stopPropagation();
        const id = favBtn.getAttribute('data-fav-radio-id');
        toggleFavorite(id);
      }
    });

    // Back Button (Minimize from Studio Player to Grid with slide-down effect)
    if (els.btnBack) {
      els.btnBack.addEventListener('click', (e) => {
        e.preventDefault();
        minimizeToBottomBar();
      });
    }

    // Topbar Minimize Button
    if (els.btnMinimize) {
      els.btnMinimize.addEventListener('click', (e) => {
        e.preventDefault();
        minimizeToBottomBar();
      });
    }

    // Bottom Bar Play/Pause Button
    if (els.barPlay && els.audio) {
      els.barPlay.addEventListener('click', () => {
        if (state.isPlaying) {
          els.audio.pause();
          setPlayingState(false);
        } else if (state.currentStation) {
          els.audio.play()
            .then(() => setPlayingState(true))
            .catch(() => setPlayingState(false));
        }
      });
    }

    // Bottom Bar Previous Station Button
    if (els.barPrev) {
      els.barPrev.addEventListener('click', (e) => {
        e.preventDefault();
        playPrevStation();
      });
    }

    // Bottom Bar Next Station Button
    if (els.barNext) {
      els.barNext.addEventListener('click', (e) => {
        e.preventDefault();
        playNextStation();
      });
    }

    // Bottom Bar Mute Button
    if (els.barMute) {
      els.barMute.addEventListener('click', (e) => {
        e.preventDefault();
        toggleMute();
      });
    }

    // Bottom Bar Volume Slider
    if (els.barVolume && els.audio) {
      els.barVolume.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        els.audio.volume = val;
        els.audio.muted = (val === 0);
        if (els.volumeSlider) els.volumeSlider.value = val;
        updateMuteIcons(val === 0);
      });
    }

    // Bottom Bar Favorite Button
    if (els.barFav) {
      els.barFav.addEventListener('click', (e) => {
        e.preventDefault();
        if (state.currentStation) {
          toggleFavorite(state.currentStation.id);
        }
      });
    }

    // Bottom Bar Expand to Studio Button
    if (els.barExpand) {
      els.barExpand.addEventListener('click', (e) => {
        e.preventDefault();
        expandToStudio();
      });
    }

    // Bottom Bar Close / Stop Button
    if (els.barClose) {
      els.barClose.addEventListener('click', (e) => {
        e.preventDefault();
        closeBottomBar();
      });
    }

    // Player Play/Pause Button
    if (els.btnPlayPause && els.audio) {
      els.btnPlayPause.addEventListener('click', () => {
        if (state.isPlaying) {
          els.audio.pause();
          setPlayingState(false);
        } else if (state.currentStation) {
          els.audio.play()
            .then(() => setPlayingState(true))
            .catch(() => setPlayingState(false));
        }
      });
    }

    // Player Mute Button
    if (els.btnMute && els.audio) {
      els.btnMute.addEventListener('click', () => {
        toggleMute();
      });
    }

    // Player Volume Slider
    if (els.volumeSlider && els.audio) {
      els.volumeSlider.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        els.audio.volume = val;
        els.audio.muted = (val === 0);
        if (els.barVolume) els.barVolume.value = val;
        updateMuteIcons(val === 0);
      });
    }

    // Player Favorite Button
    if (els.btnPlayerFav) {
      els.btnPlayerFav.addEventListener('click', () => {
        if (state.currentStation) {
          toggleFavorite(state.currentStation.id);
        }
      });
    }

    // Zap Item Click in Player View
    document.addEventListener('click', (e) => {
      const zapItem = e.target.closest('[data-zap-station-id]');
      if (zapItem) {
        e.preventDefault();
        const id = zapItem.getAttribute('data-zap-station-id');
        const station = state.allStations.find((s) => Number(s.id) === Number(id));
        if (station) {
          playStation(station, true);
        }
      }
    });

    // Zap Search Input
    if (els.miniZapSearch) {
      els.miniZapSearch.addEventListener('input', () => {
        renderMiniZapList();
      });
    }

    // Audio native events for turntable synchronization
    if (els.audio) {
      els.audio.addEventListener('play', () => setPlayingState(true));
      els.audio.addEventListener('pause', () => setPlayingState(false));
      els.audio.addEventListener('ended', () => setPlayingState(false));
      els.audio.addEventListener('error', () => setPlayingState(false));
    }

    // Auto-stop radio when ANY video plays anywhere on the site (movie, series, live TV)
    document.addEventListener('play', (e) => {
      if (e.target && e.target.tagName === 'VIDEO') {
        if (state.isPlaying || state.currentStation) {
          stopPlayer();
        }
      }
    }, true);

    // Reposition bottom bar on window resize or menu toggle
    window.addEventListener('resize', () => {
      if (els.bottomBar && !els.bottomBar.classList.contains('d-none')) {
        updateBottomBarPosition();
      }
    });

    if (window.Helpers && typeof window.Helpers.on === 'function') {
      try {
        window.Helpers.on('toggle.Helpers', () => {
          setTimeout(updateBottomBarPosition, 150);
        });
      } catch (e) {}
    }
  };

  const start = (config = {}) => {
    state.baseUrl = config.baseUrl || state.baseUrl || '/';
    state.allStations = config.initialStations || [];
    state.activeCategory = config.selectedCategoryId || null;

    initElements();
    loadFavorites();
    bindEvents();
    renderCategoriesPagination();
    renderStations();

    if (state.currentStation) {
      highlightActiveStationInGrid();
      updatePlayerFavButton();
      renderMiniZapList();
    }
  };

  const initGlobal = () => {
    state.baseUrl = document.documentElement.getAttribute('data-base-url') || '/';
    initElements();
    loadFavorites();
    bindEvents();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGlobal);
  } else {
    initGlobal();
  }

  return {
    start,
    initGlobal,
    playStation,
    stopPlayer,
    minimizeToBottomBar,
    expandToStudio,
    updateBottomBarPosition
  };
})();

