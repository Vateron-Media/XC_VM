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
?>

<!-- ─── Cinema Player Top Bar ──────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div class="d-flex align-items-center gap-3">
    <a href="<?= htmlspecialchars($backLink) ?>" class="btn btn-sm btn-label-secondary d-flex align-items-center gap-1">
      <i class="icon-base bx bx-arrow-back"></i>
      <span><?= $isSeries ? 'Back to Series' : 'Back to Details' ?></span>
    </a>
    <div>
      <h5 class="mb-0 fw-bold text-truncate text-heading"><?= htmlspecialchars($displayTitle) ?></h5>
      <div class="d-flex align-items-center gap-2 small text-body-secondary mt-1">
        <?php if (!empty($categoryName)): ?>
          <span class="badge bg-label-primary rounded-pill px-2 py-0"><?= htmlspecialchars($categoryName) ?></span>
        <?php endif; ?>
        <?php if ($year): ?>
          <span><?= $year ?></span>
        <?php endif; ?>
        <span>&bull;</span>
        <span class="badge bg-label-<?= htmlspecialchars($qualityColor ?? 'primary') ?> rounded-pill px-2 py-0">
          <?= htmlspecialchars($qualityBadge ?? 'HD') ?>
        </span>
      </div>
    </div>
  </div>

  <div class="d-flex align-items-center gap-2">
    <?php if ($isSeries && !empty($prevEp)): ?>
      <a
        href="<?= $baseUrl ?>player?type=series&id=<?= (int)$prevEp['stream_id'] ?>&series_id=<?= (int)$seriesId ?>&s=<?= (int)$prevEp['season_num'] ?>&e=<?= (int)$prevEp['episode_num'] ?>"
        class="btn btn-sm btn-label-secondary d-flex align-items-center gap-1"
        id="btn-prev-episode"
        title="Previous Episode: S<?= sprintf('%02d', (int)$prevEp['season_num']) ?>E<?= sprintf('%02d', (int)$prevEp['episode_num']) ?> (P)">
        <i class="icon-base bx bx-skip-previous"></i>
        <span class="d-none d-sm-inline">Prev Ep</span>
      </a>
    <?php endif; ?>

    <?php if ($isSeries && !empty($nextEp)): ?>
      <a
        href="<?= $baseUrl ?>player?type=series&id=<?= (int)$nextEp['stream_id'] ?>&series_id=<?= (int)$seriesId ?>&s=<?= (int)$nextEp['season_num'] ?>&e=<?= (int)$nextEp['episode_num'] ?>"
        class="btn btn-sm btn-primary d-flex align-items-center gap-1 shadow-sm"
        id="btn-next-episode"
        title="Next Episode: S<?= sprintf('%02d', (int)$nextEp['season_num']) ?>E<?= sprintf('%02d', (int)$nextEp['episode_num']) ?> (N)">
        <span class="d-none d-sm-inline">Next Ep</span>
        <i class="icon-base bx bx-skip-next"></i>
      </a>
    <?php endif; ?>

    <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded" id="btn-player-aspect" title="Cycle Aspect Ratio">
      <i class="icon-base bx bx-expand"></i>
    </button>
    <button type="button" class="btn btn-sm btn-icon btn-label-secondary rounded" id="btn-player-fullscreen" title="Fullscreen (F)">
      <i class="icon-base bx bx-fullscreen"></i>
    </button>
    <a href="<?= $baseUrl ?><?= $isSeries ? 'series' : 'movies' ?>" class="btn btn-sm btn-outline-secondary">
      <i class="icon-base <?= $isSeries ? 'bx bx-movie-play' : 'bx bx-film' ?> me-1"></i><?= $isSeries ? 'Series Catalog' : 'Movies Catalog' ?>
    </a>
  </div>
</div>

<!-- ─── Cinema Video Stage ─────────────────────────────────────────── -->
<div class="card cinema-player-container shadow-lg border-0 mb-4">
  <div class="ratio ratio-16x9 cinema-video-stage position-relative">
    <video
      id="active-video-player"
      controls
      autoplay
      playsinline
      preload="auto"
      class="w-100 h-100 object-fit-contain">
      <source src="<?= htmlspecialchars($streamUrl) ?>" type="video/mp4" />
      <p class="text-white p-4">Your browser does not support HTML5 video playback.</p>
    </video>
  </div>
</div>

<!-- ─── Playback Shortcuts Guide ──────────────────────────────────── -->
<div class="card shadow-sm border-0">
  <div class="card-body p-3">
    <div class="row g-3 align-items-center text-secondary small">
      <div class="col-12 col-md-auto fw-bold text-heading text-uppercase">
        <i class="icon-base bx bx-command me-1"></i>Keyboard Controls:
      </div>
      <div class="col-auto">
        <kbd class="bg-body text-body border px-2 py-1">Space</kbd> Play/Pause
      </div>
      <div class="col-auto">
        <kbd class="bg-body text-body border px-2 py-1">&larr; / &rarr;</kbd> Seek &plusmn;10s
      </div>
      <div class="col-auto">
        <kbd class="bg-body text-body border px-2 py-1">&uarr; / &darr;</kbd> Volume &plusmn;10%
      </div>
      <div class="col-auto">
        <kbd class="bg-body text-body border px-2 py-1">F</kbd> Fullscreen
      </div>
      <div class="col-auto">
        <kbd class="bg-body text-body border px-2 py-1">M</kbd> Mute
      </div>
      <?php if ($isSeries && !empty($nextEp)): ?>
        <div class="col-auto">
          <kbd class="bg-body text-body border px-2 py-1">N</kbd> Next Episode
        </div>
      <?php endif; ?>
      <div class="col-auto">
        <kbd class="bg-body text-body border px-2 py-1">Esc</kbd> Back
      </div>
    </div>
  </div>
</div>

<!-- ─── Theater Runtime Script ────────────────────────────────────── -->
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const video = document.getElementById('active-video-player');
    const fsBtn = document.getElementById('btn-player-fullscreen');
    const aspectBtn = document.getElementById('btn-player-aspect');
    const prevEpBtn = document.getElementById('btn-prev-episode');
    const nextEpBtn = document.getElementById('btn-next-episode');
    const streamId = <?= $streamId ?>;
    const isSeries = <?= $isSeries ? 'true' : 'false' ?>;
    const seriesId = <?= (int)($seriesId ?? 0) ?>;
    const backUrl = '<?= addslashes($backLink) ?>';

    // Aspect Ratio Toggle
    const aspectModes = ['object-fit-contain', 'object-fit-cover', 'object-fit-fill'];
    let aspectIndex = 0;

    if (aspectBtn && video) {
      aspectBtn.addEventListener('click', function () {
        aspectIndex = (aspectIndex + 1) % aspectModes.length;
        aspectModes.forEach(cls => video.classList.remove(cls));
        video.classList.add(aspectModes[aspectIndex]);
      });
    }

    // Fullscreen Toggle
    if (fsBtn && video) {
      fsBtn.addEventListener('click', function () {
        if (!document.fullscreenElement) {
          const container = video.closest('.cinema-player-container') || video;
          if (container.requestFullscreen) {
            container.requestFullscreen();
          } else if (video.webkitRequestFullscreen) {
            video.webkitRequestFullscreen();
          }
        } else {
          document.exitFullscreen();
        }
      });
    }

    // Playback Resume & Continue Watching Storage
    const resumeKey = `xc_player_v2_pos_${isSeries ? 'series' : 'movie'}_${streamId}`;
    if (video) {
      const savedPos = parseFloat(localStorage.getItem(resumeKey) || '0');
      if (savedPos > 10) {
        video.addEventListener('loadedmetadata', function () {
          if (video.duration && savedPos < video.duration - 15) {
            video.currentTime = savedPos;
          }
        }, { once: true });
      }

      // Save progress periodically
      let lastSave = 0;
      video.addEventListener('timeupdate', function () {
        const now = Date.now();
        if (now - lastSave > 3000) {
          lastSave = now;
          if (video.currentTime > 5) {
            localStorage.setItem(resumeKey, video.currentTime.toFixed(1));

            // Continue watching item for series
            if (isSeries && seriesId > 0 && video.duration) {
              try {
                const continueList = JSON.parse(localStorage.getItem('xc_player_v2_continue_series') || '[]');
                const progressPct = Math.min(100, Math.round((video.currentTime / video.duration) * 100));
                const filtered = continueList.filter(x => Number(x.seriesId) !== seriesId);
                filtered.unshift({
                  seriesId: seriesId,
                  streamId: streamId,
                  time: Math.floor(video.currentTime),
                  progress: progressPct,
                  timestamp: now
                });
                localStorage.setItem('xc_player_v2_continue_series', JSON.stringify(filtered.slice(0, 20)));
              } catch (e) {}
            }
          }
        }
      });

      // Clear when ended, and auto-navigate to next episode if present
      video.addEventListener('ended', function () {
        localStorage.removeItem(resumeKey);
        if (nextEpBtn) {
          window.location.href = nextEpBtn.href;
        }
      });
    }

    // Keyboard Shortcuts
    window.addEventListener('keydown', function (e) {
      if (['input', 'textarea', 'select'].includes(document.activeElement?.tagName?.toLowerCase())) {
        return;
      }

      switch (e.key) {
        case ' ':
          e.preventDefault();
          if (video.paused) {
            video.play();
          } else {
            video.pause();
          }
          break;
        case 'ArrowLeft':
          e.preventDefault();
          video.currentTime = Math.max(0, video.currentTime - 10);
          break;
        case 'ArrowRight':
          e.preventDefault();
          video.currentTime = Math.min(video.duration || 999999, video.currentTime + 10);
          break;
        case 'ArrowUp':
          e.preventDefault();
          video.volume = Math.min(1, video.volume + 0.1);
          break;
        case 'ArrowDown':
          e.preventDefault();
          video.volume = Math.max(0, video.volume - 0.1);
          break;
        case 'f':
        case 'F':
          e.preventDefault();
          if (fsBtn) fsBtn.click();
          break;
        case 'm':
        case 'M':
          e.preventDefault();
          video.muted = !video.muted;
          break;
        case 'n':
        case 'N':
          if (nextEpBtn) {
            e.preventDefault();
            window.location.href = nextEpBtn.href;
          }
          break;
        case 'p':
        case 'P':
          if (prevEpBtn) {
            e.preventDefault();
            window.location.href = prevEpBtn.href;
          }
          break;
        case 'Escape':
          window.location.href = backUrl;
          break;
      }
    });
  });
</script>
