/**
 * Web Player V2 — Cinema & Series Player Engine
 *
 * Uses the proven, high-performance Video.js engine from the Admin Player (admin/player.php)
 * integrated into a distinctive cinema studio split layout with client-side episode switching,
 * playback position resume, continue-watching sync, favorites, and keyboard shortcuts.
 */

'use strict';

window.CinemaPlayerApp = (function () {
  let state = {
    type: 'movie', // 'movie' or 'series'
    currentStreamId: 0,
    currentStreamUrl: '',
    seriesId: 0,
    currentSeason: null,
    currentEpisode: null,
    items: [], // list of episodes or related movies
    vjsPlayer: null,
    baseUrl: '/',
    backUrl: '/',
    aspectModes: ['aspect-contain', 'aspect-cover', 'aspect-fill'],
    aspectLabels: ['Fit (Contain)', 'Cover (Zoom)', 'Stretch (Fill)'],
    aspectIndex: 0,
    favorites: [],
    switchTimer: null,
    retryTimer: null,
    retryCount: 0,
    maxRetries: 3,
    currentReqId: 0
  };

  const els = {};

  const initElements = () => {
    els.wrapper = document.querySelector('.cinema-app-wrapper');
    els.video = document.getElementById('cinema-video');
    els.videoStage = document.querySelector('.cinema-video-stage');
    els.title = document.getElementById('cinema-player-title');
    els.categoryBadge = document.getElementById('cinema-cat-badge');
    els.qualityBadge = document.getElementById('cinema-quality-badge');
    els.seasonEpBadge = document.getElementById('cinema-se-badge');
    els.yearText = document.getElementById('cinema-year-text');
    els.plotText = document.getElementById('cinema-plot');
    els.posterImg = document.getElementById('cinema-poster-img');
    els.searchInput = document.getElementById('cinema-item-search');
    els.seasonSelect = document.getElementById('cinema-season-select');
    els.itemsList = document.getElementById('cinema-items-list');
    els.itemsCountBadge = document.getElementById('cinema-items-count-badge');
    els.btnPrev = document.getElementById('btn-cinema-prev');
    els.btnNext = document.getElementById('btn-cinema-next');
    els.btnAspect = document.getElementById('btn-cinema-aspect');
    els.btnFullscreen = document.getElementById('btn-cinema-fullscreen');
    els.btnFav = document.getElementById('btn-cinema-fav');
    els.streamStatus = document.getElementById('cinema-stream-status');
    els.statusSpinner = document.getElementById('cinema-status-spinner');
    els.statusIcon = document.getElementById('cinema-status-icon');
    els.statusTitle = document.getElementById('cinema-status-title');
    els.statusDesc = document.getElementById('cinema-status-desc');
    els.retryBtn = document.getElementById('btn-cinema-retry-stream');
  };

  const loadFavorites = () => {
    const key = state.type === 'series' ? 'xc_player_v2_favs_series' : 'xc_player_v2_favs_movies';
    try {
      state.favorites = JSON.parse(localStorage.getItem(key)) || [];
    } catch (e) {
      state.favorites = [];
    }
    updateFavoriteButton();
  };

  const isFavorite = (id) => {
    const targetId = Number(id);
    return state.favorites.includes(targetId) || state.favorites.includes(String(targetId));
  };

  const toggleFavorite = (id) => {
    const numId = Number(id);
    if (isFavorite(numId)) {
      state.favorites = state.favorites.filter(fav => Number(fav) !== numId);
    } else {
      state.favorites.push(numId);
    }
    const key = state.type === 'series' ? 'xc_player_v2_favs_series' : 'xc_player_v2_favs_movies';
    try {
      localStorage.setItem(key, JSON.stringify(state.favorites));
    } catch (e) {}
    updateFavoriteButton();
  };

  const updateFavoriteButton = () => {
    if (!els.btnFav) return;
    const activeTarget = state.type === 'series' ? state.seriesId : state.currentStreamId;
    const active = isFavorite(activeTarget);
    els.btnFav.classList.toggle('text-warning', active);
    const icon = els.btnFav.querySelector('i');
    if (icon) {
      icon.className = active ? 'icon-base bx bxs-star text-warning' : 'icon-base bx bx-star';
    }
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
  };

  // Dynamic MIME resolution
  const resolveStreamType = (url) => {
    if (!url) return 'video/mp4';
    const clean = url.split('?')[0].toLowerCase();
    if (clean.includes('.m3u8') || clean.includes('/live/') || clean.includes('/hls/')) {
      return 'application/x-mpegURL';
    }
    if (clean.endsWith('.ts')) {
      return 'video/mp2t';
    }
    if (clean.endsWith('.webm')) return 'video/webm';
    if (clean.endsWith('.ogg') || clean.endsWith('.ogv')) return 'video/ogg';
    if (clean.endsWith('.mp3')) return 'audio/mp3';
    return 'video/mp4';
  };

  // Load Stream Source with resume position
  const loadItemSource = (streamUrl, reqId) => {
    if (reqId !== state.currentReqId || !streamUrl || !state.vjsPlayer) return;

    try {
      state.vjsPlayer.error(null);
    } catch (e) {}

    const mime = resolveStreamType(streamUrl);
    state.vjsPlayer.src({
      src: streamUrl,
      type: mime
    });

    // Position resume
    const resumeKey = `xc_player_v2_pos_${state.type}_${state.currentStreamId}`;
    const savedPos = parseFloat(localStorage.getItem(resumeKey) || '0');

    state.vjsPlayer.one('loadedmetadata', function () {
      if (savedPos > 10 && state.vjsPlayer.duration() && savedPos < state.vjsPlayer.duration() - 15) {
        try {
          state.vjsPlayer.currentTime(savedPos);
        } catch (e) {}
      }
    });

    state.vjsPlayer.ready(() => {
      if (reqId !== state.currentReqId) return;
      const playPromise = state.vjsPlayer.play();
      if (playPromise !== undefined) {
        playPromise.catch(() => {});
      }
    });
  };

  /**
   * Initialize Video.js Player with connection management and auto-recovery
   */
  const initVideoJs = (initialStreamUrl) => {
    if (!els.video) return;
    state.currentStreamUrl = initialStreamUrl || '';

    if (typeof videojs !== 'undefined') {
      try {
        // Clean up previous instance if exists
        if (state.vjsPlayer) {
          try {
            state.vjsPlayer.dispose();
          } catch (e) {}
          state.vjsPlayer = null;
        }

        // Initialize VideoJS with non-aggressive preload and VHS stability
        state.vjsPlayer = videojs('cinema-video', {
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

        const player = state.vjsPlayer;

        // Clear retries and status when playback starts
        player.on('playing', function () {
          state.retryCount = 0;
          if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
          }
          hideStreamStatus();
        });

        // Handle playback errors with backoff retry
        player.on('error', function () {
          const err = player.error();
          console.warn('[CinemaPlayer] VideoJS Error:', err);

          if (!state.currentStreamUrl) return;

          if (state.retryCount < state.maxRetries) {
            state.retryCount++;
            showStreamStatus(
              'Reconnecting Video...',
              `The external server closed the connection (Limit/Network hiccup). Restoring automatically (${state.retryCount}/${state.maxRetries})...`,
              false
            );

            // Teardown player to release connection on server
            try {
              player.pause();
              player.reset();
            } catch (e) {}

            const activeReq = state.currentReqId;
            state.retryTimer = setTimeout(() => {
              if (activeReq !== state.currentReqId || !state.currentStreamUrl) return;
              loadItemSource(state.currentStreamUrl, activeReq);
            }, 2000);
          } else {
            showStreamStatus(
              'Playback Interrupted',
              'The external server disconnected the stream (Max connection limit or source offline). Click Retry to reconnect.',
              true,
              () => {
                state.retryCount = 0;
                if (state.currentStreamUrl) {
                  const reqId = ++state.currentReqId;
                  loadItemSource(state.currentStreamUrl, reqId);
                }
              }
            );
          }
        });

        // Track and save progress periodically
        let lastSave = 0;
        player.on('timeupdate', function () {
          const now = Date.now();
          if (now - lastSave > 3000) {
            lastSave = now;
            const cur = player.currentTime();
            const dur = player.duration();
            const resumeKey = `xc_player_v2_pos_${state.type}_${state.currentStreamId}`;
            if (cur > 5) {
              localStorage.setItem(resumeKey, cur.toFixed(1));

              // Continue watching tracking for series
              if (state.type === 'series' && state.seriesId > 0 && dur) {
                try {
                  const continueList = JSON.parse(localStorage.getItem('xc_player_v2_continue_series') || '[]');
                  const progressPct = Math.min(100, Math.round((cur / dur) * 100));
                  const filtered = continueList.filter(x => Number(x.seriesId) !== Number(state.seriesId));
                  filtered.unshift({
                    seriesId: state.seriesId,
                    streamId: state.currentStreamId,
                    seasonNum: state.currentSeason,
                    episodeNum: state.currentEpisode,
                    time: Math.floor(cur),
                    progress: progressPct,
                    timestamp: now
                  });
                  localStorage.setItem('xc_player_v2_continue_series', JSON.stringify(filtered.slice(0, 25)));
                } catch (e) {}
              }
            }
          }
        });

        // Auto-play next episode on end
        player.on('ended', function () {
          const resumeKey = `xc_player_v2_pos_${state.type}_${state.currentStreamId}`;
          localStorage.removeItem(resumeKey);
          handleEnded();
        });

        // Load initial stream
        if (initialStreamUrl) {
          const reqId = ++state.currentReqId;
          loadItemSource(initialStreamUrl, reqId);
        }
      } catch (err) {
        console.warn('[CinemaPlayer] VideoJS init error:', err);
      }
    }
  };

  const switchItem = (item) => {
    if (!item) return;

    state.currentStreamId = Number(item.stream_id || item.id);
    if (state.type === 'series') {
      state.currentSeason = Number(item.season_num || 1);
      state.currentEpisode = Number(item.episode_num || 1);
    }

    // Update active highlight in sidebar
    if (els.itemsList) {
      els.itemsList.querySelectorAll('.cinema-item-card').forEach(el => {
        const sid = Number(el.getAttribute('data-stream-id'));
        const isActive = sid === state.currentStreamId;
        el.classList.toggle('active', isActive);

        const statusWrap = el.querySelector('.item-status-indicator');
        if (statusWrap) {
          if (isActive) {
            statusWrap.innerHTML = `
              <div class="playing-bars me-1">
                <span></span><span></span><span></span>
              </div>
              <span class="badge bg-primary px-1 py-0" style="font-size: 0.65rem;">PLAYING</span>
            `;
          } else {
            statusWrap.innerHTML = '';
          }
        }
      });
    }

    // Update Topbar Title & Badges
    if (els.title) {
      if (state.type === 'series') {
        const sPad = String(state.currentSeason).padStart(2, '0');
        const ePad = String(state.currentEpisode).padStart(2, '0');
        els.title.textContent = `${item.series_title || 'Series'} — S${sPad}E${ePad} — ${item.title || ('Episode ' + state.currentEpisode)}`;
      } else {
        els.title.textContent = item.stream_display_name || item.title || 'Movie';
      }
    }

    if (els.seasonEpBadge && state.type === 'series') {
      const sPad = String(state.currentSeason).padStart(2, '0');
      const ePad = String(state.currentEpisode).padStart(2, '0');
      els.seasonEpBadge.textContent = `S${sPad}E${ePad}`;
      els.seasonEpBadge.classList.remove('d-none');
    }

    if (els.plotText && (item.overview || item.plot)) {
      els.plotText.textContent = item.overview || item.plot;
    }

    if (els.posterImg && item.stream_icon) {
      els.posterImg.src = item.stream_icon;
    }

    // Update Next & Prev episode buttons
    updateNextPrevButtons();

    // Update URL via pushState without reloading
    let newUrl = '';
    if (state.type === 'series') {
      newUrl = `${state.baseUrl}player?type=series&id=${state.currentStreamId}&series_id=${state.seriesId}&s=${state.currentSeason}&e=${state.currentEpisode}`;
    } else {
      newUrl = `${state.baseUrl}player?type=movie&id=${state.currentStreamId}`;
    }
    window.history.pushState({ streamId: state.currentStreamId }, '', newUrl);

    // Update Video.js playback cleanly with teardown and debouncing
    if (item.play_url) {
      teardownPlayer(true);
      hideStreamStatus();
      state.currentStreamUrl = item.play_url;

      const reqId = ++state.currentReqId;
      state.retryCount = 0;

      // 200ms debounce allows external server to clear previous TCP socket
      state.switchTimer = setTimeout(() => {
        if (reqId !== state.currentReqId) return;
        loadItemSource(item.play_url, reqId);
      }, 200);
    }
  };

  const getAdjacentEpisodes = () => {
    if (state.type !== 'series' || !state.items.length) {
      return { prev: null, next: null };
    }
    const idx = state.items.findIndex(it => Number(it.stream_id) === state.currentStreamId);
    return {
      prev: idx > 0 ? state.items[idx - 1] : null,
      next: idx >= 0 && idx < state.items.length - 1 ? state.items[idx + 1] : null
    };
  };

  const updateNextPrevButtons = () => {
    const { prev, next } = getAdjacentEpisodes();
    if (els.btnPrev) {
      if (prev) {
        els.btnPrev.classList.remove('d-none');
        els.btnPrev.setAttribute('data-target-stream-id', prev.stream_id);
        const sPad = String(prev.season_num).padStart(2, '0');
        const ePad = String(prev.episode_num).padStart(2, '0');
        els.btnPrev.setAttribute('title', `Previous Episode: S${sPad}E${ePad} (P)`);
      } else {
        els.btnPrev.classList.add('d-none');
      }
    }

    if (els.btnNext) {
      if (next) {
        els.btnNext.classList.remove('d-none');
        els.btnNext.setAttribute('data-target-stream-id', next.stream_id);
        const sPad = String(next.season_num).padStart(2, '0');
        const ePad = String(next.episode_num).padStart(2, '0');
        els.btnNext.setAttribute('title', `Next Episode: S${sPad}E${ePad} (N)`);
      } else {
        els.btnNext.classList.add('d-none');
      }
    }
  };

  const handleEnded = () => {
    const { next } = getAdjacentEpisodes();
    if (next) {
      switchItem(next);
    }
  };

  const filterSidebarItems = () => {
    const term = (els.searchInput?.value || '').trim().toLowerCase();
    const seasonFilter = els.seasonSelect?.value || 'all';

    let count = 0;
    document.querySelectorAll('.cinema-item-card').forEach(card => {
      const title = (card.getAttribute('data-title') || '').toLowerCase();
      const season = card.getAttribute('data-season-num');

      const matchesSearch = !term || title.includes(term);
      const matchesSeason = seasonFilter === 'all' || season === seasonFilter;

      if (matchesSearch && matchesSeason) {
        card.classList.remove('d-none');
        count++;
      } else {
        card.classList.add('d-none');
      }
    });

    if (els.itemsCountBadge) {
      els.itemsCountBadge.textContent = state.type === 'series' ? `${count} Episodes` : `${count} Movies`;
    }
  };

  const cycleAspectRatio = () => {
    state.aspectIndex = (state.aspectIndex + 1) % state.aspectModes.length;
    const currentCls = state.aspectModes[state.aspectIndex];
    const modeLabel = state.aspectLabels[state.aspectIndex];

    if (els.videoStage) {
      state.aspectModes.forEach(cls => els.videoStage.classList.remove(cls));
      els.videoStage.classList.add(currentCls);
    }

    if (els.btnAspect) {
      els.btnAspect.setAttribute('title', `Aspect Ratio: ${modeLabel}`);
    }
  };

  const toggleFullscreen = () => {
    if (state.vjsPlayer && state.vjsPlayer.isFullscreen) {
      if (state.vjsPlayer.isFullscreen()) {
        state.vjsPlayer.exitFullscreen();
      } else {
        state.vjsPlayer.requestFullscreen();
      }
      return;
    }

    const container = els.videoStage || els.video;
    if (!document.fullscreenElement) {
      if (container.requestFullscreen) {
        container.requestFullscreen();
      } else if (container.webkitRequestFullscreen) {
        container.webkitRequestFullscreen();
      }
    } else {
      document.exitFullscreen();
    }
  };

  const bindEvents = () => {
    // Sidebar Item Click (Switch Stream Client-Side)
    document.addEventListener('click', (e) => {
      const card = e.target.closest('.cinema-item-card');
      if (card) {
        e.preventDefault();
        const streamId = Number(card.getAttribute('data-stream-id'));
        const item = state.items.find(it => Number(it.stream_id || it.id) === streamId);
        if (item) {
          switchItem(item);
        }
      }
    });

    // Next Episode Button
    if (els.btnNext) {
      els.btnNext.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = Number(els.btnNext.getAttribute('data-target-stream-id'));
        const item = state.items.find(it => Number(it.stream_id || it.id) === targetId);
        if (item) switchItem(item);
      });
    }

    // Prev Episode Button
    if (els.btnPrev) {
      els.btnPrev.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = Number(els.btnPrev.getAttribute('data-target-stream-id'));
        const item = state.items.find(it => Number(it.stream_id || it.id) === targetId);
        if (item) switchItem(item);
      });
    }

    // Favorite Button
    if (els.btnFav) {
      els.btnFav.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = state.type === 'series' ? state.seriesId : state.currentStreamId;
        toggleFavorite(targetId);
      });
    }

    // Aspect Ratio Button
    if (els.btnAspect) {
      els.btnAspect.addEventListener('click', (e) => {
        e.preventDefault();
        cycleAspectRatio();
      });
    }

    // Fullscreen Button
    if (els.btnFullscreen) {
      els.btnFullscreen.addEventListener('click', (e) => {
        e.preventDefault();
        toggleFullscreen();
      });
    }

    // Search filter
    if (els.searchInput) {
      els.searchInput.addEventListener('input', () => {
        filterSidebarItems();
      });
    }

    // Season dropdown filter
    if (els.seasonSelect) {
      els.seasonSelect.addEventListener('change', () => {
        filterSidebarItems();
      });
    }

    // Keyboard Shortcuts
    window.addEventListener('keydown', (e) => {
      if (['input', 'textarea', 'select'].includes(document.activeElement?.tagName?.toLowerCase())) {
        return;
      }

      const p = state.vjsPlayer;
      if (!p) return;

      switch (e.key) {
        case ' ':
          e.preventDefault();
          if (p.paused()) {
            p.play();
          } else {
            p.pause();
          }
          break;
        case 'ArrowLeft':
          e.preventDefault();
          p.currentTime(Math.max(0, p.currentTime() - 10));
          break;
        case 'ArrowRight':
          e.preventDefault();
          p.currentTime(Math.min(p.duration() || 999999, p.currentTime() + 10));
          break;
        case 'ArrowUp':
          e.preventDefault();
          p.volume(Math.min(1, p.volume() + 0.1));
          break;
        case 'ArrowDown':
          e.preventDefault();
          p.volume(Math.max(0, p.volume() - 0.1));
          break;
        case 'f':
        case 'F':
          e.preventDefault();
          toggleFullscreen();
          break;
        case 'm':
        case 'M':
          e.preventDefault();
          p.muted(!p.muted());
          break;
        case 'n':
        case 'N':
          const { next } = getAdjacentEpisodes();
          if (next) {
            e.preventDefault();
            switchItem(next);
          }
          break;
        case 'p':
        case 'P':
          const { prev } = getAdjacentEpisodes();
          if (prev) {
            e.preventDefault();
            switchItem(prev);
          }
          break;
        case 'Escape':
          if (document.fullscreenElement) {
            document.exitFullscreen();
          } else if (state.backUrl) {
            window.location.href = state.backUrl;
          }
          break;
      }
    });

    // Handle Browser Popstate (Back/Forward navigation)
    window.addEventListener('popstate', (e) => {
      if (e.state && e.state.streamId) {
        const item = state.items.find(it => Number(it.stream_id || it.id) === Number(e.state.streamId));
        if (item) switchItem(item);
      }
    });
  };

  return {
    start: function (config) {
      state.type = config.type || 'movie';
      state.currentStreamId = Number(config.streamId || 0);
      state.seriesId = Number(config.seriesId || 0);
      state.currentSeason = Number(config.seasonNum || 1);
      state.currentEpisode = Number(config.episodeNum || 1);
      state.items = config.items || [];
      state.baseUrl = config.baseUrl || '/';
      state.backUrl = config.backUrl || '/';

      initElements();
      loadFavorites();
      initVideoJs(config.streamUrl);
      bindEvents();
      updateNextPrevButtons();

      // Scroll active item into view in sidebar
      setTimeout(() => {
        const activeCard = els.itemsList?.querySelector('.cinema-item-card.active');
        if (activeCard) {
          activeCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }, 250);
    }
  };
})();
