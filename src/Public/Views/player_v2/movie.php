<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '/';

$title = $movie['stream_display_name'] ?? 'Movie Details';
$originalTitle = !empty($props['o_name']) && $props['o_name'] !== $title ? $props['o_name'] : null;
$year = !empty($movie['year']) ? (int)$movie['year'] : null;
$rating = !empty($props['rating']) ? (float)$props['rating'] : (!empty($movie['rating']) ? (float)$movie['rating'] : 0);
$ratingFormatted = $rating > 0 ? number_format($rating, 1) : null;
$durationSecs = !empty($props['duration_secs']) ? (int)$props['duration_secs'] : (!empty($props['duration']) ? (int)$props['duration'] : 0);
$durationMin = $durationSecs > 0 ? (int)($durationSecs / 60) : (!empty($props['runtime']) ? (int)$props['runtime'] : null);
$plot = !empty($props['plot']) ? $props['plot'] : (!empty($props['description']) ? $props['description'] : 'No synopsis available for this movie.');
$director = !empty($props['director']) ? $props['director'] : null;
$genre = !empty($props['genre']) ? $props['genre'] : (!empty($categoryNames) ? implode(', ', $categoryNames) : null);
$country = !empty($props['country']) ? $props['country'] : null;
$releaseDate = !empty($props['releasedate']) ? $props['releasedate'] : ($year ? (string)$year : null);
$age = !empty($props['age']) ? $props['age'] : null;
$status = !empty($props['status']) ? $props['status'] : null;
$tmdbId = !empty($movie['tmdb_id']) ? $movie['tmdb_id'] : (!empty($props['tmdb_id']) ? $props['tmdb_id'] : null);

// Audio specifications formatting
$audioCodec = !empty($audio['codec_name']) ? strtoupper($audio['codec_name']) : null;
$audioChannels = !empty($audio['channels']) ? (int)$audio['channels'] : null;
$audioChannelsLabel = null;
if ($audioChannels === 2) {
    $audioChannelsLabel = 'Stereo (2.0)';
} elseif ($audioChannels === 6) {
    $audioChannelsLabel = '5.1 Surround';
} elseif ($audioChannels === 8) {
    $audioChannelsLabel = '7.1 Surround';
} elseif ($audioChannels > 0) {
    $audioChannelsLabel = $audioChannels . ' Channels';
}
$audioSampleRate = !empty($audio['sample_rate']) ? (number_format((int)$audio['sample_rate'] / 1000, 1) . ' kHz') : null;
$audioBitrate = !empty($audio['bit_rate']) ? (round((int)$audio['bit_rate'] / 1000) . ' kbps') : null;
$audioLayout = !empty($audio['channel_layout']) ? ucfirst($audio['channel_layout']) : null;
$audioSampleFmt = !empty($audio['sample_fmt']) ? strtoupper($audio['sample_fmt']) : null;

// Video specifications formatting
$videoCodec = !empty($video['codec_name']) ? strtoupper($video['codec_name']) : null;
$videoResolution = (!empty($video['width']) && !empty($video['height'])) ? ($video['width'] . ' × ' . $video['height']) : null;
$videoFps = !empty($fps) ? ($fps . ' fps') : null;
$videoPixFmt = !empty($video['pix_fmt']) ? strtoupper($video['pix_fmt']) : null;
$videoColorSpace = !empty($video['color_space']) ? strtoupper($video['color_space']) : null;
$videoProfile = !empty($video['profile']) ? $video['profile'] : null;
$videoBitrate = !empty($props['bitrate']) ? ($props['bitrate'] . ' kbps') : (!empty($video['bit_rate']) ? (round((int)$video['bit_rate'] / 1000) . ' kbps') : null);

// Hero backdrop fallback
$heroBackdrop = $backdropUrl ?: $posterUrl;
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
      <a href="<?= $baseUrl ?>movies" class="d-flex align-items-center gap-1 text-secondary">
        <i class="icon-base bx bx-film fs-6"></i>
        <span>Movies</span>
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

          <?php if ($qualityBadge): ?>
            <div class="position-absolute top-0 end-0 m-3">
              <span class="badge bg-<?= $qualityColor ?> fw-bold text-uppercase px-2 py-1 shadow-sm">
                <?= htmlspecialchars($qualityBadge) ?>
              </span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Format & TMDB Badges -->
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
          <span class="badge bg-label-secondary fw-bold px-3 py-2 text-uppercase">
            .<?= htmlspecialchars($containerExtension) ?>
          </span>
          <?php if ($tmdbId): ?>
            <a
              href="https://www.themoviedb.org/movie/<?= htmlspecialchars((string)$tmdbId) ?>"
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

        <!-- Movie Title & Original Title -->
        <h1 class="display-6 fw-bolder mb-1 text-heading">
          <?= htmlspecialchars($title) ?>
        </h1>
        <?php if ($originalTitle): ?>
          <p class="text-secondary fst-italic fs-6 mb-3"><?= htmlspecialchars($originalTitle) ?></p>
        <?php else: ?>
          <div class="mb-3"></div>
        <?php endif; ?>

        <!-- Metadata Pills Row -->
        <div class="d-flex flex-wrap gap-2 mb-4">
          <?php if ($durationMin): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-time-five text-primary"></i>
              <span><?= $durationMin ?> min</span>
            </span>
          <?php endif; ?>

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

          <?php if ($country): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-globe text-primary"></i>
              <span><?= htmlspecialchars($country) ?></span>
            </span>
          <?php endif; ?>

          <?php if ($age): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-shield text-primary"></i>
              <span><?= htmlspecialchars($age) ?>+</span>
            </span>
          <?php endif; ?>

          <?php if ($status): ?>
            <span class="movie-meta-pill">
              <i class="icon-base bx bx-pulse text-primary"></i>
              <span><?= htmlspecialchars($status) ?></span>
            </span>
          <?php endif; ?>
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
          <!-- Play Now (Navigates to dedicated theater player) -->
          <a
            href="<?= $baseUrl ?>player?type=movie&id=<?= (int)$movie['id'] ?>"
            class="btn btn-primary btn-lg d-inline-flex align-items-center gap-2 shadow px-4 py-3">
            <i class="icon-base bx bx-play fs-3"></i>
            <span class="fw-bold text-uppercase">Play Now</span>
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
            id="btn-fav-movie"
            data-id="<?= (int)$movie['id'] ?>"
            data-title="<?= htmlspecialchars($title) ?>">
            <i class="icon-base bx bx-star fs-4" id="fav-icon"></i>
            <span class="fw-bold text-uppercase" id="fav-label">Favorite</span>
          </button>

          <!-- Back to Catalog -->
          <a
            href="<?= $baseUrl ?>movies"
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

<!-- ─── Backdrop Gallery (if multiple backdrops exist) ─────────────── -->
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

<!-- ─── Technical Specifications ───────────────────────────────────── -->
<?php if ($video || $audio): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
      <i class="icon-base bx bx-chip text-primary fs-5"></i>
      <h6 class="card-title mb-0 fw-bold text-uppercase small">Technical Specifications</h6>
    </div>
    <div class="card-body p-4 pt-2">
      <div class="row g-4">
        <!-- Video Stream Specs -->
        <?php if ($video): ?>
          <div class="col-12 col-lg-6">
            <div class="movie-spec-card h-100">
              <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                <i class="icon-base bx bx-tv text-primary fs-5"></i>
                <h6 class="mb-0 fw-bold text-uppercase small text-primary">Video Stream</h6>
              </div>
              <div class="row g-3">
                <?php if ($videoResolution): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Resolution</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($videoResolution) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($videoCodec): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Codec</span>
                    <span class="movie-info-cell-value text-uppercase"><?= htmlspecialchars($videoCodec) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($videoFps): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Frame Rate</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($videoFps) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($videoBitrate): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Bitrate</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($videoBitrate) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($videoPixFmt): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Pixel Format</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($videoPixFmt) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($videoColorSpace): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Color Space</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($videoColorSpace) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($videoProfile): ?>
                  <div class="col-12">
                    <span class="movie-info-cell-label d-block">Profile</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($videoProfile) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Audio Stream Specs -->
        <?php if ($audio): ?>
          <div class="col-12 col-lg-6">
            <div class="movie-spec-card h-100">
              <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                <i class="icon-base bx bx-volume-full text-success fs-5"></i>
                <h6 class="mb-0 fw-bold text-uppercase small text-success">Audio Stream</h6>
              </div>
              <div class="row g-3">
                <?php if ($audioCodec): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Codec</span>
                    <span class="movie-info-cell-value text-uppercase"><?= htmlspecialchars($audioCodec) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($audioChannelsLabel): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Channels</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($audioChannelsLabel) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($audioSampleRate): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Sample Rate</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($audioSampleRate) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($audioBitrate): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Bitrate</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($audioBitrate) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($audioLayout): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Channel Layout</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($audioLayout) ?></span>
                  </div>
                <?php endif; ?>

                <?php if ($audioSampleFmt): ?>
                  <div class="col-6">
                    <span class="movie-info-cell-label d-block">Sample Format</span>
                    <span class="movie-info-cell-value"><?= htmlspecialchars($audioSampleFmt) ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ─── Movie Information Grid ─────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
    <i class="icon-base bx bx-info-circle text-primary fs-5"></i>
    <h6 class="card-title mb-0 fw-bold text-uppercase small">Movie Information</h6>
  </div>
  <div class="card-body p-4 pt-2">
    <div class="row g-3">
      <?php if ($durationMin): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Runtime</span>
            <span class="movie-info-cell-value"><?= $durationMin ?> min</span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($releaseDate): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Release Date</span>
            <span class="movie-info-cell-value"><?= htmlspecialchars($releaseDate) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($genre): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Genre</span>
            <span class="movie-info-cell-value"><?= htmlspecialchars($genre) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($country): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Country</span>
            <span class="movie-info-cell-value"><?= htmlspecialchars($country) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($status): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Status</span>
            <span class="movie-info-cell-value"><?= htmlspecialchars($status) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($age): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Age Rating</span>
            <span class="movie-info-cell-value"><?= htmlspecialchars($age) ?>+</span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($tmdbId): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">TMDB ID</span>
            <a
              href="https://www.themoviedb.org/movie/<?= htmlspecialchars((string)$tmdbId) ?>"
              target="_blank"
              rel="noopener noreferrer"
              class="movie-info-cell-value text-primary text-decoration-none">
              #<?= htmlspecialchars((string)$tmdbId) ?> <i class="icon-base bx bx-link-external ms-1"></i>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-6 col-md-4 col-xl-3">
        <div class="movie-info-cell">
          <span class="movie-info-cell-label d-block">Stream ID</span>
          <span class="movie-info-cell-value">#<?= (int)$movie['id'] ?></span>
        </div>
      </div>

      <?php if (!empty($movie['added'])): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Added On</span>
            <span class="movie-info-cell-value"><?= date('M d, Y', (int)$movie['added']) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($ratingFormatted): ?>
        <div class="col-6 col-md-4 col-xl-3">
          <div class="movie-info-cell">
            <span class="movie-info-cell-label d-block">Rating</span>
            <span class="movie-info-cell-value text-warning">
              <i class="icon-base bx bxs-star me-1"></i><?= $ratingFormatted ?> <span class="text-secondary small">/ 10</span>
            </span>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ─── Recommendations ("Users Also Watched") ─────────────────────── -->
<?php if (!empty($similarMovies)): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <i class="icon-base bx bx-film text-primary fs-5"></i>
        <h6 class="card-title mb-0 fw-bold text-uppercase small">Users Also Watched</h6>
      </div>
    </div>
    <div class="card-body p-4 pt-2">
      <div class="row g-3">
        <?php foreach ($similarMovies as $sim): ?>
          <div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="card h-100 border shadow-sm channel-card">
              <div class="position-relative overflow-hidden">
                <img
                  src="<?= $sim['cover'] ? htmlspecialchars($sim['cover']) : $baseUrl . 'assets/img/pages/profile-banner.png' ?>"
                  alt="<?= htmlspecialchars($sim['title']) ?>"
                  class="card-img-top object-fit-cover media-poster-ratio"
                  loading="lazy"
                  onerror="this.src='<?= $baseUrl ?>assets/img/pages/profile-banner.png';" />
                <a href="<?= $baseUrl ?>movie?id=<?= $sim['id'] ?>" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="Watch Movie">
                  <div class="avatar avatar-md rounded-circle bg-primary d-flex align-items-center justify-content-center shadow-lg">
                    <i class="icon-base bx bx-play fs-4 text-white"></i>
                  </div>
                </a>
                <?php if (!empty($sim['rating']) && $sim['rating'] !== 'N/A'): ?>
                  <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 z-3 pe-none">
                    <i class="icon-base bx bxs-star text-warning me-1"></i><?= $sim['rating'] ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="card-body p-2 d-flex flex-column justify-content-between">
                <h6 class="card-title text-truncate mb-1 small fw-bold" title="<?= htmlspecialchars($sim['title']) ?>">
                  <a href="<?= $baseUrl ?>movie?id=<?= $sim['id'] ?>" class="text-heading text-decoration-none">
                    <?= htmlspecialchars($sim['title']) ?>
                  </a>
                </h6>
                <div class="d-flex align-items-center justify-content-between text-secondary small pt-1 border-top">
                  <span><?= !empty($sim['year']) ? (int)$sim['year'] : '&mdash;' ?></span>
                  <a href="<?= $baseUrl ?>movie?id=<?= $sim['id'] ?>" class="btn btn-xs btn-primary d-flex align-items-center gap-1">
                    <i class="icon-base bx bx-play"></i>
                  </a>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

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

<!-- ─── Client-side Favorites & Trailer Logic ──────────────────────── -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const movieId = <?= (int)$movie['id'] ?>;
    const favKey = 'xc_player_v2_favs_movies';
    const favBtn = document.getElementById('btn-fav-movie');
    const favIcon = document.getElementById('fav-icon');
    const favLabel = document.getElementById('fav-label');

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
      const isFav = favs.includes(movieId);
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
        if (favs.includes(movieId)) {
          favs = favs.filter(id => id !== movieId);
        } else {
          favs.push(movieId);
        }
        saveFavorites(favs);
        updateFavButton();
      });
    }

    // Trailer modal play/pause handling
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
