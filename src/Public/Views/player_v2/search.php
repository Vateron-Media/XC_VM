<?php

use XcVm\Core\Config\SettingsManager;

$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';
$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
?>

<!-- ─── Global Search Page Header ───────────────────────────────────── -->
<div class="row mb-6">
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-4 p-md-5">
        <div class="row align-items-center g-4">
          <div class="col-12 col-lg-7">
            <div class="d-flex align-items-center gap-3 mb-2">
              <div class="avatar avatar-lg bg-label-primary rounded-3 d-flex align-items-center justify-content-center">
                <i class="icon-base bx bx-search-alt fs-2 text-primary"></i>
              </div>
              <div>
                <h4 class="mb-0 fw-bold text-heading">Global Search Engine</h4>
                <p class="text-body-secondary mb-0">Search across Live TV, Movies, TV Series, Episodes & Radio</p>
              </div>
            </div>
            <?php if (!empty($query)): ?>
              <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
                <span class="text-body-secondary">Showing results for:</span>
                <span class="badge bg-label-primary fs-6 px-3 py-1 fw-bold">"<?= htmlspecialchars($query) ?>"</span>
                <span class="badge bg-primary rounded-pill px-3 py-1"><?= number_format($total) ?> Total Matches</span>
              </div>
            <?php endif; ?>
          </div>

          <div class="col-12 col-lg-5">
            <form action="<?= $baseUrl ?>search" method="GET">
              <div class="input-group input-group-merge shadow-sm">
                <span class="input-group-text"><i class="icon-base bx bx-search text-body-secondary"></i></span>
                <input
                  type="text"
                  name="q"
                  class="form-control"
                  placeholder="Search anything (e.g. Breaking, News, Action...)"
                  value="<?= htmlspecialchars($query) ?>"
                  autofocus
                  required />
                <button class="btn btn-primary" type="submit">Search</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (empty($query)): ?>
  <!-- Empty Search Initial Prompt -->
  <div class="row">
    <div class="col-12 text-center py-6">
      <div class="avatar avatar-xl bg-label-primary rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center">
        <i class="icon-base bx bx-search-alt fs-1 text-primary"></i>
      </div>
      <h5 class="fw-bold mb-2">Search the Entire Catalog</h5>
      <p class="text-body-secondary max-w-500 mx-auto mb-4">
        Type a keyword in the box above to find live channels, movies, entire TV series, individual episodes, or radio stations instantly.
      </p>
    </div>
  </div>
<?php elseif ($total === 0): ?>
  <!-- No Results Found State -->
  <div class="row">
    <div class="col-12 text-center py-6">
      <div class="avatar avatar-xl bg-label-secondary rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center">
        <i class="icon-base bx bx-ghost fs-1 text-secondary"></i>
      </div>
      <h5 class="fw-bold mb-2">No Matches Found</h5>
      <p class="text-body-secondary max-w-500 mx-auto mb-4">
        No channels, movies, series, episodes, or radio stations matched your query <strong>"<?= htmlspecialchars($query) ?>"</strong>.
      </p>
      <a href="<?= $baseUrl ?>index" class="btn btn-outline-primary">
        <i class="icon-base bx bx-home-alt me-1"></i> Return to Dashboard
      </a>
    </div>
  </div>
<?php else: ?>

  <!-- ─── Filter Nav-Pills Toolbar ────────────────────────────────────── -->
  <div class="row mb-6">
    <div class="col-12">
      <div class="nav-align-top">
        <ul class="nav nav-pills flex-column flex-sm-row gap-sm-0 gap-2 search-type-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-search-all" role="tab">
              <i class="icon-base bx bx-grid-alt icon-sm me-1"></i> All Results
              <span class="badge bg-primary ms-1"><?= $total ?></span>
            </button>
          </li>
          <?php if ($counts['live'] > 0): ?>
            <li class="nav-item" role="presentation">
              <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-search-live" role="tab">
                <i class="icon-base bx bx-broadcast icon-sm me-1 text-primary"></i> Live TV
                <span class="badge bg-label-primary ms-1"><?= $counts['live'] ?></span>
              </button>
            </li>
          <?php endif; ?>
          <?php if ($counts['movies'] > 0): ?>
            <li class="nav-item" role="presentation">
              <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-search-movies" role="tab">
                <i class="icon-base bx bx-film icon-sm me-1 text-success"></i> Movies
                <span class="badge bg-label-success ms-1"><?= $counts['movies'] ?></span>
              </button>
            </li>
          <?php endif; ?>
          <?php if ($counts['series'] > 0): ?>
            <li class="nav-item" role="presentation">
              <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-search-series" role="tab">
                <i class="icon-base bx bx-movie-play icon-sm me-1 text-warning"></i> TV Series
                <span class="badge bg-label-warning ms-1"><?= $counts['series'] ?></span>
              </button>
            </li>
          <?php endif; ?>
          <?php if ($counts['episodes'] > 0): ?>
            <li class="nav-item" role="presentation">
              <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-search-episodes" role="tab">
                <i class="icon-base bx bx-play-circle icon-sm me-1 text-danger"></i> Episodes
                <span class="badge bg-label-danger ms-1"><?= $counts['episodes'] ?></span>
              </button>
            </li>
          <?php endif; ?>
          <?php if ($counts['radio'] > 0): ?>
            <li class="nav-item" role="presentation">
              <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-search-radio" role="tab">
                <i class="icon-base bx bx-radio icon-sm me-1 text-info"></i> Radio
                <span class="badge bg-label-info ms-1"><?= $counts['radio'] ?></span>
              </button>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <!-- ─── Search Results Panes ────────────────────────────────────────── -->
  <div class="tab-content p-0 border-0 bg-transparent">

    <!-- ═════════════════════════════════════════════════════════════════
         PANE 1: ALL RESULTS (Grouped Sections)
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade show active" id="tab-search-all" role="tabpanel">

      <!-- 1. LIVE CHANNELS SECTION -->
      <?php if (!empty($results['live'])): ?>
        <div class="mb-6">
          <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
              <i class="icon-base bx bx-broadcast text-primary"></i> Live TV Channels
              <span class="badge bg-label-primary rounded-pill"><?= count($results['live']) ?></span>
            </h5>
            <a href="<?= $baseUrl ?>live" class="btn btn-sm btn-link text-decoration-none">Go to Live Grid &rarr;</a>
          </div>
          <div class="row g-3">
            <?php foreach ($results['live'] as $item): ?>
              <div class="col-6 col-md-4 col-xl-3">
                <div class="card h-100 channel-card border shadow-sm">
                  <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="channel-logo-wrapper flex-shrink-0">
                      <?php if (!empty($item['icon'])): ?>
                        <img src="<?= htmlspecialchars($item['icon']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="channel-logo" loading="lazy" />
                      <?php else: ?>
                        <div class="avatar avatar-md bg-label-primary rounded d-flex align-items-center justify-content-center">
                          <i class="icon-base bx bx-tv text-primary"></i>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div class="overflow-hidden flex-grow-1">
                      <h6 class="mb-1 fw-semibold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                        <?= htmlspecialchars($item['title']) ?>
                      </h6>
                      <small class="badge bg-label-secondary text-truncate d-inline-block max-w-150"><?= htmlspecialchars($item['category']) ?></small>
                    </div>
                    <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-icon btn-primary flex-shrink-0" title="Watch Live">
                      <i class="icon-base bx bx-play"></i>
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 2. MOVIES SECTION -->
      <?php if (!empty($results['movies'])): ?>
        <div class="mb-6">
          <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
              <i class="icon-base bx bx-film text-success"></i> Feature Films & Movies
              <span class="badge bg-label-success rounded-pill"><?= count($results['movies']) ?></span>
            </h5>
            <a href="<?= $baseUrl ?>movies" class="btn btn-sm btn-link text-decoration-none">Go to Movies &rarr;</a>
          </div>
          <div class="row g-3">
            <?php foreach ($results['movies'] as $item): ?>
              <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card h-100 channel-card border shadow-sm position-relative">
                  <div class="channel-poster-wrapper position-relative">
                    <?php if (!empty($item['cover'])): ?>
                      <img src="<?= htmlspecialchars($item['cover']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="channel-poster" loading="lazy" />
                    <?php else: ?>
                      <div class="channel-poster-placeholder bg-label-secondary d-flex align-items-center justify-content-center">
                        <i class="icon-base bx bx-film fs-1 text-secondary"></i>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($item['rating'])): ?>
                      <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 shadow-sm">
                        ★ <?= htmlspecialchars((string)$item['rating']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                      <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                        <?= htmlspecialchars($item['title']) ?>
                      </h6>
                      <div class="d-flex align-items-center justify-content-between small text-body-secondary mb-2">
                        <span><?= htmlspecialchars($item['year'] ?: 'VOD') ?></span>
                        <span class="badge bg-label-secondary small"><?= htmlspecialchars($item['category']) ?></span>
                      </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                      <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-primary w-100">
                        <i class="icon-base bx bx-play me-1"></i> Watch
                      </a>
                      <a href="<?= $baseUrl ?><?= $item['details_url'] ?>" class="btn btn-sm btn-icon btn-outline-secondary" title="Details">
                        <i class="icon-base bx bx-info-circle"></i>
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 3. TV SERIES SECTION -->
      <?php if (!empty($results['series'])): ?>
        <div class="mb-6">
          <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
              <i class="icon-base bx bx-movie-play text-warning"></i> TV Series & Shows
              <span class="badge bg-label-warning rounded-pill"><?= count($results['series']) ?></span>
            </h5>
            <a href="<?= $baseUrl ?>series" class="btn btn-sm btn-link text-decoration-none">Go to TV Series &rarr;</a>
          </div>
          <div class="row g-3">
            <?php foreach ($results['series'] as $item): ?>
              <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card h-100 channel-card border shadow-sm position-relative">
                  <div class="channel-poster-wrapper position-relative">
                    <?php if (!empty($item['cover'])): ?>
                      <img src="<?= htmlspecialchars($item['cover']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="channel-poster" loading="lazy" />
                    <?php else: ?>
                      <div class="channel-poster-placeholder bg-label-secondary d-flex align-items-center justify-content-center">
                        <i class="icon-base bx bx-movie fs-1 text-secondary"></i>
                      </div>
                    <?php endif; ?>
                    <span class="badge bg-primary position-absolute top-0 start-0 m-2 shadow-sm">
                      <?= $item['seasons_count'] ?> <?= $item['seasons_count'] === 1 ? 'Season' : 'Seasons' ?>
                    </span>
                    <?php if (!empty($item['rating'])): ?>
                      <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 shadow-sm">
                        ★ <?= htmlspecialchars((string)$item['rating']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                      <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                        <?= htmlspecialchars($item['title']) ?>
                      </h6>
                      <small class="text-body-secondary d-block mb-2 text-truncate"><?= htmlspecialchars($item['genre']) ?></small>
                    </div>
                    <a href="<?= $baseUrl ?><?= $item['details_url'] ?>" class="btn btn-sm btn-outline-primary w-100 mt-2">
                      <i class="icon-base bx bx-show me-1"></i> View Series
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 4. SERIES EPISODES SECTION -->
      <?php if (!empty($results['episodes'])): ?>
        <div class="mb-6">
          <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
              <i class="icon-base bx bx-play-circle text-danger"></i> Specific TV Episodes
              <span class="badge bg-label-danger rounded-pill"><?= count($results['episodes']) ?></span>
            </h5>
          </div>
          <div class="row g-3">
            <?php foreach ($results['episodes'] as $item): ?>
              <div class="col-12 col-md-6 col-lg-4 col-xl-3">
                <div class="card h-100 border shadow-sm overflow-hidden">
                  <div class="position-relative">
                    <?php if (!empty($item['thumb'])): ?>
                      <img src="<?= htmlspecialchars($item['thumb']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="w-100 object-fit-cover" style="height: 140px;" loading="lazy" />
                    <?php else: ?>
                      <div class="bg-label-secondary w-100 d-flex align-items-center justify-content-center" style="height: 140px;">
                        <i class="icon-base bx bx-video fs-1 text-secondary"></i>
                      </div>
                    <?php endif; ?>
                    <span class="badge bg-danger position-absolute top-0 start-0 m-2 shadow-sm">
                      <?= htmlspecialchars($item['badge']) ?>
                    </span>
                    <?php if (!empty($item['duration'])): ?>
                      <span class="badge bg-dark bg-opacity-75 position-absolute bottom-0 end-0 m-2">
                        <?= htmlspecialchars($item['duration']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                      <small class="text-primary fw-semibold d-block text-truncate"><?= htmlspecialchars($item['series_title']) ?></small>
                      <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                        <?= htmlspecialchars($item['title']) ?>
                      </h6>
                      <?php if (!empty($item['plot'])): ?>
                        <p class="text-body-secondary small mb-2 line-clamp-2" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                          <?= htmlspecialchars($item['plot']) ?>
                        </p>
                      <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                      <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-primary w-100">
                        <i class="icon-base bx bx-play me-1"></i> Play Episode
                      </a>
                      <a href="<?= $baseUrl ?><?= $item['series_url'] ?>" class="btn btn-sm btn-icon btn-outline-secondary" title="View Series Page">
                        <i class="icon-base bx bx-layer"></i>
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 5. RADIO STATIONS SECTION -->
      <?php if (!empty($results['radio'])): ?>
        <div class="mb-6">
          <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
              <i class="icon-base bx bx-radio text-info"></i> Radio Stations & FM
              <span class="badge bg-label-info rounded-pill"><?= count($results['radio']) ?></span>
            </h5>
            <a href="<?= $baseUrl ?>radio" class="btn btn-sm btn-link text-decoration-none">Go to Radio Studio &rarr;</a>
          </div>
          <div class="row g-3">
            <?php foreach ($results['radio'] as $item): ?>
              <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <div class="card h-100 border shadow-sm">
                  <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="avatar avatar-lg bg-label-warning rounded-circle d-flex align-items-center justify-content-center flex-shrink-0">
                      <?php if (!empty($item['icon'])): ?>
                        <img src="<?= htmlspecialchars($item['icon']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="rounded-circle w-100 h-100 object-fit-contain" loading="lazy" />
                      <?php else: ?>
                        <i class="icon-base bx bx-radio fs-2 text-warning"></i>
                      <?php endif; ?>
                    </div>
                    <div class="overflow-hidden flex-grow-1">
                      <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                        <?= htmlspecialchars($item['title']) ?>
                      </h6>
                      <span class="badge bg-label-secondary small"><?= htmlspecialchars($item['category']) ?></span>
                    </div>
                    <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-icon btn-primary flex-shrink-0" title="Tune In">
                      <i class="icon-base bx bx-play"></i>
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- ═════════════════════════════════════════════════════════════════
         PANE 2: LIVE TV ONLY
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-search-live" role="tabpanel">
      <div class="row g-3">
        <?php foreach ($results['live'] as $item): ?>
          <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100 channel-card border shadow-sm">
              <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="channel-logo-wrapper flex-shrink-0">
                  <?php if (!empty($item['icon'])): ?>
                    <img src="<?= htmlspecialchars($item['icon']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="channel-logo" loading="lazy" />
                  <?php else: ?>
                    <div class="avatar avatar-md bg-label-primary rounded d-flex align-items-center justify-content-center">
                      <i class="icon-base bx bx-tv text-primary"></i>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="overflow-hidden flex-grow-1">
                  <h6 class="mb-1 fw-semibold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                    <?= htmlspecialchars($item['title']) ?>
                  </h6>
                  <small class="badge bg-label-secondary text-truncate d-inline-block max-w-150"><?= htmlspecialchars($item['category']) ?></small>
                </div>
                <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-icon btn-primary flex-shrink-0" title="Watch Live">
                  <i class="icon-base bx bx-play"></i>
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════════
         PANE 3: MOVIES ONLY
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-search-movies" role="tabpanel">
      <div class="row g-3">
        <?php foreach ($results['movies'] as $item): ?>
          <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card h-100 channel-card border shadow-sm position-relative">
              <div class="channel-poster-wrapper position-relative">
                <?php if (!empty($item['cover'])): ?>
                  <img src="<?= htmlspecialchars($item['cover']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="channel-poster" loading="lazy" />
                <?php else: ?>
                  <div class="channel-poster-placeholder bg-label-secondary d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-film fs-1 text-secondary"></i>
                  </div>
                <?php endif; ?>
                <?php if (!empty($item['rating'])): ?>
                  <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 shadow-sm">
                    ★ <?= htmlspecialchars((string)$item['rating']) ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="card-body p-3 d-flex flex-column justify-content-between">
                <div>
                  <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                    <?= htmlspecialchars($item['title']) ?>
                  </h6>
                  <div class="d-flex align-items-center justify-content-between small text-body-secondary mb-2">
                    <span><?= htmlspecialchars($item['year'] ?: 'VOD') ?></span>
                    <span class="badge bg-label-secondary small"><?= htmlspecialchars($item['category']) ?></span>
                  </div>
                </div>
                <div class="d-flex gap-2 mt-2">
                  <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-primary w-100">
                    <i class="icon-base bx bx-play me-1"></i> Watch
                  </a>
                  <a href="<?= $baseUrl ?><?= $item['details_url'] ?>" class="btn btn-sm btn-icon btn-outline-secondary" title="Details">
                    <i class="icon-base bx bx-info-circle"></i>
                  </a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════════
         PANE 4: TV SERIES ONLY
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-search-series" role="tabpanel">
      <div class="row g-3">
        <?php foreach ($results['series'] as $item): ?>
          <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card h-100 channel-card border shadow-sm position-relative">
              <div class="channel-poster-wrapper position-relative">
                <?php if (!empty($item['cover'])): ?>
                  <img src="<?= htmlspecialchars($item['cover']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="channel-poster" loading="lazy" />
                <?php else: ?>
                  <div class="channel-poster-placeholder bg-label-secondary d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-movie fs-1 text-secondary"></i>
                  </div>
                <?php endif; ?>
                <span class="badge bg-primary position-absolute top-0 start-0 m-2 shadow-sm">
                  <?= $item['seasons_count'] ?> <?= $item['seasons_count'] === 1 ? 'Season' : 'Seasons' ?>
                </span>
                <?php if (!empty($item['rating'])): ?>
                  <span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 shadow-sm">
                    ★ <?= htmlspecialchars((string)$item['rating']) ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="card-body p-3 d-flex flex-column justify-content-between">
                <div>
                  <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                    <?= htmlspecialchars($item['title']) ?>
                  </h6>
                  <small class="text-body-secondary d-block mb-2 text-truncate"><?= htmlspecialchars($item['genre']) ?></small>
                </div>
                <a href="<?= $baseUrl ?><?= $item['details_url'] ?>" class="btn btn-sm btn-outline-primary w-100 mt-2">
                  <i class="icon-base bx bx-show me-1"></i> View Series
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════════
         PANE 5: EPISODES ONLY
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-search-episodes" role="tabpanel">
      <div class="row g-3">
        <?php foreach ($results['episodes'] as $item): ?>
          <div class="col-12 col-md-6 col-lg-4 col-xl-3">
            <div class="card h-100 border shadow-sm overflow-hidden">
              <div class="position-relative">
                <?php if (!empty($item['thumb'])): ?>
                  <img src="<?= htmlspecialchars($item['thumb']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="w-100 object-fit-cover" style="height: 140px;" loading="lazy" />
                <?php else: ?>
                  <div class="bg-label-secondary w-100 d-flex align-items-center justify-content-center" style="height: 140px;">
                    <i class="icon-base bx bx-video fs-1 text-secondary"></i>
                  </div>
                <?php endif; ?>
                <span class="badge bg-danger position-absolute top-0 start-0 m-2 shadow-sm">
                  <?= htmlspecialchars($item['badge']) ?>
                </span>
                <?php if (!empty($item['duration'])): ?>
                  <span class="badge bg-dark bg-opacity-75 position-absolute bottom-0 end-0 m-2">
                    <?= htmlspecialchars($item['duration']) ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="card-body p-3 d-flex flex-column justify-content-between">
                <div>
                  <small class="text-primary fw-semibold d-block text-truncate"><?= htmlspecialchars($item['series_title']) ?></small>
                  <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                    <?= htmlspecialchars($item['title']) ?>
                  </h6>
                  <?php if (!empty($item['plot'])): ?>
                    <p class="text-body-secondary small mb-2 line-clamp-2" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                      <?= htmlspecialchars($item['plot']) ?>
                    </p>
                  <?php endif; ?>
                </div>
                <div class="d-flex gap-2 mt-2">
                  <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-primary w-100">
                    <i class="icon-base bx bx-play me-1"></i> Play Episode
                  </a>
                  <a href="<?= $baseUrl ?><?= $item['series_url'] ?>" class="btn btn-sm btn-icon btn-outline-secondary" title="View Series Page">
                    <i class="icon-base bx bx-layer"></i>
                  </a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═════════════════════════════════════════════════════════════════
         PANE 6: RADIO ONLY
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-search-radio" role="tabpanel">
      <div class="row g-3">
        <?php foreach ($results['radio'] as $item): ?>
          <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            <div class="card h-100 border shadow-sm">
              <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="avatar avatar-lg bg-label-warning rounded-circle d-flex align-items-center justify-content-center flex-shrink-0">
                  <?php if (!empty($item['icon'])): ?>
                    <img src="<?= htmlspecialchars($item['icon']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="rounded-circle w-100 h-100 object-fit-contain" loading="lazy" />
                  <?php else: ?>
                    <i class="icon-base bx bx-radio fs-2 text-warning"></i>
                  <?php endif; ?>
                </div>
                <div class="overflow-hidden flex-grow-1">
                  <h6 class="mb-1 fw-bold text-truncate" title="<?= htmlspecialchars($item['title']) ?>">
                    <?= htmlspecialchars($item['title']) ?>
                  </h6>
                  <span class="badge bg-label-secondary small"><?= htmlspecialchars($item['category']) ?></span>
                </div>
                <a href="<?= $baseUrl ?><?= $item['play_url'] ?>" class="btn btn-sm btn-icon btn-primary flex-shrink-0" title="Tune In">
                  <i class="icon-base bx bx-play"></i>
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

<?php endif; ?>
