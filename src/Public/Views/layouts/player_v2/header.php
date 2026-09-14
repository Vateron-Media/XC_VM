<?php

use XcVm\Core\Config\SettingsManager;

$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
$currentPage = defined('PAGE_NAME') ? PAGE_NAME : ($_PAGE ?? 'index');
$pageTitle = ($GLOBALS['_TITLE'] ?? 'Dashboard') . ' - ' . htmlspecialchars($serverName);

// Subscriber line information
$subscriberUsername = !empty($rUserInfo['username']) ? htmlspecialchars($rUserInfo['username']) : 'Subscriber';
$expDate = !empty($rUserInfo['exp_date']) ? date('Y-m-d', $rUserInfo['exp_date']) : 'Unlimited';
$isExpiringSoon = !empty($rUserInfo['exp_date']) && ($rUserInfo['exp_date'] - time() < 7 * 86400);

$totalLive = !empty($totalLive) ? (int)$totalLive : count($rUserInfo['live_ids'] ?? []);
$totalVod  = !empty($totalVod) ? (int)$totalVod : count($rUserInfo['vod_ids'] ?? []);
$totalSeries = !empty($totalSeries) ? (int)$totalSeries : count($rUserInfo['series_ids'] ?? []);
$totalRadio = !empty($totalRadio) ? (int)$totalRadio : count($rUserInfo['radio_ids'] ?? []);

$code = $_SERVER['XC_CODE'] ?? '';
$baseUrl = $code ? '/' . $code . '/' : '';
$assetsPath = $baseUrl . 'assets/';
?>
<!doctype html>
<html
  lang="en"
  class="layout-navbar-fixed layout-menu-fixed layout-compact"
  dir="ltr"
  data-skin="default"
  data-assets-path="<?= $assetsPath ?>"
  data-base-url="<?= $baseUrl ?>"
  data-template="vertical-menu-template"
  data-bs-theme="dark">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

    <title><?= $pageTitle ?></title>

    <!-- Immediate Theme Application & Global Runtime Guard -->
    <script>
      (function () {
        // 1. Theme application
        var storedTheme = localStorage.getItem('templateCustomizer-vertical-menu-template--Theme') || 'dark';
        if (storedTheme === 'system') {
          storedTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-bs-theme', storedTheme);

        // 2. Global Error Shield against third-party extension & Web Vitals bugs (reportAllChanges / startTime)
        var isTargetError = function (msg, err) {
          var str = ((msg || '') + ' ' + (err && err.message ? err.message : '') + ' ' + (err && err.stack ? err.stack : '')).toLowerCase();
          return str.indexOf('starttime') !== -1 || str.indexOf('reportallchanges') !== -1;
        };

        // Guard requestIdleCallback (where reportAllChanges is scheduled)
        if (typeof window.requestIdleCallback === 'function') {
          var origRequestIdleCallback = window.requestIdleCallback;
          window.requestIdleCallback = function (callback, options) {
            return origRequestIdleCallback.call(window, function (deadline) {
              try {
                return callback(deadline);
              } catch (err) {
                if (isTargetError(err ? err.message : '', err)) {
                  return; // Silently swallow extension / Web Vitals bug
                }
                throw err;
              }
            }, options);
          };
        }

        // Global unhandled error handler
        var origOnError = window.onerror;
        window.onerror = function (message, source, lineno, colno, error) {
          if (isTargetError(message, error)) {
            return true; // Prevents logging to browser console
          }
          if (typeof origOnError === 'function') {
            return origOnError.apply(this, arguments);
          }
          return false;
        };

        window.addEventListener('error', function (event) {
          if (event && isTargetError(event.message, event.error)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return true;
          }
        }, true);

        window.addEventListener('unhandledrejection', function (event) {
          var reason = event ? event.reason : null;
          if (reason && isTargetError(reason.message || reason, reason)) {
            event.preventDefault();
            event.stopImmediatePropagation();
          }
        }, true);
      })();
    </script>

    <?php if (!empty($_SESSION['saved_account_sync'])): ?>
    <script>
      (function () {
        try {
          var acc = <?= json_encode($_SESSION['saved_account_sync']) ?>;
          if (acc) {
            var STORAGE_KEY = 'xc_player_v2_accounts';
            var list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
            if (!Array.isArray(list)) list = [];
            list = list.filter(function (a) {
              if (acc.activation_code && a.activation_code) {
                if (a.activation_code.toUpperCase() === acc.activation_code.toUpperCase()) return false;
              }
              if (acc.username && a.username) {
                if (a.username.toLowerCase() === acc.username.toLowerCase()) return false;
              }
              return true;
            });
            list.unshift(acc);
            if (list.length > 20) list = list.slice(0, 20);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
          }
        } catch (e) {}
      })();
    </script>
    <?php unset($_SESSION['saved_account_sync']); ?>
    <?php endif; ?>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= $assetsPath ?>img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet" />

    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/fonts/iconify-icons.css" />

    <!-- Core CSS (Pure Sneat Full Version) -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/pickr/pickr-themes.css" />
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/css/core.css" />
    <link rel="stylesheet" href="<?= $assetsPath ?>css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="<?= $assetsPath ?>vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <!-- Helpers & Config -->
    <script src="<?= $assetsPath ?>vendor/js/helpers.js"></script>
    <script src="<?= $assetsPath ?>vendor/libs/pickr/pickr.js"></script>
    <script src="<?= $assetsPath ?>vendor/js/template-customizer.js"></script>
    <script src="<?= $assetsPath ?>js/config.js"></script>
  </head>

  <body>
    <!-- Top SPA Progress Bar (Pure Sneat Theme Styling) -->
    <div id="spa-progress-bar" class="position-fixed top-0 start-0 bg-primary" style="height: 3px; width: 0%; z-index: 99999; transition: width 0.25s ease, opacity 0.3s ease; opacity: 0; pointer-events: none;"></div>

    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <!-- Menu (Sidebar) -->
        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
          <div class="app-brand demo">
            <a href="<?= $baseUrl ?>index" class="app-brand-link">
              <span class="app-brand-logo demo">
                <span class="text-primary">
                  <svg
                    width="25"
                    viewBox="0 0 25 42"
                    version="1.1"
                    xmlns="http://www.w3.org/2000/svg"
                    xmlns:xlink="http://www.w3.org/1999/xlink">
                    <defs>
                      <path
                        d="M13.7918663,0.358365126 L3.39788168,7.44174259 C0.566865006,9.69408886 -0.379795268,12.4788597 0.557900856,15.7960551 C0.68998853,16.2305145 1.09562888,17.7872135 3.12357076,19.2293357 C3.8146334,19.7207684 5.32369333,20.3834223 7.65075054,21.2172976 L7.59773219,21.2525164 L2.63468769,24.5493413 C0.445452254,26.3002124 0.0884951797,28.5083815 1.56381646,31.1738486 C2.83770406,32.8170431 5.20850219,33.2640127 7.09180128,32.5391577 C8.347334,32.0559211 11.4559176,30.0011079 16.4175519,26.3747182 C18.0338572,24.4997857 18.6973423,22.4544883 18.4080071,20.2388261 C17.963753,17.5346866 16.1776345,15.5799961 13.0496516,14.3747546 L10.9194936,13.4715819 L18.6192054,7.984237 L13.7918663,0.358365126 Z"
                        id="path-1"></path>
                      <path
                        d="M5.47320593,6.00457225 C4.05321814,8.216144 4.36334763,10.0722806 6.40359441,11.5729822 C8.61520715,12.571656 10.0999176,13.2171421 10.8577257,13.5094407 L15.5088241,14.433041 L18.6192054,7.984237 C15.5364148,3.11535317 13.9273018,0.573395879 13.7918663,0.358365126 C13.5790555,0.511491653 10.8061687,2.3935607 5.47320593,6.00457225 Z"
                        id="path-3"></path>
                    </defs>
                    <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                      <g transform="translate(-27.000000, -15.000000)">
                        <g transform="translate(27.000000, 15.000000)">
                          <use fill="currentColor" xlink:href="#path-1"></use>
                        </g>
                      </g>
                    </g>
                  </svg>
                </span>
              </span>
              <span class="app-brand-text demo menu-text fw-bold ms-2"><?= htmlspecialchars($serverName) ?></span>
            </a>

            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
              <i class="icon-base bx bx-chevron-left"></i>
            </a>
          </div>

          <div class="menu-inner-shadow"></div>

          <ul class="menu-inner py-1">
            <!-- Dashboard -->
            <li class="menu-item <?= $currentPage === 'index' ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>index" class="menu-link">
                <i class="menu-icon icon-base bx bx-home-smile"></i>
                <div>Dashboard</div>
              </a>
            </li>

            <!-- Channels & Media -->
            <li class="menu-header small text-uppercase">
              <span class="menu-header-text">Streaming Catalog</span>
            </li>

            <li class="menu-item <?= $currentPage === 'live' ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>live" class="menu-link">
                <i class="menu-icon icon-base bx bx-tv"></i>
                <div>Live Channels</div>
                <?php if ($totalLive > 0): ?>
                  <div class="badge text-bg-primary rounded-pill ms-auto"><?= $totalLive ?></div>
                <?php endif; ?>
              </a>
            </li>

            <li class="menu-item <?= in_array($currentPage, ['movies', 'movie'], true) ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>movies" class="menu-link">
                <i class="menu-icon icon-base bx bx-film"></i>
                <div>Movies (VOD)</div>
                <?php if ($totalVod > 0): ?>
                  <div class="badge text-bg-success rounded-pill ms-auto"><?= $totalVod ?></div>
                <?php endif; ?>
              </a>
            </li>

            <li class="menu-item <?= in_array($currentPage, ['series', 'series_detail', 'episodes'], true) ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>series" class="menu-link">
                <i class="menu-icon icon-base bx bx-movie-play"></i>
                <div>TV Series</div>
                <?php if ($totalSeries > 0): ?>
                  <div class="badge text-bg-warning rounded-pill ms-auto"><?= $totalSeries ?></div>
                <?php endif; ?>
              </a>
            </li>

            <li class="menu-item <?= $currentPage === 'radio' ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>radio" class="menu-link">
                <i class="menu-icon icon-base bx bx-broadcast"></i>
                <div>Radio Stations</div>
                <?php if ($totalRadio > 0): ?>
                  <div class="badge text-bg-info rounded-pill ms-auto"><?= $totalRadio ?></div>
                <?php endif; ?>
              </a>
            </li>

            <li class="menu-item <?= $currentPage === 'favorites' ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>favorites" class="menu-link">
                <i class="menu-icon icon-base bx bxs-star text-warning"></i>
                <div>Favorites</div>
                <div class="badge text-bg-warning rounded-pill ms-auto nav-fav-menu-badge" style="display: none;">0</div>
              </a>
            </li>

            <!-- Subscriber Account -->
            <li class="menu-header small text-uppercase">
              <span class="menu-header-text">Subscriber</span>
            </li>

            <li class="menu-item <?= $currentPage === 'profile' ? 'active' : '' ?>">
              <a href="<?= $baseUrl ?>profile" class="menu-link">
                <i class="menu-icon icon-base bx bx-user"></i>
                <div>Account Details</div>
              </a>
            </li>

            <li class="menu-item">
              <a href="<?= $baseUrl ?>logout" class="menu-link text-danger" onclick="if(window.clearUserClientData){window.clearUserClientData();}">
                <i class="menu-icon icon-base bx bx-log-out text-danger"></i>
                <div>Sign Out</div>
              </a>
            </li>
          </ul>
        </aside>
        <!-- / Menu -->

        <!-- Layout container -->
        <div class="layout-page">
          <!-- Navbar -->
          <nav
            class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
            id="layout-navbar">
            <div class="layout-menu-toggle navbar-nav align-items-xl-center me-4 me-xl-0 d-xl-none">
              <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
                <i class="icon-base bx bx-menu icon-md"></i>
              </a>
            </div>

            <div class="navbar-nav-right d-flex align-items-center justify-content-between" id="navbar-collapse">
              <!-- Global Search -->
              <div class="navbar-nav align-items-center position-relative flex-grow-1 me-3 max-w-600">
                <form action="<?= $baseUrl ?>search" method="GET" class="nav-item navbar-search-wrapper mb-0 d-flex align-items-center w-100" id="global-search-form">
                  <div class="input-group input-group-merge w-100">
                    <span class="input-group-text border-0 bg-transparent ps-0 pe-2"><i class="icon-base bx bx-search icon-md text-body-secondary"></i></span>
                    <input
                      type="text"
                      name="q"
                      id="global-search-input"
                      class="form-control border-0 bg-transparent shadow-none"
                      placeholder="Search movies, series, episodes, live TV, radio... (Ctrl+K)"
                      autocomplete="off" />
                    <button type="button" class="btn btn-link text-body-secondary p-0 me-2 d-none" id="global-search-clear-btn" title="Clear">
                      <i class="icon-base bx bx-x icon-sm"></i>
                    </button>
                  </div>
                </form>

                <!-- Instant Live Search Dropdown Preview -->
                <div class="dropdown-menu dropdown-menu-start shadow-lg border p-0 w-100 position-absolute" id="global-search-dropdown" style="display: none; top: calc(100% + 4px); left: 0; z-index: 1060; max-height: 520px; overflow-y: auto;"></div>
              </div>
              <!-- /Global Search -->

              <ul class="navbar-nav flex-row align-items-center ms-md-auto">
                <!-- Favorites Vault Button -->
                <li class="nav-item me-2 me-xl-1">
                  <a
                    class="nav-link hide-arrow position-relative"
                    id="nav-favorites-btn"
                    href="<?= $baseUrl ?>favorites"
                    title="My Favorites Vault"
                    data-bs-toggle="tooltip"
                    data-bs-placement="bottom">
                    <i class="icon-base bx bxs-star icon-md text-warning" id="nav-fav-icon"></i>
                    <span class="badge bg-danger rounded-pill badge-notifications d-none" id="nav-fav-badge" style="position: absolute; top: 4px; right: 4px; font-size: 0.65rem; padding: 0.15rem 0.35rem;">0</span>
                    <span class="visually-hidden">Favorites</span>
                  </a>
                </li>

                <!-- Refresh & Sync Data Button -->
                <li class="nav-item me-2 me-xl-1">
                  <a
                    class="nav-link hide-arrow"
                    id="nav-refresh-data"
                    href="javascript:void(0);"
                    data-refresh-url="<?= $baseUrl ?>refresh"
                    title="Refresh & Sync Data"
                    data-bs-toggle="tooltip"
                    data-bs-placement="bottom">
                    <i class="icon-base bx bx-refresh icon-md text-heading" id="nav-refresh-icon"></i>
                    <span class="visually-hidden">Refresh Data</span>
                  </a>
                </li>

                <!-- Style Switcher (Light / Dark / System) -->
                <li class="nav-item dropdown me-3 me-xl-2">
                  <a
                    class="nav-link dropdown-toggle hide-arrow"
                    id="nav-theme"
                    href="javascript:void(0);"
                    data-bs-toggle="dropdown"
                    title="Toggle Theme (Alt+T)">
                    <i class="icon-base bx bx-moon icon-md theme-icon-active text-heading"></i>
                    <span class="d-none ms-2" id="nav-theme-text">Toggle theme</span>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 py-2" aria-labelledby="nav-theme-text">
                    <li class="dropdown-header text-uppercase text-xs fw-semibold px-4 pt-1 pb-2">Theme</li>
                    <li>
                      <button
                        type="button"
                        class="dropdown-item d-flex align-items-center py-2 px-4"
                        data-bs-theme-value="light">
                        <i class="icon-base bx bx-sun icon-md me-3 text-warning" data-icon="sun"></i>
                        <span>Light</span>
                        <i class="icon-base bx bx-check text-primary ms-auto theme-check d-none"></i>
                      </button>
                    </li>
                    <li>
                      <button
                        type="button"
                        class="dropdown-item d-flex align-items-center py-2 px-4"
                        data-bs-theme-value="dark">
                        <i class="icon-base bx bx-moon icon-md me-3 text-primary" data-icon="moon"></i>
                        <span>Dark</span>
                        <i class="icon-base bx bx-check text-primary ms-auto theme-check d-none"></i>
                      </button>
                    </li>
                    <li>
                      <button
                        type="button"
                        class="dropdown-item d-flex align-items-center py-2 px-4"
                        data-bs-theme-value="system">
                        <i class="icon-base bx bx-desktop icon-md me-3 text-info" data-icon="desktop"></i>
                        <span>System</span>
                        <i class="icon-base bx bx-check text-primary ms-auto theme-check d-none"></i>
                      </button>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1"></div>
                    </li>
                    <li class="px-4 py-1 text-body-secondary small d-flex align-items-center justify-content-between">
                      <span>Quick Switch</span>
                      <kbd class="bg-body-secondary text-body px-1 py-0 rounded">Alt + T</kbd>
                    </li>
                  </ul>
                </li>
                <!-- / Style Switcher -->

                <!-- User Profile Dropdown -->
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a
                    class="nav-link dropdown-toggle hide-arrow p-0"
                    href="javascript:void(0);"
                    data-bs-toggle="dropdown">
                    <div class="avatar avatar-online">
                      <span class="avatar-initial rounded-circle bg-label-primary fw-bold">
                        <?= strtoupper(substr($subscriberUsername, 0, 1)) ?>
                      </span>
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                    <li>
                      <div class="dropdown-item">
                        <div class="d-flex align-items-center">
                          <div class="flex-shrink-0 me-3">
                            <div class="avatar avatar-online">
                              <span class="avatar-initial rounded-circle bg-label-primary fw-bold">
                                <?= strtoupper(substr($subscriberUsername, 0, 1)) ?>
                              </span>
                            </div>
                          </div>
                          <div class="flex-grow-1">
                            <h6 class="mb-0 fw-bold"><?= $subscriberUsername ?></h6>
                            <small class="text-body-secondary">Expires: <span class="<?= $isExpiringSoon ? 'text-danger fw-bold' : 'text-success' ?>"><?= $expDate ?></span></small>
                          </div>
                        </div>
                      </div>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1"></div>
                    </li>
                    <li>
                      <a class="dropdown-item" href="<?= $baseUrl ?>profile">
                        <i class="icon-base bx bx-user icon-md me-3"></i><span>Account Settings</span>
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="<?= $baseUrl ?>live">
                        <i class="icon-base bx bx-tv icon-md me-3"></i><span>Live TV (<?= $totalLive ?>)</span>
                      </a>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1"></div>
                    </li>
                    <li>
                      <a class="dropdown-item text-danger" href="<?= $baseUrl ?>logout" onclick="if(window.clearUserClientData){window.clearUserClientData();}">
                        <i class="icon-base bx bx-power-off icon-md me-3"></i><span>Sign Out</span>
                      </a>
                    </li>
                  </ul>
                </li>
                <!--/ User -->
              </ul>
            </div>
          </nav>
          <!-- / Navbar -->

          <!-- Toast Container for Data Sync & Notifications -->
          <div class="toast-container position-fixed top-0 end-0 p-3 z-index-toast" id="sync-toast-container">
            <div id="sync-toast" class="bs-toast toast fade bg-primary text-white" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="toast-header bg-primary text-white border-0">
                <i class="icon-base bx bx-sync text-white me-2"></i>
                <div class="me-auto fw-medium">Sync Manager</div>
                <small class="text-white-50">Just now</small>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
              </div>
              <div class="toast-body" id="sync-toast-body">
                Synchronizing latest catalog and bouquets...
              </div>
            </div>
          </div>

          <!-- Content wrapper -->
          <div class="content-wrapper">
            <!-- Content -->
            <div class="flex-grow-1 container-p-y position-relative container-fluid" id="main-content-container">
              <div id="spa-content-target">
