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

    <title>Sign In - <?= htmlspecialchars($serverName) ?> Web Player Next-Gen</title>

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
    <link rel="stylesheet" href="<?= $assetsPath ?>css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <!-- Page CSS -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/css/pages/page-auth.css" />

    <!-- Helpers & Config -->
    <script src="<?= $assetsPath ?>vendor/js/helpers.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/pickr/pickr.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/template-customizer.js"></script>
    <script src="<?= $assetsPath ?>js/config.js"></script>
  </head>

  <body>
    <!-- Split Screen Main Wrapper -->
    <div class="container-fluid p-0">
      <div class="row g-0 login-split-wrapper">

        <!-- ═════════════════════════════════════════════════════════════════
             LEFT COLUMN: HERO SHOWCASE WITH ICONS & AMBIENT EFFECTS
        ══════════════════════════════════════════════════════════════════ -->
        <div class="col-lg-7 d-none d-lg-flex login-hero-col">
          <!-- Ambient Glowing Orbs -->
          <div class="login-hero-orb login-hero-orb-1"></div>
          <div class="login-hero-orb login-hero-orb-2"></div>

          <!-- Hero Top: Branding -->
          <div class="d-flex align-items-center justify-content-between position-relative z-1 mb-5">
            <a href="<?= $baseUrl ?>login" class="d-flex align-items-center gap-3 text-decoration-none">
              <div class="avatar avatar-md bg-label-primary rounded-3 d-flex align-items-center justify-content-center shadow-sm">
                <i class="icon-base bx bx-tv fs-2 text-primary"></i>
              </div>
              <div>
                <span class="fs-4 fw-bold text-heading"><?= htmlspecialchars($serverName) ?></span>
                <span class="badge bg-label-primary rounded-pill ms-2">Web Player Next-Gen</span>
              </div>
            </a>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-label-success rounded-pill px-3 py-2 d-flex align-items-center">
                <i class="icon-base bx bx-broadcast me-1 text-success"></i> 4K Ultra-HD Ready
              </span>
            </div>
          </div>

          <!-- Hero Middle: Value Proposition & Feature Cards -->
          <div class="my-auto position-relative z-1 py-4">
            <div class="mb-4">
              <span class="badge bg-label-primary rounded-pill px-3 py-2 mb-3">
                <i class="icon-base bx bx-sparkles me-1"></i> The Future of Digital Entertainment
              </span>
              <h1 class="display-5 fw-bold text-heading mb-3">
                Stream Smarter, Faster & In Pure 4K Clarity.
              </h1>
              <p class="fs-5 text-body-secondary max-w-600 mb-0">
                Experience ultra-low latency Live TV, an extensive cinema VOD catalog, full TV series seasons, and worldwide studio radio broadcasts with instant multi-account switching.
              </p>
            </div>

            <!-- Feature Showcase Grid -->
            <div class="row g-4 mt-2">
              <div class="col-sm-6">
                <div class="login-feature-card h-100">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="avatar avatar-sm bg-label-primary rounded d-flex align-items-center justify-content-center">
                      <i class="icon-base bx bx-broadcast text-primary"></i>
                    </div>
                    <h6 class="mb-0 fw-bold text-heading">Ultra-Low Latency Live TV</h6>
                  </div>
                  <p class="text-body-secondary small mb-0">
                    High-frame rate broadcasts, adaptive bitrate streaming, and synchronized electronic program guide (EPG).
                  </p>
                </div>
              </div>

              <div class="col-sm-6">
                <div class="login-feature-card h-100">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="avatar avatar-sm bg-label-success rounded d-flex align-items-center justify-content-center">
                      <i class="icon-base bx bx-film text-success"></i>
                    </div>
                    <h6 class="mb-0 fw-bold text-heading">Cinema VOD & TV Series</h6>
                  </div>
                  <p class="text-body-secondary small mb-0">
                    Full catalog with IMDb metadata, multi-language audio tracks, subtitles, and smart continue watching.
                  </p>
                </div>
              </div>

              <div class="col-sm-6">
                <div class="login-feature-card h-100">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="avatar avatar-sm bg-label-warning rounded d-flex align-items-center justify-content-center">
                      <i class="icon-base bx bx-radio text-warning"></i>
                    </div>
                    <h6 class="mb-0 fw-bold text-heading">Worldwide Radio Studio</h6>
                  </div>
                  <p class="text-body-secondary small mb-0">
                    Direct high-fidelity FM radio streaming with live waveform audio visualization and genre filtering.
                  </p>
                </div>
              </div>

              <div class="col-sm-6">
                <div class="login-feature-card h-100">
                  <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="avatar avatar-sm bg-label-info rounded d-flex align-items-center justify-content-center">
                      <i class="icon-base bx bx-sync text-info"></i>
                    </div>
                    <h6 class="mb-0 fw-bold text-heading">1-Click Account Switcher</h6>
                  </div>
                  <p class="text-body-secondary small mb-0">
                    Save multiple accounts and activation codes locally on your device for seamless, instant switching.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Hero Bottom: Trust Badges -->
          <div class="d-flex align-items-center gap-3 flex-wrap position-relative z-1 pt-4">
            <div class="login-stat-pill">
              <i class="icon-base bx bx-check-shield text-success"></i> 99.9% Uptime Engine
            </div>
            <div class="login-stat-pill">
              <i class="icon-base bx bx-zap text-warning"></i> Instant Code Activation
            </div>
            <div class="login-stat-pill">
              <i class="icon-base bx bx-devices text-info"></i> Multi-Device Sync
            </div>
            <div class="login-stat-pill">
              <i class="icon-base bx bx-lock-alt text-primary"></i> SSL Hardware Encrypted
            </div>
          </div>
        </div>

        <!-- ═════════════════════════════════════════════════════════════════
             RIGHT COLUMN: INTERACTIVE AUTH PANEL WITH 3 DISTINCTIVE TABS
        ══════════════════════════════════════════════════════════════════ -->
        <div class="col-lg-5 col-12 login-auth-col">
          <div class="login-auth-card">

            <!-- Top Utility Bar: Server Status & Theme Switcher -->
            <div class="d-flex align-items-center justify-content-between mb-4">
              <span class="badge bg-label-success rounded-pill px-3 py-2 d-flex align-items-center">
                <span class="badge-dot bg-success me-2"></span> Streaming Gateway Online
              </span>

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

            <!-- Mobile Brand Logo -->
            <div class="d-lg-none text-center mb-4">
              <div class="avatar avatar-lg bg-label-primary rounded-3 mx-auto mb-2 d-flex align-items-center justify-content-center">
                <i class="icon-base bx bx-tv fs-1 text-primary"></i>
              </div>
              <h4 class="fw-bold mb-0 text-heading"><?= htmlspecialchars($serverName) ?></h4>
              <small class="text-body-secondary">Web Player Next-Gen</small>
            </div>

            <!-- Header Title -->
            <div class="mb-4">
              <h3 class="fw-bold mb-1 text-heading">Welcome Back 👋</h3>
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
                    <button class="btn btn-primary d-grid w-100 py-2 fw-semibold shadow-sm" type="submit" id="btn-submit-account">
                      <i class="icon-base bx bx-log-in me-1"></i> Sign In to Account
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

                  <div class="mb-3">
                    <button class="btn btn-primary d-grid w-100 py-2 fw-semibold shadow-sm" type="submit" id="btn-submit-code">
                      <i class="icon-base bx bx-zap me-1"></i> Activate & Launch Stream
                    </button>
                  </div>
                </form>
              </div>

              <!-- ═════════════════════════════════════════════════════
                   TAB 3: SAVED ACCOUNTS ON THIS DEVICE
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

            <!-- Trust Badge Footer -->
            <div class="text-center pt-4 border-top mt-4">
              <span class="badge bg-label-secondary rounded-pill px-3 py-1 small">
                <i class="icon-base bx bx-shield-check me-1 text-success"></i> End-to-End Encrypted Session &bull; Fast CDN
              </span>
            </div>

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
