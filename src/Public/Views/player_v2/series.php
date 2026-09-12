<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '/';
$assetsPath = $baseUrl . 'assets/';
?>

<div class="row g-4 series-app-wrapper">
  <div class="col-12">
    <div class="card overflow-hidden shadow-sm border-0">
      <div class="row g-0">
        <!-- ─── Categories Sidebar ───────────────────────────────────── -->
        <div class="col-12 col-lg-3 border-end live-sidebar-pane" id="series-categories-sidebar">
          <div class="p-4 border-bottom">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h5 class="card-title mb-0 fw-bold">
                <i class="icon-base bx bx-category me-2 text-primary"></i>Categories
              </h5>
              <span class="badge bg-label-primary rounded-pill px-2" id="series-total-count-badge">
                <?= (int)($totalSeriesCount ?? 0) ?> Series
              </span>
            </div>
            <div class="input-group input-group-merge">
              <span class="input-group-text border-0 bg-body-secondary"><i class="icon-base bx bx-search text-body-secondary"></i></span>
              <input
                type="text"
                id="series-category-search"
                class="form-control border-0 bg-body-secondary"
                placeholder="Search categories..." />
            </div>
          </div>

          <div class="p-3 sidebar-categories-scroll">
            <div class="list-group list-group-flush" id="series-categories-list">
              <a
                href="javascript:void(0);"
                class="list-group-item list-group-item-action category-list-item d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 <?= empty($selectedCategoryId) ? 'active' : '' ?>"
                data-series-category-id="all">
                <div class="d-flex align-items-center">
                  <i class="icon-base bx bx-grid-alt me-2 text-primary"></i>
                  <span class="fw-medium">All Series</span>
                </div>
                <span class="badge bg-label-secondary rounded-pill"><?= (int)($totalSeriesCount ?? 0) ?></span>
              </a>

              <?php if (!empty($rCategories)): ?>
                <?php foreach ($rCategories as $cat): ?>
                  <a
                    href="javascript:void(0);"
                    class="list-group-item list-group-item-action category-list-item d-flex align-items-center justify-content-between rounded mb-1 px-3 py-2 <?= (!empty($selectedCategoryId) && (int)$selectedCategoryId === (int)$cat['id']) ? 'active' : '' ?>"
                    data-series-category-id="<?= (int)$cat['id'] ?>"
                    data-category-raw="<?= htmlspecialchars($cat['raw_name'] ?? $cat['name'] ?? '') ?>"
                    title="<?= htmlspecialchars($cat['name'] ?? 'Category') ?>">
                    <div class="d-flex align-items-center text-truncate me-2">
                      <?php if (!empty($cat['emoji'])): ?>
                        <span class="category-emoji me-2"><?= $cat['emoji'] ?></span>
                      <?php else: ?>
                        <i class="icon-base <?= $cat['icon'] ?? 'bx bx-movie-play' ?> me-2 text-primary"></i>
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
          <div class="sidebar-category-pagination p-2 d-flex align-items-center justify-content-between d-none" id="series-categories-pagination"></div>
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
                  id="btn-toggle-series-categories"
                  title="Toggle Categories">
                  <i class="icon-base bx bx-menu"></i>
                </button>

                <button
                  type="button"
                  class="btn btn-sm btn-label-secondary rounded d-flex align-items-center gap-1"
                  id="btn-series-favorites">
                  <i class="icon-base bx bx-star"></i>
                  <span>Favorites</span>
                </button>

                <!-- Sort dropdown -->
                <div class="input-group input-group-sm w-auto">
                  <span class="input-group-text bg-body border-0"><i class="icon-base bx bx-sort"></i></span>
                  <select class="form-select border-0 bg-body shadow-none" id="series-sort-select">
                    <option value="default" selected>Default</option>
                    <option value="rating_desc">Highest Rated</option>
                    <option value="year_desc">Newest Year</option>
                    <option value="year_asc">Oldest Year</option>
                    <option value="alpha_az">Title A-Z</option>
                    <option value="alpha_za">Title Z-A</option>
                  </select>
                </div>

                <!-- Column density selector -->
                <div class="btn-group btn-group-sm d-none d-sm-inline-flex" role="group" aria-label="Grid Density">
                  <button type="button" class="btn btn-label-secondary" data-series-grid-cols="3" title="3 Columns">3</button>
                  <button type="button" class="btn btn-label-secondary active" data-series-grid-cols="4" title="4 Columns">4</button>
                  <button type="button" class="btn btn-label-secondary" data-series-grid-cols="6" title="6 Columns">6</button>
                </div>

                <!-- Items per page limit -->
                <div class="btn-group btn-group-sm d-none d-md-inline-flex" role="group" aria-label="Show Limit">
                  <button type="button" class="btn btn-label-secondary" data-series-show-limit="12">12</button>
                  <button type="button" class="btn btn-label-secondary" data-series-show-limit="24">24</button>
                  <button type="button" class="btn btn-label-secondary active" data-series-show-limit="48">48</button>
                  <button type="button" class="btn btn-label-secondary" data-series-show-limit="96">96</button>
                  <button type="button" class="btn btn-label-secondary" data-series-show-limit="all">All</button>
                </div>
              </div>

              <!-- Right controls (Series Search & Count) -->
              <div class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0">
                <span class="badge bg-label-primary px-2" id="series-count-badge">
                  <?= count($initialSeries ?? []) ?> Series
                </span>
                <div class="input-group input-group-merge input-group-sm">
                  <span class="input-group-text border-0 bg-body"><i class="icon-base bx bx-search text-body-secondary"></i></span>
                  <input
                    type="text"
                    id="series-search"
                    class="form-control border-0 bg-body"
                    placeholder="Search series..." />
                </div>
              </div>
            </div>
          </div>

          <!-- Series Cards Grid Container -->
          <div class="p-4">
            <div class="row g-3" id="series-container">
              <!-- Rendered dynamically by player-series.js -->
            </div>
          </div>

          <!-- Series Cards Pagination -->
          <div class="items-pagination-bar px-4 pb-4 pt-3 d-none" id="series-pagination"></div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Series Explorer Engine Runtime -->
<script src="<?= $assetsPath ?>js/player-series.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.SeriesApp) {
      window.SeriesApp.start({
        baseUrl: '<?= $baseUrl ?>',
        initialSeries: <?= json_encode($initialSeries ?? []) ?>,
        selectedCategoryId: <?= json_encode($selectedCategoryId ?? null) ?>
      });
    }
  });
</script>
