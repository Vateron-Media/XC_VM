<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '/';
$assetsPath = $baseUrl . 'assets/';

$isSeries = isset($type) && $type === 'series';
$movie = $movie ?? ($stream ?? []);
$displayTitle = $playbackTitle ?? ($movie['stream_display_name'] ?? 'Playback');
$year = !empty($movie['year']) ? (int)$movie['year'] : (!empty($series['year']) ? (int)$series['year'] : null);
$streamId = (int)($movie['id'] ?? 0);
$backLink = $backUrl ?? ($isSeries ? $baseUrl . 'series' : $baseUrl . 'movies');
$posterUrl = $movie['stream_icon'] ?? ($series['cover'] ?? '');
$plot = $props['plot'] ?? ($props['description'] ?? ($movie['plot'] ?? ($series['plot'] ?? 'No synopsis available.')));
$cast = $props['cast'] ?? ($series['cast'] ?? '');
$director = $props['director'] ?? ($series['director'] ?? '');
$genre = $props['genre'] ?? ($series['genre'] ?? '');
$rating = $props['rating'] ?? ($movie['rating'] ?? ($series['rating'] ?? ''));
$duration = $props['duration'] ?? ($movie['duration'] ?? '');

$placeholderPoster = $assetsPath . 'img/placeholder-poster.png';
?>

<!-- Cinema & Series Player CSS -->
<link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/videojs/video-js.min.css" />
<link rel="stylesheet" href="<?= $assetsPath ?>css/cinema-player.css" />

<div class="row g-4 cinema-app-wrapper">
  <div class="col-12">
    <div class="card overflow-hidden shadow-sm border-0">
      <div class="row g-0">

        <!-- ============================================================= -->
        <!-- LEFT COLUMN: EPISODES / RELATED MOVIES SIDEBAR (col-lg-3)    -->
        <!-- ============================================================= -->
        <div class="col-12 col-lg-3 border-end cinema-sidebar-pane" id="cinema-sidebar">
          <!-- Sidebar Header & Back Link -->
          <div class="p-3 border-bottom d-flex align-items-center justify-content-between gap-2">
            <a href="<?= htmlspecialchars($backLink) ?>" class="btn btn-sm btn-icon btn-label-secondary rounded" title="Return to details">
              <i class="icon-base bx bx-arrow-back"></i>
            </a>
            <div class="min-w-0 flex-grow-1">
              <h6 class="mb-0 fw-bold text-truncate">
                <?php if ($isSeries): ?>
                  <i class="icon-base bx bx-list-ul me-1 text-primary"></i>Episodes
                <?php else: ?>
                  <i class="icon-base bx bx-film me-1 text-primary"></i>Related Movies
                <?php endif; ?>
              </h6>
            </div>
            <span class="badge bg-label-primary rounded-pill px-2 py-1" id="cinema-items-count-badge">
              <?php if ($isSeries): ?>
                <?= count($allEpisodes ?? []) ?> Ep
              <?php else: ?>
                <?= count($relatedMovies ?? []) ?> Movies
              <?php endif; ?>
            </span>
          </div>

          <!-- Season Selector (If series with multiple seasons) -->
          <?php if ($isSeries && !empty($seasons) && count($seasons) > 1): ?>
            <div class="px-3 pt-3">
              <select class="form-select form-select-sm border-0 bg-body-secondary fw-medium" id="cinema-season-select">
                <option value="all">All Seasons (<?= count($allEpisodes ?? []) ?>)</option>
                <?php foreach ($seasons as $sNum): ?>
                  <option value="<?= (int)$sNum ?>" <?= (int)$sNum === (int)$seasonNum ? 'selected' : '' ?>>
                    Season <?= (int)$sNum ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <!-- Filter Search Input -->
          <div class="p-3 border-bottom">
            <div class="input-group input-group-sm input-group-merge">
              <span class="input-group-text border-0 bg-body-secondary"><i class="icon-base bx bx-search text-body-secondary"></i></span>
              <input
                type="text"
                id="cinema-item-search"
                class="form-control border-0 bg-body-secondary"
                placeholder="Search <?= $isSeries ? 'episodes' : 'movies' ?>..." />
            </div>
          </div>

          <!-- Scrollable Items List -->
          <div class="p-2 cinema-items-scroll flex-grow-1" id="cinema-items-list">
            <?php if ($isSeries && !empty($allEpisodes)): ?>
              <?php foreach ($allEpisodes as $ep): ?>
                <?php
                  $epStreamId = (int)($ep['stream_id'] ?? 0);
                  $epSeason = (int)($ep['season_num'] ?? 1);
                  $epNumber = (int)($ep['episode_num'] ?? 1);
                  $isActive = ($epStreamId === $streamId);
                  $epTitle = $ep['title'] ?? ('Episode ' . $epNumber);
                  $epIcon = !empty($ep['stream_icon']) ? $ep['stream_icon'] : $posterUrl;
                  $sTag = 'S' . sprintf('%02d', $epSeason) . 'E' . sprintf('%02d', $epNumber);
                ?>
                <a
                  href="javascript:void(0);"
                  class="list-group-item list-group-item-action cinema-item-card p-2 mb-1 d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?>"
                  data-stream-id="<?= $epStreamId ?>"
                  data-season-num="<?= $epSeason ?>"
                  data-episode-num="<?= $epNumber ?>"
                  data-title="<?= htmlspecialchars($epTitle . ' ' . $sTag) ?>">
                  <div class="cinema-item-thumb-wrap">
                    <img
                      src="<?= htmlspecialchars($epIcon ?: $placeholderPoster) ?>"
                      alt="<?= htmlspecialchars($epTitle) ?>"
                      class="cinema-item-thumb"
                      loading="lazy"
                      onerror="this.onerror=null;this.src='<?= $placeholderPoster ?>';" />
                    <?php if (!empty($ep['duration_secs'])): ?>
                      <span class="cinema-thumb-duration"><?= round($ep['duration_secs'] / 60) ?>m</span>
                    <?php endif; ?>
                  </div>
                  <div class="min-w-0 flex-grow-1">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                      <span class="badge bg-label-primary px-1 py-0 rounded" style="font-size: 0.68rem;"><?= $sTag ?></span>
                      <div class="item-status-indicator d-flex align-items-center">
                        <?php if ($isActive): ?>
                          <div class="playing-bars me-1"><span></span><span></span><span></span></div>
                          <span class="badge bg-primary px-1 py-0" style="font-size: 0.65rem;">PLAYING</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="text-truncate small fw-medium text-heading" title="<?= htmlspecialchars($epTitle) ?>">
                      <?= htmlspecialchars($epTitle) ?>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>

            <?php elseif (!$isSeries && !empty($relatedMovies)): ?>
              <?php foreach ($relatedMovies as $rm): ?>
                <?php
                  $rmId = (int)$rm['id'];
                  $isActive = ($rmId === $streamId);
                  $rmTitle = $rm['stream_display_name'] ?? 'Movie';
                  $rmIcon = $rm['stream_icon'] ?? '';
                ?>
                <a
                  href="javascript:void(0);"
                  class="list-group-item list-group-item-action cinema-item-card p-2 mb-1 d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?>"
                  data-stream-id="<?= $rmId ?>"
                  data-title="<?= htmlspecialchars($rmTitle) ?>">
                  <div class="cinema-item-thumb-wrap">
                    <img
                      src="<?= htmlspecialchars($rmIcon ?: $placeholderPoster) ?>"
                      alt="<?= htmlspecialchars($rmTitle) ?>"
                      class="cinema-item-thumb"
                      loading="lazy"
                      onerror="this.onerror=null;this.src='<?= $placeholderPoster ?>';" />
                    <?php if (!empty($rm['duration'])): ?>
                      <span class="cinema-thumb-duration"><?= htmlspecialchars($rm['duration']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="min-w-0 flex-grow-1">
                    <div class="text-truncate small fw-medium text-heading mb-1" title="<?= htmlspecialchars($rmTitle) ?>">
                      <?= htmlspecialchars($rmTitle) ?>
                    </div>
                    <div class="d-flex align-items-center gap-1 small text-body-secondary">
                      <?php if (!empty($rm['year'])): ?>
                        <span><?= htmlspecialchars($rm['year']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($rm['rating'])): ?>
                        <span>&bull;</span>
                        <span class="text-warning"><i class="icon-base bx bxs-star font-size-10"></i> <?= htmlspecialchars($rm['rating']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="text-center p-4 text-body-secondary small">
                <i class="icon-base bx bx-info-circle fs-3 mb-2 d-block"></i>
                No items found in this section.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ============================================================= -->
        <!-- RIGHT COLUMN: MAIN VIDEO STAGE, TOPBAR & DETAILS (col-lg-9)   -->
        <!-- ============================================================= -->
        <div class="col-12 col-lg-9 d-flex flex-column flex-grow-1">

          <!-- ─── Cinema Topbar ────────────────────────────────────────── -->
          <div class="p-3 border-bottom bg-body-tertiary d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 min-w-0">
              <img
                id="cinema-poster-img"
                src="<?= htmlspecialchars($posterUrl ?: $placeholderPoster) ?>"
                alt="Poster"
                class="cinema-poster-sm d-none d-sm-block flex-shrink-0"
                onerror="this.onerror=null;this.src='<?= $placeholderPoster ?>';" />
              <div class="min-w-0">
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                  <?php if (!empty($categoryName)): ?>
                    <span class="badge bg-label-primary rounded-pill px-2 py-0" id="cinema-cat-badge">
                      <?= htmlspecialchars($categoryName) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($isSeries && isset($seasonNum) && isset($episodeNum)): ?>
                    <span class="badge bg-primary rounded-pill px-2 py-0" id="cinema-se-badge">
                      S<?= sprintf('%02d', (int)$seasonNum) ?>E<?= sprintf('%02d', (int)$episodeNum) ?>
                    </span>
                  <?php endif; ?>
                  <span class="badge bg-label-<?= htmlspecialchars($qualityColor ?? 'primary') ?> rounded-pill px-2 py-0" id="cinema-quality-badge">
                    <?= htmlspecialchars($qualityBadge ?? 'HD') ?>
                  </span>
                  <?php if ($year): ?>
                    <small class="text-body-secondary" id="cinema-year-text"><?= $year ?></small>
                  <?php endif; ?>
                </div>
                <h5 class="mb-0 fw-bold text-truncate text-heading" id="cinema-player-title">
                  <?= htmlspecialchars($displayTitle) ?>
                </h5>
              </div>
            </div>

            <!-- Right Controls -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <?php if ($isSeries): ?>
                <button
                  type="button"
                  class="btn btn-sm btn-label-secondary d-flex align-items-center gap-1 <?= empty($prevEp) ? 'd-none' : '' ?>"
                  id="btn-cinema-prev"
                  data-target-stream-id="<?= (int)($prevEp['stream_id'] ?? 0) ?>"
                  title="Previous Episode (P)">
                  <i class="icon-base bx bx-skip-previous"></i>
                  <span class="d-none d-md-inline">Prev</span>
                </button>

                <button
                  type="button"
                  class="btn btn-sm btn-primary d-flex align-items-center gap-1 shadow-sm <?= empty($nextEp) ? 'd-none' : '' ?>"
                  id="btn-cinema-next"
                  data-target-stream-id="<?= (int)($nextEp['stream_id'] ?? 0) ?>"
                  title="Next Episode (N)">
                  <span class="d-none d-md-inline">Next</span>
                  <i class="icon-base bx bx-skip-next"></i>
                </button>
              <?php endif; ?>

              <button
                type="button"
                class="btn btn-sm btn-label-secondary d-flex align-items-center gap-1"
                id="btn-cinema-fav"
                title="Favorite">
                <i class="icon-base bx bx-star"></i>
                <span class="d-none d-sm-inline">Favorite</span>
              </button>

              <button
                type="button"
                class="btn btn-sm btn-icon btn-label-secondary rounded"
                id="btn-cinema-aspect"
                title="Cycle Aspect Ratio">
                <i class="icon-base bx bx-expand"></i>
              </button>

              <button
                type="button"
                class="btn btn-sm btn-icon btn-label-secondary rounded"
                id="btn-cinema-fullscreen"
                title="Fullscreen (F)">
                <i class="icon-base bx bx-fullscreen"></i>
              </button>

              <a href="<?= $baseUrl ?><?= $isSeries ? 'series' : 'movies' ?>" class="btn btn-sm btn-outline-secondary d-none d-sm-inline-flex">
                <i class="icon-base <?= $isSeries ? 'bx bx-movie-play' : 'bx bx-film' ?> me-1"></i><?= $isSeries ? 'Catalog' : 'Catalog' ?>
              </a>
            </div>
          </div>

          <!-- ─── Video.js Studio Stage (Admin Player Engine) ─────────── -->
          <div class="cinema-video-stage position-relative">
            <video
              id="cinema-video"
              class="video-js vjs-big-play-centered"
              controls
              preload="none"
              playsinline>
            </video>

            <!-- Cinema Stream Status / Auto-Recovery Overlay -->
            <div id="cinema-stream-status" class="position-absolute top-0 start-0 w-100 h-100 d-none flex-column align-items-center justify-content-center text-center p-4 bg-black bg-opacity-75" style="z-index: 10;">
              <div id="cinema-status-spinner" class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
              </div>
              <div id="cinema-status-icon" class="avatar avatar-xl rounded-circle bg-label-danger mb-3 d-none">
                <i class="icon-base bx bx-wifi-off fs-1"></i>
              </div>
              <h5 class="text-white mb-2" id="cinema-status-title">Reconnecting Video...</h5>
              <p class="text-white-50 mb-3 small" id="cinema-status-desc" style="max-width: 420px;">The external server temporarily closed the connection. Restoring playback automatically...</p>
              <button type="button" class="btn btn-primary d-none shadow-sm" id="btn-cinema-retry-stream">
                <i class="icon-base bx bx-refresh me-1"></i> Retry Connection
              </button>
            </div>
          </div>

          <!-- ─── Bottom Details & Metadata ──────────────────────────── -->
          <div class="p-4 border-top bg-body flex-grow-1">
            <div class="row g-3">
              <div class="col-12 col-md-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span class="badge bg-label-primary px-2 py-1">OVERVIEW</span>
                  <?php if (!empty($rating)): ?>
                    <span class="badge bg-label-warning px-2 py-1">
                      <i class="icon-base bx bxs-star me-1"></i><?= htmlspecialchars($rating) ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($duration)): ?>
                    <span class="badge bg-label-secondary px-2 py-1">
                      <i class="icon-base bx bx-time-five me-1"></i><?= htmlspecialchars($duration) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <p class="text-body-secondary mb-0" id="cinema-plot" style="line-height: 1.6;">
                  <?= nl2br(htmlspecialchars($plot)) ?>
                </p>
              </div>

              <div class="col-12 col-md-4 border-start-md">
                <?php if (!empty($genre)): ?>
                  <div class="mb-2">
                    <small class="text-uppercase text-body-secondary fw-bold d-block mb-1">Genre</small>
                    <span class="text-heading small"><?= htmlspecialchars($genre) ?></span>
                  </div>
                <?php endif; ?>

                <?php if (!empty($director)): ?>
                  <div class="mb-2">
                    <small class="text-uppercase text-body-secondary fw-bold d-block mb-1">Director</small>
                    <span class="text-heading small"><?= htmlspecialchars($director) ?></span>
                  </div>
                <?php endif; ?>

                <?php if (!empty($cast)): ?>
                  <div class="mb-2">
                    <small class="text-uppercase text-body-secondary fw-bold d-block mb-1">Cast</small>
                    <span class="text-heading small text-truncate d-block" title="<?= htmlspecialchars($cast) ?>"><?= htmlspecialchars($cast) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- ─── Keyboard Controls Guide ────────────────────────────── -->
          <div class="px-4 py-2 border-top bg-body-tertiary">
            <div class="d-flex flex-wrap align-items-center gap-3 text-secondary small">
              <div class="fw-bold text-heading text-uppercase">
                <i class="icon-base bx bx-command me-1"></i>Shortcuts:
              </div>
              <div><kbd class="bg-body text-body border px-2 py-0">Space</kbd> Play/Pause</div>
              <div><kbd class="bg-body text-body border px-2 py-0">&larr; / &rarr;</kbd> Seek &plusmn;10s</div>
              <div><kbd class="bg-body text-body border px-2 py-0">&uarr; / &darr;</kbd> Vol &plusmn;10%</div>
              <div><kbd class="bg-body text-body border px-2 py-0">F</kbd> Fullscreen</div>
              <div><kbd class="bg-body text-body border px-2 py-0">M</kbd> Mute</div>
              <?php if ($isSeries): ?>
                <div><kbd class="bg-body text-body border px-2 py-0">N</kbd> / <kbd class="bg-body text-body border px-2 py-0">P</kbd> Next/Prev Ep</div>
              <?php endif; ?>
              <div><kbd class="bg-body text-body border px-2 py-0">Esc</kbd> Back</div>
            </div>
          </div>

        </div>

      </div>
    </div>
  </div>
</div>

<!-- Video.js & Cinema Runtime Scripts -->
<script src="<?= $assetsPath ?>vendor/libs/videojs/video.min.js"></script>
<script src="<?= $assetsPath ?>js/player-cinema.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.CinemaPlayerApp) {
      window.CinemaPlayerApp.start({
        type: '<?= $isSeries ? 'series' : 'movie' ?>',
        streamId: <?= (int)$streamId ?>,
        seriesId: <?= (int)($seriesId ?? 0) ?>,
        seasonNum: <?= (int)($seasonNum ?? 1) ?>,
        episodeNum: <?= (int)($episodeNum ?? 1) ?>,
        streamUrl: '<?= addslashes($streamUrl) ?>',
        baseUrl: '<?= $baseUrl ?>',
        backUrl: '<?= addslashes($backLink) ?>',
        items: <?= json_encode($isSeries ? ($allEpisodes ?? []) : ($relatedMovies ?? [])) ?>
      });
    }
  });
</script>
