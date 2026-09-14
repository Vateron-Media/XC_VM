/**
 * Web Player V2 — Live TV Explorer & Player Engine
 *
 * Implements category filtering with sidebar pagination, instant channel search,
 * sorting, grid density controls, items pagination with page numbers & ellipses,
 * local storage favorites, EPG fetching, and Hls.js video playback.
 */

'use strict';

window.LiveApp = window.LivePlayerApp = (function () {
  let state = {
    activeCategory: null,
    activeChannel: null,
    allChannels: [],
    displayedChannels: [],
    favorites: [],
    gridCols: 4,
    showLimit: 60,
    currentPage: 1,
    catCurrentPage: 1,
    catPageLimit: 10,
    currentSort: 'default',
    favFilterActive: false,
    hls: null,
    vjsPlayer: null,
    baseUrl: '',
    switchTimer: null,
    retryTimer: null,
    retryCount: 0,
    maxRetries: 3,
    currentReqId: 0
  };

  // Cache DOM Elements
  const els = {};

  const initElements = () => {
    els.browserView = document.getElementById('live-browser-view');
    els.playerView = document.getElementById('live-player-view');
    els.categoriesSidebar = document.getElementById('live-categories-sidebar');
    els.categoriesList = document.getElementById('live-categories-list');
    els.categoriesPagination = document.getElementById('live-categories-pagination');
    els.channelsContainer = document.getElementById('live-channels-container');
    els.pagination = document.getElementById('live-pagination');
    els.categorySearch = document.getElementById('live-category-search');
    els.channelSearch = document.getElementById('live-channel-search');
    els.favBtn = document.getElementById('btn-live-favorites');
    els.sortSelect = document.getElementById('live-sort-select');
    els.sidebarToggle = document.getElementById('btn-toggle-categories');
    els.backToGridBtn = document.getElementById('btn-back-live');
    els.video = document.getElementById('live-video');
    els.videoOverlay = document.getElementById('live-player-overlay');
    els.currentChLogo = document.getElementById('live-player-ch-logo');
    els.currentChName = document.getElementById('live-player-ch-name');
    els.currentEpgText = document.getElementById('live-player-epg-text');
    els.epgCurrentProg = document.getElementById('epg-current-program');
    els.epgProgressWrap = document.getElementById('live-epg-progress-wrap');
    els.epgProgressBar = document.getElementById('live-epg-progress-bar');
    els.playerFavBtn = document.getElementById('btn-live-player-fav');
    els.playerFullscreenBtn = document.getElementById('btn-live-fullscreen');
    els.playerSidebarList = document.getElementById('live-player-channel-list');
    els.playerSearchInput = document.getElementById('live-player-search');
    els.channelCountBadge = document.getElementById('live-channel-count-badge');
    els.toolbar = document.querySelector('.bg-body-tertiary');
    els.streamStatus = document.getElementById('live-stream-status');
    els.statusSpinner = document.getElementById('live-status-spinner');
    els.statusIcon = document.getElementById('live-status-icon');
    els.statusTitle = document.getElementById('live-status-title');
    els.statusDesc = document.getElementById('live-status-desc');
    els.retryBtn = document.getElementById('btn-live-retry-stream');
  };

  const loadFavorites = () => {
    try {
      state.favorites = JSON.parse(localStorage.getItem('xc_player_v2_favs_live')) || [];
    } catch (e) {
      state.favorites = [];
    }
  };

  const saveFavorites = () => {
    try {
      localStorage.setItem('xc_player_v2_favs_live', JSON.stringify(state.favorites));
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
      renderChannels();
    }
  };

  const updateFavoriteButtons = () => {
    document.querySelectorAll('[data-fav-stream-id]').forEach((btn) => {
      const sId = btn.getAttribute('data-fav-stream-id');
      const active = isFavorite(sId);
      btn.classList.toggle('text-warning', active);
      const icon = btn.querySelector('i');
      if (icon) {
        icon.className = active ? 'icon-base bx bxs-star text-warning' : 'icon-base bx bx-star text-body-secondary';
      }
    });

    if (state.activeChannel && els.playerFavBtn) {
      const active = isFavorite(state.activeChannel.id);
      els.playerFavBtn.classList.toggle('btn-label-warning', active);
      els.playerFavBtn.classList.toggle('btn-label-secondary', !active);
      const starIcon = els.playerFavBtn.querySelector('i');
      if (starIcon) {
        starIcon.className = active ? 'icon-base bx bxs-star me-1 text-warning' : 'icon-base bx bx-star me-1';
      }
    }
  };

  const getColClasses = (cols) => {
    switch (Number(cols)) {
      case 3:
        return 'col-12 col-sm-6 col-md-4';
      case 6:
        return 'col-6 col-sm-4 col-md-3 col-lg-2';
      case 8:
        return 'col-6 col-sm-3 col-md-2 col-xl-custom-8';
      case 4:
      default:
        return 'col-6 col-sm-4 col-md-3';
    }
  };

  /* ─────────────────────────────────────────────────────────────────
   * Category Sidebar Display & Search Filter
   * Displays all categories dynamically inside scrollable container
   * ───────────────────────────────────────────────────────────────── */
  const renderCategoriesPagination = () => {
    if (!els.categoriesList) return;

    const allCatItems = Array.from(els.categoriesList.querySelectorAll('[data-category-id]'));
    const val = els.categorySearch ? els.categorySearch.value.trim().toLowerCase() : '';

    // Filter matching items (pinned "all" stays visible at top)
    allCatItems.forEach((item) => {
      const catId = item.getAttribute('data-category-id');
      if (catId === 'all') {
        item.classList.remove('d-none');
      } else {
        const text = item.textContent.toLowerCase();
        const raw = (item.getAttribute('data-category-raw') || '').toLowerCase();
        const matches = !val || text.includes(val) || raw.includes(val);
        if (matches) {
          item.classList.remove('d-none');
        } else {
          item.classList.add('d-none');
        }
      }
    });

    // All categories displayed directly in scrollable sidebar
    if (els.categoriesPagination) {
      els.categoriesPagination.classList.add('d-none');
      els.categoriesPagination.innerHTML = '';
    }

    // Smoothly scroll active category into view if present
    const activeItem = els.categoriesList.querySelector('.category-list-item.active');
    if (activeItem && typeof activeItem.scrollIntoView === 'function') {
      activeItem.scrollIntoView({ block: 'nearest' });
    }
  };

  const fetchChannelsForCategory = async (catId) => {
    state.activeCategory = catId;
    state.currentPage = 1;
    if (els.channelsContainer) {
      els.channelsContainer.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="text-body-secondary mt-2 mb-0">Loading channels...</p>
        </div>`;
    }
    if (els.pagination) {
      els.pagination.classList.add('d-none');
    }

    try {
      const res = await fetch(`${state.baseUrl}live?ajax=1&category_id=${encodeURIComponent(catId || '')}`);
      const data = await res.json();
      if (data && data.channels) {
        state.allChannels = data.channels;
        renderChannels();
      }
    } catch (err) {
      console.error('Error loading channels:', err);
      if (els.channelsContainer) {
        els.channelsContainer.innerHTML = `
          <div class="col-12 text-center py-5">
            <i class="icon-base bx bx-error-circle text-danger fs-1 mb-2"></i>
            <p class="text-body-secondary">Failed to load channels. Please try again.</p>
          </div>`;
      }
    }
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
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary pagination-page-btn" data-channel-page="prev" ${state.currentPage === 1 ? 'disabled' : ''} title="Previous Page">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
    `;

    pageNums.forEach((p) => {
      if (p === '...') {
        buttonsHtml += `<span class="px-2 text-body-secondary small">•••</span>`;
      } else {
        const activeClass = p === state.currentPage ? 'btn-primary shadow-sm' : 'btn-label-secondary';
        buttonsHtml += `
          <button type="button" class="btn btn-sm btn-icon pagination-page-btn ${activeClass}" data-channel-page="${p}">
            ${p}
          </button>
        `;
      }
    });

    buttonsHtml += `
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary pagination-page-btn" data-channel-page="next" ${state.currentPage === totalPages ? 'disabled' : ''} title="Next Page">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    `;

    els.pagination.innerHTML = `
      <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3 w-100">
        <span class="text-body-secondary small order-2 order-sm-1">
          Showing ${startIdx + 1}–${endIdx} of ${total} Channels
        </span>
        <div class="d-flex align-items-center gap-1 order-1 order-sm-2">
          ${buttonsHtml}
        </div>
      </div>
    `;

    els.pagination.querySelectorAll('[data-channel-page]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const action = btn.getAttribute('data-channel-page');
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
          renderChannels();
          if (els.toolbar) {
            els.toolbar.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    });
  };

  const renderChannels = () => {
    if (!els.channelsContainer) return;

    let list = [...state.allChannels];

    // Favorites Filter
    if (state.favFilterActive) {
      list = list.filter((ch) => isFavorite(ch.id));
    }

    // Search Filter
    const searchVal = els.channelSearch ? els.channelSearch.value.trim().toLowerCase() : '';
    if (searchVal) {
      list = list.filter((ch) => ch.name.toLowerCase().includes(searchVal));
    }

    // Sorting
    const sortVal = els.sortSelect ? els.sortSelect.value : state.currentSort;
    if (sortVal === 'alpha_az') {
      list.sort((a, b) => a.name.localeCompare(b.name));
    } else if (sortVal === 'alpha_za') {
      list.sort((a, b) => b.name.localeCompare(a.name));
    } else if (sortVal === 'lang_ar') {
      list.sort((a, b) => {
        const aAr = /[\u0600-\u06FF]/.test(a.name) ? 1 : 0;
        const bAr = /[\u0600-\u06FF]/.test(b.name) ? 1 : 0;
        return bAr - aAr;
      });
    } else if (sortVal === 'lang_en') {
      list.sort((a, b) => {
        const aEn = /^[A-Za-z0-9]/.test(a.name) ? 1 : 0;
        const bEn = /^[A-Za-z0-9]/.test(b.name) ? 1 : 0;
        return bEn - aEn;
      });
    }

    state.displayedChannels = list;

    if (els.channelCountBadge) {
      els.channelCountBadge.textContent = `${list.length} Channels`;
    }

    // Render HTML
    if (list.length === 0) {
      els.channelsContainer.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="avatar avatar-xl rounded bg-label-secondary mx-auto mb-3">
            <i class="icon-base bx bx-tv fs-2"></i>
          </div>
          <h5 class="mb-1">No Channels Found</h5>
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
    items.forEach((ch) => {
      const fav = isFavorite(ch.id);
      const isCur = state.activeChannel && Number(ch.id) === Number(state.activeChannel.id);
      const logoHtml = ch.logo
        ? `<img src="${ch.logo}" alt="${ch.name}" class="channel-logo-img" loading="lazy" onerror="this.onerror=null;this.parentElement.innerHTML='<i class=\\'icon-base bx bx-tv text-primary fs-2\\'></i>';">`
        : `<i class="icon-base bx bx-tv text-primary fs-2"></i>`;

      html += `
        <div class="${colClass}">
          <div class="card h-100 channel-card border shadow-sm ${isCur ? 'border-primary shadow-sm active' : ''}" data-channel-id="${ch.id}">
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div class="d-flex align-items-start justify-content-between mb-2">
                <span class="badge ${isCur ? 'bg-primary' : 'bg-label-danger'} py-1 px-2">
                  <span class="live-badge-dot me-1"></span> ${isCur ? 'PLAYING' : 'LIVE'}
                </span>
                <button type="button" class="btn btn-sm btn-icon btn-text-secondary rounded-pill" data-fav-stream-id="${ch.id}" title="Toggle Favorite">
                  <i class="icon-base ${fav ? 'bx bxs-star text-warning' : 'bx bx-star text-body-secondary'}"></i>
                </button>
              </div>
              <div class="channel-logo-container mb-2 text-center" data-play-channel="${ch.id}">
                ${logoHtml}
              </div>
              <div class="text-center mt-auto" data-play-channel="${ch.id}">
                <h6 class="card-title mb-1 text-truncate fw-bold ${isCur ? 'text-primary' : ''}" title="${ch.name}">${ch.name}</h6>
                <small class="text-body-secondary d-block text-truncate" data-epg-id="${ch.id}">Loading EPG...</small>
              </div>
            </div>
          </div>
        </div>`;
    });

    els.channelsContainer.innerHTML = html;
    fetchEPG(items);
    renderItemsPagination(total, limit, totalPages, startIdx, endIdx);
  };

  const populatePlayerSidebar = (channels, activeId) => {
    if (!els.playerSidebarList) return;
    const filter = els.playerSearchInput ? els.playerSearchInput.value.trim().toLowerCase() : '';

    let html = '';
    channels.forEach((ch) => {
      if (filter && !ch.name.toLowerCase().includes(filter)) return;
      const isCur = Number(ch.id) === Number(activeId);
      const fav = isFavorite(ch.id);

      html += `
        <div class="live-zap-item d-flex align-items-center p-2 rounded mb-1 ${isCur ? 'active' : ''}" data-player-zap-id="${ch.id}">
          <div class="channel-logo-box me-2 d-flex align-items-center justify-content-center">
            ${ch.logo ? `<img src="${ch.logo}" alt="" class="channel-zap-logo" onerror="this.onerror=null;this.parentElement.innerHTML='<i class=\\'icon-base bx bx-tv text-primary\\'></i>';">` : `<i class="icon-base bx bx-tv ${isCur ? 'text-primary' : 'text-body-secondary'}"></i>`}
          </div>
          <div class="flex-grow-1 overflow-hidden me-1">
            <div class="fw-semibold text-truncate small channel-zap-name ${isCur ? 'text-primary' : ''}">${ch.name}</div>
            <div class="text-body-secondary text-truncate fs-tiny d-flex align-items-center gap-1">
              ${isCur ? '<span class="badge bg-label-primary px-1 py-0 rounded fw-bold">NOW PLAYING</span>' : '<span class="text-body-secondary">LIVE Broadcast</span>'}
            </div>
          </div>
          <div class="d-flex align-items-center gap-1 flex-shrink-0">
            ${isCur ? '<span class="badge bg-primary rounded-pill p-1 d-flex align-items-center justify-content-center" title="Currently Playing"><i class="icon-base bx bx-volume-full text-white fs-6"></i></span>' : ''}
            ${fav ? '<i class="icon-base bx bxs-star text-warning fs-tiny" title="Favorite"></i>' : ''}
          </div>
        </div>`;
    });

    els.playerSidebarList.innerHTML = html;

    // Smooth auto-scroll the active channel into view inside the Channel List sidebar
    const activeEl = els.playerSidebarList.querySelector('.live-zap-item.active');
    if (activeEl) {
      activeEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  };

  const fetchEPG = (channels) => {
    if (!Array.isArray(channels) || channels.length === 0) return;
    const ids = channels.map((c) => c.id).filter(Boolean);
    if (ids.length === 0) return;

    fetch(`${state.baseUrl}listings?stream_ids=${ids.join(',')}`)
      .then((res) => res.json())
      .then((data) => {
        if (!data || typeof data !== 'object') return;
        ids.forEach((streamId) => {
          let title = '';
          if (data[streamId] && data[streamId].now && data[streamId].now.title) {
            title = data[streamId].now.title;
          } else if (Array.isArray(data.Channels)) {
            const ch = data.Channels.find((c) => String(c.Id) === String(streamId));
            if (ch && Array.isArray(ch.TvListings) && ch.TvListings[0] && ch.TvListings[0].Title) {
              title = ch.TvListings[0].Title;
            }
          }
          const el = document.querySelector(`[data-epg-id="${streamId}"]`);
          if (el) {
            el.textContent = title || 'Live Transmission';
          }
        });
      })
      .catch(() => {});
  };

  const updatePlayerEPG = (streamId) => {
    if (!els.epgCurrentProg) return;

    els.epgCurrentProg.textContent = 'Loading live EPG...';
    if (els.currentEpgText) els.currentEpgText.textContent = '';
    if (els.epgProgressWrap) els.epgProgressWrap.classList.add('d-none');

    fetch(`${state.baseUrl}listings?stream_ids=${streamId}`)
      .then((res) => res.json())
      .then((data) => {
        let progTitle = 'Regular Programming';
        let progTime = 'Continuous Broadcast';
        let progPct = null;

        if (data && data[streamId] && data[streamId].now) {
          const now = data[streamId].now;
          progTitle = now.title || progTitle;
          if (now.start || now.end) {
            progTime = `${now.start || ''} - ${now.end || ''}`;
          }
          progPct = now.percentage;
        } else if (data && Array.isArray(data.Channels)) {
          const ch = data.Channels.find((c) => String(c.Id) === String(streamId));
          if (ch && Array.isArray(ch.TvListings) && ch.TvListings[0]) {
            const item = ch.TvListings[0];
            progTitle = item.Title || progTitle;
            if (item.StartTime || item.EndTime) {
              progTime = `${item.StartTime || ''} - ${item.EndTime || ''}`;
            }
            progPct = item.RelativeSize;
          }
        }

        els.epgCurrentProg.textContent = progTitle;
        if (els.currentEpgText) {
          els.currentEpgText.textContent = progTime;
        }
        if (els.epgProgressWrap && progPct !== null && progPct !== undefined) {
          els.epgProgressWrap.classList.remove('d-none');
          if (els.epgProgressBar) {
            els.epgProgressBar.style.width = `${Math.min(100, Math.max(0, progPct))}%`;
          }
        }
      })
      .catch(() => {
        els.epgCurrentProg.textContent = 'Regular Programming';
      });
  };

  // Stream Status Overlay Handlers
  const showStreamStatus = (title, desc, isError = false, onRetry = null) => {
    if (!els.streamStatus) return;
    els.streamStatus.classList.remove('d-none');
    els.streamStatus.classList.add('d-flex');

    if (els.statusSpinner) els.statusSpinner.classList.toggle('d-none', isError);
    if (els.statusIcon) els.statusIcon.classList.toggle('d-none', !isError);
    if (els.statusTitle) els.statusTitle.textContent = title;
    if (els.statusDesc) els.statusDesc.textContent = desc;

    if (els.retryBtn) {
      if (onRetry) {
        els.retryBtn.classList.remove('d-none');
        els.retryBtn.onclick = () => {
          hideStreamStatus();
          onRetry();
        };
      } else {
        els.retryBtn.classList.add('d-none');
        els.retryBtn.onclick = null;
      }
    }
  };

  const hideStreamStatus = () => {
    if (!els.streamStatus) return;
    els.streamStatus.classList.remove('d-flex');
    els.streamStatus.classList.add('d-none');
  };

  // Clean Teardown to prevent 509 Max Connections Exceeded on external servers
  const teardownPlayer = (cleanMedia = true) => {
    if (state.retryTimer) {
      clearTimeout(state.retryTimer);
      state.retryTimer = null;
    }
    if (state.switchTimer) {
      clearTimeout(state.switchTimer);
      state.switchTimer = null;
    }
    if (state.vjsPlayer) {
      try {
        state.vjsPlayer.pause();
        if (cleanMedia) {
          state.vjsPlayer.reset();
        }
      } catch (e) {}
    }
    if (state.hls) {
      try {
        state.hls.destroy();
      } catch (e) {}
      state.hls = null;
    }
    if (els.video) {
      try {
        els.video.pause();
        if (cleanMedia && !state.vjsPlayer) {
          els.video.removeAttribute('src');
          els.video.load();
        }
      } catch (e) {}
    }
  };

  // MIME Resolution
  const resolveStreamMime = (url) => {
    if (!url) return 'application/x-mpegURL';
    const clean = url.split('?')[0].toLowerCase();
    if (clean.endsWith('.mp4')) return 'video/mp4';
    if (clean.endsWith('.webm')) return 'video/webm';
    if (clean.endsWith('.ts')) return 'video/mp2t';
    return 'application/x-mpegURL';
  };

  // Ensure VideoJS Player Instance with VHS error hooks
  const ensureVjsPlayer = () => {
    if (typeof videojs === 'undefined') return null;
    if (state.vjsPlayer) return state.vjsPlayer;

    try {
      state.vjsPlayer = videojs.getPlayer('live-video') || videojs('live-video', {
        autoplay: true,
        fill: true,
        liveui: true,
        controls: true,
        preload: 'none',
        responsive: true,
        html5: {
          vhs: {
            overrideNative: !videojs.browser.IS_ANY_SAFARI,
            handlePartialData: true,
            maxPlaylistRetries: 5,
            reloadSourceOnError: false,
            limitRenditionByPlayerDimensions: false,
            experimentalBufferClipping: true
          }
        }
      });

      // Clear retry counters when playback successfully starts
      state.vjsPlayer.on('playing', () => {
        state.retryCount = 0;
        if (state.retryTimer) {
          clearTimeout(state.retryTimer);
          state.retryTimer = null;
        }
        hideStreamStatus();
      });

      // Handle stream errors (e.g. 509 concurrent connections, 206 mismatch, network hiccup)
      state.vjsPlayer.on('error', () => {
        const err = state.vjsPlayer.error();
        console.warn('[LivePlayer] VideoJS Error:', err);

        if (!state.activeChannel) return;

        if (state.retryCount < state.maxRetries) {
          state.retryCount++;
          showStreamStatus(
            'Reconnecting Stream...',
            `The external IPTV server closed the connection (Limit/Network drop). Restoring automatically (${state.retryCount}/${state.maxRetries})...`,
            false
          );

          // Force release socket on external server
          try {
            state.vjsPlayer.pause();
            state.vjsPlayer.reset();
          } catch (e) {}

          const activeReq = state.currentReqId;
          state.retryTimer = setTimeout(() => {
            if (activeReq !== state.currentReqId || !state.activeChannel) return;
            loadChannelSource(state.activeChannel, activeReq);
          }, 2000);
        } else {
          showStreamStatus(
            'Stream Disconnected',
            'Unable to sustain playback from the external server (Max connections limit or temporary source outage). Click Retry to connect again.',
            true,
            () => {
              state.retryCount = 0;
              playChannel(state.activeChannel);
            }
          );
        }
      });
    } catch (e) {
      console.warn('VideoJS init fallback:', e);
      try {
        state.vjsPlayer = videojs('live-video');
      } catch (err) {}
    }
    return state.vjsPlayer;
  };

  // Load Channel Stream Source
  const loadChannelSource = (channel, reqId) => {
    if (reqId !== state.currentReqId || !channel || !channel.url) return;

    if (typeof videojs !== 'undefined') {
      const player = ensureVjsPlayer();
      if (player) {
        try {
          player.error(null);
        } catch (e) {}

        const mime = resolveStreamMime(channel.url);
        player.src({
          src: channel.url,
          type: mime
        });

        player.ready(() => {
          if (reqId !== state.currentReqId) return;
          const playPromise = player.play();
          if (playPromise !== undefined) {
            playPromise.catch(() => {});
          }
        });
      }
    } else {
      // Fallback to Hls.js
      if (state.hls) {
        state.hls.destroy();
        state.hls = null;
      }

      if (els.video) {
        if (typeof Hls !== 'undefined' && Hls.isSupported()) {
          const hls = new Hls({
            enableWorker: true,
            lowLatencyMode: true,
            backBufferLength: 90
          });
          hls.loadSource(channel.url);
          hls.attachMedia(els.video);
          hls.on(Hls.Events.MANIFEST_PARSED, () => {
            if (reqId !== state.currentReqId) return;
            els.video.play().catch(() => {});
            hideStreamStatus();
          });
          hls.on(Hls.Events.ERROR, (event, data) => {
            if (data.fatal) {
              switch (data.type) {
                case Hls.ErrorTypes.NETWORK_ERROR:
                  if (state.retryCount < state.maxRetries) {
                    state.retryCount++;
                    showStreamStatus(
                      'Reconnecting...',
                      `Re-establishing network connection (${state.retryCount}/${state.maxRetries})...`,
                      false
                    );
                    setTimeout(() => hls.startLoad(), 2000);
                  } else {
                    showStreamStatus(
                      'Stream Failed',
                      'Network connection lost. Click Retry to reconnect.',
                      true,
                      () => {
                        state.retryCount = 0;
                        playChannel(channel);
                      }
                    );
                  }
                  break;
                case Hls.ErrorTypes.MEDIA_ERROR:
                  hls.recoverMediaError();
                  break;
                default:
                  hls.destroy();
                  break;
              }
            }
          });
          state.hls = hls;
        } else if (els.video.canPlayType('application/vnd.apple.mpegurl')) {
          els.video.src = channel.url;
          els.video.addEventListener('loadedmetadata', () => {
            if (reqId !== state.currentReqId) return;
            els.video.play().catch(() => {});
          });
        }
      }
    }
  };

  const playChannel = (channel) => {
    if (!channel) return;

    // Teardown previous stream to cleanly release external server's connection
    teardownPlayer(true);

    const reqId = ++state.currentReqId;
    state.retryCount = 0;
    state.activeChannel = channel;

    if (els.browserView) els.browserView.classList.add('d-none');
    if (els.playerView) els.playerView.classList.remove('d-none');
    if (els.videoOverlay) els.videoOverlay.classList.add('d-none');

    if (els.currentChName) els.currentChName.textContent = channel.name;
    if (els.currentChLogo) {
      els.currentChLogo.src = channel.logo || '';
      els.currentChLogo.classList.toggle('d-none', !channel.logo);
    }

    updateFavoriteButtons();
    populatePlayerSidebar(state.displayedChannels.length > 0 ? state.displayedChannels : state.allChannels, channel.id);
    updatePlayerEPG(channel.id);
    hideStreamStatus();

    // 200ms debounce allows the external server TCP socket to close and reset active_cons
    state.switchTimer = setTimeout(() => {
      if (reqId !== state.currentReqId) return;
      loadChannelSource(channel, reqId);
    }, 200);
  };

  const stopPlayer = () => {
    teardownPlayer(true);
    hideStreamStatus();

    if (els.videoOverlay) {
      els.videoOverlay.classList.remove('d-none');
    }
    if (els.browserView) els.browserView.classList.remove('d-none');
    if (els.playerView) els.playerView.classList.add('d-none');
  };

  const bindEvents = () => {
    // Category Click
    document.addEventListener('click', (e) => {
      const catBtn = e.target.closest('[data-category-id]');
      if (catBtn) {
        e.preventDefault();
        document.querySelectorAll('[data-category-id]').forEach((el) => el.classList.remove('active'));
        catBtn.classList.add('active');
        state.favFilterActive = false;
        if (els.favBtn) els.favBtn.classList.remove('active');

        if (els.playerView && !els.playerView.classList.contains('d-none')) {
          stopPlayer();
        }

        const catId = catBtn.getAttribute('data-category-id');
        fetchChannelsForCategory(catId === 'all' ? null : catId);
      }
    });

    // Favorite Button Filter in Toolbar
    if (els.favBtn) {
      els.favBtn.addEventListener('click', (e) => {
        e.preventDefault();
        state.favFilterActive = !state.favFilterActive;
        state.currentPage = 1;
        els.favBtn.classList.toggle('active', state.favFilterActive);
        renderChannels();
      });
    }

    // Category Search Filter (with category pagination update)
    if (els.categorySearch) {
      els.categorySearch.addEventListener('input', () => {
        state.catCurrentPage = 1;
        renderCategoriesPagination();
      });
    }

    // Channel Search Filter
    if (els.channelSearch) {
      els.channelSearch.addEventListener('input', () => {
        state.currentPage = 1;
        renderChannels();
      });
    }

    // Sorting select
    if (els.sortSelect) {
      els.sortSelect.addEventListener('change', (e) => {
        state.currentSort = e.target.value;
        state.currentPage = 1;
        renderChannels();
      });
    }

    // Grid Density Columns Selector
    document.querySelectorAll('[data-grid-cols]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-grid-cols]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        state.gridCols = Number(btn.getAttribute('data-grid-cols'));
        renderChannels();
      });
    });

    // Show Limit Buttons
    document.querySelectorAll('[data-show-limit]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-show-limit]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        state.showLimit = btn.getAttribute('data-show-limit');
        state.currentPage = 1;
        renderChannels();
      });
    });

    // Toggle Categories Sidebar
    if (els.sidebarToggle && els.categoriesSidebar) {
      els.sidebarToggle.addEventListener('click', () => {
        els.categoriesSidebar.classList.toggle('d-none');
      });
    }

    // Document-level delegated events (bind only once globally)
    if (!documentEventsBound) {
      documentEventsBound = true;

      // Play channel on Card Click
      document.addEventListener('click', (e) => {
        const playCard = e.target.closest('[data-play-channel]');
        if (playCard) {
          e.preventDefault();
          const id = playCard.getAttribute('data-play-channel');
          const ch = state.allChannels.find((c) => Number(c.id) === Number(id));
          if (ch) playChannel(ch);
        }
      });

      // Toggle Favorite on Star Click
      document.addEventListener('click', (e) => {
        const favBtn = e.target.closest('[data-fav-stream-id]');
        if (favBtn) {
          e.preventDefault();
          e.stopPropagation();
          const id = favBtn.getAttribute('data-fav-stream-id');
          toggleFavorite(id);
        }
      });

      // Zap-Sidebar Click in Player View
      document.addEventListener('click', (e) => {
        const zapItem = e.target.closest('[data-player-zap-id]');
        if (zapItem) {
          const id = zapItem.getAttribute('data-player-zap-id');

          // Immediate visual active state toggle
          document.querySelectorAll('[data-player-zap-id]').forEach((item) => {
            item.classList.remove('active');
            const nameEl = item.querySelector('.channel-zap-name');
            if (nameEl) nameEl.classList.remove('text-primary');
          });
          zapItem.classList.add('active');
          const activeName = zapItem.querySelector('.channel-zap-name');
          if (activeName) activeName.classList.add('text-primary');

          const ch = state.allChannels.find((c) => Number(c.id) === Number(id));
          if (ch) playChannel(ch);
        }
      });
    }

    // Toggle Favorite in Player View
    if (els.playerFavBtn) {
      els.playerFavBtn.addEventListener('click', () => {
        if (state.activeChannel) {
          toggleFavorite(state.activeChannel.id);
        }
      });
    }

    // Zap-Sidebar Search
    if (els.playerSearchInput) {
      els.playerSearchInput.addEventListener('input', () => {
        if (state.activeChannel) {
          populatePlayerSidebar(state.displayedChannels.length > 0 ? state.displayedChannels : state.allChannels, state.activeChannel.id);
        }
      });
    }

    // Back to Channels Grid Button
    if (els.backToGridBtn) {
      els.backToGridBtn.addEventListener('click', (e) => {
        e.preventDefault();
        stopPlayer();
      });
    }

    // Fullscreen Button
    if (els.playerFullscreenBtn) {
      els.playerFullscreenBtn.addEventListener('click', () => {
        if (state.vjsPlayer) {
          if (state.vjsPlayer.isFullscreen()) {
            state.vjsPlayer.exitFullscreen();
          } else {
            state.vjsPlayer.requestFullscreen();
          }
        } else if (els.video) {
          if (!document.fullscreenElement) {
            els.video.requestFullscreen().catch(() => {});
          } else {
            document.exitFullscreen().catch(() => {});
          }
        }
      });
    }
  };

  let documentEventsBound = false;

  const start = (config = {}) => {
    state.baseUrl = config.baseUrl || state.baseUrl || '/';
    if (Array.isArray(config.initialChannels) && config.initialChannels.length > 0) {
      state.allChannels = config.initialChannels;
    }
    if (config.selectedCategoryId !== undefined) {
      state.activeCategory = config.selectedCategoryId;
    }

    initElements();
    if (els.categoriesList) {
      els.categoriesList.dataset.appInitialized = '1';
    }
    loadFavorites();
    bindEvents();
    renderCategoriesPagination();

    if (state.allChannels.length > 0) {
      renderChannels();
    } else {
      fetchChannelsForCategory(state.activeCategory === 'all' ? null : state.activeCategory);
    }
  };

  return {
    start,
    playChannel,
    stopPlayer
  };
})();

window.LivePlayerApp = window.LiveApp;

