/**
 * Web Player V2 — Movies (VOD) Explorer Engine
 *
 * Implements category filtering with sidebar pagination, instant search,
 * sorting, grid density controls, items pagination with page numbers & ellipses,
 * and local storage favorites management.
 */

'use strict';

window.MoviesApp = (function () {
  let state = {
    activeCategory: null,
    allMovies: [],
    displayedMovies: [],
    favorites: [],
    gridCols: 4,
    showLimit: 48,
    currentPage: 1,
    catCurrentPage: 1,
    catPageLimit: 10,
    currentSort: 'default',
    favFilterActive: false,
    baseUrl: ''
  };

  // Cache DOM Elements
  const els = {};

  const initElements = () => {
    els.sidebar = document.getElementById('movies-categories-sidebar');
    els.categoriesList = document.getElementById('movies-categories-list');
    els.categoriesPagination = document.getElementById('movies-categories-pagination');
    els.container = document.getElementById('movies-container');
    els.pagination = document.getElementById('movies-pagination');
    els.categorySearch = document.getElementById('movie-category-search');
    els.movieSearch = document.getElementById('movie-search');
    els.favBtn = document.getElementById('btn-movie-favorites');
    els.sortSelect = document.getElementById('movie-sort-select');
    els.sidebarToggle = document.getElementById('btn-toggle-movie-categories');
    els.countBadge = document.getElementById('movies-count-badge');
    els.toolbar = document.querySelector('.bg-body-tertiary');
  };

  const loadFavorites = () => {
    try {
      state.favorites = JSON.parse(localStorage.getItem('xc_player_v2_favs_movies')) || [];
    } catch (e) {
      state.favorites = [];
    }
  };

  const saveFavorites = () => {
    try {
      localStorage.setItem('xc_player_v2_favs_movies', JSON.stringify(state.favorites));
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
      renderMovies();
    }
  };

  const updateFavoriteButtons = () => {
    document.querySelectorAll('[data-fav-movie-id]').forEach((btn) => {
      const sId = btn.getAttribute('data-fav-movie-id');
      const active = isFavorite(sId);
      btn.classList.toggle('text-warning', active);
      const icon = btn.querySelector('i');
      if (icon) {
        icon.className = active ? 'icon-base bx bxs-star text-warning' : 'icon-base bx bx-star text-body-secondary';
      }
    });
  };

  const getColClasses = (cols) => {
    switch (Number(cols)) {
      case 3:
        return 'col-12 col-sm-6 col-md-4';
      case 6:
        return 'col-6 col-sm-4 col-md-3 col-lg-2';
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

    const allCatItems = Array.from(els.categoriesList.querySelectorAll('[data-movie-category-id]'));
    const val = els.categorySearch ? els.categorySearch.value.trim().toLowerCase() : '';

    // Filter matching items (pinned "all" and "popular" stay visible at top)
    allCatItems.forEach((item) => {
      const catId = item.getAttribute('data-movie-category-id');
      if (catId === 'all' || catId === 'popular') {
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

  const fetchMoviesForCategory = async (catId) => {
    state.activeCategory = catId;
    state.currentPage = 1;
    if (els.container) {
      els.container.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="text-body-secondary mt-2 mb-0">Loading movies...</p>
        </div>`;
    }
    if (els.pagination) {
      els.pagination.classList.add('d-none');
    }

    try {
      const url = catId === 'popular'
        ? `${state.baseUrl}movies?ajax=1&filter=popular`
        : `${state.baseUrl}movies?ajax=1&category_id=${encodeURIComponent(catId || '')}`;

      const res = await fetch(url);
      const data = await res.json();
      if (data && data.movies) {
        state.allMovies = data.movies;
        renderMovies();
      }
    } catch (err) {
      console.error('Error loading movies:', err);
      if (els.container) {
        els.container.innerHTML = `
          <div class="col-12 text-center py-5">
            <i class="icon-base bx bx-error-circle text-danger fs-1 mb-2"></i>
            <p class="text-body-secondary">Failed to load movies. Please try again.</p>
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
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary pagination-page-btn" data-movie-page="prev" ${state.currentPage === 1 ? 'disabled' : ''} title="Previous Page">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
    `;

    pageNums.forEach((p) => {
      if (p === '...') {
        buttonsHtml += `<span class="px-2 text-body-secondary small">•••</span>`;
      } else {
        const activeClass = p === state.currentPage ? 'btn-primary shadow-sm' : 'btn-label-secondary';
        buttonsHtml += `
          <button type="button" class="btn btn-sm btn-icon pagination-page-btn ${activeClass}" data-movie-page="${p}">
            ${p}
          </button>
        `;
      }
    });

    buttonsHtml += `
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary pagination-page-btn" data-movie-page="next" ${state.currentPage === totalPages ? 'disabled' : ''} title="Next Page">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    `;

    els.pagination.innerHTML = `
      <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3 w-100">
        <span class="text-body-secondary small order-2 order-sm-1">
          Showing ${startIdx + 1}–${endIdx} of ${total} Movies
        </span>
        <div class="d-flex align-items-center gap-1 order-1 order-sm-2">
          ${buttonsHtml}
        </div>
      </div>
    `;

    els.pagination.querySelectorAll('[data-movie-page]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const action = btn.getAttribute('data-movie-page');
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
          renderMovies();
          if (els.toolbar) {
            els.toolbar.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }
      });
    });
  };

  const renderMovies = () => {
    if (!els.container) return;

    let list = [...state.allMovies];

    // Favorites filter
    if (state.favFilterActive) {
      list = list.filter((m) => isFavorite(m.id));
    }

    // Search query filter
    const searchVal = els.movieSearch ? els.movieSearch.value.trim().toLowerCase() : '';
    if (searchVal) {
      list = list.filter((m) => m.title.toLowerCase().includes(searchVal));
    }

    // Sorting
    const sortVal = els.sortSelect ? els.sortSelect.value : state.currentSort;
    if (sortVal === 'rating_desc') {
      list.sort((a, b) => (parseFloat(b.rating) || 0) - (parseFloat(a.rating) || 0));
    } else if (sortVal === 'year_desc') {
      list.sort((a, b) => (parseInt(b.year) || 0) - (parseInt(a.year) || 0));
    } else if (sortVal === 'year_asc') {
      list.sort((a, b) => (parseInt(a.year) || 0) - (parseInt(b.year) || 0));
    } else if (sortVal === 'alpha_az') {
      list.sort((a, b) => a.title.localeCompare(b.title));
    } else if (sortVal === 'alpha_za') {
      list.sort((a, b) => b.title.localeCompare(a.title));
    }

    state.displayedMovies = list;

    if (els.countBadge) {
      els.countBadge.textContent = `${list.length} Movies`;
    }

    if (list.length === 0) {
      els.container.innerHTML = `
        <div class="col-12 text-center py-5">
          <div class="avatar avatar-xl rounded bg-label-secondary mx-auto mb-3">
            <i class="icon-base bx bx-film fs-2"></i>
          </div>
          <h5 class="mb-1">No Movies Found</h5>
          <p class="text-body-secondary mb-0">Try selecting another genre or adjusting your search criteria.</p>
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
    items.forEach((m) => {
      const fav = isFavorite(m.id);
      const posterSrc = m.cover
        ? `${state.baseUrl}resize?url=${encodeURIComponent(m.cover)}&w=300&h=450`
        : `${state.baseUrl}assets/img/placeholder-poster.png`;

      html += `
        <div class="${colClass}">
          <div class="card h-100 channel-card border shadow-sm" data-movie-card-id="${m.id}">
            <div class="position-relative overflow-hidden">
              <img
                src="${posterSrc}"
                alt="${m.title}"
                class="card-img-top object-fit-cover media-poster-ratio"
                loading="lazy"
                onerror="this.src='${state.baseUrl}assets/img/placeholder-poster.png';" />
              <a href="${state.baseUrl}movie?id=${m.id}" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="Watch Movie">
                <div class="avatar avatar-md rounded-circle bg-primary d-flex align-items-center justify-content-center shadow-lg">
                  <i class="icon-base bx bx-play fs-4 text-white"></i>
                </div>
              </a>
              ${m.rating && m.rating !== 'N/A' ? `
                <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 z-3 pe-none">
                  <i class="icon-base bx bxs-star text-warning me-1"></i>${m.rating}
                </span>` : ''}
              <button
                type="button"
                class="btn btn-sm btn-icon btn-text-secondary rounded-pill position-absolute top-0 start-0 m-2 bg-dark bg-opacity-50 text-white z-3"
                data-fav-movie-id="${m.id}"
                title="Toggle Favorite">
                <i class="icon-base ${fav ? 'bx bxs-star text-warning' : 'bx bx-star'}"></i>
              </button>
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div>
                <h6 class="card-title text-truncate mb-1 fw-bold" title="${m.title}">
                  <a href="${state.baseUrl}movie?id=${m.id}" class="text-heading">${m.title}</a>
                </h6>
              </div>
              <div class="d-flex align-items-center justify-content-between text-body-secondary small mt-2 pt-2 border-top">
                <span>${m.year ? m.year : '&mdash;'}</span>
                <a href="${state.baseUrl}movie?id=${m.id}" class="btn btn-xs btn-primary d-flex align-items-center gap-1">
                  <i class="icon-base bx bx-play"></i> Watch
                </a>
              </div>
            </div>
          </div>
        </div>`;
    });

    els.container.innerHTML = html;
    renderItemsPagination(total, limit, totalPages, startIdx, endIdx);
  };

  let documentEventsBound = false;

  const bindEvents = () => {
    // Document-level delegated events (bind only once globally)
    if (!documentEventsBound) {
      documentEventsBound = true;

      // Category Click
      document.addEventListener('click', (e) => {
        const catBtn = e.target.closest('[data-movie-category-id]');
        if (catBtn) {
          e.preventDefault();
          document.querySelectorAll('[data-movie-category-id]').forEach((el) => el.classList.remove('active'));
          catBtn.classList.add('active');
          state.favFilterActive = false;
          if (els.favBtn) els.favBtn.classList.remove('active');

          const catId = catBtn.getAttribute('data-movie-category-id');
          fetchMoviesForCategory(catId === 'all' ? null : catId);
        }
      });

      // Favorite Toggle on Card
      document.addEventListener('click', (e) => {
        const favBtn = e.target.closest('[data-fav-movie-id]');
        if (favBtn) {
          e.preventDefault();
          e.stopPropagation();
          const id = favBtn.getAttribute('data-fav-movie-id');
          toggleFavorite(id);
        }
      });
    }

    // Favorite Filter in Toolbar
    if (els.favBtn) {
      els.favBtn.addEventListener('click', (e) => {
        e.preventDefault();
        state.favFilterActive = !state.favFilterActive;
        state.currentPage = 1;
        els.favBtn.classList.toggle('active', state.favFilterActive);
        renderMovies();
      });
    }

    // Category Search Filter (with category pagination update)
    if (els.categorySearch) {
      els.categorySearch.addEventListener('input', () => {
        state.catCurrentPage = 1;
        renderCategoriesPagination();
      });
    }

    // Movie Search
    if (els.movieSearch) {
      els.movieSearch.addEventListener('input', () => {
        state.currentPage = 1;
        renderMovies();
      });
    }

    // Sort Dropdown
    if (els.sortSelect) {
      els.sortSelect.addEventListener('change', (e) => {
        state.currentSort = e.target.value;
        state.currentPage = 1;
        renderMovies();
      });
    }

    // Column Density Selector
    document.querySelectorAll('[data-movie-grid-cols]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-movie-grid-cols]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        state.gridCols = Number(btn.getAttribute('data-movie-grid-cols'));
        renderMovies();
      });
    });

    // Show Limit Buttons
    document.querySelectorAll('[data-movie-show-limit]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('[data-movie-show-limit]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        state.showLimit = btn.getAttribute('data-movie-show-limit');
        state.currentPage = 1;
        renderMovies();
      });
    });

    // Toggle Categories Sidebar
    if (els.sidebarToggle && els.sidebar) {
      els.sidebarToggle.addEventListener('click', () => {
        els.sidebar.classList.toggle('d-none');
      });
    }
  };

  const start = (config = {}) => {
    state.baseUrl = config.baseUrl || state.baseUrl || '/';
    if (config.initialMovies && config.initialMovies.length > 0) {
      state.allMovies = config.initialMovies;
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
    renderMovies();
  };

  return {
    start
  };
})();
