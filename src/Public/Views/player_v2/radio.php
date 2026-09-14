<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '/';
$assetsPath = $baseUrl . 'assets/';
?>

<div class="row g-4 radio-app-wrapper">
  <!-- ================================================================= -->
  <!-- VIEW 1: RADIO STATION BROWSER GRID VIEW                           -->
  <!-- ================================================================= -->
  <div class="col-12" id="radio-browser-view">
    <div class="card shadow-sm border-0">
      <div class="row g-0">
        <!-- ─── Categories Sidebar ───────────────────────────────────── -->
        <div class="col-12 col-lg-3 border-end live-sidebar-pane" id="radio-categories-sidebar">
          <div class="p-4 border-bottom">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0 fw-bold">
                <i class="icon-base bx bx-category me-2 text-primary"></i>Categories
              </h5>
              <span class="badge bg-label-primary rounded-pill px-2" id="radio-total-count-badge">
                <?= (int)($totalRadioCount ?? 0) ?> Stations
              </span>
            </div>
            <div class="input-group input-group-merge">
              <span class="input-group-text border-0 bg-body-secondary"><i class="icon-base bx bx-search text-body-secondary"></i></span>
              <input
                type="text"
                id="radio-category-search"
                class="form-control border-0 bg-body-secondary"
                placeholder="Search categories..." />
            </div>
          </div>

          <div class="p-3 sidebar-categories-scroll">
            <div class="list-group list-group-flush" id="radio-categories-list">
              <a
                href="javascript:void(0);"
                class="list-group-item list-group-item-action category-list-item d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 <?= empty($selectedCategoryId) ? 'active' : '' ?>"
                data-radio-category-id="all">
                <div class="d-flex align-items-center">
                  <i class="icon-base bx bx-broadcast me-2 text-primary"></i>
                  <span class="fw-medium">All Stations</span>
                </div>
                <span class="badge bg-label-secondary rounded-pill"><?= (int)($totalRadioCount ?? 0) ?></span>
              </a>

              <?php if (!empty($rCategories)): ?>
                <?php foreach ($rCategories as $cat): ?>
                  <a
                    href="javascript:void(0);"
                    class="list-group-item list-group-item-action category-list-item d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 <?= (!empty($selectedCategoryId) && (int)$selectedCategoryId === (int)$cat['id']) ? 'active' : '' ?>"
                    data-radio-category-id="<?= (int)$cat['id'] ?>"
                    data-category-raw="<?= htmlspecialchars($cat['raw_name'] ?? $cat['name'] ?? '') ?>"
                    title="<?= htmlspecialchars($cat['name'] ?? 'Category') ?>">
                    <div class="d-flex align-items-center text-truncate me-2">
                      <?php if (!empty($cat['emoji'])): ?>
                        <span class="category-emoji me-2"><?= $cat['emoji'] ?></span>
                      <?php else: ?>
                        <i class="icon-base <?= $cat['icon'] ?? 'bx bx-broadcast' ?> me-2 text-primary"></i>
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
          <div class="sidebar-category-pagination p-2 d-flex align-items-center justify-content-between d-none" id="radio-categories-pagination"></div>
        </div>

        <!-- ─── Main Catalog Area ───────────────────────────────────── -->
        <div class="col-12 col-lg-9 flex-grow-1">
          <!-- Toolbar -->
          <div class="p-4 border-bottom bg-body-tertiary">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
              <!-- Left controls -->
              <div class="d-flex flex-wrap align-items-center gap-2">
                <button
                  type="button"
                  class="btn btn-sm btn-icon btn-label-secondary rounded d-lg-none"
                  id="btn-toggle-radio-categories"
                  title="Toggle Categories">
                  <i class="icon-base bx bx-menu"></i>
                </button>

                <button
                  type="button"
                  class="btn btn-sm btn-label-secondary rounded d-flex align-items-center gap-1"
                  id="btn-radio-favorites">
                  <i class="icon-base bx bx-star"></i>
                  <span>Favorites</span>
                </button>

                <!-- Sort dropdown -->
                <div class="input-group input-group-sm w-auto">
                  <span class="input-group-text bg-body border-0"><i class="icon-base bx bx-sort"></i></span>
                  <select class="form-select border-0 bg-body shadow-none" id="radio-sort-select">
                    <option value="default" selected>Default</option>
                    <option value="alpha_az">Title A-Z</option>
                    <option value="alpha_za">Title Z-A</option>
                  </select>
                </div>

                <!-- Column density selector -->
                <div class="btn-group btn-group-sm d-none d-sm-inline-flex" role="group" aria-label="Grid Density">
                  <button type="button" class="btn btn-label-secondary" data-radio-grid-cols="2" title="2 Columns">2</button>
                  <button type="button" class="btn btn-label-secondary active" data-radio-grid-cols="3" title="3 Columns">3</button>
                  <button type="button" class="btn btn-label-secondary" data-radio-grid-cols="4" title="4 Columns">4</button>
                  <button type="button" class="btn btn-label-secondary" data-radio-grid-cols="6" title="6 Columns">6</button>
                </div>

                <!-- Items per page limit -->
                <div class="btn-group btn-group-sm d-none d-md-inline-flex" role="group" aria-label="Show Limit">
                  <button type="button" class="btn btn-label-secondary" data-radio-show-limit="12">12</button>
                  <button type="button" class="btn btn-label-secondary" data-radio-show-limit="24">24</button>
                  <button type="button" class="btn btn-label-secondary active" data-radio-show-limit="48">48</button>
                  <button type="button" class="btn btn-label-secondary" data-radio-show-limit="96">96</button>
                  <button type="button" class="btn btn-label-secondary" data-radio-show-limit="all">All</button>
                </div>
              </div>

              <!-- Right controls (Radio Search & Count) -->
              <div class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0">
                <span class="badge bg-label-primary px-2" id="radio-count-badge">
                  <?= count($initialStations ?? []) ?> Stations
                </span>
                <div class="input-group input-group-merge input-group-sm">
                  <span class="input-group-text border-0 bg-body"><i class="icon-base bx bx-search text-body-secondary"></i></span>
                  <input
                    type="text"
                    id="radio-search"
                    class="form-control border-0 bg-body"
                    placeholder="Search stations..." />
                </div>
              </div>
            </div>
          </div>

          <!-- Radio Cards Grid Container -->
          <div class="p-4">
            <div class="row g-3" id="radio-container">
              <!-- Rendered dynamically by player-radio.js -->
            </div>
          </div>

          <!-- Radio Cards Pagination -->
          <div class="items-pagination-bar px-4 pb-4 pt-3 d-none" id="radio-pagination"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ================================================================= -->
  <!-- VIEW 2: LIVE RADIO STUDIO PLAYER VIEW                             -->
  <!-- ================================================================= -->
  <div class="col-12 d-none" id="radio-player-view">
    <div class="card overflow-hidden shadow-sm border-0">
      <div class="row g-0">
        <!-- ─── Mini Zap Sidebar (Left) ──────────────────────────────── -->
        <div class="col-12 col-lg-3 border-end radio-player-mini-sidebar">
          <div class="p-3 border-bottom d-flex align-items-center gap-2">
            <button
              type="button"
              class="btn btn-sm btn-icon btn-label-secondary rounded"
              id="btn-back-radio"
              title="Back to stations grid">
              <i class="icon-base bx bx-arrow-back"></i>
            </button>
            <h6 class="mb-0 fw-bold text-truncate">Station List</h6>
          </div>
          <div class="p-3 border-bottom">
            <div class="input-group input-group-sm input-group-merge">
              <span class="input-group-text border-0 bg-body-secondary"><i class="icon-base bx bx-search text-body-secondary"></i></span>
              <input
                type="text"
                id="radio-mini-zap-search"
                class="form-control border-0 bg-body-secondary"
                placeholder="Search stations..." />
            </div>
          </div>
          <div class="p-2 overflow-y-auto" id="radio-mini-zap-list">
            <!-- Populated dynamically by player-radio.js -->
          </div>
        </div>

        <!-- ─── Studio Audio Player Stage Area (Right) ───────────────── -->
        <div class="col-12 col-lg-9 d-flex flex-column flex-grow-1">
          <!-- Player Header Topbar -->
          <div class="p-3 border-bottom bg-body-tertiary d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 min-w-0">
              <img
                id="radio-player-station-logo"
                src=""
                alt=""
                class="rounded p-1 bg-body border flex-shrink-0 channel-logo-sm d-none" />
              <div class="min-w-0">
                <h5 class="mb-0 fw-bold text-truncate" id="radio-player-station-name">Radio Broadcast</h5>
                <small class="text-body-secondary text-truncate d-block">Live Audiophile Stream</small>
              </div>
            </div>

            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-danger rounded-pill px-3 py-1">
                <span class="live-badge-dot me-1"></span> ON AIR
              </span>
              <span class="badge bg-label-primary rounded-pill px-3 py-1">
                128 kbps HQ
              </span>
              <button
                type="button"
                class="btn btn-sm btn-label-secondary d-flex align-items-center gap-1"
                id="btn-radio-player-fav"
                title="Favorite">
                <i class="icon-base bx bx-star"></i>
                <span class="d-none d-sm-inline">Favorite</span>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-label-primary d-flex align-items-center gap-1"
                id="btn-radio-minimize"
                title="Minimize player to bottom bar">
                <i class="icon-base bx bx-down-arrow-alt"></i>
                <span class="d-none d-sm-inline">Minimize</span>
              </button>
            </div>
          </div>

          <!-- Studio Acoustic Stage -->
          <div class="radio-studio-stage p-5">
            <!-- Animated Vinyl Turntable -->
            <div class="radio-turntable-wrap mb-4">
              <div class="radio-turntable-disc w-100 h-100 d-flex align-items-center justify-content-center" id="radio-turntable-disc">
                <div class="radio-center-label">
                  <img src="" alt="" class="radio-center-logo d-none" id="radio-center-logo" />
                  <i class="icon-base bx bx-broadcast fs-1 text-primary"></i>
                </div>
              </div>
            </div>

            <!-- Sound Waves Equalizer -->
            <div class="radio-equalizer mb-3" id="radio-equalizer">
              <div class="radio-equalizer-bar"></div>
              <div class="radio-equalizer-bar"></div>
              <div class="radio-equalizer-bar"></div>
              <div class="radio-equalizer-bar"></div>
              <div class="radio-equalizer-bar"></div>
              <div class="radio-equalizer-bar"></div>
              <div class="radio-equalizer-bar"></div>
            </div>
          </div>

          <!-- Studio Bottom Audio Controls -->
          <div class="p-4 border-top bg-body d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <button
                type="button"
                class="btn btn-primary btn-icon rounded-circle p-2"
                id="btn-radio-play-pause"
                title="Play / Pause">
                <i class="icon-base bx bx-play fs-4"></i>
              </button>
              <div class="d-flex align-items-center gap-2">
                <button
                  type="button"
                  class="btn btn-sm btn-icon btn-label-secondary"
                  id="btn-radio-mute"
                  title="Mute / Unmute">
                  <i class="icon-base bx bx-volume-full"></i>
                </button>
                <input
                  type="range"
                  class="form-range"
                  id="radio-volume-slider"
                  min="0"
                  max="1"
                  step="0.05"
                  value="1" />
              </div>
            </div>

            <div class="d-flex align-items-center gap-2 text-body-secondary small">
              <i class="icon-base bx bx-podcast text-primary fs-5"></i>
              <span>Stereo Sound &bull; Continuous Live Transmission</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Radio Explorer Engine Runtime -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.RadioApp) {
      window.RadioApp.start({
        baseUrl: '<?= $baseUrl ?>',
        initialStations: <?= json_encode($initialStations ?? []) ?>,
        selectedCategoryId: <?= json_encode($selectedCategoryId ?? null) ?>
      });
    }
  });
</script>
