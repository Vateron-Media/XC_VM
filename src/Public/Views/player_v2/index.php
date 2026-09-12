<?php

use XcVm\Core\Util\ImageUtils;

$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';

$totalLive   = !empty($totalLive) ? (int)$totalLive : count($rUserInfo['live_ids'] ?? []);
$totalVod    = !empty($totalVod) ? (int)$totalVod : count($rUserInfo['vod_ids'] ?? []);
$totalSeries = !empty($totalSeries) ? (int)$totalSeries : count($rUserInfo['series_ids'] ?? []);
$totalRadio  = !empty($totalRadio) ? (int)$totalRadio : count($rUserInfo['radio_ids'] ?? []);

$subscriberUsername = !empty($rUserInfo['username']) ? htmlspecialchars($rUserInfo['username']) : 'Subscriber';
$expDate = !empty($rUserInfo['exp_date']) ? date('M j, Y', (int)$rUserInfo['exp_date']) : 'Unlimited';

// Primary Hero Featured Item
$primaryHero = !empty($heroSlides[0]) ? $heroSlides[0] : [
    'type'     => 'movie',
    'id'       => 0,
    'title'    => 'Unlimited High-Definition Streaming',
    'year'     => date('Y'),
    'rating'   => '9.5',
    'cover'    => '',
    'backdrop' => $assetsPath . 'img/pages/profile-banner.png',
    'plot'     => 'Explore thousands of live channels, blockbuster movies, and binge-worthy TV series with high-definition audio and crystal clear streams.',
    'genre'    => 'Cinema, Live Broadcast, Series',
    'duration' => '4K Ultra HD',
];

$heroBackdrop = !empty($primaryHero['backdrop'])
    ? $primaryHero['backdrop']
    : (!empty($primaryHero['cover']) ? $primaryHero['cover'] : $assetsPath . 'img/pages/profile-banner.png');

$heroDetailUrl = $primaryHero['type'] === 'series'
    ? $baseUrl . 'series?id=' . (int)$primaryHero['id']
    : $baseUrl . 'movie?id=' . (int)$primaryHero['id'];

$heroPlayUrl = $primaryHero['type'] === 'series'
    ? $baseUrl . 'player?type=series&series_id=' . (int)$primaryHero['id'] . '&s=1&e=1'
    : $baseUrl . 'player?type=movie&id=' . (int)$primaryHero['id'];
?>

<!-- ═════════════════════════════════════════════════════════════════
     1. NETFLIX-STYLE BILLBOARD CINEMATIC HERO
══════════════════════════════════════════════════════════════════ -->
<div class="hero-billboard shadow-lg border-0" id="hero-billboard-stage">
  <img
    src="<?= htmlspecialchars($heroBackdrop) ?>"
    alt="<?= htmlspecialchars($primaryHero['title']) ?>"
    class="hero-billboard-bg"
    id="hero-billboard-bg"
    onerror="this.src='<?= $assetsPath ?>img/pages/profile-banner.png';" />
  <div class="hero-billboard-overlay"></div>

  <div class="hero-billboard-content">
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <span class="badge hero-badge-tag rounded-pill px-3 py-1 text-uppercase" id="hero-tag-badge">
        <?= $primaryHero['type'] === 'series' ? '#1 IN SERIES TODAY' : '#1 IN MOVIES TODAY' ?>
      </span>
      <span class="badge bg-dark bg-opacity-75 text-white rounded-pill px-2 py-1">
        <i class="icon-base bx bx-badge-check text-success me-1"></i>4K Ultra HD
      </span>
      <span class="badge bg-dark bg-opacity-75 text-white rounded-pill px-2 py-1">
        <i class="icon-base bx bx-volume-full text-info me-1"></i>5.1 Audio
      </span>
    </div>

    <h1 class="hero-title-text text-white mb-2" id="hero-title">
      <?= htmlspecialchars($primaryHero['title']) ?>
    </h1>

    <div class="d-flex align-items-center gap-3 text-white-50 small mb-3 flex-wrap">
      <span class="badge bg-warning text-dark fw-bold" id="hero-rating">
        ★ <?= !empty($primaryHero['rating']) ? number_format((float)$primaryHero['rating'], 1) : '8.5' ?>
      </span>
      <span class="fw-medium text-white" id="hero-year"><?= !empty($primaryHero['year']) ? (int)$primaryHero['year'] : date('Y') ?></span>
      <span class="badge bg-label-secondary text-white rounded-pill px-2">16+</span>
      <span class="text-white-50" id="hero-genre"><?= htmlspecialchars($primaryHero['genre'] ?? 'Cinema, Entertainment') ?></span>
    </div>

    <p class="hero-plot-text mb-4" id="hero-plot">
      <?= htmlspecialchars($primaryHero['plot'] ?? 'Watch now on XC_VM Web Player.') ?>
    </p>

    <div class="d-flex align-items-center gap-3 flex-wrap">
      <a href="<?= $heroPlayUrl ?>" class="btn btn-primary btn-lg rounded-pill px-4" id="hero-play-btn">
        <i class="icon-base bx bx-play me-2 fs-4"></i> Play Now
      </a>
      <a href="<?= $heroDetailUrl ?>" class="btn btn-outline-light btn-lg rounded-pill px-4" id="hero-info-btn">
        <i class="icon-base bx bx-info-circle me-2 fs-5"></i> More Details
      </a>
    </div>
  </div>

  <!-- Carousel Slide Indicators -->
  <div class="position-absolute bottom-0 end-0 p-4 d-flex align-items-center gap-2 z-index-toast" id="hero-slider-dots"></div>
</div>

<!-- Hero Slides JSON for Rotator -->
<script type="application/json" id="hero-slides-data">
  <?= json_encode($heroSlides ?? []) ?>
</script>

<!-- ═════════════════════════════════════════════════════════════════
     2. QUICK STATS & SUBSCRIPTION STATUS RIBBON
══════════════════════════════════════════════════════════════════ -->
<div class="row g-4 mb-6">
  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>live" class="card text-decoration-none h-100 shadow-sm border-0 zap-channel-card">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="live-badge-dot"></span>
            <span class="text-body-secondary small fw-bold text-uppercase">Live TV</span>
          </div>
          <h4 class="mb-0 fw-bold text-heading"><?= number_format($totalLive) ?></h4>
          <small class="text-success fw-medium">Active Channels</small>
        </div>
        <div class="avatar avatar-md bg-label-primary rounded d-flex align-items-center justify-content-center">
          <i class="icon-base bx bx-tv fs-3 text-primary"></i>
        </div>
      </div>
    </a>
  </div>

  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>movies" class="card text-decoration-none h-100 shadow-sm border-0 zap-channel-card">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-body-secondary small fw-bold text-uppercase d-block mb-1">Movies (VOD)</span>
          <h4 class="mb-0 fw-bold text-heading"><?= number_format($totalVod) ?></h4>
          <small class="text-success fw-medium">4K &amp; HD Films</small>
        </div>
        <div class="avatar avatar-md bg-label-success rounded d-flex align-items-center justify-content-center">
          <i class="icon-base bx bx-film fs-3 text-success"></i>
        </div>
      </div>
    </a>
  </div>

  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>series" class="card text-decoration-none h-100 shadow-sm border-0 zap-channel-card">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-body-secondary small fw-bold text-uppercase d-block mb-1">TV Series</span>
          <h4 class="mb-0 fw-bold text-heading"><?= number_format($totalSeries) ?></h4>
          <small class="text-info fw-medium">Complete Shows</small>
        </div>
        <div class="avatar avatar-md bg-label-info rounded d-flex align-items-center justify-content-center">
          <i class="icon-base bx bx-movie-play fs-3 text-info"></i>
        </div>
      </div>
    </a>
  </div>

  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>profile" class="card text-decoration-none h-100 shadow-sm border-0 zap-channel-card">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-body-secondary small fw-bold text-uppercase d-block mb-1">Subscription</span>
          <h6 class="mb-0 fw-bold text-success text-truncate"><?= $expDate ?></h6>
          <small class="text-body-secondary"><?= $subscriberUsername ?></small>
        </div>
        <div class="avatar avatar-md bg-label-warning rounded d-flex align-items-center justify-content-center">
          <i class="icon-base bx bx-crown fs-3 text-warning"></i>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- ═════════════════════════════════════════════════════════════════
     3. "CONTINUE WATCHING" SHELF (DYNAMIC FROM LOCALSTORAGE)
══════════════════════════════════════════════════════════════════ -->
<!-- ═════════════════════════════════════════════════════════════════
     3. "CONTINUE WATCHING" SHELF (DYNAMIC FROM LOCALSTORAGE)
══════════════════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-6 d-none" id="continue-watching-shelf-wrap">
  <div class="card-header border-0 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-4 px-4 px-md-5 pb-0">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar avatar-md bg-label-primary rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
        <i class="icon-base bx bx-play-circle fs-3 text-primary"></i>
      </div>
      <div>
        <h4 class="mb-0 fw-bold text-heading">Continue Watching</h4>
        <p class="text-body-secondary small mb-0 mt-1">Pick up exactly where you left off</p>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="continue-watching-container" data-shelf-dir="left" title="Previous">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="continue-watching-container" data-shelf-dir="right" title="Next">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    </div>
  </div>
  <div class="card-body px-4 px-md-5 pt-3 pb-4">
    <div class="shelf-scroll-row" id="continue-watching-container"></div>
  </div>
</div>

<!-- ═════════════════════════════════════════════════════════════════
     4. NETFLIX-STYLE "TOP 10 TODAY" RANKING ROW
══════════════════════════════════════════════════════════════════ -->
<?php if (!empty($top10Items)): ?>
<div class="card border-0 shadow-sm mb-6">
  <div class="card-header border-0 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-4 px-4 px-md-5 pb-0">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar avatar-md bg-label-danger rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
        <i class="icon-base bx bx-trending-up fs-3 text-danger"></i>
      </div>
      <div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <h4 class="mb-0 fw-bold text-heading">Top 10 in Your Region Today</h4>
          <span class="badge bg-danger rounded-pill px-2 py-1 small fw-bold">Live Rankings</span>
        </div>
        <p class="text-body-secondary small mb-0 mt-1">The most-watched titles right now across the platform</p>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-label-secondary rounded-pill px-3 py-1 d-none d-sm-inline-block">10 Titles</span>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="top-10-shelf-row" data-shelf-dir="left" title="Previous">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="top-10-shelf-row" data-shelf-dir="right" title="Next">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    </div>
  </div>

  <div class="card-body px-4 px-md-5 pt-3 pb-4">
    <div class="shelf-scroll-row" id="top-10-shelf-row">
      <?php foreach ($top10Items as $idx => $item):
        $rank = $idx + 1;
        $isSeries = $item['type'] === 'series';
        $detailLink = $isSeries ? $baseUrl . 'series?id=' . (int)$item['id'] : $baseUrl . 'movie?id=' . (int)$item['id'];
        $posterUrl = !empty($item['cover'])
            ? $baseUrl . 'resize?url=' . urlencode($item['cover']) . '&w=300&h=450'
            : (!empty($item['backdrop']) ? $baseUrl . 'resize?url=' . urlencode($item['backdrop']) . '&w=300&h=450' : $assetsPath . 'img/pages/profile-banner.png');
        $rating = !empty($item['rating']) ? number_format((float)$item['rating'], 1) : null;
        $itemGenre = !empty($item['genre']) ? (is_array($item['genre']) ? implode(', ', $item['genre']) : $item['genre']) : '';
        $firstGenre = $itemGenre ? explode(',', $itemGenre)[0] : ($isSeries ? 'Series' : 'Feature');
      ?>
        <div class="top10-item-wrap flex-shrink-0">
          <span class="top10-rank-number"><?= $rank ?></span>
          <div class="top10-poster-card shelf-card-item">
            <div class="card h-100 border shadow-sm">
              <div class="position-relative overflow-hidden">
                <img
                  src="<?= $posterUrl ?>"
                  alt="<?= htmlspecialchars($item['title']) ?>"
                  class="card-img-top object-fit-cover media-poster-ratio"
                  loading="lazy"
                  onerror="this.src='<?= $assetsPath ?>img/pages/profile-banner.png';" />
                <a href="<?= $detailLink ?>" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="Play <?= htmlspecialchars($item['title']) ?>">
                  <div class="avatar avatar-md rounded-circle <?= $isSeries ? 'bg-info' : 'bg-danger' ?> d-flex align-items-center justify-content-center shadow-lg">
                    <i class="icon-base bx <?= $isSeries ? 'bx-show' : 'bx-play' ?> fs-4 text-white"></i>
                  </div>
                </a>
                <span class="position-absolute top-0 start-0 m-2 badge bg-danger text-white shadow-sm fw-bold z-3 pe-none">
                  #<?= $rank ?>
                </span>
                <?php if ($rating): ?>
                  <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-warning shadow-sm z-3 pe-none">
                    <i class="icon-base bx bxs-star text-warning me-1"></i><?= $rating ?>
                  </span>
                <?php endif; ?>
                <span class="position-absolute bottom-0 start-0 m-2 badge <?= $isSeries ? 'bg-info' : 'bg-primary' ?> bg-opacity-90 text-white shadow-sm small text-uppercase z-3 pe-none">
                  <?= $isSeries ? 'Series' : 'Movie' ?>
                </span>
              </div>
              <div class="card-body p-3 d-flex flex-column justify-content-between">
                <div>
                  <h6 class="card-title text-truncate mb-1 fw-bold text-heading" title="<?= htmlspecialchars($item['title']) ?>">
                    <a href="<?= $detailLink ?>" class="text-heading text-decoration-none"><?= htmlspecialchars($item['title']) ?></a>
                  </h6>
                  <div class="d-flex align-items-center gap-1 mb-2">
                    <span class="badge bg-label-secondary rounded-pill small py-0 px-2"><?= !empty($item['year']) ? (int)$item['year'] : 'Top' ?></span>
                    <span class="badge bg-label-<?= $isSeries ? 'info' : 'primary' ?> rounded-pill small py-0 px-2 text-truncate max-w-100"><?= htmlspecialchars(trim($firstGenre)) ?></span>
                  </div>
                </div>
                <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-auto gap-2">
                  <a href="<?= $detailLink ?>" class="btn btn-xs btn-primary d-flex align-items-center gap-1 flex-grow-1 justify-content-center">
                    <i class="icon-base bx bx-play"></i> Watch
                  </a>
                  <button
                    type="button"
                    class="btn btn-xs btn-icon btn-label-secondary rounded-circle"
                    data-fav-toggle-type="<?= $isSeries ? 'series' : 'movies' ?>"
                    data-fav-id="<?= (int)$item['id'] ?>"
                    title="Add to Favorites">
                    <i class="icon-base bx bx-star"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═════════════════════════════════════════════════════════════════
     5. TRENDING MOVIES ROW
══════════════════════════════════════════════════════════════════ -->
<?php if (!empty($rMovies['streams'])): ?>
<div class="card border-0 shadow-sm mb-6">
  <div class="card-header border-0 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-4 px-4 px-md-5 pb-0">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar avatar-md bg-label-primary rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
        <i class="icon-base bx bx-film fs-3 text-primary"></i>
      </div>
      <div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <h4 class="mb-0 fw-bold text-heading">Trending Movies &amp; Feature Films</h4>
          <span class="badge bg-label-primary rounded-pill px-2 py-1 small">Fresh Releases</span>
        </div>
        <p class="text-body-secondary small mb-0 mt-1">Recently added Hollywood, European, and Arabic movies</p>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a href="<?= $baseUrl ?>movies" class="btn btn-sm btn-outline-primary rounded-pill d-none d-sm-inline-flex">
        Explore All Movies <i class="icon-base bx bx-right-arrow-alt ms-1"></i>
      </a>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="movies-shelf-row" data-shelf-dir="left" title="Previous">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="movies-shelf-row" data-shelf-dir="right" title="Next">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    </div>
  </div>

  <div class="card-body px-4 px-md-5 pt-3 pb-4">
    <div class="shelf-scroll-row" id="movies-shelf-row">
      <?php foreach (array_slice($rMovies['streams'], 0, 18) as $m):
        $props = json_decode($m['movie_properties'] ?? '', true) ?: [];
        $cover = ImageUtils::validateURL($props['movie_image'] ?? '') ?: '';
        $rating = !empty($props['rating']) ? number_format((float)$props['rating'], 1) : (!empty($m['rating']) ? number_format((float)$m['rating'], 1) : null);
        $posterUrl = $cover ? $baseUrl . 'resize?url=' . urlencode($cover) . '&w=300&h=450' : $assetsPath . 'img/pages/profile-banner.png';
        $genre = !empty($props['genre']) ? (is_array($props['genre']) ? implode(', ', $props['genre']) : $props['genre']) : '';
        $primaryGenre = $genre ? explode(',', $genre)[0] : 'Feature';
        $detailUrl = $baseUrl . 'movie?id=' . (int)$m['id'];
      ?>
        <div class="shelf-card-item flex-shrink-0">
          <div class="card h-100 border shadow-sm channel-card">
            <div class="position-relative overflow-hidden">
              <img
                src="<?= $posterUrl ?>"
                alt="<?= htmlspecialchars($m['stream_display_name']) ?>"
                class="card-img-top object-fit-cover media-poster-ratio"
                loading="lazy"
                onerror="this.src='<?= $assetsPath ?>img/pages/profile-banner.png';" />
              <a href="<?= $detailUrl ?>" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="Play <?= htmlspecialchars($m['stream_display_name']) ?>">
                <div class="avatar avatar-md rounded-circle bg-primary d-flex align-items-center justify-content-center shadow-lg">
                  <i class="icon-base bx bx-play fs-4 text-white"></i>
                </div>
              </a>
              <button
                type="button"
                class="btn btn-sm btn-icon btn-dark bg-opacity-75 position-absolute top-0 start-0 m-2 rounded-circle z-3"
                data-fav-toggle-type="movies"
                data-fav-id="<?= (int)$m['id'] ?>"
                title="Add to Favorites">
                <i class="icon-base bx bx-star text-white"></i>
              </button>
              <?php if ($rating): ?>
                <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-warning shadow-sm z-3 pe-none">
                  <i class="icon-base bx bxs-star text-warning me-1"></i><?= $rating ?>
                </span>
              <?php endif; ?>
              <span class="position-absolute bottom-0 start-0 m-2 badge bg-primary bg-opacity-85 text-white shadow-sm small z-3 pe-none">
                HD VOD
              </span>
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div>
                <h6 class="card-title text-truncate mb-1 fw-bold text-heading" title="<?= htmlspecialchars($m['stream_display_name']) ?>">
                  <a href="<?= $detailUrl ?>" class="text-heading text-decoration-none"><?= htmlspecialchars($m['stream_display_name']) ?></a>
                </h6>
                <div class="d-flex align-items-center gap-1 mb-2">
                  <span class="badge bg-label-primary rounded-pill small py-0 px-2"><?= !empty($m['year']) ? (int)$m['year'] : 'Movie' ?></span>
                  <span class="badge bg-label-secondary rounded-pill small py-0 px-2 text-truncate max-w-100"><?= htmlspecialchars(trim($primaryGenre)) ?></span>
                </div>
              </div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-auto gap-2">
                <a href="<?= $detailUrl ?>" class="btn btn-xs btn-primary d-flex align-items-center gap-1 flex-grow-1 justify-content-center">
                  <i class="icon-base bx bx-play"></i> Watch
                </a>
                <a href="<?= $detailUrl ?>" class="btn btn-xs btn-outline-secondary btn-icon" title="Movie Details">
                  <i class="icon-base bx bx-info-circle"></i>
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

<!-- ═════════════════════════════════════════════════════════════════
     6. BINGE-WORTHY TV SERIES ROW
══════════════════════════════════════════════════════════════════ -->
<?php if (!empty($rSeries['streams'])): ?>
<div class="card border-0 shadow-sm mb-6">
  <div class="card-header border-0 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-4 px-4 px-md-5 pb-0">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar avatar-md bg-label-info rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
        <i class="icon-base bx bx-movie-play fs-3 text-info"></i>
      </div>
      <div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <h4 class="mb-0 fw-bold text-heading">Binge-Worthy TV Series &amp; Shows</h4>
          <span class="badge bg-label-info rounded-pill px-2 py-1 small">Multi-Season</span>
        </div>
        <p class="text-body-secondary small mb-0 mt-1">Follow continuous storylines, dramas, and bingeable sagas</p>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a href="<?= $baseUrl ?>series" class="btn btn-sm btn-outline-info rounded-pill d-none d-sm-inline-flex">
        Explore All Series <i class="icon-base bx bx-right-arrow-alt ms-1"></i>
      </a>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="series-shelf-row" data-shelf-dir="left" title="Previous">
        <i class="icon-base bx bx-chevron-left"></i>
      </button>
      <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded-circle shadow-sm" data-shelf-scroll="series-shelf-row" data-shelf-dir="right" title="Next">
        <i class="icon-base bx bx-chevron-right"></i>
      </button>
    </div>
  </div>

  <div class="card-body px-4 px-md-5 pt-3 pb-4">
    <div class="shelf-scroll-row" id="series-shelf-row">
      <?php foreach (array_slice($rSeries['streams'], 0, 18) as $s):
        $cover = ImageUtils::validateURL($s['cover'] ?? '') ?: '';
        $rating = !empty($s['rating']) ? number_format((float)$s['rating'], 1) : null;
        $posterUrl = $cover ? $baseUrl . 'resize?url=' . urlencode($cover) . '&w=300&h=450' : $assetsPath . 'img/pages/profile-banner.png';
        $genre = !empty($s['genre']) ? (is_array($s['genre']) ? implode(', ', $s['genre']) : $s['genre']) : '';
        $primaryGenre = $genre ? explode(',', $genre)[0] : 'Drama';
        $sData = !empty($s['seasons']) ? json_decode($s['seasons'], true) : null;
        $seasonsCount = is_array($sData) ? max(1, count($sData)) : 1;
        $detailUrl = $baseUrl . 'series?id=' . (int)$s['id'];
      ?>
        <div class="shelf-card-item flex-shrink-0">
          <div class="card h-100 border shadow-sm channel-card">
            <div class="position-relative overflow-hidden">
              <img
                src="<?= $posterUrl ?>"
                alt="<?= htmlspecialchars($s['title']) ?>"
                class="card-img-top object-fit-cover media-poster-ratio"
                loading="lazy"
                onerror="this.src='<?= $assetsPath ?>img/pages/profile-banner.png';" />
              <a href="<?= $detailUrl ?>" class="play-hover-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none z-2" title="View <?= htmlspecialchars($s['title']) ?>">
                <div class="avatar avatar-md rounded-circle bg-info d-flex align-items-center justify-content-center shadow-lg">
                  <i class="icon-base bx bx-show fs-4 text-white"></i>
                </div>
              </a>
              <button
                type="button"
                class="btn btn-sm btn-icon btn-dark bg-opacity-75 position-absolute top-0 start-0 m-2 rounded-circle z-3"
                data-fav-toggle-type="series"
                data-fav-id="<?= (int)$s['id'] ?>"
                title="Add to Favorites">
                <i class="icon-base bx bx-star text-white"></i>
              </button>
              <?php if ($rating): ?>
                <span class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-warning shadow-sm z-3 pe-none">
                  <i class="icon-base bx bxs-star text-warning me-1"></i><?= $rating ?>
                </span>
              <?php endif; ?>
              <span class="position-absolute bottom-0 start-0 m-2 badge bg-info bg-opacity-85 text-white shadow-sm small z-3 pe-none">
                <?= $seasonsCount ?> <?= $seasonsCount > 1 ? 'Seasons' : 'Season' ?>
              </span>
            </div>
            <div class="card-body p-3 d-flex flex-column justify-content-between">
              <div>
                <h6 class="card-title text-truncate mb-1 fw-bold text-heading" title="<?= htmlspecialchars($s['title']) ?>">
                  <a href="<?= $detailUrl ?>" class="text-heading text-decoration-none"><?= htmlspecialchars($s['title']) ?></a>
                </h6>
                <div class="d-flex align-items-center gap-1 mb-2">
                  <span class="badge bg-label-info rounded-pill small py-0 px-2"><?= !empty($s['year']) ? (int)$s['year'] : 'Series' ?></span>
                  <span class="badge bg-label-secondary rounded-pill small py-0 px-2 text-truncate max-w-100"><?= htmlspecialchars(trim($primaryGenre)) ?></span>
                </div>
              </div>
              <div class="d-flex align-items-center justify-content-between pt-2 border-top mt-auto gap-2">
                <a href="<?= $detailUrl ?>" class="btn btn-xs btn-info text-white d-flex align-items-center gap-1 flex-grow-1 justify-content-center">
                  <i class="icon-base bx bx-show"></i> Episodes
                </a>
                <a href="<?= $detailUrl ?>" class="btn btn-xs btn-outline-secondary btn-icon" title="Series Info">
                  <i class="icon-base bx bx-info-circle"></i>
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

<!-- ═════════════════════════════════════════════════════════════════
     7. LIVE TV QUICK ZAP PREVIEW ROW
══════════════════════════════════════════════════════════════════ -->
<?php if (!empty($rLiveChannels['streams'])): ?>
<div class="card border-0 shadow-sm mb-6">
  <div class="card-header border-0 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-4 px-4 px-md-5 pb-0">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar avatar-md bg-label-danger rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
        <i class="icon-base bx bx-broadcast fs-3 text-danger"></i>
      </div>
      <div>
        <h4 class="mb-0 fw-bold text-heading">Live Channels On-Air</h4>
        <p class="text-body-secondary small mb-0 mt-1">High-definition satellite and terrestrial broadcast streams</p>
      </div>
    </div>
    <a href="<?= $baseUrl ?>live" class="btn btn-sm btn-outline-danger rounded-pill">
      Open Live TV Player <i class="icon-base bx bx-right-arrow-alt ms-1"></i>
    </a>
  </div>

  <div class="card-body px-4 px-md-5 pt-3 pb-4">
    <div class="row g-3">
      <?php foreach (array_slice($rLiveChannels['streams'], 0, 8) as $ch):
        $logo = !empty($ch['stream_icon']) ? $ch['stream_icon'] : '';
      ?>
        <div class="col-6 col-sm-4 col-md-3 col-xl-custom-8">
          <a href="<?= $baseUrl ?>live" class="card text-decoration-none h-100 shadow-sm border zap-channel-card">
            <div class="card-body p-3 text-center d-flex flex-column align-items-center justify-content-between">
              <div class="d-flex align-items-center justify-content-between w-100 mb-2">
                <span class="badge bg-label-danger py-0 px-2 small">
                  <span class="live-badge-dot me-1"></span> LIVE
                </span>
                <i class="icon-base bx bx-tv text-body-secondary"></i>
              </div>
              <div class="my-2 d-flex align-items-center justify-content-center">
                <?php if ($logo): ?>
                  <img src="<?= htmlspecialchars($logo) ?>" alt="" class="zap-channel-logo" onerror="this.remove();" />
                <?php else: ?>
                  <div class="avatar avatar-md bg-label-primary rounded d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-tv fs-4"></i>
                  </div>
                <?php endif; ?>
              </div>
              <h6 class="mb-0 fw-bold small text-truncate text-heading w-100" title="<?= htmlspecialchars($ch['stream_display_name']) ?>">
                <?= htmlspecialchars($ch['stream_display_name']) ?>
              </h6>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═════════════════════════════════════════════════════════════════
     8. RADIO & MUSIC STATIONS ROW
══════════════════════════════════════════════════════════════════ -->
<?php if (!empty($rRadioStreams['streams'])): ?>
<div class="card border-0 shadow-sm mb-6">
  <div class="card-header border-0 d-flex flex-wrap align-items-center justify-content-between gap-3 pt-4 px-4 px-md-5 pb-0">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar avatar-md bg-label-warning rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
        <i class="icon-base bx bx-headphone fs-3 text-warning"></i>
      </div>
      <div>
        <h4 class="mb-0 fw-bold text-heading">Live Radio &amp; Audio Broadcasts</h4>
        <p class="text-body-secondary small mb-0 mt-1">Continuous music, news, and Islamic radio broadcasts</p>
      </div>
    </div>
    <a href="<?= $baseUrl ?>radio" class="btn btn-sm btn-outline-warning rounded-pill">
      Open Radio Studio <i class="icon-base bx bx-right-arrow-alt ms-1"></i>
    </a>
  </div>

  <div class="card-body px-4 px-md-5 pt-3 pb-4">
    <div class="row g-3">
      <?php foreach (array_slice($rRadioStreams['streams'], 0, 6) as $rSt): ?>
        <div class="col-6 col-sm-4 col-md-2">
          <a href="<?= $baseUrl ?>radio" class="card text-decoration-none h-100 shadow-sm border zap-channel-card">
            <div class="card-body p-3 text-center d-flex flex-column align-items-center justify-content-between">
              <div class="avatar avatar-md bg-label-warning rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center">
                <i class="icon-base bx bx-broadcast fs-4 text-warning"></i>
              </div>
              <h6 class="mb-1 fw-bold small text-truncate text-heading w-100" title="<?= htmlspecialchars($rSt['stream_display_name']) ?>">
                <?= htmlspecialchars($rSt['stream_display_name']) ?>
              </h6>
              <span class="badge bg-label-secondary rounded-pill small">Stereo</span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Netflix Dashboard Engine Runtime -->
<script src="<?= $assetsPath ?>js/player-home.js"></script>
