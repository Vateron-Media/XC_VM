<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '/';
$assetsPath = $baseUrl . 'assets/';

$title = $series['title'] ?? 'TV Series Details';
$year = !empty($series['year']) ? (int)$series['year'] : null;
$rating = !empty($series['rating']) ? (float)$series['rating'] : 0;
$ratingFormatted = $rating > 0 ? number_format($rating, 1) : null;
$plot = !empty($series['plot']) ? $series['plot'] : 'No synopsis available for this series.';
$director = !empty($series['director']) ? $series['director'] : null;
$genre = !empty($series['genre']) ? $series['genre'] : (!empty($categoryNames) ? implode(', ', $categoryNames) : null);
$releaseDate = !empty($series['release_date']) ? $series['release_date'] : ($year ? (string)$year : null);
$episodeRuntime = !empty($series['episode_run_time']) ? (int)$series['episode_run_time'] : null;
$tmdbId = !empty($series['tmdb_id']) ? $series['tmdb_id'] : null;
$seasonsCount = count($seasons ?? []);

// Hero backdrop fallback
$heroBackdrop = $backdropUrl ?: $posterUrl;
$initialSeason = !empty($seasons[0]['season_number']) ? (int)$seasons[0]['season_number'] : 1;
?>

<!-- ─── Breadcrumb Navigation ──────────────────────────────────────── -->
<nav aria-label="breadcrumb" class="mb-4">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item">
      <a href="<?= $baseUrl ?>index" class="d-flex align-items-center gap-1 text-secondary">
        <i class="icon-base bx bx-home fs-6"></i>
        <span>Dashboard</span>
      </a>
    </li>
    <li class="breadcrumb-item">
      <a href="<?= $baseUrl ?>series" class="d-flex align-items-center gap-1 text-secondary">
        <i class="icon-base bx bx-movie-play fs-6"></i>
        <span>TV Series</span>
      </a>
    </li>
    <li class="breadcrumb-item active text-truncate" aria-current="page">
      <?= htmlspecialchars($title) ?>
    </li>
  </ol>
</nav>

<!-- ─── Cinematic Hero Card ────────────────────────────────────────── -->
<div class="card border-0 shadow-sm movie-hero-card mb-4">
  <?php if ($heroBackdrop): ?>
    <div class="movie-hero-backdrop-wrap">
      <img
        src="<?= htmlspecialchars($heroBackdrop) ?>"
        alt="<?= htmlspecialchars($title) ?>"
        class="movie-hero-backdrop-img"
        loading="eager" />
      <div class="movie-hero-backdrop-overlay"></div>
    </div>
  <?php endif; ?>

  <div class="card-body p-4 p-md-5 movie-hero-content">
    <div class="row g-4 g-lg-5 align-items-start">
      <!-- Poster Column -->
      <div class="col-12 col-md-5 col-lg-4 col-xl-3">
        <div class="movie-poster-wrap">
          <img
            src="<?= $posterUrl ?: $baseUrl . 'assets/img/pages/profile-banner.png' ?>"
            alt="<?= htmlspecialchars($title) ?>"
            class="movie-poster-img"
            onerror="this.src='<?= $baseUrl ?>assets/img/pages/profile-banner.png';" />

          <?php if ($ratingFormatted): ?>
            <div class="position-absolute top-0 start-0 m-3">
              <span class="movie-badge-glass px-2 py-1 rounded-pill d-inline-flex align-items-center gap-1 text-xs fw-bold">
                <i class="icon-base bx bxs-star text-warning"></i>
                <span><?= $ratingFormatted ?></span>
                <span class="text-white-50 text-xs">/10</span>
              </span>
            </div>
          <?php endif; ?>

          <div class="position-absolute top-0 end-0 m-3">
            <span class="badge bg-primary fw-bold text-uppercase px-2 py-1 shadow-sm">
              <?= $seasonsCount > 1 ? $seasonsCount . ' Seasons' : $seasonsCount . ' Season' ?>
            </span>
          </div>
        </div>

        <!-- Format & TMDB Badges -->
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
          <span class="badge bg-label-info fw-bold px-3 py-2 text-uppercase">
            <?= (int)($totalEpisodes ?? 0) ?> Episodes
          </span>
          <?php if ($tmdbId): ?>
            <a
              href="https://www.themoviedb.org/tv/<?= htmlspecialchars((string)$tmdbId) ?>"
              target="_blank"
              rel="noopener noreferrer"
              class="badge bg-label-primary fw-bold px-3 py-2 text-decoration-none">
              TMDB #<?= htmlspecialchars((string)$tmdbId) ?> <i class="icon-base bx bx-link-external ms-1"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Details Column -->
      <div class="col-12 col-md-7 col-lg-8 col-xl-9">
        <!-- Presenting Breadcrumb -->
        <?php if (!empty($categoryNames)): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-label-danger text-uppercase fw-bold">Now Presenting</span>
            <i class="icon-base bx bx-chevron-right text-secondary"></i>
            <span class="text-secondary small fw-medium"><?= htmlspecialchars(implode(' &bull; ', $categoryNames)) ?></span>
          </div>
        <?php endif; ?>

        <!-- Series Title -->
        <h1 class="display-6 fw-bolder mb-1 text-heading">
          <?= htmlspecialchars($title) ?>
        </h1>

        <!-- Metadata Pills Row -->
        <div class="d-flex flex-wrap gap-2 mb-4">
          <?php if ($releaseDate): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-calendar text-primary"></i>
              <span><?= htmlspecialchars($releaseDate) ?></span>
            </span>
          <?php endif; ?>

          <?php if ($ratingFormatted): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bxs-star text-warning"></i>
              <span><?= $ratingFormatted ?> / 10</span>
            </span>
          <?php endif; ?>

          <?php if ($genre): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-film text-primary"></i>
              <span><?= htmlspecialchars($genre) ?></span>
            </span>
          <?php endif; ?>

          <?php if ($episodeRuntime): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-time-five text-primary"></i>
              <span>~<?= $episodeRuntime ?> min / ep</span>
            </span>
          <?php endif; ?>

          <span class="movie-meta-pill">
            <i class="icon-base bx bx-layer text-primary"></i>
            <span><?= $seasonsCount ?> Seasons</span>
          </span>

          <span class="movie-meta-pill">
            <i class="icon-base bx bx-list-ul text-primary"></i>
            <span><?= (int)($totalEpisodes ?? 0) ?> Episodes</span>
          </span>
        </div>

        <!-- Synopsis / Plot -->
        <div class="mb-4">
          <h6 class="text-uppercase fw-bold text-secondary tracking-wider small mb-2">
            <i class="icon-base bx bx-align-left me-1"></i> Synopsis
          </h6>
          <p class="lead fs-6 text-body lh-base mb-0">
            <?= nl2br(htmlspecialchars($plot)) ?>
          </p>
        </div>

        <!-- Director Card -->
        <?php if ($director): ?>
          <div class="movie-director-card mb-4">
            <div class="avatar avatar-md bg-label-danger rounded-circle d-flex align-items-center justify-content-center">
              <i class="icon-base bx bx-video fs-4"></i>
            </div>
            <div>
              <span class="text-uppercase text-secondary fw-bold small d-block">Director</span>
              <span class="fw-bold text-heading fs-6"><?= htmlspecialchars($director) ?></span>
            </div>
          </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="d-flex flex-wrap align-items-center gap-3 mt-4">
          <!-- Play First Episode (Navigates to dedicated theater player on S01E01) -->
          <?php if (!empty($firstEpisode)): ?>
            <a
              href="<?= $baseUrl ?>player?type=series&id=<?= (int)$firstEpisode['stream_id'] ?>&series_id=<?= (int)$series['id'] ?>&s=<?= (int)$firstEpisode['season_num'] ?>&e=<?= (int)$firstEpisode['episode_num'] ?>"
              class="btn btn-primary btn-lg d-inline-flex align-items-center gap-2 shadow px-4 py-3"
              id="btn-play-first-episode">
              <i class="icon-base bx bx-play fs-3"></i>
              <span class="fw-bold text-uppercase">Play First Episode</span>
            </a>
          <?php endif; ?>

          <!-- Resume Watching Button (Dynamically shown if watched before) -->
          <a
            href="javascript:void(0);"
            class="btn btn-success btn-lg d-none align-items-center gap-2 shadow px-4 py-3"
            id="btn-series-resume">
            <i class="icon-base bx bx-play-circle fs-3 text-white"></i>
            <div class="d-flex flex-column text-start lh-1">
              <span class="x-small text-uppercase opacity-75">Resume Watching</span>
              <span class="fw-bold" id="resume-ep-label">S01E01</span>
            </div>
          </a>

          <!-- Trailer Button (Opens modal) -->
          <?php if (!empty($trailerId)): ?>
            <button
              type="button"
              class="btn btn-outline-danger btn-lg d-inline-flex align-items-center gap-2 px-4 py-3"
              data-bs-toggle="modal"
              data-bs-target="#trailerModal">
              <i class="icon-base bx bxl-youtube fs-4"></i>
              <span class="fw-bold text-uppercase">Trailer</span>
            </button>
          <?php endif; ?>

          <!-- Favorite Button -->
          <button
            type="button"
            class="btn btn-outline-warning btn-lg d-inline-flex align-items-center gap-2 px-4 py-3"
            id="btn-fav-series"
            data-id="<?= (int)$series['id'] ?>"
            data-title="<?= htmlspecialchars($title) ?>">
            <i class="icon-base bx bx-star fs-4" id="fav-icon"></i>
            <span class="fw-bold text-uppercase" id="fav-label">Favorite</span>
          </button>

          <!-- Back to Series -->
          <a
            href="<?= $baseUrl ?>series"
            class="btn btn-label-secondary btn-lg d-inline-flex align-items-center gap-2 px-4 py-3">
            <i class="icon-base bx bx-arrow-back fs-4"></i>
            <span class="fw-bold text-uppercase">Back</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Cast & Crew Section ────────────────────────────────────────── -->
<?php if (!empty($castList) || $director): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
      <i class="icon-base bx bx-group text-primary fs-5"></i>
      <h6 class="card-title mb-0 fw-bold text-uppercase small">Cast & Crew</h6>
    </div>
    <div class="card-body p-4 pt-2">
      <div class="movie-cast-scroll">
        <?php if ($director): ?>
          <div class="movie-cast-card">
            <div class="movie-cast-avatar mx-auto mb-2 bg-label-danger">
              <i class="icon-base bx bx-video fs-3 text-danger"></i>
            </div>
            <div class="fw-bold text-truncate text-heading small" title="<?= htmlspecialchars($director) ?>">
              <?= htmlspecialchars($director) ?>
            </div>
            <span class="badge bg-label-danger text-uppercase x-small">Director</span>
          </div>
        <?php endif; ?>

        <?php foreach ($castList as $actorName): ?>
          <div class="movie-cast-card">
            <div class="movie-cast-avatar mx-auto mb-2 bg-label-secondary">
              <i class="icon-base bx bx-user fs-3 text-secondary"></i>
            </div>
            <div class="fw-semibold text-truncate text-heading small" title="<?= htmlspecialchars($actorName) ?>">
              <?= htmlspecialchars($actorName) ?>
            </div>
            <span class="text-secondary text-uppercase x-small d-block">Actor</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ─── Backdrop Gallery Section ───────────────────────────────────── -->
<?php if (count($backdrops) > 1): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
      <i class="icon-base bx bx-images text-primary fs-5"></i>
      <h6 class="card-title mb-0 fw-bold text-uppercase small">Backdrop Gallery</h6>
    </div>
    <div class="card-body p-4 pt-2">
      <div class="movie-gallery-scroll">
        <?php foreach ($backdrops as $index => $bdImg): ?>
          <a
            href="<?= htmlspecialchars($bdImg) ?>"
            target="_blank"
            rel="noopener noreferrer"
            class="movie-gallery-item"
            title="View Full Backdrop <?= $index + 1 ?>">
            <img
              src="<?= htmlspecialchars($bdImg) ?>"
              alt="Backdrop <?= $index + 1 ?>"
              loading="lazy"
              onerror="this.parentElement.remove();" />
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ─── Seasons Selection Cards ────────────────────────────────────── -->
<?php if (!empty($seasons)): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <i class="icon-base bx bx-layer text-primary fs-5"></i>
        <h6 class="card-title mb-0 fw-bold text-uppercase small">Select Season</h6>
      </div>
      <span class="badge bg-label-primary rounded-pill"><?= count($seasons) ?> Seasons Available</span>
    </div>
    <div class="card-body p-4 pt-2">
      <div class="row g-3">
        <?php foreach ($seasons as $sIdx => $season): ?>
          <?php
            $sNum = (int)$season['season_number'];
            $isActive = $sNum === $initialSeason;
            $seasonCover = !empty($season['cover']) ? $season['cover'] : $posterUrl;
            $seasonName = !empty($season['name']) ? $season['name'] : ('Season ' . $sNum);
            $epCount = (int)($season['episode_count'] ?? (isset($episodesMap[$sNum]) ? count($episodesMap[$sNum]) : 0));
          ?>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div
              class="series-season-card h-100 <?= $isActive ? 'series-season-active' : '' ?>"
              data-season-btn="<?= $sNum ?>"
              data-season-title="<?= htmlspecialchars($seasonName) ?>"
              role="button"
              tabindex="0">
              <div class="series-season-thumb">
                <img
                  src="<?= htmlspecialchars($seasonCover) ?>"
                  alt="<?= htmlspecialchars($seasonName) ?>"
                  loading="lazy"
                  onerror="this.src='<?= $posterUrl ?: $baseUrl . 'assets/img/pages/profile-banner.png' ?>';" />
                <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75">
                  <?= $epCount ?> Eps
                </span>
              </div>
              <div class="p-2 text-center">
                <h6 class="fw-bold mb-0 text-truncate small text-heading"><?= htmlspecialchars($seasonName) ?></h6>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ─── Episodes Grid Section ──────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <i class="icon-base bx bx-movie-play text-primary fs-5"></i>
      <h6 class="card-title mb-0 fw-bold text-uppercase small" id="episodes-header-title">
        Episodes &mdash; Season <?= $initialSeason ?>
      </h6>
    </div>
  </div>
  <div class="card-body p-4 pt-2">
    <?php if (empty($episodesMap)): ?>
      <div class="py-5 text-center">
        <i class="icon-base bx bx-info-circle text-secondary fs-1 mb-2"></i>
        <h6 class="fw-bold text-heading">No Episodes Available</h6>
        <p class="text-secondary small">Episodes for this series have not been released yet.</p>
      </div>
    <?php else: ?>
      <?php foreach ($episodesMap as $sNum => $episodes): ?>
        <div
          class="row g-4 season-episodes-container <?= (int)$sNum === $initialSeason ? '' : 'd-none' ?>"
          id="season-episodes-<?= (int)$sNum ?>">
          <?php foreach ($episodes as $ep): ?>
            <?php
              $epStreamId = (int)$ep['stream_id'];
              $epNum = (int)$ep['episode_num'];
              $epTitle = $ep['title'] ?: ('Episode ' . $epNum);
              $epWatchUrl = $baseUrl . 'player?type=series&id=' . $epStreamId . '&series_id=' . (int)$series['id'] . '&s=' . (int)$sNum . '&e=' . $epNum;
            ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
              <div
                class="series-ep-card h-100 p-2 d-flex flex-column"
                onclick="window.location.href='<?= $epWatchUrl ?>'">
                <!-- Thumbnail with Play Overlay -->
                <div class="series-ep-thumb mb-2">
                  <img
                    src="<?= htmlspecialchars($ep['cover']) ?>"
                    alt="<?= htmlspecialchars($epTitle) ?>"
                    loading="lazy"
                    onerror="this.src='<?= $posterUrl ?: $baseUrl . 'assets/img/pages/profile-banner.png' ?>';" />

                  <div class="series-ep-play-overlay">
                    <div class="series-ep-play-btn">
                      <i class="icon-base bx bx-play fs-3"></i>
                    </div>
                  </div>

                  <?php if (!empty($ep['duration'])): ?>
                    <span class="position-absolute bottom-0 end-0 m-2 badge bg-dark bg-opacity-75">
                      <?= htmlspecialchars($ep['duration']) ?>
                    </span>
                  <?php endif; ?>

                  <?php if (!empty($ep['qualityBadge'])): ?>
                    <span class="position-absolute top-0 start-0 m-2 badge bg-<?= htmlspecialchars($ep['qualityColor']) ?> text-uppercase">
                      <?= htmlspecialchars($ep['qualityBadge']) ?>
                    </span>
                  <?php endif; ?>

                  <!-- Progress Bar for Resume Tracking -->
                  <div class="series-ep-progress d-none" id="progress-bar-<?= $epStreamId ?>">
                    <div class="series-ep-progress-bar" id="progress-fill-<?= $epStreamId ?>"></div>
                  </div>
                </div>

                <!-- Episode Info -->
                <div class="d-flex flex-column flex-grow-1 justify-content-between px-1">
                  <div>
                    <span class="badge bg-label-danger text-uppercase x-small fw-bold mb-1">
                      Episode <?= $epNum ?>
                    </span>
                    <h6 class="fw-bold text-heading text-truncate mb-1 small" title="<?= htmlspecialchars($epTitle) ?>">
                      <?= htmlspecialchars($epTitle) ?>
                    </h6>
                    <?php if (!empty($ep['air_date'])): ?>
                      <div class="text-secondary x-small d-flex align-items-center gap-1 mb-2">
                        <i class="icon-base bx bx-calendar"></i>
                        <span><?= htmlspecialchars($ep['air_date']) ?></span>
                      </div>
                    <?php endif; ?>
                  </div>

                  <a
                    href="<?= $epWatchUrl ?>"
                    class="btn btn-xs btn-label-primary w-100 d-flex align-items-center justify-content-center gap-1 mt-auto">
                    <i class="icon-base bx bx-play"></i>
                    <span>Watch Episode</span>
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ─── Bootstrap 5 YouTube Trailer Modal ──────────────────────────── -->
<?php if (!empty($trailerId)): ?>
  <div class="modal fade" id="trailerModal" tabindex="-1" aria-labelledby="trailerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
      <div class="modal-content border-0 shadow-lg bg-black">
        <div class="modal-header border-0 py-2 px-3">
          <h6 class="modal-title text-white d-flex align-items-center gap-2" id="trailerModalLabel">
            <i class="icon-base bx bxl-youtube text-danger fs-4"></i>
            <span><?= htmlspecialchars($title) ?> &mdash; Official Trailer</span>
          </h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-0">
          <div class="trailer-iframe-wrap">
            <iframe
              id="trailer-iframe"
              src=""
              data-src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($trailerId) ?>?autoplay=1&rel=0&modestbranding=1"
              title="<?= htmlspecialchars($title) ?> Trailer"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen
              class="w-100 h-100 border-0">
            </iframe>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ─── Client-side Favorites, Seasons Switching & Resume Logic ────── -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const seriesId = <?= (int)$series['id'] ?>;
    const favKey = 'xc_player_v2_favs_series';
    const favBtn = document.getElementById('btn-fav-series');
    const favIcon = document.getElementById('fav-icon');
    const favLabel = document.getElementById('fav-label');
    const resumeBtn = document.getElementById('btn-series-resume');
    const resumeEpLabel = document.getElementById('resume-ep-label');
    const epHeaderTitle = document.getElementById('episodes-header-title');

    // 1. Favorites Management
    function getFavorites() {
      try {
        const stored = localStorage.getItem(favKey);
        return stored ? JSON.parse(stored) : [];
      } catch (e) {
        return [];
      }
    }

    function saveFavorites(favs) {
      try {
        localStorage.setItem(favKey, JSON.stringify(favs));
      } catch (e) {}
    }

    function updateFavButton() {
      const favs = getFavorites();
      const isFav = favs.includes(seriesId);
      if (isFav) {
        favBtn.classList.remove('btn-outline-warning');
        favBtn.classList.add('btn-warning');
        favIcon.classList.remove('bx-star');
        favIcon.classList.add('bxs-star');
        favLabel.textContent = 'Favorited';
      } else {
        favBtn.classList.remove('btn-warning');
        favBtn.classList.add('btn-outline-warning');
        favIcon.classList.remove('bxs-star');
        favIcon.classList.add('bx-star');
        favLabel.textContent = 'Favorite';
      }
    }

    if (favBtn) {
      updateFavButton();
      favBtn.addEventListener('click', function () {
        let favs = getFavorites();
        if (favs.includes(seriesId)) {
          favs = favs.filter(id => id !== seriesId);
        } else {
          favs.push(seriesId);
        }
        saveFavorites(favs);
        updateFavButton();
      });
    }

    // 2. Interactive Seasons Switching
    const seasonCards = document.querySelectorAll('[data-season-btn]');
    seasonCards.forEach((card) => {
      card.addEventListener('click', function () {
        const targetSeason = this.getAttribute('data-season-btn');
        const seasonTitle = this.getAttribute('data-season-title') || ('Season ' + targetSeason);

        seasonCards.forEach(c => c.classList.remove('series-season-active'));
        this.classList.add('series-season-active');

        document.querySelectorAll('.season-episodes-container').forEach(cont => {
          cont.classList.add('d-none');
        });

        const targetContainer = document.getElementById('season-episodes-' + targetSeason);
        if (targetContainer) {
          targetContainer.classList.remove('d-none');
        }

        if (epHeaderTitle) {
          epHeaderTitle.textContent = 'Episodes \u2014 ' + seasonTitle;
        }
      });
    });

    // 3. Resume Watching Button
    try {
      const continueList = JSON.parse(localStorage.getItem('xc_player_v2_continue_series') || '[]');
      const continueItem = continueList.find(x => Number(x.seriesId) === seriesId);
      if (continueItem && resumeBtn) {
        resumeBtn.classList.remove('d-none');
        resumeBtn.classList.add('d-inline-flex');

        // Look for corresponding episode
        const resumeUrl = '<?= $baseUrl ?>player?type=series&id=' + continueItem.streamId + '&series_id=' + seriesId;
        resumeBtn.href = resumeUrl;

        // Update fill bar on the episode card
        const progressFill = document.getElementById('progress-fill-' + continueItem.streamId);
        const progressBar = document.getElementById('progress-bar-' + continueItem.streamId);
        if (progressFill && progressBar) {
          progressBar.classList.remove('d-none');
          progressFill.style.width = continueItem.progress + '%';
        }
      }
    } catch (e) {}

    // 4. Trailer Modal Lifecycle
    const trailerModal = document.getElementById('trailerModal');
    const trailerIframe = document.getElementById('trailer-iframe');
    if (trailerModal && trailerIframe) {
      trailerModal.addEventListener('show.bs.modal', function () {
        trailerIframe.src = trailerIframe.getAttribute('data-src');
      });
      trailerModal.addEventListener('hidden.bs.modal', function () {
        trailerIframe.src = '';
      });
    }
  });
</script>
