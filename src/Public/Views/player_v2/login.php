<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';
?>
<!doctype html>
<html
  lang="en"
  class="layout-wide customizer-hide"
  dir="ltr"
  data-skin="default"
  data-assets-path="<?= $assetsPath ?>"
  data-template="vertical-menu-template"
  data-bs-theme="dark">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

    <title>Sign In - <?= htmlspecialchars($serverName) ?> Web Player</title>

    <!-- Immediate Theme Application -->
    <script>
      (function () {
        var storedTheme = localStorage.getItem('templateCustomizer-vertical-menu-template--Theme') || 'dark';
        if (storedTheme === 'system') {
          storedTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-bs-theme', storedTheme);
      })();
    </script>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= $assetsPath ?>img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet" />

    <!-- Icons -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/fonts/iconify-icons.css" />

    <!-- Core CSS (Pure Sneat Full Version) -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/pickr/pickr-themes.css" />
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/css/core.css" />
    <link rel="stylesheet" href="<?= $assetsPath ?>css/demo.css?v=2" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <!-- Page CSS -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/css/pages/page-auth.css" />

    <!-- Helpers & Config -->
    <script src="<?= $assetsPath ?>vendor/js/helpers.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/pickr/pickr.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/template-customizer.js"></script>
    <script src="<?= $assetsPath ?>js/config.js"></script>
    <script defer src="<?= $assetsPath ?>vendor/libs/tsparticles/tsparticles.engine.min.js"></script>
    <script defer src="<?= $assetsPath ?>vendor/libs/tsparticles/tsparticles.slim.bundle.min.js"></script>
    <script defer src="<?= $assetsPath ?>js/login-particles.js?v=6"></script>
  </head>

  <body>
    <div id="tsparticles" class="login-particles" aria-hidden="true"></div>
    <!-- Login Main Wrapper -->
    <div class="container-fluid p-0">
      <div class="row g-0 login-split-wrapper">
        <div class="col-12 login-auth-col">
          <div class="login-auth-card">

            <!-- Brand & Theme Switcher -->
            <div class="d-flex align-items-center justify-content-between mb-4">
              <a href="<?= $baseUrl ?>login" class="d-flex align-items-center gap-3 text-decoration-none">
                <img class="login-brand-logo" src="/assets/admin/img/logo-topbar.png" alt="<?= htmlspecialchars($serverName) ?>" />
                <div class="d-flex flex-column">
                  <small class="text-body-secondary">Web Player</small>
                </div>
              </a>

              <!-- Theme Switcher Dropdown -->
              <div class="dropdown">
                <button
                  class="btn btn-sm btn-icon btn-label-secondary rounded-pill dropdown-toggle hide-arrow shadow-sm"
                  type="button"
                  id="nav-theme"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                  title="Toggle Theme">
                  <i class="icon-base bx bx-moon icon-md theme-icon-active text-heading"></i>
                  <span class="d-none ms-2" id="nav-theme-text">Toggle theme</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 py-2" aria-labelledby="nav-theme">
                  <li class="dropdown-header text-uppercase text-xs fw-semibold px-4 pt-1 pb-2">Theme</li>
                  <li>
                    <button type="button" class="dropdown-item d-flex align-items-center py-2 px-4" data-bs-theme-value="light">
                      <i class="icon-base bx bx-sun icon-md me-3 text-warning"></i>
                      <span>Light</span>
                    </button>
                  </li>
                  <li>
                    <button type="button" class="dropdown-item d-flex align-items-center py-2 px-4" data-bs-theme-value="dark">
                      <i class="icon-base bx bx-moon icon-md me-3 text-primary"></i>
                      <span>Dark</span>
                    </button>
                  </li>
                  <li>
                    <button type="button" class="dropdown-item d-flex align-items-center py-2 px-4" data-bs-theme-value="system">
                      <i class="icon-base bx bx-desktop icon-md me-3 text-info"></i>
                      <span>System</span>
                    </button>
                  </li>
                </ul>
              </div>
            </div>

            <!-- Header Title -->
            <div class="mb-4">
              <h3 class="fw-bold mb-1 text-heading">Welcome Back <i class="icon-base bx bx-happy text-warning" aria-hidden="true"></i></h3>
              <p class="text-body-secondary mb-0">Select your preferred login method to continue</p>
            </div>

            <!-- Dynamic Alert Container for Errors and Notifications -->
            <div id="login-alert-container">
              <?php if (!empty($_ERROR_MSG)): ?>
                <div class="alert alert-danger alert-dismissible d-flex align-items-center gap-2 mb-4" role="alert">
                  <i class="icon-base bx bx-error-circle fs-4 flex-shrink-0"></i>
                  <div class="flex-grow-1"><?= htmlspecialchars($_ERROR_MSG) ?></div>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>
            </div>

            <!-- ─── Sneat Nav-Pills Tabs (3 Distinctive Options) ─────── -->
            <ul class="nav nav-pills auth-nav-pills nav-fill mb-4" role="tablist">
              <li class="nav-item" role="presentation">
                <button
                  type="button"
                  class="nav-link active"
                  role="tab"
                  data-bs-toggle="tab"
                  data-bs-target="#tab-account"
                  aria-controls="tab-account"
                  aria-selected="true">
                  <i class="icon-base bx bx-user icon-sm me-2"></i> Account
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button
                  type="button"
                  class="nav-link"
                  role="tab"
                  data-bs-toggle="tab"
                  data-bs-target="#tab-code"
                  aria-controls="tab-code"
                  aria-selected="false">
                  <i class="icon-base bx bx-key icon-sm me-2"></i> Active Code
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button
                  type="button"
                  class="nav-link"
                  role="tab"
                  data-bs-toggle="tab"
                  data-bs-target="#tab-xtream"
                  aria-controls="tab-xtream"
                  aria-selected="false">
                  <i class="icon-base bx bx-server icon-sm me-1"></i> Xtream API
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button
                  type="button"
                  class="nav-link"
                  role="tab"
                  data-bs-toggle="tab"
                  data-bs-target="#tab-playlist"
                  aria-controls="tab-playlist"
                  aria-selected="false">
                  <i class="icon-base bx bx-link-alt icon-sm me-1"></i> Playlist URL
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button
                  type="button"
                  class="nav-link"
                  role="tab"
                  data-bs-toggle="tab"
                  data-bs-target="#tab-saved"
                  aria-controls="tab-saved"
                  aria-selected="false">
                  <i class="icon-base bx bx-group icon-sm me-1"></i> Saved <span class="badge bg-label-secondary ms-1" id="saved-count-badge">0</span>
                </button>
              </li>
            </ul>

            <!-- ─── Tab Content Panels ─────────────────────────────── -->
            <div class="tab-content p-0 border-0 shadow-none bg-transparent">

              <!-- ═════════════════════════════════════════════════════
                   TAB 1: DIRECT SUBSCRIBER ACCOUNT LOGIN
              ══════════════════════════════════════════════════════ -->
              <div class="tab-pane fade show active" id="tab-account" role="tabpanel">
                <form id="formAuthentication" action="<?= $baseUrl ?>login" method="POST" novalidate>
                  <div class="mb-4">
                    <label for="username" class="form-label fw-semibold">Subscriber Username</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="icon-base bx bx-user"></i></span>
                      <input
                        type="text"
                        class="form-control"
                        id="username"
                        name="username"
                        placeholder="Enter your subscriber username"
                        autocomplete="username"
                        required
                        autofocus />
                    </div>
                  </div>

                  <div class="mb-4 form-password-toggle">
                    <div class="d-flex justify-content-between align-items-center">
                      <label class="form-label fw-semibold" for="password">Password</label>
                    </div>
                    <div class="input-group input-group-merge">
                      <span class="input-group-text"><i class="icon-base bx bx-lock-alt"></i></span>
                      <input
                        type="password"
                        id="password"
                        class="form-control"
                        name="password"
                        placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                        aria-describedby="password"
                        autocomplete="current-password"
                        required />
                      <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
                    </div>
                  </div>

                  <div class="mb-4 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="remember-me" checked />
                      <label class="form-check-label text-body-secondary small" for="remember-me">
                        Remember & save account on this device
                      </label>
                    </div>
                  </div>

                  <div class="mb-3">
                    <button class="btn btn-primary d-flex align-items-center justify-content-center gap-2 w-100 py-2 fw-semibold shadow-sm" type="submit" id="btn-submit-account">
                      <i class="icon-base bx bx-log-in"></i> Sign In to Account
                    </button>
                  </div>
                </form>
              </div>

              <!-- ═════════════════════════════════════════════════════
                   TAB 2: NATIVE SMART ACTIVATION CODE LOGIN
              ══════════════════════════════════════════════════════ -->
              <div class="tab-pane fade" id="tab-code" role="tabpanel">
                <form id="formActivationCode" novalidate>
                  <div class="mb-4">
                    <label for="activation_code" class="form-label fw-semibold">Smart Activation Code</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="icon-base bx bx-key"></i></span>
                      <input
                        type="text"
                        class="form-control code-input-formatted fs-5"
                        id="activation_code"
                        name="activation_code"
                        placeholder="ENTER CODE"
                        maxlength="32"
                        autocomplete="off"
                        required />
                      <button class="btn btn-outline-secondary" type="button" id="btn-paste-code" title="Paste from clipboard">
                        <i class="icon-base bx bx-paste me-1"></i> Paste
                      </button>
                    </div>
                    <div class="form-text small mt-2 text-body-secondary d-flex align-items-start gap-1">
                      <i class="icon-base bx bx-info-circle text-primary mt-1 flex-shrink-0"></i>
                      <span>Instant Stock Activation: Your subscription begins its duration countdown upon first activation.</span>
                    </div>
                  </div>

                  <div class="alert alert-label-info d-flex align-items-center gap-2 mb-4 p-3 rounded" role="alert">
                    <i class="icon-base bx bx-shield-quarter fs-4 text-info flex-shrink-0"></i>
                    <div class="small">
                      Activation codes are verified automatically against the central database and securely bound to this player.
                    </div>
                  </div>

                  <div class="mb-4 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="remember-code" checked />
                      <label class="form-check-label text-body-secondary small" for="remember-code">
                        Remember & save activation code on this device
                      </label>
                    </div>
                  </div>

                  <div class="mb-3">
                    <button class="btn btn-primary d-flex align-items-center justify-content-center gap-2 w-100 py-2 fw-semibold shadow-sm" type="submit" id="btn-submit-code">
                      <i class="icon-base bx bx-bolt-circle"></i> Activate & Launch Stream
                    </button>
                  </div>
                </form>
              </div>

              <!-- ═════════════════════════════════════════════════════
                   TAB 3: CUSTOM XTREAM CODES SERVER (HOST:PORT)
              ══════════════════════════════════════════════════════ -->
              <div class="tab-pane fade" id="tab-xtream" role="tabpanel">
                <form id="formXtreamServer" novalidate>
                  <div class="mb-3">
                    <label for="xtream-server" class="form-label fw-semibold">Server Host & Port</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="icon-base bx bx-server"></i></span>
                      <input
                        type="text"
                        class="form-control font-monospace"
                        id="xtream-server"
                        name="server"
                        placeholder="http://domain.com:8080"
                        autocomplete="off"
                        required />
                    </div>
                    <div class="form-text small text-body-secondary mt-1">
                      Enter server address with port (e.g. <code>http://domain.com:8080</code> or <code>104.243.37.202:80</code>).
                    </div>
                  </div>

                  <div class="mb-3">
                    <label for="xtream-username" class="form-label fw-semibold">Xtream Username</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="icon-base bx bx-user"></i></span>
                      <input
                        type="text"
                        class="form-control"
                        id="xtream-username"
                        name="username"
                        placeholder="Enter xtream username"
                        autocomplete="username"
                        required />
                    </div>
                  </div>

                  <div class="mb-4 form-password-toggle">
                    <label class="form-label fw-semibold" for="xtream-password">Xtream Password</label>
                    <div class="input-group input-group-merge">
                      <span class="input-group-text"><i class="icon-base bx bx-lock-alt"></i></span>
                      <input
                        type="password"
                        id="xtream-password"
                        class="form-control"
                        name="password"
                        placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                        autocomplete="current-password"
                        required />
                      <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
                    </div>
                  </div>

                  <div class="mb-4 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="remember-xtream" checked />
                      <label class="form-check-label text-body-secondary small" for="remember-xtream">
                        Remember & save server profile on this device
                      </label>
                    </div>
                  </div>

                  <div class="mb-3">
                    <button class="btn btn-primary d-flex align-items-center justify-content-center gap-2 w-100 py-2 fw-semibold shadow-sm" type="submit" id="btn-submit-xtream">
                      <i class="icon-base bx bx-link-external"></i> Connect & Launch Player
                    </button>
                  </div>
                </form>
              </div>

              <!-- ═════════════════════════════════════════════════════
                   TAB 4: PLAYLIST / M3U URL EXTRACTION & LOGIN
              ══════════════════════════════════════════════════════ -->
              <div class="tab-pane fade" id="tab-playlist" role="tabpanel">
                <form id="formPlaylistUrl" novalidate>
                  <div class="mb-3">
                    <label for="playlist-url" class="form-label fw-semibold">M3U / Playlist URL</label>
                    <div class="input-group">
                      <span class="input-group-text"><i class="icon-base bx bx-link-alt"></i></span>
                      <input
                        type="text"
                        class="form-control font-monospace"
                        id="playlist-url"
                        name="playlist_url"
                        placeholder="http://domain:port/get.php?username=...&password=..."
                        autocomplete="off"
                        required />
                      <button class="btn btn-outline-secondary" type="button" id="btn-paste-playlist" title="Paste from clipboard">
                        <i class="icon-base bx bx-paste me-1"></i> Paste
                      </button>
                    </div>
                    <div class="form-text small text-body-secondary mt-1">
                      Paste your M3U Plus or Xtream playlist URL (e.g. <code>104.243.37.202/player_api.php?username=...&password=...</code>).
                    </div>
                  </div>

                  <!-- Live Extracted Credentials Indicator Box -->
                  <div id="playlist-parsed-box" class="alert alert-label-primary p-3 rounded mb-3" style="display: none;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <i class="icon-base bx bx-check-circle text-success fs-5"></i>
                      <strong class="text-heading small">Extracted Credentials</strong>
                    </div>
                    <div class="small font-monospace">
                      <div class="d-flex justify-content-between py-1 border-bottom border-primary-subtle">
                        <span class="text-muted">Server:</span>
                        <span class="fw-semibold text-truncate ms-2" id="parsed-server" style="max-width: 240px;">-</span>
                      </div>
                      <div class="d-flex justify-content-between py-1 border-bottom border-primary-subtle">
                        <span class="text-muted">Username:</span>
                        <span class="fw-semibold text-truncate ms-2" id="parsed-username">-</span>
                      </div>
                      <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Password:</span>
                        <span class="fw-semibold text-truncate ms-2" id="parsed-password">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
                      </div>
                    </div>
                  </div>

                  <div class="mb-4 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="remember-playlist" checked />
                      <label class="form-check-label text-body-secondary small" for="remember-playlist">
                        Remember & save server profile on this device
                      </label>
                    </div>
                  </div>

                  <div class="mb-3">
                    <button class="btn btn-primary d-flex align-items-center justify-content-center gap-2 w-100 py-2 fw-semibold shadow-sm" type="submit" id="btn-submit-playlist">
                      <i class="icon-base bx bx-log-in-circle"></i> Parse & Launch Player
                    </button>
                  </div>
                </form>
              </div>

              <!-- ═════════════════════════════════════════════════════
                   TAB 5: SAVED ACCOUNTS ON THIS DEVICE
              ══════════════════════════════════════════════════════ -->
              <div class="tab-pane fade" id="tab-saved" role="tabpanel">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <span class="fw-semibold text-heading small">Available Profiles on this Device</span>
                  <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none" id="btn-clear-all-saved" style="display: none;">
                    <i class="icon-base bx bx-trash me-1"></i> Clear All
                  </button>
                </div>

                <!-- Dynamic container populated by player-login.js -->
                <div id="saved-accounts-list" class="mb-4"></div>
              </div>

            </div>
            <!-- /Tab Content -->

          </div>
        </div>

      </div>
    </div>

    <!-- Core JS (Pure Sneat Full Version) -->
    <script src="<?= $assetsPath ?>vendor/libs/jquery/jquery.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/popper/popper.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/bootstrap.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/menu.js"></script>
    <script src="<?= $assetsPath ?>js/main.js"></script>

    <!-- Theme Switcher Runtime Handler -->
    <script src="<?= $assetsPath ?>js/player-theme.js"></script>

    <!-- Web Player V2 Multi-Mode Login & Saved Accounts Controller -->
    <script src="<?= $assetsPath ?>js/player-login.js"></script>
  </body>
</html>
