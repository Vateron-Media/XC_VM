<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';
?>
              </div>
              <!-- / spa-content-target -->

              <!-- ================================================================= -->
              <!-- GLOBAL PERSISTENT RADIO MINI-PLAYER BAR (INSIDE CONTENT CONTAINER)-->
              <!-- ================================================================= -->
              <audio id="radio-audio" preload="none" class="d-none"></audio>
              <div
                id="radio-bottom-bar"
                class="bg-body border rounded-3 shadow-lg px-3 px-md-4 py-2 py-md-3 d-none align-items-center justify-content-between gap-3 z-3">
              <!-- Left: Station Branding & Status -->
              <div class="d-flex align-items-center gap-3 min-w-0 flex-grow-1 flex-md-grow-0">
                <div class="avatar avatar-md flex-shrink-0">
                  <img
                    id="radio-bar-logo"
                    src=""
                    alt="Station"
                    class="rounded p-1 bg-body-secondary border d-none object-fit-contain" />
                  <span id="radio-bar-avatar-placeholder" class="avatar-initial rounded bg-label-primary">
                    <i class="icon-base bx bx-broadcast fs-4"></i>
                  </span>
                </div>
                <div class="min-w-0">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <h6 class="mb-0 fw-bold text-truncate text-heading" id="radio-bar-title">Radio Broadcast</h6>
                    <span class="badge bg-danger rounded-pill px-2 py-0">
                      <span class="badge-dot bg-white me-1"></span> ON AIR
                    </span>
                  </div>
                  <div class="d-flex align-items-center gap-2 text-body-secondary small text-truncate">
                    <span class="badge bg-label-primary rounded-pill px-2 py-0 d-none d-sm-inline-block" id="radio-bar-badge">Live HQ</span>
                    <span class="text-truncate" id="radio-bar-subtitle">Live Audiophile Stream</span>
                  </div>
                </div>
              </div>

              <!-- Center: Audio Playback Controls & Status -->
              <div class="d-flex flex-column align-items-center justify-content-center gap-1 flex-shrink-0">
                <div class="d-flex align-items-center gap-2">
                  <button
                    type="button"
                    class="btn btn-sm btn-icon btn-label-secondary rounded-pill"
                    id="btn-radio-bar-prev"
                    title="Previous Station">
                    <i class="icon-base bx bx-skip-previous fs-4"></i>
                  </button>
                  <button
                    type="button"
                    class="btn btn-primary btn-icon rounded-circle shadow-sm"
                    id="btn-radio-bar-play"
                    title="Play / Pause">
                    <i class="icon-base bx bx-play fs-4"></i>
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-icon btn-label-secondary rounded-pill"
                    id="btn-radio-bar-next"
                    title="Next Station">
                    <i class="icon-base bx bx-skip-next fs-4"></i>
                  </button>
                </div>
                <!-- Small status indicator -->
                <div class="d-none d-sm-flex align-items-center gap-1 small text-body-secondary">
                  <span class="badge-dot bg-success me-1" id="radio-bar-indicator-dot"></span>
                  <span id="radio-bar-status-text" class="small">Live Audiophile Stream</span>
                </div>
              </div>

              <!-- Right: Volume, Favorite, Expand, and Close -->
              <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <!-- Volume Control -->
                <div class="d-none d-md-flex align-items-center gap-2 me-1">
                  <button
                    type="button"
                    class="btn btn-sm btn-icon btn-label-secondary rounded-pill"
                    id="btn-radio-bar-mute"
                    title="Mute / Unmute">
                    <i class="icon-base bx bx-volume-full"></i>
                  </button>
                  <input
                    type="range"
                    class="form-range w-px-100"
                    id="radio-bar-volume"
                    min="0"
                    max="1"
                    step="0.05"
                    value="1"
                    title="Volume" />
                </div>

                <!-- Favorite Button -->
                <button
                  type="button"
                  class="btn btn-sm btn-icon btn-label-secondary rounded-pill"
                  id="btn-radio-bar-fav"
                  title="Favorite">
                  <i class="icon-base bx bx-star"></i>
                </button>

                <!-- Expand to Studio Player Button -->
                <button
                  type="button"
                  class="btn btn-sm btn-label-primary rounded-pill d-flex align-items-center gap-1 px-3"
                  id="btn-radio-bar-expand"
                  title="Return to Studio Stage">
                  <i class="icon-base bx bx-chevron-up fs-4"></i>
                  <span class="d-none d-md-inline fw-semibold">Studio</span>
                </button>

                <!-- Close / Stop Button -->
                <button
                  type="button"
                  class="btn btn-sm btn-icon btn-label-danger rounded-pill"
                  id="btn-radio-bar-close"
                  title="Stop & Close">
                  <i class="icon-base bx bx-x fs-4"></i>
                </button>
              </div>
            </div>
          </div>
          <!-- / Content -->

            <!-- Footer -->
            <footer class="content-footer footer bg-footer-theme">
              <div class="container-xxl">
                <div
                  class="footer-container d-flex align-items-center justify-content-between py-4 flex-md-row flex-column">
                  <div class="text-body small">
                    &copy; <?= date('Y') ?>, <strong class="text-heading"><?= htmlspecialchars($serverName) ?></strong> Web Player V2.
                  </div>
                  <div class="d-none d-lg-inline-block">
                    <span class="badge bg-label-success rounded-pill px-3 py-1">Online &bull; High-Definition Streaming</span>
                  </div>
                </div>
              </div>
            </footer>
            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>

      <!-- Overlay -->
      <div class="layout-overlay layout-menu-toggle"></div>

      <!-- Drag Target Area To SlideIn Menu On Small Screens -->
      <div class="drag-target"></div>
    </div>
    <!-- / Layout wrapper -->

    <!-- Core JS (Pure Sneat Full Version) -->
    <script src="<?= $assetsPath ?>vendor/libs/jquery/jquery.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/popper/popper.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/bootstrap.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/menu.js"></script>
    <script src="<?= $assetsPath ?>js/main.js"></script>

    <!-- Theme Switcher Runtime Handler -->
    <script src="<?= $assetsPath ?>js/player-theme.js"></script>

    <!-- Catalog & Data Sync Handler -->
    <script src="<?= $assetsPath ?>js/player-sync.js"></script>

    <!-- Global Cross-Catalog Search Engine -->
    <script src="<?= $assetsPath ?>js/player-search.js"></script>

    <!-- Global Favorites & Starred Vault Handler -->
    <script src="<?= $assetsPath ?>js/player-favorites.js"></script>

    <!-- Video & Audio Streaming Runtimes (Video.js & HLS) -->
    <script src="<?= $assetsPath ?>vendor/libs/videojs/video.min.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/hls/hls.js"></script>

    <!-- Page Explorer Engines (Resident in persistent SPA shell) -->
    <script src="<?= $assetsPath ?>js/player-home.js"></script>
    <script src="<?= $assetsPath ?>js/player-movies.js"></script>
    <script src="<?= $assetsPath ?>js/player-series.js"></script>
    <script src="<?= $assetsPath ?>js/player-live.js"></script>
    <script src="<?= $assetsPath ?>js/player-radio.js"></script>
    <script src="<?= $assetsPath ?>js/player-profile.js"></script>

    <!-- Universal Single Page Application (SPA) Engine -->
    <script src="<?= $assetsPath ?>js/player-spa.js"></script>
  </body>
</html>
