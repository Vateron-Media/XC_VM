<?php

use XcVm\Core\Config\SettingsManager;

$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';

$username = htmlspecialchars($lineData['username'] ?? '');
$password = htmlspecialchars($lineData['password'] ?? '');
$maxCons = max(1, (int)($lineData['max_connections'] ?? 1));
$connPercent = min(100, (int)(($activeConsCount / $maxCons) * 100));
$connProgressColor = $connPercent >= 100 ? 'bg-danger' : ($connPercent >= 75 ? 'bg-warning' : 'bg-primary');
?>

<!-- ─── User Profile Header ─────────────────────────────────────────── -->
<div class="row">
  <div class="col-12">
    <div class="card mb-6">
      <div class="user-profile-header-banner">
        <img src="<?= $assetsPath ?>img/pages/profile-banner.png" alt="Profile Banner" class="rounded-top" />
      </div>
      <div class="user-profile-header d-flex flex-column flex-lg-row text-sm-start text-center mb-6">
        <div class="flex-shrink-0 mt-1 mx-sm-0 mx-auto">
          <div class="avatar avatar-xl ms-0 ms-sm-6 rounded-3 user-profile-avatar-initials bg-label-primary text-primary">
            <?= strtoupper(substr($lineData['username'] ?? 'S', 0, 2)) ?>
          </div>
        </div>
        <div class="flex-grow-1 mt-3 mt-lg-5">
          <div class="d-flex align-items-md-end align-items-sm-start align-items-center justify-content-md-between justify-content-start mx-5 flex-md-row flex-column gap-4">
            <div class="user-profile-info">
              <div class="d-flex align-items-center justify-content-sm-start justify-content-center gap-2 mb-1 flex-wrap">
                <h4 class="mb-0 fw-bold"><?= $username ?></h4>
                <?php if (!empty($activationCode)): ?>
                  <span class="badge bg-label-info rounded-pill px-2">
                    <i class="icon-base bx bx-key me-1"></i><?= htmlspecialchars($activationCode) ?>
                  </span>
                <?php endif; ?>
                <?php if ($isExpired): ?>
                  <span class="badge bg-label-danger rounded-pill px-2">Expired</span>
                <?php elseif (!empty($lineData['is_trial'])): ?>
                  <span class="badge bg-label-warning rounded-pill px-2">Trial Account</span>
                <?php else: ?>
                  <span class="badge bg-label-success rounded-pill px-2">Active Subscription</span>
                <?php endif; ?>
              </div>
              <ul class="list-inline mb-0 d-flex align-items-center flex-wrap justify-content-sm-start justify-content-center gap-4 mt-3">
                <li class="list-inline-item">
                  <i class="icon-base bx bx-id-card me-1 text-primary"></i>
                  <span class="fw-medium">Account ID:</span> #<?= (int)$lineData['id'] ?>
                </li>
                <li class="list-inline-item">
                  <i class="icon-base bx bx-calendar me-1 text-info"></i>
                  <span class="fw-medium">Joined:</span> <?= !empty($lineData['created_at']) ? date('M j, Y', (int)$lineData['created_at']) : 'Active' ?>
                </li>
                <li class="list-inline-item">
                  <i class="icon-base bx bx-time-five me-1 <?= $isExpiringSoon ? 'text-danger' : 'text-success' ?>"></i>
                  <span class="fw-medium">Expires:</span> <?= $expTimestamp ? date('M j, Y', $expTimestamp) : 'Unlimited' ?>
                </li>
                <li class="list-inline-item">
                  <i class="icon-base bx bx-wifi me-1 text-warning"></i>
                  <span class="fw-medium">Active:</span> <?= $activeConsCount ?> / <?= $maxCons ?> Cons
                </li>
              </ul>
            </div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap justify-content-center">
              <button type="button" class="btn btn-outline-primary" id="btn-header-switch-account">
                <i class="icon-base bx bx-sync icon-sm me-1"></i> Switch Account
              </button>
              <button type="button" class="btn btn-primary" id="btn-profile-sync">
                <i class="icon-base bx bx-refresh icon-sm me-1"></i> Sync Catalog
              </button>
              <a href="<?= $baseUrl ?>logout" class="btn btn-outline-danger" onclick="if(window.clearUserClientData){window.clearUserClientData();}">
                <i class="icon-base bx bx-log-out icon-sm me-1"></i> Sign Out
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Nav-Pills Tabs Bar ─────────────────────────────────────────── -->
<div class="row">
  <div class="col-md-12">
    <div class="nav-align-top">
      <ul class="nav nav-pills flex-column flex-sm-row mb-6 gap-sm-0 gap-2" role="tablist">
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link active" role="tab" data-bs-toggle="tab" data-bs-target="#tab-overview" aria-controls="tab-overview" aria-selected="true">
            <i class="icon-base bx bx-user icon-sm me-2"></i> Overview & Subscription
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" role="tab" data-bs-toggle="tab" data-bs-target="#tab-playlist" aria-controls="tab-playlist" aria-selected="false">
            <i class="icon-base bx bx-download icon-sm me-2"></i> Playlist & M3U
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" role="tab" data-bs-toggle="tab" data-bs-target="#tab-bouquets" aria-controls="tab-bouquets" aria-selected="false">
            <i class="icon-base bx bx-layer icon-sm me-2"></i> Bouquets Ordering
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" role="tab" data-bs-toggle="tab" data-bs-target="#tab-api" aria-controls="tab-api" aria-selected="false">
            <i class="icon-base bx bx-devices icon-sm me-2"></i> Device & API Setup
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button type="button" class="nav-link" role="tab" data-bs-toggle="tab" data-bs-target="#tab-accounts" aria-controls="tab-accounts" aria-selected="false" id="tab-nav-accounts">
            <i class="icon-base bx bx-sync icon-sm me-2"></i> Switch Accounts <span class="badge bg-primary ms-1" id="profile-accounts-count-badge">0</span>
          </button>
        </li>
      </ul>
    </div>
  </div>
</div>

<!-- ─── Tab Panes Content ──────────────────────────────────────────── -->
<div class="tab-content p-0 border-0 shadow-none bg-transparent">

  <!-- ═════════════════════════════════════════════════════════════════
       TAB 1: OVERVIEW & SUBSCRIPTION
  ══════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">

    <!-- KPI Metric Cards -->
    <div class="row g-6 mb-6">
      <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-body d-flex align-items-center justify-content-between p-4">
            <div>
              <span class="d-block text-body-secondary small fw-medium mb-1">Live TV</span>
              <h4 class="mb-0 fw-bold"><?= number_format($totalLive) ?></h4>
              <small class="text-success fw-medium"><i class="icon-base bx bx-broadcast me-1"></i>Channels</small>
            </div>
            <div class="avatar avatar-md bg-label-primary rounded p-2 d-flex align-items-center justify-content-center">
              <i class="icon-base bx bx-tv icon-lg text-primary"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-body d-flex align-items-center justify-content-between p-4">
            <div>
              <span class="d-block text-body-secondary small fw-medium mb-1">VOD Movies</span>
              <h4 class="mb-0 fw-bold"><?= number_format($totalVod) ?></h4>
              <small class="text-success fw-medium"><i class="icon-base bx bx-film me-1"></i>Films</small>
            </div>
            <div class="avatar avatar-md bg-label-success rounded p-2 d-flex align-items-center justify-content-center">
              <i class="icon-base bx bx-camera-movie icon-lg text-success"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-body d-flex align-items-center justify-content-between p-4">
            <div>
              <span class="d-block text-body-secondary small fw-medium mb-1">TV Series</span>
              <h4 class="mb-0 fw-bold"><?= number_format($totalSeries) ?></h4>
              <small class="text-info fw-medium"><i class="icon-base bx bx-video-recording me-1"></i>Shows</small>
            </div>
            <div class="avatar avatar-md bg-label-info rounded p-2 d-flex align-items-center justify-content-center">
              <i class="icon-base bx bx-movie-play icon-lg text-info"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-body d-flex align-items-center justify-content-between p-4">
            <div>
              <span class="d-block text-body-secondary small fw-medium mb-1">Radio</span>
              <h4 class="mb-0 fw-bold"><?= number_format($totalRadio) ?></h4>
              <small class="text-warning fw-medium"><i class="icon-base bx bx-radio me-1"></i>Stations</small>
            </div>
            <div class="avatar avatar-md bg-label-warning rounded p-2 d-flex align-items-center justify-content-center">
              <i class="icon-base bx bx-headphone icon-lg text-warning"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Details Columns -->
    <div class="row g-6">
      <!-- Left Column: Credentials & Server -->
      <div class="col-12 col-xl-5 col-lg-5">
        <!-- Credentials Card -->
        <div class="card mb-6 shadow-sm border-0">
          <div class="card-header pb-2 d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-shield-quarter me-2 text-primary"></i>Account Credentials
            </h5>
            <span class="badge bg-label-primary rounded-pill px-2">Confidential</span>
          </div>
          <div class="card-body pt-3">
            <!-- Username -->
            <div class="mb-4">
              <label class="form-label small text-uppercase text-body-secondary fw-semibold" for="profile-username-input">Username</label>
              <div class="input-group">
                <span class="input-group-text bg-body-secondary border-0"><i class="icon-base bx bx-user text-body-secondary"></i></span>
                <input type="text" id="profile-username-input" class="form-control" value="<?= $username ?>" readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="profile-username-input" title="Copy Username">
                  <i class="icon-base bx bx-copy icon-sm"></i>
                </button>
              </div>
            </div>

            <!-- Password -->
            <div class="mb-4">
              <label class="form-label small text-uppercase text-body-secondary fw-semibold" for="profile-password-input">Password</label>
              <div class="input-group">
                <span class="input-group-text bg-body-secondary border-0"><i class="icon-base bx bx-key text-body-secondary"></i></span>
                <input type="password" id="profile-password-input" class="form-control" value="<?= $password ?>" readonly />
                <button class="btn btn-outline-secondary" type="button" id="btn-toggle-password" title="Show / Hide Password">
                  <i class="icon-base bx bx-show icon-md" id="icon-toggle-password"></i>
                </button>
                <button class="btn btn-outline-primary" type="button" data-copy-target="profile-password-input" title="Copy Password">
                  <i class="icon-base bx bx-copy icon-sm"></i>
                </button>
              </div>
            </div>

            <!-- Expiry Details -->
            <div class="mb-4">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <span class="small text-uppercase text-body-secondary fw-semibold">Subscription Status</span>
                <?php if ($expTimestamp === null): ?>
                  <span class="badge bg-label-success rounded-pill">Lifetime Access</span>
                <?php elseif ($isExpired): ?>
                  <span class="badge bg-label-danger rounded-pill">Expired</span>
                <?php else: ?>
                  <span class="badge bg-label-primary rounded-pill"><?= $daysRemaining ?> Days Remaining</span>
                <?php endif; ?>
              </div>
              <p class="small mb-2 text-body">
                <i class="icon-base bx bx-calendar-event me-1 text-primary"></i>
                Expiration: <strong><?= $expTimestamp ? date('l, F j, Y — h:i A', $expTimestamp) : 'Unlimited / Never Expires' ?></strong>
              </p>
            </div>

            <!-- Output Formats -->
            <div class="mb-2">
              <span class="small text-uppercase text-body-secondary fw-semibold d-block mb-2">Allowed Output Protocols</span>
              <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-label-secondary rounded-pill px-2">HLS (.m3u8)</span>
                <span class="badge bg-label-secondary rounded-pill px-2">MPEG-TS (.ts)</span>
                <span class="badge bg-label-secondary rounded-pill px-2">RTMP</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Server Information Card -->
        <div class="card mb-6 shadow-sm border-0">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-server me-2 text-info"></i>Server & Host
            </h5>
          </div>
          <div class="card-body pt-3">
            <ul class="list-unstyled mb-0">
              <li class="d-flex align-items-center justify-content-between py-2 border-bottom">
                <span class="text-body-secondary"><i class="icon-base bx bx-globe me-2 text-primary"></i>Server Host</span>
                <span class="fw-semibold text-break" id="server-host-val"><?= htmlspecialchars($serverPublicUrl) ?></span>
              </li>
              <li class="d-flex align-items-center justify-content-between py-2 border-bottom">
                <span class="text-body-secondary"><i class="icon-base bx bx-lock-alt me-2 text-success"></i>SSL Security</span>
                <span class="badge bg-label-success rounded-pill">Active (HTTPS)</span>
              </li>
              <li class="d-flex align-items-center justify-content-between py-2 border-bottom">
                <span class="text-body-secondary"><i class="icon-base bx bx-time me-2 text-info"></i>Server Time</span>
                <span class="fw-medium"><?= date('Y-m-d H:i:s T') ?></span>
              </li>
              <li class="d-flex align-items-center justify-content-between py-2">
                <span class="text-body-secondary"><i class="icon-base bx bx-map-pin me-2 text-warning"></i>ISP / Geo Lock</span>
                <span><?= !empty($lineData['is_isplock']) ? '<span class="badge bg-label-warning">ISP Locked</span>' : '<span class="badge bg-label-success">Unlocked (Global)</span>' ?></span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Right Column: Connections & Activity -->
      <div class="col-12 col-xl-7 col-lg-7">
        <!-- Active Connections Card -->
        <div class="card mb-6 shadow-sm border-0">
          <div class="card-header pb-2 d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-pulse me-2 text-danger"></i>Active Streams & Concurrency
            </h5>
            <span class="badge <?= $activeConsCount > 0 ? 'bg-label-success' : 'bg-label-secondary' ?> rounded-pill px-2">
              <?= $activeConsCount ?> Active
            </span>
          </div>
          <div class="card-body pt-3">
            <!-- Concurrency Bar -->
            <div class="mb-4">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-semibold text-body">Connection Utilization</span>
                <span class="small fw-bold <?= $connPercent >= 100 ? 'text-danger' : 'text-primary' ?>">
                  <?= $activeConsCount ?> / <?= $maxCons ?> Streams (<?= $connPercent ?>%)
                </span>
              </div>
              <div class="progress rounded-pill">
                <div
                  class="progress-bar <?= $connProgressColor ?> progress-bar-striped progress-bar-animated"
                  role="progressbar"
                  aria-valuenow="<?= $connPercent ?>"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  data-progress-percent="<?= $connPercent ?>">
                </div>
              </div>
            </div>

            <!-- Active Sessions List -->
            <?php if (!empty($activeSessions)): ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr class="small text-uppercase">
                      <th>Stream</th>
                      <th>Client IP</th>
                      <th>Device / Player</th>
                      <th>Started</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($activeSessions as $session): ?>
                      <tr>
                        <td>
                          <span class="badge bg-label-primary rounded-pill">#<?= (int)$session['stream_id'] ?></span>
                        </td>
                        <td>
                          <span class="fw-medium"><?= htmlspecialchars($session['user_ip'] ?? 'Unknown') ?></span>
                          <?php if (!empty($session['geoip_country_code'])): ?>
                            <small class="text-body-secondary d-block"><?= htmlspecialchars($session['geoip_country_code']) ?></small>
                          <?php endif; ?>
                        </td>
                        <td>
                          <small class="text-truncate d-block mw-100" title="<?= htmlspecialchars($session['user_agent'] ?? 'Generic') ?>">
                            <?= htmlspecialchars(substr($session['user_agent'] ?? 'Player', 0, 30)) ?>...
                          </small>
                        </td>
                        <td>
                          <small class="text-body-secondary"><?= !empty($session['date_start']) ? date('H:i:s', $session['date_start']) : 'Just now' ?></small>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-center py-5">
                <div class="avatar avatar-xl bg-label-success rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center">
                  <i class="icon-base bx bx-check-double icon-xl text-success"></i>
                </div>
                <h6 class="mb-1 fw-bold">No Active Streams Connected</h6>
                <p class="text-body-secondary small mb-0">
                  All <?= $maxCons ?> streaming slots are currently open and ready for immediate playback.
                </p>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Package & Features Card -->
        <div class="card mb-6 shadow-sm border-0">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-package me-2 text-warning"></i>Plan Features & Capabilities
            </h5>
          </div>
          <div class="card-body pt-3">
            <div class="row g-4">
              <div class="col-sm-6">
                <div class="p-3 border rounded">
                  <div class="d-flex align-items-center mb-1">
                    <i class="icon-base bx bx-layer text-primary icon-md me-2"></i>
                    <span class="fw-semibold">Assigned Bouquets</span>
                  </div>
                  <h5 class="mb-0 fw-bold text-primary"><?= count($userBouquets) ?> Bouquets</h5>
                  <small class="text-body-secondary">Custom curated categories</small>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-3 border rounded">
                  <div class="d-flex align-items-center mb-1">
                    <i class="icon-base bx bx-devices text-info icon-md me-2"></i>
                    <span class="fw-semibold">Multi-Screen Access</span>
                  </div>
                  <h5 class="mb-0 fw-bold text-info"><?= $maxCons ?> Simultaneous</h5>
                  <small class="text-body-secondary">Concurrent viewer capacity</small>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-3 border rounded">
                  <div class="d-flex align-items-center mb-1">
                    <i class="icon-base bx bx-check-shield text-success icon-md me-2"></i>
                    <span class="fw-semibold">Restreaming</span>
                  </div>
                  <h5 class="mb-0 fw-bold text-success"><?= !empty($lineData['is_restreamer']) ? 'Allowed' : 'Standard End-User' ?></h5>
                  <small class="text-body-secondary">Stream relay permission</small>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-3 border rounded">
                  <div class="d-flex align-items-center mb-1">
                    <i class="icon-base bx bx-chip text-secondary icon-md me-2"></i>
                    <span class="fw-semibold">Hardware STB</span>
                  </div>
                  <h5 class="mb-0 fw-bold text-secondary"><?= !empty($lineData['is_mag']) ? 'MAG Enabled' : (!empty($lineData['is_e2']) ? 'Enigma2' : 'Universal (M3U / XC)') ?></h5>
                  <small class="text-body-secondary">Device integration mode</small>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═════════════════════════════════════════════════════════════════
       TAB 2: PLAYLIST & M3U GENERATOR
  ══════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="tab-playlist" role="tabpanel">
    <div class="row g-6">
      <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 mb-6">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-download me-2 text-primary"></i>M3U Playlist & EPG Link Generator
            </h5>
            <p class="text-body-secondary small mb-0 mt-1">
              Select your favorite IPTV application or device format to generate customized download and streaming links.
            </p>
          </div>
          <div class="card-body pt-3">
            <div class="row g-4 mb-4">
              <!-- Device Format Selector -->
              <div class="col-12 col-md-6">
                <label class="form-label fw-semibold" for="playlist-device-select">Device / Output Format</label>
                <select class="form-select" id="playlist-device-select">
                  <optgroup label="Standard M3U Formats (Recommended)">
                    <option value="m3u_plus_hls" selected>M3U Plus — HLS (m3u8, Best Compatibility)</option>
                    <option value="m3u_plus_ts">M3U Plus — MPEG-TS (ts, Fast Zapping)</option>
                    <option value="m3u_standard">M3U Standard (Plain text format)</option>
                  </optgroup>
                  <?php if (!empty($outputDevices)): ?>
                    <optgroup label="Hardware Receivers & Set-Top Boxes">
                      <?php foreach ($outputDevices as $dev): ?>
                        <option value="<?= htmlspecialchars($dev['device_key']) ?>">
                          <?= htmlspecialchars($dev['device_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Output Category Filter -->
              <div class="col-12 col-md-6">
                <label class="form-label fw-semibold" for="playlist-output-select">Included Catalog Content</label>
                <select class="form-select" id="playlist-output-select">
                  <option value="" selected>Everything (Live TV, Movies, Series, Radio)</option>
                  <option value="live">Live TV Channels Only</option>
                  <option value="movie">VOD Movies Only</option>
                  <option value="series">TV Series Only</option>
                  <option value="radio_streams">Radio Stations Only</option>
                </select>
              </div>
            </div>

            <!-- Generated M3U URL -->
            <div class="mb-4">
              <label class="form-label fw-semibold" for="playlist-generated-url">Generated M3U Playlist URL</label>
              <div class="input-group">
                <input
                  type="text"
                  id="playlist-generated-url"
                  class="form-control"
                  data-server-base="<?= htmlspecialchars($serverPublicUrl) ?>"
                  data-username="<?= $username ?>"
                  data-password="<?= $password ?>"
                  readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="playlist-generated-url">
                  <i class="icon-base bx bx-copy icon-sm me-1"></i> Copy URL
                </button>
                <a href="#" id="playlist-download-btn" class="btn btn-primary" download="playlist.m3u">
                  <i class="icon-base bx bx-download icon-sm me-1"></i> Download .m3u
                </a>
              </div>
              <small class="text-body-secondary d-block mt-1">
                Paste this URL directly into IPTV Smarters, TiviMate, VLC Media Player, or Apple TV.
              </small>
            </div>

            <!-- XMLTV EPG URL -->
            <div class="mb-2">
              <label class="form-label fw-semibold" for="epg-generated-url">Electronic Program Guide (XMLTV / EPG URL)</label>
              <div class="input-group">
                <input
                  type="text"
                  id="epg-generated-url"
                  class="form-control"
                  value="<?= htmlspecialchars($serverPublicUrl) ?>/xmltv.php?username=<?= $username ?>&password=<?= $password ?>"
                  readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="epg-generated-url">
                  <i class="icon-base bx bx-copy icon-sm me-1"></i> Copy EPG URL
                </button>
              </div>
              <small class="text-body-secondary d-block mt-1">
                Provides TV program schedules, channel logos, and upcoming broadcast timelines.
              </small>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Integration Guides -->
      <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 mb-6">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-help-circle me-2 text-info"></i>Quick App Setup
            </h5>
          </div>
          <div class="card-body pt-3">
            <div class="mb-4">
              <h6 class="fw-bold mb-1 text-primary"><i class="icon-base bx bx-play-circle me-1"></i>VLC Media Player</h6>
              <p class="small text-body-secondary mb-0">
                Open VLC &rarr; Click <strong>Media</strong> &rarr; <strong>Open Network Stream</strong> &rarr; Paste your generated M3U URL &rarr; Play.
              </p>
            </div>
            <div class="mb-4">
              <h6 class="fw-bold mb-1 text-success"><i class="icon-base bx bx-mobile me-1"></i>TiviMate & Smart TV</h6>
              <p class="small text-body-secondary mb-0">
                Add Playlist &rarr; Select <strong>M3U Playlist</strong> &rarr; Enter URL &rarr; Enter EPG URL when prompted &rarr; Done.
              </p>
            </div>
            <div class="mb-0">
              <h6 class="fw-bold mb-1 text-warning"><i class="icon-base bx bx-laptop me-1"></i>IPTV Smarters Pro</h6>
              <p class="small text-body-secondary mb-0">
                Select <strong>Load Your Playlist or File/URL</strong> &rarr; Name your playlist &rarr; Paste M3U URL &rarr; Save.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═════════════════════════════════════════════════════════════════
       TAB 3: BOUQUETS ORDERING
  ══════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="tab-bouquets" role="tabpanel">
    <div class="row g-6">
      <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 mb-6">
          <div class="card-header pb-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
              <h5 class="card-title mb-0 fw-bold">
                <i class="icon-base bx bx-layer me-2 text-primary"></i>Bouquet Ordering & Priority
              </h5>
              <p class="text-body-secondary small mb-0 mt-1">
                Click a bouquet to select it, then use the controls to arrange the order channels appear in your players.
              </p>
            </div>
            <!-- Toolbar -->
            <div class="d-flex align-items-center gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bouquet-up" title="Move Selected Bouquet Up">
                <i class="icon-base bx bx-up-arrow-alt me-1"></i> Move Up
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bouquet-down" title="Move Selected Bouquet Down">
                <i class="icon-base bx bx-down-arrow-alt me-1"></i> Move Down
              </button>
              <button type="button" class="btn btn-sm btn-outline-primary" id="btn-bouquet-az" title="Sort Alphabetically">
                <i class="icon-base bx bx-sort-a-z me-1"></i> Sort A-Z
              </button>
              <button type="button" class="btn btn-sm btn-primary" id="btn-save-bouquets" data-save-url="<?= $baseUrl ?>profile">
                <i class="icon-base bx bx-save me-1"></i> Save Changes
              </button>
            </div>
          </div>
          <div class="card-body pt-3">
            <?php if (!empty($userBouquets)): ?>
              <div class="list-group bouquet-sort-list" id="bouquet-sort-container">
                <?php foreach ($userBouquets as $idx => $b): ?>
                  <div
                    class="list-group-item list-group-item-action d-flex align-items-center justify-content-between bouquet-sort-item rounded mb-2 border"
                    data-bouquet-id="<?= (int)$b['id'] ?>"
                    data-bouquet-name="<?= htmlspecialchars($b['name']) ?>">
                    <div class="d-flex align-items-center">
                      <i class="icon-base bx bx-menu me-3 text-body-secondary"></i>
                      <div>
                        <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($b['name']) ?></h6>
                        <small class="text-body-secondary">Bouquet ID: #<?= (int)$b['id'] ?></small>
                      </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <span class="badge bg-label-primary rounded-pill px-2">
                        <i class="icon-base bx bx-tv me-1"></i><?= $b['channels_count'] ?> Live
                      </span>
                      <?php if (!empty($b['series_count'])): ?>
                        <span class="badge bg-label-info rounded-pill px-2">
                          <i class="icon-base bx bx-movie-play me-1"></i><?= $b['series_count'] ?> Series
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-center py-5">
                <i class="icon-base bx bx-layer-minus icon-xl text-body-secondary mb-2"></i>
                <h6 class="fw-bold">No Custom Bouquets Assigned</h6>
                <p class="text-body-secondary small mb-0">All channels are loaded in default system order.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Bouquet Guide Card -->
      <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 mb-6">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-info-circle me-2 text-warning"></i>How It Works
            </h5>
          </div>
          <div class="card-body pt-3">
            <p class="small text-body-secondary mb-3">
              Your bouquet order determines the hierarchy of category groups in both the Web Player and exported M3U playlists.
            </p>
            <ul class="list-unstyled small mb-0">
              <li class="mb-2"><i class="icon-base bx bx-check text-success me-1"></i> Select any bouquet to highlight it.</li>
              <li class="mb-2"><i class="icon-base bx bx-check text-success me-1"></i> Click <strong>Move Up</strong> or <strong>Move Down</strong> to reposition.</li>
              <li class="mb-2"><i class="icon-base bx bx-check text-success me-1"></i> Click <strong>Sort A-Z</strong> to sort all alphabetically.</li>
              <li class="mb-0"><i class="icon-base bx bx-check text-success me-1"></i> Hit <strong>Save Changes</strong> to apply order globally.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═════════════════════════════════════════════════════════════════
       TAB 4: DEVICE & API SETUP
  ══════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="tab-api" role="tabpanel">
    <div class="row g-6">
      <div class="col-12 col-lg-7">
        <div class="card shadow-sm border-0 mb-6">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-code-curly me-2 text-primary"></i>Xtream Codes API Parameters
            </h5>
            <p class="text-body-secondary small mb-0 mt-1">
              Use these credentials to log in to applications that support Xtream Codes API (e.g. IPTV Smarters, XCIPTV, TiviMate).
            </p>
          </div>
          <div class="card-body pt-3">
            <!-- Server URL -->
            <div class="mb-4">
              <label class="form-label small text-uppercase fw-semibold text-body-secondary" for="api-server-url">Server URL / Portal URL</label>
              <div class="input-group">
                <span class="input-group-text bg-body-secondary border-0"><i class="icon-base bx bx-globe text-body-secondary"></i></span>
                <input type="text" id="api-server-url" class="form-control" value="<?= htmlspecialchars($serverPublicUrl) ?>" readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="api-server-url">
                  <i class="icon-base bx bx-copy icon-sm me-1"></i> Copy
                </button>
              </div>
            </div>

            <!-- Username -->
            <div class="mb-4">
              <label class="form-label small text-uppercase fw-semibold text-body-secondary" for="api-username">Username</label>
              <div class="input-group">
                <span class="input-group-text bg-body-secondary border-0"><i class="icon-base bx bx-user text-body-secondary"></i></span>
                <input type="text" id="api-username" class="form-control" value="<?= $username ?>" readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="api-username">
                  <i class="icon-base bx bx-copy icon-sm me-1"></i> Copy
                </button>
              </div>
            </div>

            <!-- Password -->
            <div class="mb-4">
              <label class="form-label small text-uppercase fw-semibold text-body-secondary" for="api-password">Password</label>
              <div class="input-group">
                <span class="input-group-text bg-body-secondary border-0"><i class="icon-base bx bx-key text-body-secondary"></i></span>
                <input type="text" id="api-password" class="form-control" value="<?= $password ?>" readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="api-password">
                  <i class="icon-base bx bx-copy icon-sm me-1"></i> Copy
                </button>
              </div>
            </div>

            <!-- Direct API Endpoint URL -->
            <div class="mb-2">
              <label class="form-label small text-uppercase fw-semibold text-body-secondary" for="api-player-endpoint">Direct Player API Endpoint</label>
              <div class="input-group">
                <input
                  type="text"
                  id="api-player-endpoint"
                  class="form-control"
                  value="<?= htmlspecialchars($serverPublicUrl) ?>/player_api.php?username=<?= $username ?>&password=<?= $password ?>"
                  readonly />
                <button class="btn btn-outline-primary" type="button" data-copy-target="api-player-endpoint">
                  <i class="icon-base bx bx-copy icon-sm me-1"></i> Copy Endpoint
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- App Card Snippets -->
      <div class="col-12 col-lg-5">
        <div class="card shadow-sm border-0 mb-6">
          <div class="card-header pb-2">
            <h5 class="card-title mb-0 fw-bold">
              <i class="icon-base bx bx-mobile-alt me-2 text-success"></i>Supported Applications
            </h5>
          </div>
          <div class="card-body pt-3">
            <ul class="list-unstyled mb-0">
              <li class="d-flex align-items-center justify-content-between py-3 border-bottom">
                <div class="d-flex align-items-center">
                  <div class="avatar avatar-sm bg-label-primary rounded me-3 d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-tv text-primary"></i>
                  </div>
                  <div>
                    <h6 class="mb-0 fw-semibold">IPTV Smarters Pro</h6>
                    <small class="text-body-secondary">Android, iOS, Windows, Mac</small>
                  </div>
                </div>
                <span class="badge bg-label-success rounded-pill">Xtream API</span>
              </li>
              <li class="d-flex align-items-center justify-content-between py-3 border-bottom">
                <div class="d-flex align-items-center">
                  <div class="avatar avatar-sm bg-label-info rounded me-3 d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-devices text-info"></i>
                  </div>
                  <div>
                    <h6 class="mb-0 fw-semibold">TiviMate IPTV Player</h6>
                    <small class="text-body-secondary">Android TV, Fire TV</small>
                  </div>
                </div>
                <span class="badge bg-label-success rounded-pill">Xtream API</span>
              </li>
              <li class="d-flex align-items-center justify-content-between py-3 border-bottom">
                <div class="d-flex align-items-center">
                  <div class="avatar avatar-sm bg-label-warning rounded me-3 d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-play text-warning"></i>
                  </div>
                  <div>
                    <h6 class="mb-0 fw-semibold">XCIPTV Player</h6>
                    <small class="text-body-secondary">Android, Fire TV, Smart TV</small>
                  </div>
                </div>
                <span class="badge bg-label-success rounded-pill">Xtream API</span>
              </li>
              <li class="d-flex align-items-center justify-content-between py-3">
                <div class="d-flex align-items-center">
                  <div class="avatar avatar-sm bg-label-danger rounded me-3 d-flex align-items-center justify-content-center">
                    <i class="icon-base bx bx-video text-danger"></i>
                  </div>
                  <div>
                    <h6 class="mb-0 fw-semibold">VLC / Kodi / Apple TV</h6>
                    <small class="text-body-secondary">Universal streaming players</small>
                  </div>
                </div>
                <span class="badge bg-label-primary rounded-pill">M3U Playlist</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═════════════════════════════════════════════════════════════════
       TAB 5: SWITCH ACCOUNTS & PROFILES (التبديل بين الحسابات)
  ══════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane fade" id="tab-accounts" role="tabpanel">

    <!-- Active Connected Account Card -->
    <div class="card shadow-sm border-0 mb-6 border-start border-primary border-4">
      <div class="card-body p-4">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
          <div class="d-flex align-items-center gap-3">
            <div class="avatar avatar-lg bg-label-primary rounded-circle d-flex align-items-center justify-content-center fw-bold fs-4">
              <?= strtoupper(substr($lineData['username'] ?? 'S', 0, 2)) ?>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <h5 class="mb-0 fw-bold"><?= $username ?></h5>
                <span class="badge bg-success rounded-pill px-3 py-1">
                  <span class="badge-dot bg-white me-1"></span> Current Active Account
                </span>
                <?php if (!empty($lineData['is_trial'])): ?>
                  <span class="badge bg-label-warning rounded-pill">Trial</span>
                <?php endif; ?>
              </div>
              <div class="d-flex align-items-center gap-3 flex-wrap text-body-secondary small">
                <span><i class="icon-base bx bx-id-card me-1 text-primary"></i>ID: #<?= (int)$lineData['id'] ?></span>
                <span><i class="icon-base bx bx-time-five me-1 text-info"></i>Expires: <?= $expTimestamp ? date('M j, Y', $expTimestamp) : 'Unlimited' ?></span>
                <span><i class="icon-base bx bx-wifi me-1 text-warning"></i>Connections: <?= $activeConsCount ?> / <?= $maxCons ?></span>
              </div>
            </div>
          </div>

          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddAccount">
              <i class="icon-base bx bx-user-plus icon-sm me-1"></i> Add Another Account
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Saved Accounts on this Device Section -->
    <div class="card shadow-sm border-0 mb-6">
      <div class="card-header d-flex align-items-center justify-content-between pb-3 flex-wrap gap-2">
        <div>
          <h5 class="card-title mb-1 fw-bold">
            <i class="icon-base bx bx-devices me-2 text-primary"></i>Saved Accounts on this Device
          </h5>
          <small class="text-body-secondary">
            Switch between accounts or activation codes with a single click without signing out.
          </small>
        </div>
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAddAccount">
            <i class="icon-base bx bx-plus me-1"></i> Add Account
          </button>
          <button type="button" class="btn btn-sm btn-label-danger" id="btn-profile-clear-accounts" style="display: none;">
            <i class="icon-base bx bx-trash me-1"></i> Clear All
          </button>
        </div>
      </div>
      <div class="card-body pt-0">
        <!-- Dynamic list rendered by player-profile.js -->
        <div id="profile-saved-accounts-list" class="row g-4"></div>
      </div>
    </div>

  </div>

</div>

<!-- ─── Modal: Add Another Account / Switch Account ────────────────── -->
<div class="modal fade" id="modalAddAccount" tabindex="-1" aria-labelledby="modalAddAccountLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header pb-2">
        <h5 class="modal-title fw-bold" id="modalAddAccountLabel">
          <i class="icon-base bx bx-user-plus me-2 text-primary"></i>Add & Switch Account
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2">
        <p class="text-body-secondary small mb-4">
          Add another subscriber account or smart activation code to this device for instant 1-click switching.
        </p>

        <!-- Dynamic alert container inside modal -->
        <div id="modal-account-alert"></div>

        <!-- Nav Pills inside Modal -->
        <ul class="nav nav-pills auth-nav-pills nav-fill mb-4" role="tablist">
          <li class="nav-item" role="presentation">
            <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#modal-tab-account" role="tab">
              <i class="icon-base bx bx-user icon-sm me-2"></i> Subscriber Account
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#modal-tab-code" role="tab">
              <i class="icon-base bx bx-key icon-sm me-2"></i> Activation Code
            </button>
          </li>
        </ul>

        <div class="tab-content p-0 border-0 bg-transparent">
          <!-- Modal Tab 1: Username & Password -->
          <div class="tab-pane fade show active" id="modal-tab-account" role="tabpanel">
            <form id="formModalAddAccount">
              <div class="mb-3">
                <label for="modal-input-username" class="form-label fw-semibold">Subscriber Username</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="icon-base bx bx-user"></i></span>
                  <input type="text" class="form-control" id="modal-input-username" placeholder="Subscriber username" autocomplete="username" required />
                </div>
              </div>
              <div class="mb-4 form-password-toggle">
                <label for="modal-input-password" class="form-label fw-semibold">Password</label>
                <div class="input-group input-group-merge">
                  <span class="input-group-text"><i class="icon-base bx bx-lock-alt"></i></span>
                  <input type="password" class="form-control" id="modal-input-password" placeholder="Subscriber password" autocomplete="current-password" required />
                  <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
                </div>
              </div>
              <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btn-modal-submit-account">
                  <i class="icon-base bx bx-sync me-1"></i> Add & Switch Now
                </button>
              </div>
            </form>
          </div>

          <!-- Modal Tab 2: Activation Code -->
          <div class="tab-pane fade" id="modal-tab-code" role="tabpanel">
            <form id="formModalAddCode">
              <div class="mb-4">
                <label for="modal-input-code" class="form-label fw-semibold">Smart Activation Code</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="icon-base bx bx-key"></i></span>
                  <input type="text" class="form-control code-input-formatted fs-5" id="modal-input-code" placeholder="ENTER CODE" maxlength="32" required />
                  <button class="btn btn-outline-secondary" type="button" id="btn-modal-paste-code">
                    <i class="icon-base bx bx-paste me-1"></i> Paste
                  </button>
                </div>
                <div class="form-text small mt-2 text-body-secondary">
                  If this is a stock activation code, its duration timer will start immediately.
                </div>
              </div>
              <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btn-modal-submit-code">
                  <i class="icon-base bx bx-zap me-1"></i> Activate & Switch Now
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Current Authenticated Subscriber Global Object -->
<script>
  window.CURRENT_SUBSCRIBER = {
    id: <?= (int)$lineData['id'] ?>,
    username: <?= json_encode($lineData['username'] ?? '') ?>,
    password: <?= json_encode($lineData['password'] ?? '') ?>,
    name: <?= json_encode(!empty($activationCode) ? 'Code: ' . $activationCode : ($lineData['username'] ?? '')) ?>,
    activation_code: <?= json_encode($activationCode ?? '') ?>,
    exp_date: <?= (int)($expTimestamp ?? 0) ?>,
    exp_date_formatted: <?= json_encode($expTimestamp ? date('Y-m-d H:i:s', $expTimestamp) : 'Unlimited') ?>,
    type: <?= json_encode(!empty($activationCode) ? 'code' : 'credentials') ?>,
    added_at: <?= (int)($lineData['created_at'] ?? time()) ?>
  };
</script>

<!-- Player Profile Controller Script -->
<script src="<?= $assetsPath ?>js/player-profile.js"></script>
