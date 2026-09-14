<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '/';
$assetsPath = $baseUrl . 'assets/';
?>

<div class="row g-4 live-app-wrapper">
  <!-- ================================================================= -->
  <!-- VIEW 1: CHANNEL BROWSER GRID VIEW                                 -->
  <!-- ================================================================= -->
  <div class="col-12" id="live-browser-view">
    <div class="card shadow-sm border-0">
      <div class="row g-0">
        <!-- ─── Categories Sidebar ───────────────────────────────────── -->
        <div class="col-12 col-lg-3 border-end live-sidebar-pane" id="live-categories-sidebar">
          <div class="p-4 border-bottom">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0 fw-bold">
                <i class="icon-base bx bx-category me-2 text-primary"></i>Categories
              </h5>
              <span class="badge bg-label-primary rounded-pill px-2" id="live-channel-count-badge">
                <?= (int)($totalLiveCount ?? 0) ?> Channels
              </span>
            </div>
            <div class="input-group input-group-merge">
              <span class="input-group-text border-0 bg-body-secondary"><i class="icon-base bx bx-search text-body-secondary"></i></span>
              <input
                type="text"
                id="live-category-search"
                class="form-control border-0 bg-body-secondary"
                placeholder="Search categories..." />
            </div>
          </div>

          <div class="p-3 sidebar-categories-scroll">
            <div class="list-group list-group-flush" id="live-categories-list">
              <a
                href="javascript:void(0);"
                class="list-group-item list-group-item-action category-list-item d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 <?= empty($selectedCategoryId) ? 'active' : '' ?>"
                data-category-id="all">
                <div class="d-flex align-items-center">
                  <i class="icon-base bx bx-grid-alt me-2 text-primary"></i>
                  <span class="fw-medium">All Channels</span>
                </div>
                <span class="badge bg-label-secondary rounded-pill"><?= (int)($totalLiveCount ?? 0) ?></span>
              </a>

              <?php if (!empty($rCategories)): ?>
                <?php foreach ($rCategories as $cat): ?>
                  <a
                    href="javascript:void(0);"
                    class="list-group-item list-group-item-action category-list-item d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 <?= (!empty($selectedCategoryId) && (int)$selectedCategoryId === (int)$cat['id']) ? 'active' : '' ?>"
                    data-category-id="<?= (int)$cat['id'] ?>"
                    data-category-raw="<?= htmlspecialchars($cat['raw_name'] ?? $cat['name'] ?? '') ?>"
                    title="<?= htmlspecialchars($cat['name'] ?? 'Category') ?>">
                    <div class="d-flex align-items-center text-truncate me-2">
                      <?php if (!empty($cat['emoji'])): ?>
                        <span class="category-emoji me-2"><?= $cat['emoji'] ?></span>
                      <?php else: ?>
                        <i class="icon-base <?= $cat['icon'] ?? 'bx bx-tv' ?> me-2 text-primary"></i>
                      <?php endif; ?>

                      <?php if (!empty($cat['tag'])): ?>
                        <span class="badge bg-label-<?= htmlspecialchars($cat['tag_color'] ?? 'primary') ?> category-tag-badge rounded-pill px-2 py-0 me-2 fw-bold">
                          <?= htmlspecialchars($cat['tag']) ?>
                        </span>
                      <?php endif; ?>

                      <span class="text-truncate fw-medium"><?= htmlspecialchars($cat['clean_name'] ?? $cat['name'] ?? 'Category') ?></span>

                      <?php if (!empty($cat['is_custom'])): ?>
                        <i class="icon-base bx bxs-badge-check text-info ms-1" title="Template Custom Name"></i>
                      <?php endif; ?>
                    </div>
                    <?php if (isset($cat['stream_count'])): ?>
                      <span class="badge bg-label-secondary rounded-pill"><?= (int)$cat['stream_count'] ?></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Categories Sidebar Pagination -->
          <div class="sidebar-category-pagination p-2 d-flex align-items-center justify-content-between d-none" id="live-categories-pagination"></div>
        </div>

        <!-- ─── Main Channel Grid Area ───────────────────────────────── -->
        <div class="col-12 col-lg-9 flex-grow-1">
          <!-- Toolbar -->
          <div class="p-4 border-bottom bg-body-tertiary">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
              <!-- Left controls -->
              <div class="d-flex flex-wrap align-items-center gap-2">
                <button
                  type="button"
                  class="btn btn-sm btn-icon btn-label-secondary rounded d-lg-none"
                  id="btn-toggle-categories"
                  title="Toggle Categories">
                  <i class="icon-base bx bx-menu"></i>
                </button>

                <button
                  type="button"
                  class="btn btn-sm btn-label-secondary rounded d-flex align-items-center gap-1"
                  id="btn-live-favorites">
                  <i class="icon-base bx bx-star"></i>
                  <span>Favorites</span>
                </button>

                <div class="input-group input-group-sm w-auto">
                  <span class="input-group-text bg-body border-0"><i class="icon-base bx bx-sort"></i></span>
                  <select class="form-select border-0 bg-body shadow-none" id="live-sort-select">
                    <option value="default" selected>Default</option>
                    <option value="alpha_az">Name A-Z</option>
                    <option value="alpha_za">Name Z-A</option>
                    <option value="lang_ar">Arabic First</option>
                    <option value="lang_en">English First</option>
                  </select>
                </div>

                <!-- Column density selector -->
                <div class="btn-group btn-group-sm d-none d-sm-inline-flex" role="group" aria-label="Grid Density">
                  <button type="button" class="btn btn-label-secondary" data-grid-cols="3" title="3 Columns">3</button>
                  <button type="button" class="btn btn-label-secondary active" data-grid-cols="4" title="4 Columns">4</button>
                  <button type="button" class="btn btn-label-secondary" data-grid-cols="6" title="6 Columns">6</button>
                </div>

                <!-- Items per page limit -->
                <div class="btn-group btn-group-sm d-none d-md-inline-flex" role="group" aria-label="Show Limit">
                  <button type="button" class="btn btn-label-secondary" data-show-limit="30">30</button>
                  <button type="button" class="btn btn-label-secondary active" data-show-limit="60">60</button>
                  <button type="button" class="btn btn-label-secondary" data-show-limit="120">120</button>
                  <button type="button" class="btn btn-label-secondary" data-show-limit="all">All</button>
                </div>
              </div>

              <!-- Right controls (Channel Search) -->
              <div class="flex-grow-1 flex-md-grow-0">
                <div class="input-group input-group-merge input-group-sm">
                  <span class="input-group-text border-0 bg-body"><i class="icon-base bx bx-search text-body-secondary"></i></span>
                  <input
                    type="text"
                    id="live-channel-search"
                    class="form-control border-0 bg-body"
                    placeholder="Filter channels..." />
                </div>
              </div>
            </div>
          </div>

          <!-- Channel Cards Grid Container -->
          <div class="p-4">
            <div class="row g-3" id="live-channels-container">
              <!-- Rendered dynamically by player-live.js -->
            </div>
          </div>

          <!-- Channel Cards Pagination -->
          <div class="items-pagination-bar px-4 pb-4 pt-3 d-none" id="live-pagination"></div>
        </div>

      </div>
    </div>
  </div>

  <!-- ================================================================= -->
  <!-- VIEW 2: DEDICATED LIVE PLAYER & MINI-SIDEBAR VIEW                 -->
  <!-- ================================================================= -->
  <div class="col-12 d-none" id="live-player-view">
    <div class="card overflow-hidden shadow-sm border-0">
      <div class="row g-0">
        <!-- ─── Mini Zap Sidebar (Left) ──────────────────────────────── -->
        <div class="col-12 col-lg-3 border-end live-player-mini-sidebar">
          <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <button
              type="button"
              class="btn btn-sm btn-icon btn-label-secondary rounded"
              id="btn-back-live"
              title="Back to channel grid">
              <i class="icon-base bx bx-arrow-back"></i>
            </button>
            <h6 class="mb-0 fw-bold text-truncate">Channel List</h6>
          </div>
          <div class="p-3 border-bottom">
            <div class="input-group input-group-sm input-group-merge">
              <span class="input-group-text border-0 bg-body-secondary"><i class="icon-base bx bx-search text-body-secondary"></i></span>
              <input
                type="text"
                id="live-player-search"
                class="form-control border-0 bg-body-secondary"
                placeholder="Search channels..." />
            </div>
          </div>
          <div class="p-2 overflow-y-auto" id="live-player-channel-list">
            <!-- Populated dynamically by player-live.js -->
          </div>
        </div>

        <!-- ─── Main Video Stage & EPG Area (Right) ──────────────────── -->
        <div class="col-12 col-lg-9 d-flex flex-column flex-grow-1">
          <!-- Player Header Topbar -->
          <div class="p-3 border-bottom bg-body-tertiary d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 min-w-0">
              <img
                id="live-player-ch-logo"
                src=""
                alt=""
                class="rounded p-1 bg-body border flex-shrink-0 channel-logo-sm d-none" />
              <div class="min-w-0">
                <h5 class="mb-0 fw-bold text-truncate" id="live-player-ch-name">Live Stream</h5>
                <small class="text-body-secondary text-truncate d-block" id="live-player-epg-text">Connecting live broadcast...</small>
              </div>
            </div>

            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-danger rounded-pill px-3 py-1">
                <span class="live-badge-dot me-1"></span> LIVE
              </span>
              <button
                type="button"
                class="btn btn-sm btn-label-secondary d-flex align-items-center gap-1"
                id="btn-live-player-fav"
                title="Favorite">
                <i class="icon-base bx bx-star"></i>
                <span class="d-none d-sm-inline">Favorite</span>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-icon btn-label-secondary"
                id="btn-live-fullscreen"
                title="Fullscreen">
                <i class="icon-base bx bx-fullscreen"></i>
              </button>
            </div>
          </div>

          <!-- Video Element Stage -->
          <div class="position-relative live-video-stage d-flex align-items-center justify-content-center">
            <video
              id="live-video"
              class="video-js vjs-big-play-centered w-100 h-100"
              controls
              preload="none"
              playsinline>
            </video>

            <!-- Video Overlay / Waiting State -->
            <div id="live-player-overlay" class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center p-4 bg-black bg-opacity-75">
              <div class="avatar avatar-xl rounded-circle bg-label-primary mb-3">
                <i class="icon-base bx bx-tv fs-1"></i>
              </div>
              <h5 class="text-white mb-1">Select a channel to start watching</h5>
              <p class="text-white-50 mb-0">Choose from the quick list on the left or return to the grid.</p>
            </div>

            <!-- Stream Status / Auto-Recovery Overlay -->
            <div id="live-stream-status" class="position-absolute top-0 start-0 w-100 h-100 d-none flex-column align-items-center justify-content-center text-center p-4 bg-black bg-opacity-75" style="z-index: 10;">
              <div id="live-status-spinner" class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
              </div>
              <div id="live-status-icon" class="avatar avatar-xl rounded-circle bg-label-danger mb-3 d-none">
                <i class="icon-base bx bx-wifi-off fs-1"></i>
              </div>
              <h5 class="text-white mb-2" id="live-status-title">Reconnecting Stream...</h5>
              <p class="text-white-50 mb-3 small" id="live-status-desc" style="max-width: 420px;">The external server temporarily closed the connection. Restoring playback automatically...</p>
              <button type="button" class="btn btn-primary d-none shadow-sm" id="btn-live-retry-stream">
                <i class="icon-base bx bx-refresh me-1"></i> Retry Connection
              </button>
            </div>
          </div>

          <!-- Bottom EPG Program Schedule -->
          <div class="p-4 border-top bg-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-label-primary px-2 py-1">NOW PLAYING</span>
                <h6 class="mb-0 fw-bold" id="epg-current-program">No Programme Information Available</h6>
              </div>
              <span class="badge bg-label-secondary" id="live-epg-badge">Live Broadcast</span>
            </div>

            <!-- Progress Bar -->
            <div class="progress mb-2 live-progress-wrap d-none" id="live-epg-progress-wrap">
              <div
                class="progress-bar bg-primary"
                role="progressbar"
                id="live-epg-progress-bar"
                aria-valuenow="0"
                aria-valuemin="0"
                aria-valuemax="100"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Video.js & HLS Runtime -->
<link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/videojs/video-js.min.css" />
<script src="<?= $assetsPath ?>vendor/libs/videojs/video.min.js"></script>
<script src="<?= $assetsPath ?>vendor/libs/hls/hls.js"></script>
<script src="<?= $assetsPath ?>js/player-live.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const liveApp = window.LivePlayerApp || window.LiveApp;
    if (liveApp) {
      liveApp.start({
        baseUrl: '<?= $baseUrl ?>',
        initialChannels: <?= json_encode($initialChannels ?? []) ?>,
        selectedCategoryId: <?= json_encode($selectedCategoryId ?? null) ?>
      });
    }
  });
</script>
