<?php

/**
 * Subscriber Activation Portal (XC_VM Platform Edition)
 *
 * Fully unified with the XC_VM core design system (Vuexy / Sneat Bootstrap 5),
 * matching the official panel typography, cards, components, and native
 * Dark & Light themes.
 *
 * @package XC_VM_Public_Views_Portal
 */

use XcVm\Core\Config\SettingsManager;

$serverName = class_exists(SettingsManager::class)
    ? (SettingsManager::get('server_name') ?: 'XC_VM')
    : 'XC_VM';
$serverLogo = 'assets/img/logo-topbar.png';
$initialCode = trim((string)($_GET['code'] ?? ''));
$initialResultJson = !empty($result) ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null';
?>
<!doctype html>
<html
    lang="en"
    class="layout-navbar-fixed layout-menu-fixed layout-compact"
    dir="ltr"
    data-skin="default"
    data-bs-theme="dark"
    data-assets-path="assets/"
    data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($serverName, ENT_QUOTES); ?> | Subscriber Activation Portal</title>
    <meta name="description" content="Redeem your voucher code to activate streaming access, obtain Xtream Codes credentials, and download M3U playlists.">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">

    <!-- Zero-Flicker Theme State Restoration -->
    <script>
        (function() {
            try {
                var t = localStorage.getItem('portal_theme');
                if (!t) {
                    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
                }
                document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) {}
        })();
    </script>

    <!-- Google Fonts: Public Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&display=swap">

    <!-- Tabler Icons (Iconify) -->
    <link rel="stylesheet" href="assets/vendor/fonts/iconify-icons.css">

    <!-- Core Platform Styles (Bootstrap 5 + Vuexy Theme) -->
    <link rel="stylesheet" href="assets/vendor/libs/node-waves/node-waves.css">
    <link rel="stylesheet" href="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
    <link rel="stylesheet" href="assets/vendor/css/bootstrap.css">
    <link rel="stylesheet" href="assets/vendor/css/custom.css">
    <link rel="stylesheet" href="assets/css/demo.css">
    <link rel="stylesheet" href="assets/xcvm/custom.css">
    <link rel="stylesheet" href="assets/vendor/libs/sweetalert2/sweetalert2.css">

    <style>
        /* Lightweight Native Micro-Adjustments */
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: 'Public Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bs-body-bg);
            color: var(--bs-body-color);
        }

        .code-input-lg {
            font-family: 'JetBrains Mono', monospace !important;
            letter-spacing: 3px !important;
        }

        .countdown-box {
            background-color: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.65rem;
            transition: all 0.2s ease;
        }

        .countdown-num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.85rem;
            font-weight: 800;
            line-height: 1.1;
            color: var(--bs-primary);
        }

        .countdown-lbl {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--bs-secondary-color, #808390);
        }

        .cred-tile {
            background-color: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 0.65rem;
            padding: 1rem;
            height: 100%;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .cred-tile:hover {
            border-color: var(--bs-primary);
        }

        .cred-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.95rem;
            font-weight: 700;
            word-break: break-all;
        }

        .badge-pulse {
            animation: pulse-ring 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 0.85; }
            50% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(0.95); opacity: 0.85; }
        }

        .qr-canvas-box {
            background: #ffffff;
            padding: 14px;
            border-radius: 0.75rem;
            display: inline-block;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }
    </style>
</head>

<body>
    <!-- Topbar: Official XC_VM Navigation Header -->
    <nav class="layout-navbar navbar navbar-expand-xl align-items-center bg-navbar-theme border-bottom py-2 shadow-xs sticky-top" id="layout-navbar">
        <div class="container-xxl d-flex align-items-center justify-content-between">
            <!-- Brand & Gateway Title -->
            <div class="app-brand demo d-flex align-items-center">
                <a href="./portal" class="app-brand-link d-flex align-items-center gap-2 text-decoration-none">
                    <span class="app-brand-logo demo">
                        <img src="<?= htmlspecialchars($serverLogo, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($serverName, ENT_QUOTES); ?>" height="28">
                    </span>
                    <span class="app-brand-text demo menu-text fw-bold fs-5 text-heading"><?= htmlspecialchars($serverName, ENT_QUOTES); ?></span>
                </a>
                <span class="badge bg-label-primary rounded-pill px-3 py-1 ms-3 d-none d-sm-inline-flex align-items-center">
                    <i class="ti tabler-key me-1"></i>Subscriber Activation Portal
                </span>
            </div>

            <!-- Right Controls: Status & Theme Toggle -->
            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-label-success rounded-pill px-3 py-2 d-none d-md-inline-flex align-items-center">
                    <span class="badge-dot bg-success me-1"></span>Gateway Active
                </span>
                <button type="button" class="btn btn-icon btn-sm btn-label-secondary rounded-circle" id="btn-theme-toggle" title="Toggle Dark / Light Mode" aria-label="Toggle Theme">
                    <i class="ti tabler-moon icon-sm" id="theme-icon"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Main Content Wrapper -->
    <div class="layout-wrapper layout-content-navbar flex-grow-1">
        <div class="layout-container">
            <div class="content-wrapper d-flex flex-column flex-grow-1">
                <div class="container-xxl flex-grow-1 container-p-y" style="max-width: 980px;">

                    <!-- Hero Section Header -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
                        <div>
                            <span class="badge bg-label-primary rounded-pill px-3 py-1 mb-2 fw-semibold">
                                <i class="ti tabler-sparkles me-1"></i>Instant Voucher Redemption
                            </span>
                            <h3 class="fw-bold mb-1 text-heading">Subscriber Activation Portal</h3>
                            <p class="text-muted mb-0 small">
                                Redeem your activation code to unlock streaming access, obtain Xtream Codes credentials, and download playlists.
                            </p>
                        </div>
                        <div class="mt-2 mt-sm-0">
                            <span class="badge bg-label-info px-3 py-2 fs-6">
                                <i class="ti tabler-shield-check me-1"></i>Direct Line Provisioning
                            </span>
                        </div>
                    </div>

                    <!-- ACTIVATION FORM CARD -->
                    <div class="card shadow-sm border-0 mb-4" id="activation-card">
                        <div class="card-header border-bottom py-3 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center">
                                <div class="avatar avatar-sm bg-label-primary rounded-2 p-2 me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="ti tabler-barcode fs-4 text-primary"></i>
                                </div>
                                <div>
                                    <h5 class="card-title mb-0 fw-bold">Enter Activation Code</h5>
                                    <small class="text-muted">Enter the 8 to 16 character alphanumeric voucher PIN</small>
                                </div>
                            </div>
                            <span class="badge bg-label-primary fw-bold">Instant Provisioning</span>
                        </div>

                        <div class="card-body p-4">
                            <form id="portal-activate-form" autocomplete="off">
                                <div class="mb-4">
                                    <label class="form-label fw-semibold fs-6" for="voucher-code">
                                        Activation Code <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-merge input-group-lg shadow-none">
                                        <span class="input-group-text"><i class="ti tabler-key fs-4 text-primary"></i></span>
                                        <input
                                            type="text"
                                            id="voucher-code"
                                            name="code"
                                            class="form-control code-input-lg fw-bold fs-3 text-center text-uppercase"
                                            placeholder="e.g. 8K9P-2M7X-4Q"
                                            maxlength="32"
                                            required
                                            autofocus
                                            value="<?= htmlspecialchars($initialCode, ENT_QUOTES); ?>">
                                        <button type="button" class="btn btn-label-secondary" id="btn-paste-code" title="Paste from clipboard">
                                            <i class="ti tabler-clipboard me-1"></i>Paste
                                        </button>
                                    </div>
                                    <div class="form-text small mt-1">
                                        Codes are case-insensitive. Your subscription period starts counting upon first activation.
                                    </div>
                                </div>

                                <!-- Optional Hardware Device Binding Accordion -->
                                <div class="accordion mb-4" id="accordionDeviceLock">
                                    <div class="accordion-item border rounded-3 overflow-hidden">
                                        <h2 class="accordion-header" id="headingDevice">
                                            <button
                                                class="accordion-button collapsed py-2 px-3 fw-semibold small bg-light-subtle"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapseDevice"
                                                aria-expanded="false"
                                                aria-controls="collapseDevice">
                                                <i class="ti tabler-device-tv text-info me-2 fs-5"></i>Hardware Lock / Device Binding (Optional for MAG & Smart TVs)
                                            </button>
                                        </h2>
                                        <div id="collapseDevice" class="accordion-collapse collapse" aria-labelledby="headingDevice" data-bs-parent="#accordionDeviceLock">
                                            <div class="accordion-body p-3">
                                                <div class="row g-3">
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label small fw-semibold" for="device-mac">Device MAC Address</label>
                                                        <input
                                                            type="text"
                                                            id="device-mac"
                                                            name="mac"
                                                            class="form-control font-monospace text-uppercase"
                                                            placeholder="00:1A:79:XX:XX:XX"
                                                            maxlength="17">
                                                        <small class="text-muted" style="font-size: 0.75rem;">Bind this code to a specific MAG or STB box MAC address.</small>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label small fw-semibold" for="device-id">Custom Device Identifier</label>
                                                        <input
                                                            type="text"
                                                            id="device-id"
                                                            name="device_id"
                                                            class="form-control font-monospace"
                                                            placeholder="Optional Hardware UUID">
                                                        <small class="text-muted" style="font-size: 0.75rem;">Optional unique device identifier or smart player key.</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid mb-3">
                                    <button type="submit" class="btn btn-primary btn-lg py-3 fw-bold shadow-sm" id="btn-activate">
                                        <i class="ti tabler-bolt me-2 fs-4"></i>Activate Subscription Now
                                    </button>
                                </div>
                            </form>

                            <!-- Error Alert -->
                            <div id="portal-error-alert" class="alert alert-danger d-flex align-items-center d-none" role="alert">
                                <i class="ti tabler-alert-circle fs-4 me-2 flex-shrink-0"></i>
                                <div id="portal-error-text" class="fw-semibold">Invalid activation code entered.</div>
                            </div>

                            <!-- 3 Micro-Feature Highlight Cards -->
                            <div class="row g-3 pt-3 border-top mt-2">
                                <div class="col-12 col-md-4">
                                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 bg-light-subtle border h-100">
                                        <div class="avatar avatar-sm bg-label-warning rounded-2 d-flex align-items-center justify-content-center p-2" style="width: 38px; height: 38px;">
                                            <i class="ti tabler-bolt fs-4 text-warning"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold small text-heading">0.3s Instant Delivery</div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Immediate automated stream provisioning</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 bg-light-subtle border h-100">
                                        <div class="avatar avatar-sm bg-label-info rounded-2 d-flex align-items-center justify-content-center p-2" style="width: 38px; height: 38px;">
                                            <i class="ti tabler-device-tv fs-4 text-info"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold small text-heading">Multi-Device Ready</div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Smart TV, iOS, Android, MAG, PC</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 bg-light-subtle border h-100">
                                        <div class="avatar avatar-sm bg-label-success rounded-2 d-flex align-items-center justify-content-center p-2" style="width: 38px; height: 38px;">
                                            <i class="ti tabler-shield-check fs-4 text-success"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold small text-heading">High-Speed CDN</div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Ultra-low latency streaming architecture</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SUCCESS RESULT CARD (Shown upon valid activation) -->
                    <div class="card shadow-sm border-0 mb-4 d-none" id="success-card">
                        <div class="card-header border-bottom py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center">
                                <div class="avatar avatar-sm bg-label-success rounded-2 p-2 me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="ti tabler-circle-check fs-4 text-success"></i>
                                </div>
                                <div>
                                    <h5 class="card-title text-success mb-0 fw-bold">Subscription Activated Successfully</h5>
                                    <small class="text-muted">Your streaming credentials and playlist links are now live and ready</small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-label-success fw-bold px-3 py-2" id="res-status-badge">
                                    <i class="ti tabler-circle-check me-1"></i>Active (Operational)
                                </span>
                                <span class="badge bg-label-primary fw-bold px-3 py-2" id="res-pkg-badge">
                                    --
                                </span>
                            </div>
                        </div>

                        <div class="card-body p-4">
                            <!-- Top Highlight Metric Boxes -->
                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-4">
                                    <div class="p-3 rounded-3 bg-light-subtle border h-100 d-flex flex-column justify-content-between">
                                        <span class="small text-muted text-uppercase fw-semibold d-block">Activated Code</span>
                                        <div class="d-flex align-items-center justify-content-between mt-2">
                                            <span class="fs-5 fw-bold font-monospace text-primary" id="res-active-code">--------</span>
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-copy-code-val" data-code="">
                                                <i class="ti tabler-copy me-1"></i>Copy
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-3 rounded-3 bg-light-subtle border h-100 d-flex flex-column justify-content-between">
                                        <span class="small text-muted text-uppercase fw-semibold d-block">Subscription Plan</span>
                                        <div class="d-flex align-items-center justify-content-between mt-2">
                                            <span class="fs-6 fw-bold text-heading text-truncate" id="res-pkg-name">Full IPTV Package</span>
                                            <span class="badge bg-label-info ms-2" id="res-conn-count">
                                                <i class="ti tabler-devices me-1"></i>1 Connection
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-3 rounded-3 bg-light-subtle border h-100 d-flex flex-column justify-content-between">
                                        <span class="small text-muted text-uppercase fw-semibold d-block">Stream Protocol</span>
                                        <div class="d-flex align-items-center justify-content-between mt-2">
                                            <span class="fs-6 fw-bold text-heading">HLS & MPEG-TS Direct</span>
                                            <span class="badge bg-label-success">TLS 1.3</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Live Digital HUD Expiry Countdown -->
                            <div class="p-4 rounded-3 border mb-4 bg-light-subtle">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                                    <span class="small text-muted text-uppercase fw-semibold d-flex align-items-center">
                                        <i class="ti tabler-clock-hour-4 text-warning me-2 fs-5"></i>Subscription Remaining Time
                                    </span>
                                    <span class="small text-muted">
                                        Expires on: <span id="res-exp-formatted" class="font-monospace fw-bold text-heading"></span>
                                    </span>
                                </div>
                                <div class="row g-2 text-center" id="countdown-timer">
                                    <div class="col-6 col-sm-3">
                                        <div class="countdown-box p-3">
                                            <div class="countdown-num" id="cd-days">00</div>
                                            <div class="countdown-lbl mt-1">Days</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <div class="countdown-box p-3">
                                            <div class="countdown-num" id="cd-hours">00</div>
                                            <div class="countdown-lbl mt-1">Hours</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <div class="countdown-box p-3">
                                            <div class="countdown-num" id="cd-minutes">00</div>
                                            <div class="countdown-lbl mt-1">Minutes</div>
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <div class="countdown-box p-3">
                                            <div class="countdown-num" id="cd-seconds">00</div>
                                            <div class="countdown-lbl mt-1">Seconds</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Xtream Codes Credentials Hub -->
                            <div class="card shadow-none border mb-4">
                                <div class="card-header border-bottom py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 bg-light-subtle">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="ti tabler-device-tv text-warning fs-4"></i>
                                        <h6 class="mb-0 fw-bold">Xtream Codes Credentials & API Endpoints</h6>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-copy-all-creds" title="Copy Host, Port, Username & Password together">
                                            <i class="ti tabler-copy me-1"></i>Copy All Parameters
                                        </button>
                                        <button type="button" class="btn btn-sm btn-label-secondary" id="btn-open-qr" data-bs-toggle="modal" data-bs-target="#qrCodeModal" title="Scan QR Code">
                                            <i class="ti tabler-qrcode me-1"></i>Scan QR
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body p-3 p-md-4">
                                    <div class="row g-3">
                                        <!-- Server Host -->
                                        <div class="col-12 col-md-6">
                                            <div class="cred-tile">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small text-muted fw-semibold">
                                                        <i class="ti tabler-server text-primary me-1"></i>Server Host / URL
                                                    </span>
                                                    <button type="button" class="btn btn-icon btn-sm btn-label-secondary btn-copy-elem" data-target="res-host" title="Copy Server Host">
                                                        <i class="ti tabler-copy"></i>
                                                    </button>
                                                </div>
                                                <div class="cred-val text-primary text-truncate" id="res-host" title="Server Host">--</div>
                                            </div>
                                        </div>

                                        <!-- Port -->
                                        <div class="col-12 col-md-6">
                                            <div class="cred-tile">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small text-muted fw-semibold">
                                                        <i class="ti tabler-network text-info me-1"></i>Server Port
                                                    </span>
                                                    <button type="button" class="btn btn-icon btn-sm btn-label-secondary btn-copy-elem" data-target="res-port" title="Copy Port">
                                                        <i class="ti tabler-copy"></i>
                                                    </button>
                                                </div>
                                                <div class="cred-val text-heading" id="res-port">--</div>
                                            </div>
                                        </div>

                                        <!-- Username -->
                                        <div class="col-12 col-md-6">
                                            <div class="cred-tile">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small text-muted fw-semibold">
                                                        <i class="ti tabler-user text-success me-1"></i>Streaming Username
                                                    </span>
                                                    <button type="button" class="btn btn-icon btn-sm btn-label-secondary btn-copy-elem" data-target="res-username" title="Copy Username">
                                                        <i class="ti tabler-copy"></i>
                                                    </button>
                                                </div>
                                                <div class="cred-val text-heading font-monospace" id="res-username">--</div>
                                            </div>
                                        </div>

                                        <!-- Password with Eye Toggle -->
                                        <div class="col-12 col-md-6">
                                            <div class="cred-tile">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small text-muted fw-semibold">
                                                        <i class="ti tabler-lock text-danger me-1"></i>Streaming Password
                                                    </span>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <button type="button" class="btn btn-icon btn-sm btn-label-secondary" id="btn-toggle-pw" title="Show / Hide Password">
                                                            <i class="ti tabler-eye" id="eye-pw-icon"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-icon btn-sm btn-label-secondary" id="btn-copy-pw" title="Copy Password">
                                                            <i class="ti tabler-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="cred-val text-heading font-monospace" id="res-password">••••••••••••</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Playlists & Web Player Hub -->
                            <div class="card shadow-none border mb-4">
                                <div class="card-header border-bottom py-3 bg-light-subtle">
                                    <h6 class="mb-0 fw-bold d-flex align-items-center">
                                        <i class="ti tabler-download text-primary me-2 fs-5"></i>Playlist Downloads & Direct Streaming Links
                                    </h6>
                                </div>
                                <div class="card-body p-3 p-md-4">
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <div class="p-3 rounded-3 border bg-body h-100 d-flex flex-column justify-content-between">
                                                <div>
                                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                                        <span class="fw-bold text-heading">M3U Plus (HLS / m3u8)</span>
                                                        <span class="badge bg-label-primary">Recommended</span>
                                                    </div>
                                                    <small class="text-muted d-block mb-3">Adaptive bitrate streaming ideal for Smart TVs, TiviMate, and mobile devices.</small>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <a href="#" id="res-m3u-hls" class="btn btn-primary btn-sm flex-grow-1" download="playlist.m3u">
                                                        <i class="ti tabler-download me-1"></i>Download .M3U
                                                    </a>
                                                    <button type="button" class="btn btn-label-secondary btn-sm" id="btn-copy-m3u" data-url="" title="Copy M3U URL">
                                                        <i class="ti tabler-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <div class="p-3 rounded-3 border bg-body h-100 d-flex flex-column justify-content-between">
                                                <div>
                                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                                        <span class="fw-bold text-heading">M3U Plus (MPEG-TS)</span>
                                                        <span class="badge bg-label-info">Direct TS</span>
                                                    </div>
                                                    <small class="text-muted d-block mb-3">Direct transport stream container preferred for VLC, Enigma2, and legacy boxes.</small>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <a href="#" id="res-m3u-ts" class="btn btn-info btn-sm flex-grow-1 text-white" download="playlist-ts.m3u">
                                                        <i class="ti tabler-download me-1"></i>Download .M3U
                                                    </a>
                                                    <button type="button" class="btn btn-label-secondary btn-sm" id="btn-copy-m3u-ts" data-url="" title="Copy M3U TS URL">
                                                        <i class="ti tabler-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Web Player & Direct Link Row -->
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 pt-3 border-top">
                                        <div class="d-flex align-items-center gap-2">
                                            <a href="#" target="_blank" class="btn btn-success d-none" id="res-launch-player">
                                                <i class="ti tabler-player-play me-1"></i>Launch Web Player
                                            </a>
                                            <button type="button" class="btn btn-label-secondary" id="btn-copy-epg" data-url="" title="Copy XMLTV EPG URL">
                                                <i class="ti tabler-calendar-event me-1"></i>Copy EPG URL
                                            </button>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-label-secondary" id="btn-activate-another">
                                                <i class="ti tabler-rotate me-1"></i>Activate Another Code
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Setup Instructions Accordion -->
                            <div class="accordion" id="accordionSetupTutorials">
                                <div class="accordion-item border rounded-3 overflow-hidden mb-2">
                                    <h2 class="accordion-header" id="headingTivi">
                                        <button class="accordion-button collapsed py-2 px-3 fw-semibold small bg-light-subtle" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTivi" aria-expanded="false">
                                            <i class="ti tabler-device-tv text-primary me-2"></i>How to Setup on TiviMate / Android TV
                                        </button>
                                    </h2>
                                    <div id="collapseTivi" class="accordion-collapse collapse" data-bs-parent="#accordionSetupTutorials">
                                        <div class="accordion-body p-3 small text-muted">
                                            1. Open TiviMate &rarr; <strong>Add Playlist</strong> &rarr; Select <strong>Xtream Codes Login</strong>.<br>
                                            2. Enter the <strong>Server Host / URL</strong> and <strong>Port</strong> shown above.<br>
                                            3. Enter your <strong>Username</strong> and <strong>Password</strong>, then click <strong>Next</strong>.<br>
                                            4. Wait 5-10 seconds for TV channels, movies, and EPG guides to sync automatically.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item border rounded-3 overflow-hidden mb-2">
                                    <h2 class="accordion-header" id="headingSmarters">
                                        <button class="accordion-button collapsed py-2 px-3 fw-semibold small bg-light-subtle" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSmarters" aria-expanded="false">
                                            <i class="ti tabler-player-play text-info me-2"></i>How to Setup on IPTV Smarters Pro (Mobile & Smart TV)
                                        </button>
                                    </h2>
                                    <div id="collapseSmarters" class="accordion-collapse collapse" data-bs-parent="#accordionSetupTutorials">
                                        <div class="accordion-body p-3 small text-muted">
                                            1. Open IPTV Smarters &rarr; Choose <strong>Login with Xtream Codes API</strong>.<br>
                                            2. Enter any name in <strong>Any Name</strong> (e.g. <?= htmlspecialchars($serverName, ENT_QUOTES); ?>).<br>
                                            3. Fill in <strong>Username</strong>, <strong>Password</strong>, and <strong>Server URL</strong> (with Port).<br>
                                            4. Click <strong>Add User</strong> to log in and start streaming immediately.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item border rounded-3 overflow-hidden">
                                    <h2 class="accordion-header" id="headingVLC">
                                        <button class="accordion-button collapsed py-2 px-3 fw-semibold small bg-light-subtle" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVLC" aria-expanded="false">
                                            <i class="ti tabler-brand-vlc text-warning me-2"></i>How to Setup on VLC Media Player (PC / Mac)
                                        </button>
                                    </h2>
                                    <div id="collapseVLC" class="accordion-collapse collapse" data-bs-parent="#accordionSetupTutorials">
                                        <div class="accordion-body p-3 small text-muted">
                                            1. Download the <strong>.M3U</strong> playlist file using the button above.<br>
                                            2. Drag and drop the downloaded file directly into VLC Player.<br>
                                            3. Press <strong>Ctrl + L</strong> (or Cmd + L on Mac) to view the complete channel playlist.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer: Authentic Platform Footer -->
                <footer class="content-footer footer bg-footer-theme py-4 mt-auto border-top">
                    <div class="container-xxl d-flex flex-wrap justify-content-between align-items-center py-2 flex-md-row flex-column">
                        <div class="text-body mb-2 mb-md-0 small">
                            &copy; <?= date('Y'); ?> <?= htmlspecialchars($serverName, ENT_QUOTES); ?> &bull; Powered by <span class="fw-semibold">XC_VM</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-label-secondary small">
                                <i class="ti tabler-lock me-1"></i>256-Bit TLS Encrypted
                            </span>
                            <span class="badge bg-label-success small">
                                <span class="badge-dot bg-success me-1"></span>Service Operational
                            </span>
                        </div>
                    </div>
                </footer>

            </div>
        </div>
    </div>

    <!-- QR CODE SCANNER MODAL -->
    <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-3">
                <div class="modal-header border-0 pb-0 justify-content-between">
                    <h6 class="modal-title fw-bold" id="qrCodeModalLabel">
                        <i class="ti tabler-qrcode text-primary me-1"></i>Scan to Connect
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3">
                    <ul class="nav nav-pills nav-fill mb-3" id="qr-tab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1 px-2 small" id="qr-m3u-tab" data-bs-toggle="pill" data-bs-target="#qr-m3u-pane" type="button" role="tab">M3U Link</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link py-1 px-2 small" id="qr-portal-tab" data-bs-toggle="pill" data-bs-target="#qr-portal-pane" type="button" role="tab">Web Player</button>
                        </li>
                    </ul>
                    <div class="tab-content" id="qr-tabContent">
                        <div class="tab-pane fade show active" id="qr-m3u-pane" role="tabpanel">
                            <div class="qr-canvas-box mb-2">
                                <img id="qr-code-img-m3u" src="" alt="M3U QR Code" width="200" height="200">
                            </div>
                            <small class="text-muted d-block">Scan on Mobile or Smart TV to load playlist directly.</small>
                        </div>
                        <div class="tab-pane fade" id="qr-portal-pane" role="tabpanel">
                            <div class="qr-canvas-box mb-2">
                                <img id="qr-code-img-portal" src="" alt="Player QR Code" width="200" height="200">
                            </div>
                            <small class="text-muted d-block">Scan to launch Web Player instantly.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 justify-content-center">
                    <button type="button" class="btn btn-sm btn-label-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Core Scripts -->
    <script src="assets/vendor/libs/jquery/jquery.js"></script>
    <script src="assets/vendor/js/bootstrap.js"></script>
    <script src="assets/vendor/libs/node-waves/node-waves.js"></script>
    <script src="assets/vendor/libs/sweetalert2/sweetalert2.js"></script>

    <script>
    (function($) {
        'use strict';

        // ─── Theme Toggler (Synced with data-bs-theme & localStorage) ───
        const themeBtn = document.getElementById('btn-theme-toggle');
        const themeIcon = document.getElementById('theme-icon');

        function updateThemeIcon(isLight) {
            if (!themeIcon) return;
            themeIcon.className = isLight ? 'ti tabler-sun icon-sm text-warning' : 'ti tabler-moon icon-sm';
        }

        const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'dark';
        updateThemeIcon(currentTheme === 'light');

        if (themeBtn) {
            themeBtn.addEventListener('click', function() {
                const now = document.documentElement.getAttribute('data-bs-theme') || 'dark';
                const next = (now === 'dark') ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                try {
                    localStorage.setItem('portal_theme', next);
                } catch (e) {}
                updateThemeIcon(next === 'light');
            });
        }

        // ─── Clipboard Helper ───
        function copyToClipboard(text, btnElement) {
            if (!text) return;
            const performUiSuccess = () => {
                if (!btnElement) return;
                const origHtml = btnElement.innerHTML;
                btnElement.innerHTML = '<i class="ti tabler-check me-1"></i>Copied!';
                btnElement.classList.add('btn-success');
                setTimeout(() => {
                    btnElement.innerHTML = origHtml;
                    btnElement.classList.remove('btn-success');
                }, 1800);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(performUiSuccess).catch(() => {
                    promptCopyFallback(text);
                });
            } else {
                try {
                    const ta = document.createElement('textarea');
                    ta.value = String(text);
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    performUiSuccess();
                } catch (e) {
                    promptCopyFallback(text);
                }
            }
        }

        function promptCopyFallback(text) {
            prompt('Copy to clipboard:', text);
        }

        // ─── DOM Elements ───
        const activateForm = document.getElementById('portal-activate-form');
        const voucherInput = document.getElementById('voucher-code');
        const submitBtn = document.getElementById('btn-activate');
        const errorAlert = document.getElementById('portal-error-alert');
        const errorText = document.getElementById('portal-error-text');
        const activationCard = document.getElementById('activation-card');
        const successCard = document.getElementById('success-card');
        const pasteBtn = document.getElementById('btn-paste-code');

        let originalPassword = '';
        let passwordHidden = true;
        let countdownInterval = null;

        // Paste button handler
        if (pasteBtn && voucherInput) {
            pasteBtn.addEventListener('click', async function() {
                try {
                    if (navigator.clipboard && navigator.clipboard.readText) {
                        const clipText = await navigator.clipboard.readText();
                        if (clipText) {
                            voucherInput.value = clipText.trim();
                            voucherInput.dispatchEvent(new Event('input'));
                            voucherInput.focus();
                        }
                    } else {
                        voucherInput.focus();
                        document.execCommand('paste');
                    }
                } catch (e) {
                    voucherInput.focus();
                }
            });
        }

        // Auto format voucher code (auto uppercase)
        if (voucherInput) {
            voucherInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/\s+/g, '');
            });
        }

        // Toggle Password Visibility
        const togglePwBtn = document.getElementById('btn-toggle-pw');
        const pwDisplay = document.getElementById('res-password');
        const eyePwIcon = document.getElementById('eye-pw-icon');

        if (togglePwBtn && pwDisplay) {
            togglePwBtn.addEventListener('click', function() {
                if (passwordHidden) {
                    pwDisplay.textContent = originalPassword;
                    if (eyePwIcon) eyePwIcon.className = 'ti tabler-eye-off';
                    passwordHidden = false;
                } else {
                    pwDisplay.textContent = '••••••••••••';
                    if (eyePwIcon) eyePwIcon.className = 'ti tabler-eye';
                    passwordHidden = true;
                }
            });
        }

        // Copy individual credential elements
        $(document).on('click', '.btn-copy-elem', function() {
            const targetId = $(this).data('target');
            const targetEl = document.getElementById(targetId);
            if (targetEl) {
                copyToClipboard(targetEl.textContent.trim(), this);
            }
        });

        // Copy Password button
        const copyPwBtn = document.getElementById('btn-copy-pw');
        if (copyPwBtn) {
            copyPwBtn.addEventListener('click', function() {
                copyToClipboard(originalPassword, this);
            });
        }

        // Copy Activation Code
        const copyCodeValBtn = document.getElementById('btn-copy-code-val');
        if (copyCodeValBtn) {
            copyCodeValBtn.addEventListener('click', function() {
                const code = this.getAttribute('data-code') || $('#res-active-code').text().trim();
                copyToClipboard(code, this);
            });
        }

        // Copy M3U & EPG URLs
        $(document).on('click', '#btn-copy-m3u, #btn-copy-m3u-ts, #btn-copy-epg', function() {
            const url = $(this).attr('data-url');
            if (url) {
                copyToClipboard(url, this);
            }
        });

        // Copy All Parameters
        const copyAllBtn = document.getElementById('btn-copy-all-creds');
        if (copyAllBtn) {
            copyAllBtn.addEventListener('click', function() {
                const host = $('#res-host').text().trim();
                const port = $('#res-port').text().trim();
                const user = $('#res-username').text().trim();
                const fullText = `Server Host: ${host}\nPort: ${port}\nUsername: ${user}\nPassword: ${originalPassword}`;
                copyToClipboard(fullText, this);
            });
        }

        // Activate Another Code Button
        const activateAnotherBtn = document.getElementById('btn-activate-another');
        if (activateAnotherBtn) {
            activateAnotherBtn.addEventListener('click', function() {
                if (countdownInterval) clearInterval(countdownInterval);
                if (successCard) successCard.classList.add('d-none');
                if (activationCard) activationCard.classList.remove('d-none');
                if (voucherInput) {
                    voucherInput.value = '';
                    voucherInput.focus();
                }
                if (errorAlert) errorAlert.classList.add('d-none');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // ─── Countdown Timer Engine ───
        function startCountdown(expTimestamp) {
            if (countdownInterval) clearInterval(countdownInterval);

            function update() {
                const now = Math.floor(Date.now() / 1000);
                const diff = expTimestamp - now;

                if (diff <= 0) {
                    $('#cd-days').text('00');
                    $('#cd-hours').text('00');
                    $('#cd-minutes').text('00');
                    $('#cd-seconds').text('00');
                    $('#res-status-badge').removeClass('bg-label-success').addClass('bg-label-danger').html('<i class="ti tabler-alert-circle me-1"></i>Expired');
                    clearInterval(countdownInterval);
                    return;
                }

                const days = Math.floor(diff / 86400);
                const hours = Math.floor((diff % 86400) / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;

                $('#cd-days').text(String(days).padStart(2, '0'));
                $('#cd-hours').text(String(hours).padStart(2, '0'));
                $('#cd-minutes').text(String(minutes).padStart(2, '0'));
                $('#cd-seconds').text(String(seconds).padStart(2, '0'));
            }

            update();
            countdownInterval = setInterval(update, 1000);
        }

        // ─── Render Success Payload ───
        function renderSuccess(data, code) {
            if (!data) return;

            $('#res-active-code').text(code || data.code || '');
            $('#btn-copy-code-val').attr('data-code', code || data.code || '');
            $('#res-pkg-badge').text(data.package_name || 'Active Package');
            $('#res-pkg-name').text(data.package_name || 'Active Package');
            $('#res-exp-formatted').text(data.exp_date_formatted || '');

            const maxConn = data.max_connections || 1;
            $('#res-conn-count').html(`<i class="ti tabler-devices me-1"></i>${maxConn} Connection${maxConn > 1 ? 's' : ''}`);

            // Origin / Scheme
            const currentProtocol = window.location.protocol;
            const currentHost = window.location.host;
            const defaultOrigin = `${currentProtocol}//${currentHost}`;

            if (data.credentials) {
                let serverHostUrl = defaultOrigin;
                if (data.credentials.host && data.credentials.host.startsWith('http')) {
                    try {
                        const u = new URL(data.credentials.host);
                        if (u.hostname === 'localhost' || u.hostname === '127.0.0.1' || !u.hostname) {
                            serverHostUrl = defaultOrigin;
                        } else {
                            serverHostUrl = `${currentProtocol}//${u.host}`;
                        }
                    } catch (e) {
                        serverHostUrl = defaultOrigin;
                    }
                } else if (data.credentials.host) {
                    serverHostUrl = `${currentProtocol}//${data.credentials.host.replace(/^\/+/, '')}`;
                }

                let serverPort = data.credentials.port || '';
                if (!serverPort || serverPort == 80 || serverPort == 443) {
                    try {
                        const pu = new URL(serverHostUrl);
                        serverPort = pu.port || (currentProtocol === 'https:' ? 443 : 80);
                    } catch (e) {
                        serverPort = currentProtocol === 'https:' ? 443 : 80;
                    }
                }

                $('#res-host').text(serverHostUrl).attr('title', serverHostUrl);
                $('#res-port').text(serverPort);
                $('#res-username').text(data.credentials.username || '');

                originalPassword = data.credentials.password || '';
                $('#res-password').text('••••••••••••');
                passwordHidden = true;
                if (eyePwIcon) eyePwIcon.className = 'ti tabler-eye';

                // Playlists & EPG
                const m3uHls = `${serverHostUrl}/get.php?username=${encodeURIComponent(data.credentials.username)}&password=${encodeURIComponent(data.credentials.password)}&type=m3u_plus&output=hls`;
                const m3uTs  = `${serverHostUrl}/get.php?username=${encodeURIComponent(data.credentials.username)}&password=${encodeURIComponent(data.credentials.password)}&type=m3u_plus&output=ts`;
                const epgUrl = `${serverHostUrl}/xmltv.php?username=${encodeURIComponent(data.credentials.username)}&password=${encodeURIComponent(data.credentials.password)}`;

                $('#res-m3u-hls').attr('href', m3uHls);
                $('#res-m3u-ts').attr('href', m3uTs);
                $('#btn-copy-m3u').attr('data-url', m3uHls);
                $('#btn-copy-m3u-ts').attr('data-url', m3uTs);
                $('#btn-copy-epg').attr('data-url', epgUrl);

                // QR Codes
                const qrImgM3u = document.getElementById('qr-code-img-m3u');
                const qrImgPortal = document.getElementById('qr-code-img-portal');
                const playerUrl = data.web_player_url || `${serverHostUrl}/portal`;

                if (qrImgM3u) {
                    qrImgM3u.src = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(m3uHls)}`;
                }
                if (qrImgPortal) {
                    qrImgPortal.src = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(playerUrl)}`;
                }
            }

            // Web Player button
            const playerBtn = document.getElementById('res-launch-player');
            if (data.web_player_url && playerBtn) {
                playerBtn.href = data.web_player_url;
                playerBtn.classList.remove('d-none');
            } else if (playerBtn) {
                playerBtn.classList.add('d-none');
            }

            // Countdown
            if (data.exp_date) {
                startCountdown(data.exp_date);
            }

            // Swap cards
            if (activationCard) activationCard.classList.add('d-none');
            if (successCard) {
                successCard.classList.remove('d-none');
                successCard.scrollIntoView({ behavior: 'smooth' });
            }
        }

        // ─── Initial Result Handling (if supplied by server directly) ───
        const serverInitialResult = <?= $initialResultJson; ?>;
        if (serverInitialResult) {
            if (serverInitialResult.status === 'SUCCESS') {
                renderSuccess(serverInitialResult, <?= json_encode($initialCode); ?>);
            } else if (serverInitialResult.message) {
                if (errorText) errorText.textContent = serverInitialResult.message;
                if (errorAlert) errorAlert.classList.remove('d-none');
            }
        }

        // ─── Activation Form AJAX Submission ───
        if (activateForm) {
            activateForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const code = (voucherInput ? voucherInput.value.trim() : '').toUpperCase();
                const mac = (document.getElementById('device-mac') ? document.getElementById('device-mac').value.trim() : '');
                const deviceId = (document.getElementById('device-id') ? document.getElementById('device-id').value.trim() : '');

                if (!code) {
                    if (errorText) errorText.textContent = 'Please enter an activation code.';
                    if (errorAlert) errorAlert.classList.remove('d-none');
                    return;
                }

                if (errorAlert) errorAlert.classList.add('d-none');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Activating Subscription...';
                }

                $.ajax({
                    url: './portal',
                    type: 'POST',
                    dataType: 'json',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    data: {
                        action: 'activate',
                        code: code,
                        mac: mac,
                        device_id: deviceId
                    },
                    success: function(res) {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="ti tabler-bolt me-2 fs-4"></i>Activate Subscription Now';
                        }

                        if (!res || res.status !== 'SUCCESS') {
                            const msg = (res && res.message) ? res.message : 'Activation failed. Please check your voucher code.';
                            if (errorText) errorText.textContent = msg;
                            if (errorAlert) errorAlert.classList.remove('d-none');
                            return;
                        }

                        renderSuccess(res, code);
                    },
                    error: function() {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="ti tabler-bolt me-2 fs-4"></i>Activate Subscription Now';
                        }
                        if (errorText) errorText.textContent = 'Network or connection error. Please try again.';
                        if (errorAlert) errorAlert.classList.remove('d-none');
                    }
                });
            });
        }

        // ─── Auto-submit if code is in URL ───
        const initialParamCode = <?= json_encode($initialCode); ?>;
        if (initialParamCode && !serverInitialResult && activateForm) {
            $(activateForm).trigger('submit');
        }

    })(jQuery);
    </script>
</body>

</html>
