<?php

use XcVm\Core\Config\SettingsManager;

$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';
$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
?>

<!-- ─── Favorites Vault Page Header ─────────────────────────────────── -->
<div class="row mb-6">
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4 p-md-5">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
          <div class="d-flex align-items-center gap-3">
            <div class="avatar avatar-lg bg-label-warning rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
              <i class="icon-base bx bxs-star fs-2 text-warning"></i>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <h4 class="mb-0 fw-bold text-heading">My Starred & Favorites Vault</h4>
                <span class="badge bg-primary rounded-pill px-3 py-1" id="fav-header-badge">0 Items</span>
              </div>
              <p class="text-body-secondary mb-0 mt-1">
                Your personalized collection of bookmarked Live Channels, Movies, TV Series, and Radio Stations.
              </p>
            </div>
          </div>

          <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <button type="button" class="btn btn-outline-danger btn-sm" id="btn-clear-all-favs" style="display: none;">
              <i class="icon-base bx bx-trash me-1"></i> Clear All Favorites
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Filter Nav-Pills Toolbar ────────────────────────────────────── -->
<div class="row mb-6">
  <div class="col-12">
    <div class="nav-align-top">
      <ul class="nav nav-pills flex-column flex-sm-row gap-sm-0 gap-2" role="tablist" id="favorites-nav-tabs">
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-fav-all" role="tab">
            <i class="icon-base bx bx-grid-alt icon-sm me-1"></i> All Favorites
            <span class="badge bg-primary ms-1" id="fav-count-all">0</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-fav-live" role="tab">
            <i class="icon-base bx bx-broadcast icon-sm me-1 text-primary"></i> Live TV
            <span class="badge bg-label-primary ms-1" id="fav-count-live">0</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-fav-movies" role="tab">
            <i class="icon-base bx bx-film icon-sm me-1 text-success"></i> Movies
            <span class="badge bg-label-success ms-1" id="fav-count-movies">0</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-fav-series" role="tab">
            <i class="icon-base bx bx-movie-play icon-sm me-1 text-warning"></i> TV Series
            <span class="badge bg-label-warning ms-1" id="fav-count-series">0</span>
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-fav-radio" role="tab">
            <i class="icon-base bx bx-radio icon-sm me-1 text-info"></i> Radio
            <span class="badge bg-label-info ms-1" id="fav-count-radio">0</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</div>

<!-- ─── Favorites Tab Panes ─────────────────────────────────────────── -->
<div class="tab-content p-0 border-0 bg-transparent" id="favorites-content-wrapper">

  <!-- Loading Placeholder -->
  <div class="text-center py-6" id="fav-loading-state">
    <div class="spinner-border text-primary mb-3" role="status">
      <span class="visually-hidden">Loading Favorites...</span>
    </div>
    <h6 class="text-body-secondary">Synchronizing your saved favorites...</h6>
  </div>

  <!-- Tab 1: All Favorites -->
  <div class="tab-pane fade show active" id="tab-fav-all" role="tabpanel">
    <div id="fav-list-all" class="row g-4"></div>
  </div>

  <!-- Tab 2: Live Channels -->
  <div class="tab-pane fade" id="tab-fav-live" role="tabpanel">
    <div id="fav-list-live" class="row g-3"></div>
  </div>

  <!-- Tab 3: Movies -->
  <div class="tab-pane fade" id="tab-fav-movies" role="tabpanel">
    <div id="fav-list-movies" class="row g-3"></div>
  </div>

  <!-- Tab 4: TV Series -->
  <div class="tab-pane fade" id="tab-fav-series" role="tabpanel">
    <div id="fav-list-series" class="row g-3"></div>
  </div>

  <!-- Tab 5: Radio -->
  <div class="tab-pane fade" id="tab-fav-radio" role="tabpanel">
    <div id="fav-list-radio" class="row g-3"></div>
  </div>

</div>

<!-- Favorites Controller Script -->
<script src="<?= $assetsPath ?>js/player-favorites.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.PlayerFavorites && typeof window.PlayerFavorites.init === 'function') {
      window.PlayerFavorites.init();
    }
  });
</script>
