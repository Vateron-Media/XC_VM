<?php

/**
 * Subscriber Activation Portal (Bootstrap 5 / Sneat-Vuexy Platform Theme)
 *
 * Designed to seamlessly match the XC_VM platform's active codes management UI,
 * featuring Public Sans & JetBrains Mono typography, Tabler icons, Vuexy dark styling,
 * live delayed-countdown timer, Xtream Codes credentials viewer, and M3U downloads.
 */

use XcVm\Core\Config\SettingsManager;

$serverName = class_exists(SettingsManager::class)
    ? (SettingsManager::get('server_name') ?: 'XC_VM')
    : 'XC_VM';
$serverLogo = '/assets/admin/img/logo-topbar.png';
$initialCode = trim((string)($_GET['code'] ?? ''));
?>
<!doctype html>
<html
    lang="en"
    class="layout-navbar-fixed layout-menu-fixed layout-compact"
    dir="ltr"
    data-skin="default"
    data-bs-theme="dark"
    data-assets-path="/assets/admin/"
    data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($serverName, ENT_QUOTES); ?> | Subscriber Activation Portal</title>
    <meta name="description" content="Redeem your voucher code to activate streaming access, obtain Xtream Codes credentials, and download M3U playlists.">
    <link rel="icon" type="image/x-icon" href="/assets/admin/img/favicon/favicon.ico">

    <!-- Fonts: Public Sans & JetBrains Mono (Platform Standard) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap">

    <!-- Tabler Icons -->
    <link rel="stylesheet" href="/assets/admin/vendor/fonts/iconify-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">

    <!-- Core Platform Styles (Bootstrap 5 + Vuexy Theme) -->
    <link rel="stylesheet" href="/assets/admin/vendor/css/bootstrap.css">
    <link rel="stylesheet" href="/assets/admin/vendor/css/custom.css">
    <link rel="stylesheet" href="/assets/admin/xcvm/custom.css">

    <style>
        :root {
            --bs-primary: #7367f0;
            --bs-primary-rgb: 115, 103, 240;
            --bs-body-bg: #25293c;
            --bs-body-color: #cfcde4;
            --bs-card-bg: #2f3349;
            --bs-border-color: rgba(255, 255, 255, 0.08);
        }

        body {
            font-family: 'Public Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #25293c;
            color: #cfcde4;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            padding: 0;
        }

        .portal-topbar {
            background-color: #2f3349;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 0.85rem 0;
        }

        .app-brand-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.2px;
        }

        .portal-container {
            max-width: 820px;
            margin: 0 auto;
            width: 100%;
        }

        .card {
            background-color: #2f3349;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.75rem;
            box-shadow: 0 0.25rem 1.125rem rgba(15, 20, 34, 0.4);
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            background-color: transparent;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .code-input-lg {
            font-family: 'JetBrains Mono', monospace !important;
            font-size: 1.5rem !important;
            font-weight: 700 !important;
            letter-spacing: 3px !important;
            text-transform: uppercase !important;
            text-align: center !important;
            padding: 0.9rem 1.25rem !important;
            background-color: #25293c !important;
            color: #7367f0 !important;
            border-color: #434968 !important;
        }

        .code-input-lg:focus {
            border-color: #7367f0 !important;
            box-shadow: 0 0 0 0.25rem rgba(115, 103, 240, 0.25) !important;
        }

        .btn-primary {
            background-color: #7367f0 !important;
            border-color: #7367f0 !important;
            box-shadow: 0 0.125rem 0.25rem 0 rgba(115, 103, 240, 0.4);
            font-weight: 600;
        }

        .btn-primary:hover, .btn-primary:focus {
            background-color: #685dd8 !important;
            border-color: #685dd8 !important;
            box-shadow: 0 0.25rem 0.5rem 0 rgba(115, 103, 240, 0.5);
        }

        .btn-label-primary {
            background-color: rgba(115, 103, 240, 0.16) !important;
            color: #7367f0 !important;
            border: 1px solid transparent !important;
        }

        .btn-label-primary:hover {
            background-color: #7367f0 !important;
            color: #ffffff !important;
        }

        .btn-label-secondary {
            background-color: rgba(168, 170, 174, 0.16) !important;
            color: #a8aaae !important;
            border: 1px solid transparent !important;
        }

        .btn-label-secondary:hover {
            background-color: #a8aaae !important;
            color: #25293c !important;
        }

        .btn-label-info {
            background-color: rgba(0, 186, 209, 0.16) !important;
            color: #00bad1 !important;
            border: 1px solid transparent !important;
        }

        .btn-label-info:hover {
            background-color: #00bad1 !important;
            color: #ffffff !important;
        }

        .btn-label-success {
            background-color: rgba(40, 199, 111, 0.16) !important;
            color: #28c76f !important;
            border: 1px solid transparent !important;
        }

        .btn-label-warning {
            background-color: rgba(255, 159, 67, 0.16) !important;
            color: #ff9f43 !important;
            border: 1px solid transparent !important;
        }

        .badge.bg-label-primary {
            background-color: rgba(115, 103, 240, 0.16) !important;
            color: #7367f0 !important;
        }

        .badge.bg-label-success {
            background-color: rgba(40, 199, 111, 0.16) !important;
            color: #28c76f !important;
        }

        .badge.bg-label-info {
            background-color: rgba(0, 186, 209, 0.16) !important;
            color: #00bad1 !important;
        }

        .badge.bg-label-warning {
            background-color: rgba(255, 159, 67, 0.16) !important;
            color: #ff9f43 !important;
        }

        /* Countdown Segments */
        .countdown-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
        }

        .countdown-tile {
            background-color: #25293c;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0.5rem;
            padding: 1rem 0.5rem;
            text-align: center;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .countdown-number {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.9rem;
            font-weight: 700;
            line-height: 1;
            color: #7367f0;
        }

        .countdown-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            color: #8692d0;
            letter-spacing: 1px;
            margin-top: 0.35rem;
        }

        .cred-box {
            background-color: #25293c;
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
        }

        .pulse-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #28c76f;
            box-shadow: 0 0 0 rgba(40, 199, 111, 0.6);
            animation: pulse-dot-anim 2s infinite;
        }

        @keyframes pulse-dot-anim {
            0% { box-shadow: 0 0 0 0 rgba(40, 199, 111, 0.7); }
            70% { box-shadow: 0 0 0 7px rgba(40, 199, 111, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 199, 111, 0); }
        }

        .toast-copy-feedback {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1090;
        }

        @media (max-width: 576px) {
            .countdown-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .code-input-lg {
                font-size: 1.25rem !important;
                letter-spacing: 2px !important;
            }
        }
    </style>
</head>

<body>
    <!-- Topbar matching Platform Admin/Reseller Header -->
    <header class="portal-topbar mb-4">
        <div class="container-xxl d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <span class="app-brand-logo demo me-2">
                    <img src="<?= htmlspecialchars($serverLogo, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($serverName, ENT_QUOTES); ?>" height="28" onerror="this.style.display='none'">
                </span>
                <span class="app-brand-text"><?= htmlspecialchars($serverName, ENT_QUOTES); ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-label-primary px-3 py-2 d-none d-sm-inline-flex align-items-center">
                    <i class="ti tabler-sparkles me-1"></i>Subscriber Activation Portal
                </span>
                <span class="badge bg-label-success px-3 py-2 d-inline-flex align-items-center">
                    <span class="pulse-dot me-2"></span>Service Online
                </span>
            </div>
        </div>
    </header>

    <!-- Main Content Stage -->
    <main class="container-xxl flex-grow-1 d-flex flex-column justify-content-center py-3">
        <div class="portal-container">
            
            <!-- Breadcrumb / Header Subtitle -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h4 class="fw-bold mb-1 d-flex align-items-center">
                        <i class="ti tabler-ticket text-primary me-2 fs-3"></i>Smart Code Activation
                    </h4>
                    <p class="text-muted small mb-0">Redeem voucher codes, stream IPTV, and access live playlist endpoints.</p>
                </div>
                <div class="text-end d-none d-md-block">
                    <span class="badge bg-label-secondary"><i class="ti tabler-shield-check me-1"></i>Secure Stream Provisioning</span>
                </div>
            </div>

            <!-- ACTIVATION INPUT CARD -->
            <div class="card shadow-sm border-0 mb-4" id="activation-card">
                <div class="card-header border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-1"><i class="ti tabler-key text-primary me-2"></i>Enter Activation Voucher</h5>
                        <p class="text-muted small mb-0">Enter your active voucher PIN to unlock your stream access.</p>
                    </div>
                    <span class="badge bg-label-info">
                        <i class="ti tabler-bolt me-1"></i>Instant Provision
                    </span>
                </div>

                <div class="card-body p-4">
                    <form id="portal-form">
                        <div class="mb-4">
                            <label class="form-label fw-semibold text-uppercase small text-muted mb-2 d-block text-center" for="activation-code">
                                Activation Code / Voucher PIN <span class="text-danger">*</span>
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light-subtle"><i class="ti tabler-ticket text-primary fs-4"></i></span>
                                <input 
                                    type="text" 
                                    id="activation-code" 
                                    name="code" 
                                    class="form-control code-input-lg" 
                                    placeholder="8X7K9P2M" 
                                    maxlength="32" 
                                    autocomplete="off" 
                                    autofocus 
                                    required 
                                    value="<?= htmlspecialchars($initialCode, ENT_QUOTES); ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btn-paste-code" title="Paste from clipboard">
                                    <i class="ti tabler-clipboard"></i>
                                </button>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 px-1">
                                <small class="text-muted">
                                    <i class="ti tabler-info-circle me-1"></i>Letters and digits only • Case insensitive
                                </small>
                                <a href="javascript:void(0);" class="small text-decoration-none text-muted" id="btn-toggle-advanced">
                                    <i class="ti tabler-device-desktop me-1"></i>Device Binding (Optional)
                                </a>
                            </div>
                        </div>

                        <!-- Optional Advanced Device / MAC Lock -->
                        <div class="d-none mb-4 p-3 bg-light-subtle rounded-3 border" id="advanced-device-box">
                            <label class="form-label fw-semibold small text-muted text-uppercase" for="device_mac">
                                Device MAC Address (Optional Lock)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ti tabler-cpu"></i></span>
                                <input type="text" id="device_mac" name="mac" class="form-control font-monospace" placeholder="00:1A:79:XX:XX:XX">
                            </div>
                            <div class="form-text small">Leave blank unless your code is locked to a specific MAC / MAG STB.</div>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg" id="btn-activate-submit">
                                <span class="d-flex align-items-center justify-content-center gap-2">
                                    <i class="ti tabler-bolt fs-4"></i>
                                    <span class="fs-5">Activate Subscription</span>
                                </span>
                            </button>
                        </div>
                    </form>

                    <!-- Error Alert -->
                    <div id="portal-error-alert" class="alert alert-danger d-flex align-items-center mt-4 d-none" role="alert">
                        <i class="ti tabler-alert-circle fs-3 me-2 flex-shrink-0"></i>
                        <div id="portal-error-text" class="fw-semibold">Invalid activation code entered.</div>
                    </div>
                </div>
            </div>

            <!-- SUCCESS RESULT CARD (Styled Exactly like Active Codes Details Modal) -->
            <div class="card shadow-sm border-0 d-none" id="success-card">
                <div class="card-header border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="card-title mb-1 text-success d-flex align-items-center">
                            <i class="ti tabler-circle-check text-success me-2 fs-4"></i>Subscription Activated Successfully
                        </h5>
                        <p class="text-muted small mb-0">Your streaming credentials and playlist links are now active and ready.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success fs-6 px-3 py-2" id="res-status-badge">
                            <i class="ti tabler-circle-check me-1"></i>Active
                        </span>
                        <span class="badge bg-label-primary fs-6 px-3 py-2" id="res-pkg-badge">
                            Premium Package
                        </span>
                    </div>
                </div>

                <div class="card-body p-4">
                    <!-- Highlight Grid -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <div class="p-3 bg-light-subtle rounded-3 border h-100">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Activation Code</label>
                                <div class="d-flex align-items-center justify-content-between mt-1">
                                    <span class="fs-4 fw-bold font-monospace text-primary" id="res-active-code">--------</span>
                                    <button class="btn btn-sm btn-outline-primary btn-copy-code" id="btn-copy-code-val" data-code="">
                                        <i class="ti tabler-copy me-1"></i>Copy
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <div class="p-3 bg-light-subtle rounded-3 border h-100">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Package & Plan</label>
                                <div class="d-flex align-items-center justify-content-between mt-1">
                                    <span class="fs-5 fw-bold text-white" id="res-pkg-name">Full IPTV Package</span>
                                    <span class="badge bg-label-info" id="res-conn-count">
                                        <i class="ti tabler-devices me-1"></i>1 Connection
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Digital Countdown Expiry Box -->
                    <div class="p-3 bg-light-subtle rounded-3 border mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                            <span class="small text-muted text-uppercase fw-semibold">
                                <i class="ti tabler-clock text-warning me-1"></i>Subscription Remaining Time
                            </span>
                            <span class="small text-muted">
                                Expires on: <span id="res-exp-formatted" class="font-monospace text-white fw-bold"></span>
                            </span>
                        </div>
                        <div class="countdown-grid" id="countdown-timer">
                            <div class="countdown-tile">
                                <div class="countdown-number" id="cd-days">00</div>
                                <div class="countdown-title">Days</div>
                            </div>
                            <div class="countdown-tile">
                                <div class="countdown-number" id="cd-hours">00</div>
                                <div class="countdown-title">Hours</div>
                            </div>
                            <div class="countdown-tile">
                                <div class="countdown-number" id="cd-minutes">00</div>
                                <div class="countdown-title">Minutes</div>
                            </div>
                            <div class="countdown-tile">
                                <div class="countdown-number" id="cd-seconds">00</div>
                                <div class="countdown-title">Seconds</div>
                            </div>
                        </div>
                    </div>

                    <!-- Xtream Codes Credentials Card (Platform Design Standard) -->
                    <div class="card bg-dark text-white border-0 shadow-sm mb-4">
                        <div class="card-body p-3 p-md-4">
                            <h6 class="card-title text-white d-flex align-items-center gap-2 mb-3">
                                <i class="ti tabler-device-tv text-warning fs-5"></i>Xtream Codes & Streaming Credentials
                            </h6>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <div class="cred-box">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-secondary text-uppercase fw-semibold">Username</small>
                                            <button class="btn btn-sm btn-icon btn-outline-secondary py-0 px-2 btn-copy-elem" data-target="res-username" title="Copy Username">
                                                <i class="ti tabler-copy fs-6"></i>
                                            </button>
                                        </div>
                                        <div class="font-monospace fw-bold fs-5 text-white mt-1" id="res-username">--</div>
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="cred-box">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-secondary text-uppercase fw-semibold">Password</small>
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-icon btn-outline-secondary py-0 px-2" id="btn-toggle-pw" title="Show/Hide Password">
                                                    <i class="ti tabler-eye fs-6" id="eye-pw-icon"></i>
                                                </button>
                                                <button class="btn btn-sm btn-icon btn-outline-secondary py-0 px-2 btn-copy-elem" data-target="res-password" title="Copy Password">
                                                    <i class="ti tabler-copy fs-6"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="font-monospace fw-bold fs-5 text-white mt-1" id="res-password">--</div>
                                    </div>
                                </div>

                                <div class="col-12 col-md-8">
                                    <div class="cred-box">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-secondary text-uppercase fw-semibold">Server Host / URL</small>
                                            <button class="btn btn-sm btn-icon btn-outline-secondary py-0 px-2 btn-copy-elem" data-target="res-host" title="Copy Server Host">
                                                <i class="ti tabler-copy fs-6"></i>
                                            </button>
                                        </div>
                                        <div class="font-monospace small text-white mt-1 text-truncate" id="res-host">--</div>
                                    </div>
                                </div>

                                <div class="col-12 col-md-4">
                                    <div class="cred-box">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-secondary text-uppercase fw-semibold">Port</small>
                                            <button class="btn btn-sm btn-icon btn-outline-secondary py-0 px-2 btn-copy-elem" data-target="res-port" title="Copy Port">
                                                <i class="ti tabler-copy fs-6"></i>
                                            </button>
                                        </div>
                                        <div class="font-monospace fw-bold text-white mt-1" id="res-port">--</div>
                                    </div>
                                </div>
                            </div>

                            <hr class="border-secondary my-3">

                            <!-- Action Buttons Bar -->
                            <div class="d-flex flex-wrap gap-2">
                                <a href="#" id="res-m3u-hls" class="btn btn-outline-light flex-fill" target="_blank">
                                    <i class="ti tabler-download me-1"></i>Download M3U (HLS)
                                </a>
                                <a href="#" id="res-m3u-ts" class="btn btn-outline-light flex-fill" target="_blank">
                                    <i class="ti tabler-download me-1"></i>Download M3U (TS)
                                </a>
                                <button type="button" id="btn-copy-m3u" class="btn btn-primary flex-fill">
                                    <i class="ti tabler-copy me-1"></i>Copy M3U Link
                                </button>
                                <a href="#" id="res-launch-player" class="btn btn-warning fw-bold flex-fill d-none" target="_blank">
                                    <i class="ti tabler-player-play me-1"></i>Launch Web Player
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Options -->
                    <div class="d-flex justify-content-between align-items-center pt-2">
                        <button type="button" id="btn-portal-back" class="btn btn-label-secondary">
                            <i class="ti tabler-arrow-left me-1"></i>Activate Another Voucher
                        </button>
                        <button type="button" onclick="window.print()" class="btn btn-label-secondary">
                            <i class="ti tabler-printer me-1"></i>Print Voucher Details
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="text-center py-3 border-top mt-auto" style="border-color: rgba(255, 255, 255, 0.08) !important;">
        <div class="container-xxl">
            <small class="text-muted">
                &copy; <?= date('Y'); ?> <?= htmlspecialchars($serverName, ENT_QUOTES); ?>. All rights reserved. Powered by Smart Activation Engine.
            </small>
        </div>
    </footer>

    <!-- Bootstrap Bundle JS -->
    <script src="/assets/admin/vendor/js/bootstrap.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('portal-form');
        const codeInput = document.getElementById('activation-code');
        const submitBtn = document.getElementById('btn-activate-submit');
        const errorAlert = document.getElementById('portal-error-alert');
        const errorText = document.getElementById('portal-error-text');
        const activationCard = document.getElementById('activation-card');
        const successCard = document.getElementById('success-card');
        const toggleAdvBtn = document.getElementById('btn-toggle-advanced');
        const advBox = document.getElementById('advanced-device-box');
        const pasteBtn = document.getElementById('btn-paste-code');

        let countdownInterval = null;
        let originalPassword = '';
        let passwordHidden = false;

        // Auto uppercase & clean code input
        codeInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });

        // Advanced device toggle
        if (toggleAdvBtn && advBox) {
            toggleAdvBtn.addEventListener('click', function() {
                advBox.classList.toggle('d-none');
            });
        }

        // Paste button
        if (pasteBtn && navigator.clipboard) {
            pasteBtn.addEventListener('click', function() {
                navigator.clipboard.readText().then(text => {
                    if (text) {
                        codeInput.value = text.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                        codeInput.focus();
                    }
                }).catch(() => {});
            });
        }

        // Form Submit via AJAX
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const code = codeInput.value.trim();
            if (!code) return;

            const mac = document.getElementById('device_mac') ? document.getElementById('device_mac').value.trim() : '';

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Activating...';
            errorAlert.classList.add('d-none');

            const postUrl = window.location.pathname + window.location.search;
            fetch(postUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'activate',
                    code: code,
                    mac: mac
                })
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="ti tabler-bolt fs-4 me-2"></i>Activate Subscription';

                if (data.status !== 'SUCCESS') {
                    errorText.textContent = data.message || 'Activation failed.';
                    errorAlert.classList.remove('d-none');
                    return;
                }

                // Populate success card
                document.getElementById('res-active-code').textContent = code;
                document.getElementById('btn-copy-code-val').setAttribute('data-code', code);
                document.getElementById('res-pkg-badge').textContent = data.package_name || 'Active Package';
                document.getElementById('res-pkg-name').textContent = data.package_name || 'Active Package';
                document.getElementById('res-exp-formatted').textContent = data.exp_date_formatted || '';

                if (data.credentials) {
                    document.getElementById('res-host').textContent = data.credentials.host || '';
                    document.getElementById('res-username').textContent = data.credentials.username || '';
                    originalPassword = data.credentials.password || '';
                    document.getElementById('res-password').textContent = originalPassword;

                    // Extract port if present
                    const hostParts = (data.credentials.host || '').split(':');
                    const port = hostParts.length > 2 ? hostParts[hostParts.length - 1].split('/')[0] : '80';
                    document.getElementById('res-port').textContent = port;
                }

                // Playlists
                if (data.playlists) {
                    document.getElementById('res-m3u-hls').href = data.playlists.m3u_hls || '#';
                    document.getElementById('res-m3u-ts').href = data.playlists.m3u_ts || '#';
                    document.getElementById('btn-copy-m3u').setAttribute('data-url', data.playlists.m3u_hls || '');
                }

                // Web Player button
                const pBtn = document.getElementById('res-launch-player');
                if (data.web_player_url && pBtn) {
                    pBtn.href = data.web_player_url;
                    pBtn.classList.remove('d-none');
                } else if (pBtn) {
                    pBtn.classList.add('d-none');
                }

                // Start countdown
                startCountdown(data.exp_date);

                // Transition cards
                activationCard.classList.add('d-none');
                successCard.classList.remove('d-none');
                successCard.scrollIntoView({ behavior: 'smooth' });
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="ti tabler-bolt fs-4 me-2"></i>Activate Subscription';
                errorText.textContent = 'Network or connection error. Please try again.';
                errorAlert.classList.remove('d-none');
            });
        });

        // Countdown Timer Logic
        function startCountdown(expTimestamp) {
            if (countdownInterval) clearInterval(countdownInterval);

            function update() {
                const now = Math.floor(Date.now() / 1000);
                const diff = expTimestamp - now;

                if (diff <= 0) {
                    clearInterval(countdownInterval);
                    document.getElementById('cd-days').textContent = '00';
                    document.getElementById('cd-hours').textContent = '00';
                    document.getElementById('cd-minutes').textContent = '00';
                    document.getElementById('cd-seconds').textContent = '00';
                    const badge = document.getElementById('res-status-badge');
                    if (badge) {
                        badge.className = 'badge bg-danger fs-6 px-3 py-2';
                        badge.innerHTML = '<i class="ti tabler-clock-x me-1"></i>Expired';
                    }
                    return;
                }

                const days = Math.floor(diff / 86400);
                const hours = Math.floor((diff % 86400) / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;

                document.getElementById('cd-days').textContent = String(days).padStart(2, '0');
                document.getElementById('cd-hours').textContent = String(hours).padStart(2, '0');
                document.getElementById('cd-minutes').textContent = String(minutes).padStart(2, '0');
                document.getElementById('cd-seconds').textContent = String(seconds).padStart(2, '0');
            }

            update();
            countdownInterval = setInterval(update, 1000);
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise((resolve, reject) => {
                try {
                    const textarea = document.createElement('textarea');
                    textarea.value = String(text);
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    textarea.style.top = '0';
                    textarea.setAttribute('readonly', '');
                    document.body.appendChild(textarea);
                    textarea.focus();
                    textarea.select();
                    const success = document.execCommand('copy');
                    document.body.removeChild(textarea);
                    success ? resolve() : reject();
                } catch (err) {
                    reject(err);
                }
            });
        }

        // Copy Handlers
        document.querySelectorAll('.btn-copy-elem').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const text = document.getElementById(targetId).textContent.trim();
                copyToClipboard(text).then(() => {
                    const icon = this.querySelector('i');
                    const origClass = icon.className;
                    icon.className = 'ti tabler-check text-success fs-6';
                    setTimeout(() => icon.className = origClass, 1500);
                }).catch(() => {
                    prompt('Copy to clipboard:', text);
                });
            });
        });

        const copyCodeBtn = document.getElementById('btn-copy-code-val');
        if (copyCodeBtn) {
            copyCodeBtn.addEventListener('click', function() {
                const code = this.getAttribute('data-code');
                copyToClipboard(code).then(() => {
                    const orig = copyCodeBtn.innerHTML;
                    copyCodeBtn.innerHTML = '<i class="ti tabler-check text-success me-1"></i>Copied!';
                    setTimeout(() => copyCodeBtn.innerHTML = orig, 1500);
                }).catch(() => {
                    prompt('Copy code:', code);
                });
            });
        }

        const copyM3uBtn = document.getElementById('btn-copy-m3u');
        if (copyM3uBtn) {
            copyM3uBtn.addEventListener('click', function() {
                const url = this.getAttribute('data-url');
                copyToClipboard(url).then(() => {
                    const orig = copyM3uBtn.innerHTML;
                    copyM3uBtn.innerHTML = '<i class="ti tabler-check me-1"></i>Copied M3U Link!';
                    setTimeout(() => copyM3uBtn.innerHTML = orig, 1500);
                }).catch(() => {
                    prompt('Copy M3U Link:', url);
                });
            });
        }

        // Toggle password visibility
        const togglePwBtn = document.getElementById('btn-toggle-pw');
        if (togglePwBtn) {
            togglePwBtn.addEventListener('click', function() {
                const pwElem = document.getElementById('res-password');
                const icon = document.getElementById('eye-pw-icon');
                if (passwordHidden) {
                    pwElem.textContent = originalPassword;
                    icon.className = 'ti tabler-eye fs-6';
                    passwordHidden = false;
                } else {
                    pwElem.textContent = '••••••••••••';
                    icon.className = 'ti tabler-eye-off fs-6';
                    passwordHidden = true;
                }
            });
        }

        // Back to activation form
        const backBtn = document.getElementById('btn-portal-back');
        if (backBtn) {
            backBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (countdownInterval) clearInterval(countdownInterval);
                codeInput.value = '';
                successCard.classList.add('d-none');
                activationCard.classList.remove('d-none');
                codeInput.focus();
            });
        }

        // Auto-submit if auto query param is present
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto') === '1' && codeInput.value.trim().length >= 4) {
            form.dispatchEvent(new Event('submit'));
        }
    });
    </script>
</body>
</html>
