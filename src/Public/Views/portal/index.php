<?php

/**
 * Subscriber Activation Portal (Elite Next-Gen Edition)
 *
 * Designed to deliver an executive, world-class streaming activation experience
 * with rich glassmorphism, ambient lighting mesh, distinctive Day & Night modes,
 * live HUD countdown timer, Xtream Codes credentials viewer, and instant M3U downloads.
 */

use XcVm\Core\Config\SettingsManager;

$serverName = class_exists(SettingsManager::class)
    ? (SettingsManager::get('server_name') ?: 'XC_VM')
    : 'XC_VM';
$serverLogo = 'assets/img/logo-topbar.png';
$initialCode = trim((string)($_GET['code'] ?? ''));
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
    <link rel="icon" type="image/x-icon" href="assets/img/favicon/favicon.ico">

    <!-- Zero-Flicker Theme State Restoration -->
    <script>
        (function() {
            var t = localStorage.getItem('portal_theme');
            if (!t) {
                t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
            }
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>

    <!-- Google Fonts: Public Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700;800&display=swap">

    <!-- Tabler Icons -->
    <link rel="stylesheet" href="assets/vendor/fonts/iconify-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">

    <!-- Core Platform Styles (Bootstrap 5 + Vuexy Theme) -->
    <link rel="stylesheet" href="assets/vendor/css/bootstrap.css">
    <link rel="stylesheet" href="assets/vendor/css/custom.css">
    <link rel="stylesheet" href="assets/xcvm/custom.css">

    <style>
        /* ==========================================================================
           DUAL THEME DESIGN SYSTEM (NIGHT / DAY)
           ========================================================================== */
        :root,
        [data-bs-theme="dark"] {
            --bs-primary: #7367f0;
            --bs-primary-rgb: 115, 103, 240;
            --bs-body-bg: #0b0e1e;
            --bs-body-color: #cfd3ec;

            --portal-canvas-bg: #0b0e1e;
            --portal-ambient-glow-1: rgba(115, 103, 240, 0.16);
            --portal-ambient-glow-2: rgba(0, 186, 209, 0.12);
            --portal-ambient-glow-3: rgba(40, 199, 111, 0.08);

            --portal-topbar-bg: rgba(18, 22, 44, 0.85);
            --portal-topbar-border: rgba(255, 255, 255, 0.08);
            --portal-topbar-shadow: 0 4px 25px rgba(0, 0, 0, 0.45);

            --portal-brand-title: #ffffff;
            --portal-brand-pill-bg: rgba(115, 103, 240, 0.15);
            --portal-brand-pill-border: rgba(115, 103, 240, 0.32);
            --portal-brand-pill-text: #9e95f6;

            --portal-status-pill-bg: rgba(40, 199, 111, 0.12);
            --portal-status-pill-border: rgba(40, 199, 111, 0.28);
            --portal-status-pill-text: #28c76f;

            --portal-theme-btn-bg: rgba(255, 255, 255, 0.07);
            --portal-theme-btn-border: rgba(255, 255, 255, 0.14);
            --portal-theme-btn-color: #ffb84d;
            --portal-theme-btn-hover: rgba(255, 184, 77, 0.18);

            --portal-hero-badge-bg: rgba(115, 103, 240, 0.12);
            --portal-hero-badge-border: rgba(115, 103, 240, 0.28);
            --portal-hero-badge-text: #8e85f3;
            --portal-hero-title: #ffffff;
            --portal-hero-desc: #8692d0;

            --portal-card-bg: rgba(21, 26, 50, 0.82);
            --portal-card-border: rgba(255, 255, 255, 0.08);
            --portal-card-shadow: 0 16px 45px -10px rgba(5, 7, 20, 0.7), 0 0 1px 1px rgba(255, 255, 255, 0.06);
            --portal-card-header-border: rgba(255, 255, 255, 0.08);
            --portal-card-icon-bg: rgba(115, 103, 240, 0.16);
            --portal-card-icon-color: #7367f0;

            --portal-heading-color: #ffffff;
            --portal-muted-color: #8692d0;

            --portal-input-wrapper-bg: rgba(12, 15, 32, 0.85);
            --portal-input-addon-bg: rgba(16, 20, 42, 0.9);
            --portal-input-addon-border: rgba(115, 103, 240, 0.28);
            --portal-input-bg: rgba(12, 15, 32, 0.92);
            --portal-input-border: rgba(115, 103, 240, 0.35);
            --portal-input-text: #8e85f3;
            --portal-input-focus-border: #7367f0;
            --portal-input-focus-shadow: 0 0 0 0.28rem rgba(115, 103, 240, 0.28), 0 0 24px rgba(115, 103, 240, 0.25);

            --portal-paste-btn-bg: rgba(115, 103, 240, 0.15);
            --portal-paste-btn-border: rgba(115, 103, 240, 0.28);
            --portal-paste-btn-color: #8e85f3;

            --portal-btn-submit-gradient: linear-gradient(135deg, #7367f0 0%, #5d4fe6 50%, #4839d3 100%);
            --portal-btn-submit-shadow: 0 8px 24px -4px rgba(115, 103, 240, 0.6);

            --portal-summary-bg: rgba(16, 20, 42, 0.7);
            --portal-summary-border: rgba(255, 255, 255, 0.07);

            --portal-countdown-bg: linear-gradient(135deg, rgba(16, 20, 40, 0.95) 0%, rgba(23, 29, 56, 0.95) 100%);
            --portal-countdown-border: rgba(115, 103, 240, 0.24);
            --portal-countdown-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.35), 0 4px 16px rgba(115, 103, 240, 0.1);
            --portal-countdown-num: #8e85f3;
            --portal-countdown-lbl: #8692d0;

            --portal-feature-pill-bg: rgba(16, 20, 42, 0.6);
            --portal-feature-pill-border: rgba(255, 255, 255, 0.06);

            --portal-modal-bg: rgba(20, 25, 48, 0.96);
            --portal-modal-border: rgba(255, 255, 255, 0.1);
            --portal-footer-border: rgba(255, 255, 255, 0.08);
        }

        [data-bs-theme="light"] {
            --bs-primary: #7367f0;
            --bs-primary-rgb: 115, 103, 240;
            --bs-body-bg: #f5f7fc;
            --bs-body-color: #4b4f69;

            --portal-canvas-bg: #f5f7fc;
            --portal-ambient-glow-1: rgba(115, 103, 240, 0.09);
            --portal-ambient-glow-2: rgba(0, 186, 209, 0.07);
            --portal-ambient-glow-3: rgba(40, 199, 111, 0.06);

            --portal-topbar-bg: rgba(255, 255, 255, 0.92);
            --portal-topbar-border: rgba(115, 103, 240, 0.15);
            --portal-topbar-shadow: 0 4px 20px rgba(85, 95, 150, 0.07);

            --portal-brand-title: #23273e;
            --portal-brand-pill-bg: rgba(115, 103, 240, 0.08);
            --portal-brand-pill-border: rgba(115, 103, 240, 0.22);
            --portal-brand-pill-text: #7367f0;

            --portal-status-pill-bg: rgba(40, 199, 111, 0.1);
            --portal-status-pill-border: rgba(40, 199, 111, 0.24);
            --portal-status-pill-text: #28c76f;

            --portal-theme-btn-bg: rgba(115, 103, 240, 0.08);
            --portal-theme-btn-border: rgba(115, 103, 240, 0.22);
            --portal-theme-btn-color: #7367f0;
            --portal-theme-btn-hover: rgba(115, 103, 240, 0.16);

            --portal-hero-badge-bg: rgba(115, 103, 240, 0.08);
            --portal-hero-badge-border: rgba(115, 103, 240, 0.2);
            --portal-hero-badge-text: #7367f0;
            --portal-hero-title: #23273e;
            --portal-hero-desc: #5d617d;

            --portal-card-bg: rgba(255, 255, 255, 0.95);
            --portal-card-border: rgba(115, 103, 240, 0.18);
            --portal-card-shadow: 0 16px 42px -10px rgba(85, 95, 150, 0.12), 0 2px 8px rgba(0, 0, 0, 0.02);
            --portal-card-header-border: rgba(115, 103, 240, 0.12);
            --portal-card-icon-bg: rgba(115, 103, 240, 0.1);
            --portal-card-icon-color: #7367f0;

            --portal-heading-color: #23273e;
            --portal-muted-color: #5d617d;

            --portal-input-wrapper-bg: #f8f9fe;
            --portal-input-addon-bg: #ffffff;
            --portal-input-addon-border: rgba(115, 103, 240, 0.26);
            --portal-input-bg: #ffffff;
            --portal-input-border: rgba(115, 103, 240, 0.32);
            --portal-input-text: #685dd8;
            --portal-input-focus-border: #7367f0;
            --portal-input-focus-shadow: 0 0 0 0.28rem rgba(115, 103, 240, 0.2), 0 4px 18px rgba(115, 103, 240, 0.15);

            --portal-paste-btn-bg: rgba(115, 103, 240, 0.08);
            --portal-paste-btn-border: rgba(115, 103, 240, 0.22);
            --portal-paste-btn-color: #7367f0;

            --portal-btn-submit-gradient: linear-gradient(135deg, #7367f0 0%, #685dd8 100%);
            --portal-btn-submit-shadow: 0 8px 24px -4px rgba(115, 103, 240, 0.4);

            --portal-summary-bg: #f8f9fe;
            --portal-summary-border: rgba(115, 103, 240, 0.14);

            --portal-countdown-bg: linear-gradient(135deg, #ffffff 0%, #f7f8fe 100%);
            --portal-countdown-border: rgba(115, 103, 240, 0.22);
            --portal-countdown-shadow: 0 4px 16px rgba(115, 103, 240, 0.08), 0 1px 3px rgba(0, 0, 0, 0.02);
            --portal-countdown-num: #7367f0;
            --portal-countdown-lbl: #5d617d;

            --portal-feature-pill-bg: #ffffff;
            --portal-feature-pill-border: rgba(115, 103, 240, 0.14);

            --portal-modal-bg: #ffffff;
            --portal-modal-border: rgba(115, 103, 240, 0.16);
            --portal-footer-border: rgba(115, 103, 240, 0.12);
        }

        /* ==========================================================================
           GLOBAL CANVAS & BACKGROUND ATMOSPHERE
           ========================================================================== */
        body {
            font-family: 'Public Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--portal-canvas-bg);
            background-image: 
                radial-gradient(circle at 12% 18%, var(--portal-ambient-glow-1) 0%, transparent 40%),
                radial-gradient(circle at 88% 75%, var(--portal-ambient-glow-2) 0%, transparent 45%),
                radial-gradient(circle at 50% 95%, var(--portal-ambient-glow-3) 0%, transparent 35%);
            background-attachment: fixed;
            color: var(--bs-body-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            padding: 0;
            transition: background 0.35s ease, color 0.35s ease;
        }

        /* Floating Frosted Topbar */
        .portal-topbar {
            background: var(--portal-topbar-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--portal-topbar-border);
            padding: 0.95rem 0;
            box-shadow: var(--portal-topbar-shadow);
            position: sticky;
            top: 0;
            z-index: 1020;
            transition: background 0.35s ease, border-color 0.35s ease, box-shadow 0.35s ease;
        }

        .app-brand-text {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--portal-brand-title);
            letter-spacing: -0.4px;
            transition: color 0.35s ease;
        }

        .portal-brand-pill {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            background: var(--portal-brand-pill-bg);
            border: 1px solid var(--portal-brand-pill-border);
            color: var(--portal-brand-pill-text);
            letter-spacing: 0.3px;
        }

        .portal-status-badge {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.35rem 0.85rem;
            border-radius: 50px;
            background: var(--portal-status-pill-bg);
            border: 1px solid var(--portal-status-pill-border);
            color: var(--portal-status-pill-text);
            letter-spacing: 0.3px;
        }

        .portal-pulse-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: #28c76f;
            box-shadow: 0 0 0 rgba(40, 199, 111, 0.6);
            animation: portal-pulse-anim 2s infinite;
        }

        @keyframes portal-pulse-anim {
            0% { box-shadow: 0 0 0 0 rgba(40, 199, 111, 0.7); }
            70% { box-shadow: 0 0 0 8px rgba(40, 199, 111, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 199, 111, 0); }
        }

        /* Tactile Theme Toggle Button */
        .btn-theme-toggle {
            background: var(--portal-theme-btn-bg);
            border: 1px solid var(--portal-theme-btn-border);
            color: var(--portal-theme-btn-color);
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-theme-toggle:hover {
            transform: scale(1.1) rotate(15deg);
            background: var(--portal-theme-btn-hover);
            border-color: #7367f0;
            color: #7367f0;
        }

        /* Main Hero Presentation */
        .portal-container {
            max-width: 840px;
            margin: 0 auto;
            width: 100%;
        }

        .portal-hero-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.95rem;
            border-radius: 50px;
            background: var(--portal-hero-badge-bg);
            border: 1px solid var(--portal-hero-badge-border);
            color: var(--portal-hero-badge-text);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .portal-hero-title {
            font-size: 2.1rem;
            font-weight: 800;
            color: var(--portal-hero-title);
            letter-spacing: -0.6px;
            margin-top: 0.6rem;
            margin-bottom: 0.4rem;
        }

        .portal-hero-desc {
            font-size: 0.95rem;
            color: var(--portal-hero-desc);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.55;
        }

        /* Portal Glassmorphic Cards */
        .portal-card {
            background: var(--portal-card-bg) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--portal-card-border) !important;
            border-radius: 1.15rem !important;
            box-shadow: var(--portal-card-shadow) !important;
            position: relative;
            overflow: hidden;
            transition: transform 0.25s ease, border-color 0.35s ease, box-shadow 0.35s ease, background 0.35s ease;
        }

        .portal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #7367f0 0%, #00bad1 50%, #28c76f 100%);
            z-index: 2;
        }

        .card-header {
            padding: 1.35rem 1.75rem;
            background-color: transparent;
            border-bottom: 1px solid var(--portal-card-header-border) !important;
            transition: border-color 0.35s ease;
        }

        .portal-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: var(--portal-card-icon-bg);
            color: var(--portal-card-icon-color);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .portal-card-title {
            font-size: 1.18rem;
            font-weight: 700;
            color: var(--portal-heading-color);
            letter-spacing: -0.2px;
        }

        .portal-card-sub {
            font-size: 0.8rem;
            color: var(--portal-muted-color);
            display: block;
        }

        .portal-text-heading {
            color: var(--portal-heading-color) !important;
        }

        /* High-Tech Voucher Input */
        .portal-input-label {
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--portal-muted-color);
        }

        .code-input-lg {
            font-family: 'JetBrains Mono', monospace !important;
            font-size: 1.65rem !important;
            font-weight: 800 !important;
            letter-spacing: 4px !important;
            text-transform: uppercase !important;
            text-align: center !important;
            padding: 1rem 1.25rem !important;
            background-color: var(--portal-input-bg) !important;
            color: var(--portal-input-text) !important;
            border: 2px solid var(--portal-input-border) !important;
            border-radius: 0.75rem !important;
            transition: border-color 0.25s ease, box-shadow 0.25s ease, background-color 0.35s ease;
        }

        .code-input-lg:focus {
            border-color: var(--portal-input-focus-border) !important;
            box-shadow: var(--portal-input-focus-shadow) !important;
            outline: none !important;
        }

        .portal-input-addon {
            background: var(--portal-input-addon-bg) !important;
            border: 2px solid var(--portal-input-addon-border) !important;
            border-right: none !important;
            border-top-left-radius: 0.75rem !important;
            border-bottom-left-radius: 0.75rem !important;
        }

        .portal-paste-btn {
            background: var(--portal-paste-btn-bg);
            border: 2px solid var(--portal-paste-btn-border);
            border-left: none;
            color: var(--portal-paste-btn-color);
            font-weight: 700;
            font-size: 0.88rem;
            padding: 0 1.25rem;
            border-top-right-radius: 0.75rem !important;
            border-bottom-right-radius: 0.75rem !important;
            transition: all 0.2s ease;
        }

        .portal-paste-btn:hover {
            background: #7367f0;
            color: #ffffff;
            border-color: #7367f0;
        }

        .portal-adv-toggle {
            color: var(--portal-muted-color);
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .portal-adv-toggle:hover {
            color: #7367f0;
        }

        /* Primary High-Energy Action Button */
        .btn-portal-submit {
            background: var(--portal-btn-submit-gradient) !important;
            border: none !important;
            color: #ffffff !important;
            padding: 1rem 1.5rem !important;
            border-radius: 0.85rem !important;
            font-weight: 700 !important;
            box-shadow: var(--portal-btn-submit-shadow) !important;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.25s ease;
        }

        .btn-portal-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px -4px rgba(115, 103, 240, 0.7) !important;
            color: #ffffff !important;
        }

        .btn-portal-submit:active {
            transform: translateY(0);
        }

        .btn-portal-submit::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -60%;
            width: 40%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.28), transparent);
            transform: rotate(25deg);
            transition: all 0.6s ease;
        }

        .btn-portal-submit:hover::after {
            left: 120%;
        }

        /* Trust & Feature Badges Strip */
        .portal-feature-pill {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.75rem 0.85rem;
            border-radius: 0.75rem;
            background: var(--portal-feature-pill-bg);
            border: 1px solid var(--portal-feature-pill-border);
            transition: all 0.2s ease;
        }

        .portal-feature-pill:hover {
            transform: translateY(-2px);
            border-color: rgba(115, 103, 240, 0.35);
        }

        .portal-feature-pill strong {
            display: block;
            color: var(--portal-heading-color);
            font-size: 0.82rem;
            line-height: 1.2;
        }

        .portal-feature-pill span {
            color: var(--portal-muted-color);
            font-size: 0.72rem;
        }

        /* Summary Boxes in Success Card */
        .portal-summary-box {
            background: var(--portal-summary-bg);
            border: 1px solid var(--portal-summary-border) !important;
            border-radius: 0.75rem;
            transition: background 0.35s ease, border-color 0.35s ease;
        }

        /* Digital HUD Countdown */
        .countdown-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
        }

        .countdown-tile {
            background: var(--portal-countdown-bg);
            border: 1px solid var(--portal-countdown-border);
            border-radius: 0.75rem;
            padding: 1.1rem 0.5rem;
            text-align: center;
            box-shadow: var(--portal-countdown-shadow);
            transition: transform 0.2s ease, border-color 0.25s ease, background 0.35s ease;
        }

        .countdown-tile:hover {
            transform: translateY(-2px);
            border-color: rgba(115, 103, 240, 0.5);
        }

        .countdown-number {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2.15rem;
            font-weight: 800;
            line-height: 1;
            color: var(--portal-countdown-num);
            letter-spacing: -1px;
        }

        .countdown-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--portal-countdown-lbl);
            letter-spacing: 1px;
            margin-top: 0.4rem;
        }

        /* Modal & Footer */
        .modal-content {
            background-color: var(--portal-modal-bg);
            border: 1px solid var(--portal-modal-border);
            color: var(--bs-body-color);
            border-radius: 1rem;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.35);
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }

        footer {
            border-color: var(--portal-footer-border) !important;
            transition: border-color 0.35s ease;
        }

        @media (max-width: 576px) {
            .countdown-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .code-input-lg {
                font-size: 1.25rem !important;
                letter-spacing: 2px !important;
            }
            .portal-hero-title {
                font-size: 1.65rem;
            }
        }
    </style>
</head>

<body>
    <!-- Floating Frosted Topbar -->
    <header class="portal-topbar mb-4 mb-md-5">
        <div class="container-xxl d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <a href="./portal" class="d-flex align-items-center text-decoration-none">
                    <span class="app-brand-logo me-2">
                        <img src="<?= htmlspecialchars($serverLogo, ENT_QUOTES); ?>" alt="<?= htmlspecialchars($serverName, ENT_QUOTES); ?>" height="32" onerror="this.style.display='none'">
                    </span>
                    <span class="app-brand-text"><?= htmlspecialchars($serverName, ENT_QUOTES); ?></span>
                </a>
                <span class="portal-brand-pill d-none d-sm-inline-flex">
                    <i class="ti tabler-sparkles text-primary me-1"></i>Subscriber Gateway
                </span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <!-- Day / Night Mode Switcher -->
                <button type="button" class="btn-theme-toggle" id="btn-theme-toggle" title="Toggle Day / Night Mode" aria-label="Toggle Theme">
                    <i class="ti tabler-sun fs-5" id="theme-toggle-icon"></i>
                </button>
                <div class="portal-status-badge d-none d-md-inline-flex align-items-center">
                    <span class="portal-pulse-dot me-2"></span>
                    <span>Service Online</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Stage -->
    <main class="container-xxl flex-grow-1 d-flex flex-column justify-content-center py-2 pb-5">
        <div class="portal-container">
            
            <!-- Hero Introduction -->
            <div class="portal-hero text-center mb-4">
                <div class="portal-hero-badge mx-auto mb-2">
                    <i class="ti tabler-shield-check text-primary me-1"></i>Official Activation Gateway
                </div>
                <h1 class="portal-hero-title">Smart Stream Activation</h1>
                <p class="portal-hero-desc">Redeem your voucher PIN below to instantly unlock high-speed streaming lines, Xtream Codes credentials, and live M3U playlists.</p>
            </div>

            <!-- ACTIVATION INPUT CARD -->
            <div class="card portal-card mb-4" id="activation-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <div class="portal-card-icon">
                            <i class="ti tabler-ticket fs-3"></i>
                        </div>
                        <div>
                            <h5 class="portal-card-title mb-0">Enter Activation Voucher</h5>
                            <span class="portal-card-sub">Instant Provisioning • Multi-Device Ready</span>
                        </div>
                    </div>
                    <span class="badge bg-label-info px-3 py-2 rounded-pill d-none d-sm-inline-flex align-items-center">
                        <i class="ti tabler-bolt me-1"></i>Instant Provision
                    </span>
                </div>

                <div class="card-body p-4 p-md-5">
                    <form id="portal-form">
                        <div class="mb-4">
                            <label class="form-label portal-input-label text-center d-block mb-3" for="activation-code">
                                Enter 8–16 Character Voucher PIN <span class="text-danger">*</span>
                            </label>
                            <div class="portal-code-wrapper">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text portal-input-addon">
                                        <i class="ti tabler-key fs-4 text-primary"></i>
                                    </span>
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
                                    <button type="button" class="btn portal-paste-btn" id="btn-paste-code" title="Paste from clipboard">
                                        <i class="ti tabler-clipboard me-1"></i>Paste
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2 px-1">
                                <small class="text-muted">
                                    <i class="ti tabler-info-circle me-1"></i>Letters and digits only • Case insensitive
                                </small>
                                <a href="javascript:void(0);" class="small text-decoration-none portal-adv-toggle" id="btn-toggle-advanced">
                                    <i class="ti tabler-cpu me-1"></i>Device Binding (Optional)
                                </a>
                            </div>
                        </div>

                        <!-- Optional Advanced Device / MAC Lock -->
                        <div class="d-none mb-4 p-3 portal-summary-box" id="advanced-device-box">
                            <label class="form-label fw-semibold small text-muted text-uppercase" for="device_mac">
                                Device MAC Address (Optional Hardware Lock)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ti tabler-cpu"></i></span>
                                <input type="text" id="device_mac" name="mac" class="form-control font-monospace" placeholder="00:1A:79:XX:XX:XX">
                            </div>
                            <div class="form-text small">Leave blank unless your voucher is locked to a specific MAC / MAG STB.</div>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-portal-submit btn-lg" id="btn-activate-submit">
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

                    <!-- Feature Micro-Strip -->
                    <div class="row g-2 mt-4 pt-3 border-top" style="border-color: var(--portal-card-header-border) !important;">
                        <div class="col-12 col-sm-4">
                            <div class="portal-feature-pill h-100">
                                <i class="ti tabler-bolt text-warning fs-4 flex-shrink-0"></i>
                                <div>
                                    <strong>0.3s Instant</strong>
                                    <span>Automated stream access</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="portal-feature-pill h-100">
                                <i class="ti tabler-device-tv text-info fs-4 flex-shrink-0"></i>
                                <div>
                                    <strong>All Devices</strong>
                                    <span>Smart TV, Mobile, VLC, MAG</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="portal-feature-pill h-100">
                                <i class="ti tabler-shield-check text-success fs-4 flex-shrink-0"></i>
                                <div>
                                    <strong>Protected CDN</strong>
                                    <span>High-speed low-latency stream</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SUCCESS RESULT CARD -->
            <div class="card portal-card d-none" id="success-card">
                <div class="card-header border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <div class="portal-card-icon" style="background: rgba(40, 199, 111, 0.16); color: #28c76f;">
                            <i class="ti tabler-circle-check fs-2"></i>
                        </div>
                        <div>
                            <h5 class="portal-card-title text-success mb-0">Subscription Activated Successfully</h5>
                            <span class="portal-card-sub">Your streaming credentials and playlist links are now active and ready.</span>
                        </div>
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

                <div class="card-body p-4 p-md-5">
                    <!-- Highlight Grid -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <div class="p-3 portal-summary-box h-100">
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
                            <div class="p-3 portal-summary-box h-100">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Package & Plan</label>
                                <div class="d-flex align-items-center justify-content-between mt-1">
                                    <span class="fs-5 fw-bold portal-text-heading" id="res-pkg-name">Full IPTV Package</span>
                                    <span class="badge bg-label-info" id="res-conn-count">
                                        <i class="ti tabler-devices me-1"></i>1 Connection
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Digital HUD Countdown Expiry Box -->
                    <div class="p-3 portal-summary-box mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                            <span class="small text-muted text-uppercase fw-semibold d-flex align-items-center">
                                <i class="ti tabler-clock-hour-4 text-warning me-1 fs-5"></i>Subscription Remaining Time
                            </span>
                            <span class="small text-muted">
                                Expires on: <span id="res-exp-formatted" class="font-monospace portal-text-heading fw-bold"></span>
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

                    <!-- Streaming & Xtream Codes Credentials Hub -->
                    <div class="xc-cred-hub mb-4">
                        <div class="card-body p-3 p-md-4">
                            <!-- Hub Header -->
                            <div class="xc-cred-header">
                                <div>
                                    <div class="xc-cred-title">
                                        <i class="ti tabler-device-tv text-warning fs-4"></i>
                                        <span>Streaming & Xtream Codes Credentials</span>
                                        <span class="xc-status-pill ms-2">
                                            <span class="xc-pulse-dot"></span>
                                            Active
                                        </span>
                                    </div>
                                    <span class="xc-cred-subtitle">High-speed endpoints for IPTV Apps, Smart TVs, MAG, and Mobile Devices</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="btn-copy-all-creds" title="Copy Host, Port, Username & Password together">
                                        <i class="ti tabler-copy me-1"></i>Copy All Parameters
                                    </button>
                                    <button type="button" class="btn btn-sm btn-label-secondary rounded-pill px-3" id="btn-open-qr" data-bs-toggle="modal" data-bs-target="#qrCodeModal" title="Scan QR Code to Connect">
                                        <i class="ti tabler-qrcode me-1"></i>QR Code
                                    </button>
                                </div>
                            </div>

                            <!-- Interactive Credential Tiles -->
                            <div class="row g-3">
                                <!-- Server Host / URL -->
                                <div class="col-12 col-md-6">
                                    <div class="xc-cred-tile h-100">
                                        <div class="xc-cred-tile-top">
                                            <div class="xc-cred-label-wrap">
                                                <div class="xc-cred-icon xc-icon-server">
                                                    <i class="ti tabler-server"></i>
                                                </div>
                                                <span class="xc-cred-label">Server Host / URL</span>
                                            </div>
                                            <button class="btn btn-xc-icon btn-copy-elem" data-target="res-host" title="Copy Server Host">
                                                <i class="ti tabler-copy"></i>
                                            </button>
                                        </div>
                                        <div class="xc-cred-val" id="res-host" title="Server Host">--</div>
                                    </div>
                                </div>

                                <!-- Server Port -->
                                <div class="col-12 col-md-6">
                                    <div class="xc-cred-tile h-100">
                                        <div class="xc-cred-tile-top">
                                            <div class="xc-cred-label-wrap">
                                                <div class="xc-cred-icon xc-icon-port">
                                                    <i class="ti tabler-network"></i>
                                                </div>
                                                <span class="xc-cred-label">Server Port</span>
                                            </div>
                                            <button class="btn btn-xc-icon btn-copy-elem" data-target="res-port" title="Copy Port">
                                                <i class="ti tabler-copy"></i>
                                            </button>
                                        </div>
                                        <div class="xc-cred-val" id="res-port">--</div>
                                    </div>
                                </div>

                                <!-- Username -->
                                <div class="col-12 col-md-6">
                                    <div class="xc-cred-tile h-100">
                                        <div class="xc-cred-tile-top">
                                            <div class="xc-cred-label-wrap">
                                                <div class="xc-cred-icon xc-icon-user">
                                                    <i class="ti tabler-user"></i>
                                                </div>
                                                <span class="xc-cred-label">Streaming Username</span>
                                            </div>
                                            <button class="btn btn-xc-icon btn-copy-elem" data-target="res-username" title="Copy Username">
                                                <i class="ti tabler-copy"></i>
                                            </button>
                                        </div>
                                        <div class="xc-cred-val" id="res-username">--</div>
                                    </div>
                                </div>

                                <!-- Password -->
                                <div class="col-12 col-md-6">
                                    <div class="xc-cred-tile h-100">
                                        <div class="xc-cred-tile-top">
                                            <div class="xc-cred-label-wrap">
                                                <div class="xc-cred-icon xc-icon-pass">
                                                    <i class="ti tabler-key"></i>
                                                </div>
                                                <span class="xc-cred-label">Streaming Password</span>
                                            </div>
                                            <div class="xc-cred-actions">
                                                <button class="btn btn-xc-icon" id="btn-toggle-pw" title="Show / Hide Password">
                                                    <i class="ti tabler-eye" id="eye-pw-icon"></i>
                                                </button>
                                                <button class="btn btn-xc-icon btn-copy-elem" data-target="res-password" title="Copy Password">
                                                    <i class="ti tabler-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="xc-cred-val" id="res-password">••••••••••••</div>
                                    </div>
                                </div>
                            </div>

                            <!-- One-Click Direct Stream URI Bar -->
                            <div class="xc-uri-bar">
                                <div class="xc-uri-label">
                                    <i class="ti tabler-link text-primary"></i>
                                    <span>Stream URL</span>
                                </div>
                                <div class="xc-uri-text" id="res-direct-uri">--</div>
                                <button type="button" class="btn btn-xs btn-label-primary px-3 rounded-pill flex-shrink-0" id="btn-copy-direct-uri" title="Copy Stream Link">
                                    <i class="ti tabler-copy me-1"></i>Copy
                                </button>
                            </div>

                            <!-- Action Buttons Bar -->
                            <div class="xc-action-bar">
                                <a href="#" id="res-m3u-hls" class="btn-xc-m3u" target="_blank">
                                    <i class="ti tabler-download fs-5"></i>
                                    <span>Download M3U (HLS)</span>
                                    <span class="btn-subtag">Apple / Android / PC</span>
                                </a>
                                <a href="#" id="res-m3u-ts" class="btn-xc-m3u" target="_blank">
                                    <i class="ti tabler-download fs-5"></i>
                                    <span>Download M3U (TS)</span>
                                    <span class="btn-subtag">Smart TV / MAG</span>
                                </a>
                                <button type="button" id="btn-copy-m3u" class="btn-xc-copy-m3u">
                                    <i class="ti tabler-copy fs-5"></i>
                                    <span>Copy M3U Link</span>
                                </button>
                                <a href="#" id="res-launch-player" class="btn-xc-player d-none" target="_blank">
                                    <i class="ti tabler-player-play fs-5"></i>
                                    <span>Launch Web Player</span>
                                </a>
                            </div>

                            <!-- IPTV Apps Compatibility & Guide Strip -->
                            <div class="xc-apps-strip">
                                <div class="xc-apps-label">
                                    <i class="ti tabler-devices text-info"></i>
                                    <span>Compatible with Leading IPTV Players:</span>
                                </div>
                                <div class="xc-app-badges">
                                    <span class="xc-app-badge">IPTV Smarters Pro</span>
                                    <span class="xc-app-badge">TiviMate</span>
                                    <span class="xc-app-badge">XCIPTV</span>
                                    <span class="xc-app-badge">IBO Player</span>
                                    <span class="xc-app-badge">VLC Media Player</span>
                                    <span class="xc-app-badge">OTT Navigator</span>
                                </div>
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

    <!-- QR Code Scan Modal -->
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
                            <div class="xc-qr-box mb-2">
                                <img id="qr-code-img-m3u" src="" alt="M3U QR Code" width="200" height="200">
                            </div>
                            <small class="text-muted d-block">Scan on Mobile or Smart TV to load playlist directly.</small>
                        </div>
                        <div class="tab-pane fade" id="qr-portal-pane" role="tabpanel">
                            <div class="xc-qr-box mb-2">
                                <img id="qr-code-img-portal" src="" alt="Player QR Code" width="200" height="200">
                            </div>
                            <small class="text-muted d-block">Scan to launch the Web Player instantly.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 justify-content-center">
                    <button type="button" class="btn btn-sm btn-label-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center py-3 border-top mt-auto">
        <div class="container-xxl d-flex flex-wrap justify-content-between align-items-center">
            <small class="text-muted">
                &copy; <?= date('Y'); ?> <?= htmlspecialchars($serverName, ENT_QUOTES); ?>. All rights reserved.
            </small>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-label-secondary small">
                    <i class="ti tabler-lock me-1"></i>256-Bit Encrypted
                </span>
                <span class="badge bg-label-success small">
                    <span class="portal-pulse-dot me-1"></span>Operational
                </span>
            </div>
        </div>
    </footer>

    <!-- Bootstrap Bundle JS -->
    <script src="assets/vendor/js/bootstrap.js"></script>
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

        // Day / Night Theme Switcher Logic
        const themeToggleBtn = document.getElementById('btn-theme-toggle');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');

        function setTheme(theme) {
            document.documentElement.setAttribute('data-bs-theme', theme);
            localStorage.setItem('portal_theme', theme);
            if (themeToggleIcon) {
                if (theme === 'light') {
                    themeToggleIcon.className = 'ti tabler-moon fs-5 text-primary';
                    if (themeToggleBtn) themeToggleBtn.setAttribute('title', 'Switch to Night Mode');
                } else {
                    themeToggleIcon.className = 'ti tabler-sun fs-5 text-warning';
                    if (themeToggleBtn) themeToggleBtn.setAttribute('title', 'Switch to Day Mode');
                }
            }
        }

        // Initialize theme switcher icon to match active theme
        const activeTheme = document.documentElement.getAttribute('data-bs-theme') || 'dark';
        setTheme(activeTheme);

        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-bs-theme') || 'dark';
                const nextTheme = current === 'dark' ? 'light' : 'dark';
                setTheme(nextTheme);
            });
        }

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
                    // Exact website URL with http or https according to connection
                    const currentProtocol = window.location.protocol; // "http:" or "https:"
                    const currentHost = window.location.host;
                    const defaultOrigin = `${currentProtocol}//${currentHost}`;

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

                    // Extract / format port
                    let serverPort = data.credentials.port || '';
                    if (!serverPort || serverPort == 80 || serverPort == 443) {
                        try {
                            const pu = new URL(serverHostUrl);
                            if (pu.port) {
                                serverPort = pu.port;
                            } else {
                                serverPort = currentProtocol === 'https:' ? 443 : 80;
                            }
                        } catch (e) {
                            serverPort = currentProtocol === 'https:' ? 443 : 80;
                        }
                    }

                    document.getElementById('res-host').textContent = serverHostUrl;
                    document.getElementById('res-host').setAttribute('title', serverHostUrl);
                    document.getElementById('res-port').textContent = serverPort;

                    document.getElementById('res-username').textContent = data.credentials.username || '';
                    originalPassword = data.credentials.password || '';
                    document.getElementById('res-password').textContent = '••••••••••••';
                    passwordHidden = true;
                    const eyeIcon = document.getElementById('eye-pw-icon');
                    if (eyeIcon) eyeIcon.className = 'ti tabler-eye fs-6';

                    // Direct Stream URI
                    const directUri = `${serverHostUrl}/get.php?username=${encodeURIComponent(data.credentials.username)}&password=${encodeURIComponent(data.credentials.password)}&type=m3u_plus&output=ts`;
                    const uriElem = document.getElementById('res-direct-uri');
                    if (uriElem) {
                        uriElem.textContent = directUri;
                        uriElem.setAttribute('title', directUri);
                    }
                }

                // Playlists
                if (data.playlists) {
                    let m3uHls = data.playlists.m3u_hls || '';
                    let m3uTs = data.playlists.m3u_ts || '';
                    const currentProtocol = window.location.protocol;
                    const currentHost = window.location.host;
                    const defaultOrigin = `${currentProtocol}//${currentHost}`;
                    const targetBase = document.getElementById('res-host') ? document.getElementById('res-host').textContent.trim() : defaultOrigin;

                    if (m3uHls.startsWith('http')) {
                        try {
                            const hu = new URL(m3uHls);
                            m3uHls = `${targetBase}${hu.pathname}${hu.search}`;
                        } catch(e) {}
                    }
                    if (m3uTs.startsWith('http')) {
                        try {
                            const tu = new URL(m3uTs);
                            m3uTs = `${targetBase}${tu.pathname}${tu.search}`;
                        } catch(e) {}
                    }

                    document.getElementById('res-m3u-hls').href = m3uHls || '#';
                    document.getElementById('res-m3u-ts').href = m3uTs || '#';
                    document.getElementById('btn-copy-m3u').setAttribute('data-url', m3uHls || '');
                }

                // QR Code generation
                const m3uUrl = (document.getElementById('btn-copy-m3u') && document.getElementById('btn-copy-m3u').getAttribute('data-url')) ? document.getElementById('btn-copy-m3u').getAttribute('data-url') : '';
                const playerUrl = data.web_player_url || window.location.href;
                const qrImgM3u = document.getElementById('qr-code-img-m3u');
                const qrImgPortal = document.getElementById('qr-code-img-portal');
                if (qrImgM3u && m3uUrl) {
                    qrImgM3u.src = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(m3uUrl)}`;
                }
                if (qrImgPortal) {
                    qrImgPortal.src = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(playerUrl)}`;
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
                let text = '';
                if (targetId === 'res-password') {
                    text = originalPassword;
                } else {
                    text = document.getElementById(targetId).textContent.trim();
                }
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

        // Copy Direct Stream URI
        const copyDirectUriBtn = document.getElementById('btn-copy-direct-uri');
        if (copyDirectUriBtn) {
            copyDirectUriBtn.addEventListener('click', function() {
                const text = document.getElementById('res-direct-uri').textContent.trim();
                copyToClipboard(text).then(() => {
                    const orig = copyDirectUriBtn.innerHTML;
                    copyDirectUriBtn.innerHTML = '<i class="ti tabler-check text-success me-1"></i>Copied';
                    setTimeout(() => copyDirectUriBtn.innerHTML = orig, 1500);
                });
            });
        }

        // Copy All Parameters
        const copyAllBtn = document.getElementById('btn-copy-all-creds');
        if (copyAllBtn) {
            copyAllBtn.addEventListener('click', function() {
                const host = document.getElementById('res-host').textContent.trim();
                const port = document.getElementById('res-port').textContent.trim();
                const user = document.getElementById('res-username').textContent.trim();
                const m3u = document.getElementById('btn-copy-m3u') ? document.getElementById('btn-copy-m3u').getAttribute('data-url') : '';
                const allCreds = [
                    '========================================',
                    '   STREAMING & XTREAM CODES CREDENTIALS',
                    '========================================',
                    `Server Host / URL : ${host}`,
                    `Server Port       : ${port}`,
                    `Username          : ${user}`,
                    `Password          : ${originalPassword}`,
                    `M3U Playlist Link : ${m3u}`,
                    '========================================'
                ].join('\n');

                copyToClipboard(allCreds).then(() => {
                    const orig = copyAllBtn.innerHTML;
                    copyAllBtn.innerHTML = '<i class="ti tabler-check text-success me-1"></i>Copied All!';
                    setTimeout(() => copyAllBtn.innerHTML = orig, 1800);
                });
            });
        }

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
                    icon.className = 'ti tabler-eye-off fs-6';
                    passwordHidden = false;
                } else {
                    pwElem.textContent = '••••••••••••';
                    icon.className = 'ti tabler-eye fs-6';
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
