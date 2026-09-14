/**
 * Web Player V2 — Netflix-Inspired Cinematic Dashboard Engine
 *
 * Manages the Billboard Hero rotator, Continue Watching shelf from localStorage,
 * horizontal shelf scrolling, and interactive favorite toggles.
 */

'use strict';

window.PlayerHome = (function () {
  let heroTimer = null;
  let currentHeroIdx = 0;
  let heroSlidesData = [];

  const init = () => {
    initHeroBillboard();
    initContinueWatchingShelf();
    initShelfScrollButtons();
    initFavoriteToggles();
  };

  /**
   * Billboard Hero Rotating Carousel.
   */
  const initHeroBillboard = () => {
    const billboardEl = document.getElementById('hero-billboard-stage');
    const heroDataScript = document.getElementById('hero-slides-data');
    const dotsContainer = document.getElementById('hero-slider-dots');

    if (!billboardEl || !heroDataScript) return;

    try {
      heroSlidesData = JSON.parse(heroDataScript.textContent || '[]');
    } catch (e) {
      heroSlidesData = [];
    }

    if (!Array.isArray(heroSlidesData) || heroSlidesData.length <= 1) return;

    // Render navigation dots
    if (dotsContainer) {
      let dotsHtml = '';
      heroSlidesData.forEach((_, idx) => {
        dotsHtml += `<button type="button" class="hero-slider-dot ${idx === 0 ? 'active' : ''}" data-hero-slide="${idx}" title="Slide ${idx + 1}"></button>`;
      });
      dotsContainer.innerHTML = dotsHtml;

      dotsContainer.querySelectorAll('[data-hero-slide]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const targetIdx = Number(btn.getAttribute('data-hero-slide'));
          switchHeroSlide(targetIdx);
          restartHeroTimer();
        });
      });
    }

    startHeroTimer();
  };

  const switchHeroSlide = (idx) => {
    if (!heroSlidesData[idx]) return;
    currentHeroIdx = idx;
    const item = heroSlidesData[idx];

    const bgImg = document.getElementById('hero-billboard-bg');
    const titleEl = document.getElementById('hero-title');
    const plotEl = document.getElementById('hero-plot');
    const ratingEl = document.getElementById('hero-rating');
    const yearEl = document.getElementById('hero-year');
    const genreEl = document.getElementById('hero-genre');
    const playBtn = document.getElementById('hero-play-btn');
    const infoBtn = document.getElementById('hero-info-btn');
    const tagBadge = document.getElementById('hero-tag-badge');

    if (bgImg && item.backdrop) {
      bgImg.style.opacity = '0.4';
      setTimeout(() => {
        bgImg.src = item.backdrop;
        bgImg.style.opacity = '1';
      }, 250);
    }

    if (titleEl) titleEl.textContent = item.title;
    if (plotEl) plotEl.textContent = item.plot || 'Streaming now on XC_VM Web Player.';
    if (ratingEl) {
      ratingEl.textContent = item.rating ? `★ ${Number(item.rating).toFixed(1)}` : '★ 8.5';
    }
    if (yearEl) yearEl.textContent = item.year || new Date().getFullYear();
    if (genreEl) genreEl.textContent = item.genre || 'Action, Drama';
    if (tagBadge) {
      tagBadge.textContent = item.type === 'series' ? '#1 IN SERIES TODAY' : '#1 IN MOVIES TODAY';
    }

    const detailUrl = (item.type === 'series' ? 'series?id=' : 'movie?id=') + item.id;
    if (infoBtn) infoBtn.href = detailUrl;

    if (playBtn) {
      if (item.type === 'series') {
        playBtn.href = `player?type=series&series_id=${item.id}&s=1&e=1`;
      } else {
        playBtn.href = `player?type=movie&id=${item.id}`;
      }
    }

    // Update dots
    document.querySelectorAll('.hero-slider-dot').forEach((d, i) => {
      d.classList.toggle('active', i === idx);
    });
  };

  const startHeroTimer = () => {
    stopHeroTimer();
    heroTimer = setInterval(() => {
      const nextIdx = (currentHeroIdx + 1) % heroSlidesData.length;
      switchHeroSlide(nextIdx);
    }, 8000);
  };

  const stopHeroTimer = () => {
    if (heroTimer) {
      clearInterval(heroTimer);
      heroTimer = null;
    }
  };

  const restartHeroTimer = () => {
    stopHeroTimer();
    startHeroTimer();
  };

  /**
   * Continue Watching Shelf populated from localStorage.
   */
  const initContinueWatchingShelf = () => {
    const shelfWrap = document.getElementById('continue-watching-shelf-wrap');
    const container = document.getElementById('continue-watching-container');
    if (!shelfWrap || !container) return;

    let continueMovies = [];
    let continueSeries = [];
    try {
      continueMovies = JSON.parse(localStorage.getItem('xc_player_v2_continue_movies')) || [];
    } catch (e) {}
    try {
      continueSeries = JSON.parse(localStorage.getItem('xc_player_v2_continue_series')) || [];
    } catch (e) {}

    const allItems = [...continueMovies, ...continueSeries];

    if (allItems.length === 0) {
      shelfWrap.classList.add('d-none');
      return;
    }

    shelfWrap.classList.remove('d-none');
    let cardsHtml = '';

    allItems.slice(0, 10).forEach((item) => {
      const isSeries = !!item.series_id || item.type === 'series';
      const resumeUrl = isSeries
        ? `player?type=series&id=${item.stream_id || item.id}&series_id=${item.series_id || item.id}&s=${item.season || 1}&e=${item.episode || 1}`
        : `player?type=movie&id=${item.id}`;

      const pct = Math.min(100, Math.max(5, item.progressPercent || 25));
      const thumb = item.backdrop || item.cover || 'assets/img/pages/profile-banner.png';

      cardsHtml += `
        <div class="shelf-card-item">
          <div class="continue-watch-card">
            <div class="position-relative overflow-hidden rounded-top">
              <img src="${thumb}" alt="${item.title || 'Stream'}" class="continue-watch-thumb" onerror="this.src='assets/img/pages/profile-banner.png';" />
              <a href="${resumeUrl}" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="Resume Playback">
                <div class="avatar avatar-md rounded-circle bg-primary d-flex align-items-center justify-content-center shadow">
                  <i class="icon-base bx bx-play fs-4 text-white"></i>
                </div>
              </a>
            </div>
            <div class="progress continue-watch-progress bg-body-secondary">
              <div class="progress-bar bg-danger" role="progressbar" data-progress-percent="${pct}"></div>
            </div>
            <div class="p-2">
              <h6 class="mb-0 fw-bold small text-truncate" title="${item.title || ''}">${item.title || 'Untitled'}</h6>
              <div class="d-flex align-items-center justify-content-between mt-1 text-body-secondary small">
                <span>${isSeries ? `S${item.season || 1}E${item.episode || 1}` : 'Movie'}</span>
                <span class="text-primary fw-medium">Resume</span>
              </div>
            </div>
          </div>
        </div>
      `;
    });

    container.innerHTML = cardsHtml;

    // Apply progress bar widths dynamically without inline style
    container.querySelectorAll('[data-progress-percent]').forEach((bar) => {
      const p = bar.getAttribute('data-progress-percent');
      bar.style.width = p + '%';
    });
  };

  /**
   * Shelf Horizontal Scroll Buttons.
   */
  const initShelfScrollButtons = () => {
    document.querySelectorAll('[data-shelf-scroll]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = btn.getAttribute('data-shelf-scroll');
        const direction = btn.getAttribute('data-shelf-dir') === 'left' ? -1 : 1;
        const targetEl = document.getElementById(targetId);
        if (targetEl) {
          targetEl.scrollBy({ left: direction * 450, behavior: 'smooth' });
        }
      });
    });
  };

  /**
   * Universal Client-Side Favorite Toggle.
   */
  const initFavoriteToggles = () => {
    document.addEventListener('click', (e) => {
      const favBtn = e.target.closest('[data-fav-toggle-type]');
      if (!favBtn) return;

      e.preventDefault();
      e.stopPropagation();

      const type = favBtn.getAttribute('data-fav-toggle-type');
      const id = favBtn.getAttribute('data-fav-id');
      if (!type || !id) return;

      const storageKey = `xc_player_v2_favs_${type}`;
      let favs = [];
      try {
        favs = JSON.parse(localStorage.getItem(storageKey)) || [];
      } catch (err) {}

      const numId = Number(id);
      const isFav = favs.includes(numId);

      if (isFav) {
        favs = favs.filter((item) => Number(item) !== numId);
      } else {
        favs.push(numId);
      }

      try {
        localStorage.setItem(storageKey, JSON.stringify(favs));
      } catch (err) {}

      // Update icon
      const icon = favBtn.querySelector('i');
      if (icon) {
        if (!isFav) {
          icon.className = 'icon-base bx bxs-star text-warning';
        } else {
          icon.className = 'icon-base bx bx-star text-white';
        }
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  return { init };
})();
