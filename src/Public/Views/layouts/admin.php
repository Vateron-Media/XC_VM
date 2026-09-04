<?php

/**
 * Unified Layout Header
 *
 * Единая точка входа для header/layout admin/reseller/player.
 * На текущем этапе используется как совместимая обёртка над legacy header.php,
 * чтобы начать миграцию страниц без риска регрессий.
 *
 * Параметры:
 * - $scope: 'admin' | 'reseller' | 'player'
 * - $vars:  набор переменных страницы (опционально)
 *
 * Пример:
 *   renderUnifiedLayoutHeader('admin', ['_TITLE' => 'Dashboard']);
 */

if (!function_exists('xc_admin_use_newui')) {
    /**
     * Per-page opt-in to the admin shell.
     *
     * The redesign migrates admin pages one at a time, so the shell must stay
     * legacy for every page that has NOT been rebuilt yet. Only pages listed in
     * XC_NEWUI_PAGES render inside header.newui.php/footer.newui.php.
     *
     * Forced back to the legacy shell when:
     *  - XC_ADMIN_LEGACY_UI is defined (global kill-switch), or
     *  - the request is a modal (?modal=) or the setup wizard ($_SETUP) — both
     *    keep the legacy chrome this migration pass.
     */
    function xc_admin_use_newui(): bool {
        if (defined('XC_ADMIN_LEGACY_UI')) {
            return false;
        }
        $page = \XcVm\Core\Util\AdminHelpers::getPageName();
        // Modal (iframe) edit forms: only pages rebuilt for the Bootstrap 5 modal shell
        // opt in; the setup wizard always stays legacy.
        if (isset($_GET['modal'])) {
            if (!empty($GLOBALS['_SETUP'])) {
                return false;
            }
            static $migratedModals = ['line', 'enigma', 'mag', 'user', 'radio', 'movie', 'stream', 'serie', 'created_channel'];
            return in_array($page, $migratedModals, true);
        }
        if (!empty($GLOBALS['_SETUP'])) {
            return false;
        }
        static $migrated = ['dashboard', 'panel_logs', 'login_logs', 'client_logs', 'credit_logs', 'stream_errors', 'restream_logs', 'mag_events', 'mysql_syslog', 'queue', 'user_logs', 'line_activity', 'live_connections', 'ondemand', 'theft_detection', 'stream_rank', 'ips', 'isps', 'useragents', 'hmacs', 'rtmp_ips', 'asns', 'groups', 'packages', 'codes', 'epgs', 'providers', 'users', 'series', 'mags', 'enigmas', 'movies', 'radios', 'backups', 'lines', 'streams', 'epg', 'code', 'ip', 'isp', 'useragent', 'rtmp_ip', 'hmac', 'provider', 'package', 'group', 'line', 'enigma', 'mag', 'user', 'radio', 'movie', 'stream', 'serie', 'created_channels', 'created_channel', 'tickets', 'ticket_view', 'ticket', 'profiles'];
        return in_array($page, $migrated, true);
    }
}

if (!function_exists('renderUnifiedLayoutHeader')) {
    function renderUnifiedLayoutHeader($scope = 'admin', array $vars = []) {
        foreach ($vars as $key => $value) {
            if (!array_key_exists($key, $GLOBALS)) {
                $GLOBALS[$key] = $value;
            }
        }

        // Legacy header.php expects these variables in file scope.
        // Since we require it from inside a function, pull them from $GLOBALS.
        foreach (
            [
                'rUserInfo',
                'rSettings',
                'rThemes',
                'rMobile',
                'rHues',
                'db',
                'allServersHealthy',
                'rServerError',
                'rServers',
                'allServers',
                'rUpdate',
                '_TITLE',
                'rModal',
                'rProxyServers',
                'rPermissions',
                '_PAGE',
                '_SETUP',
            ] as $_g
        ) {
            if (array_key_exists($_g, $GLOBALS)) {
                $$_g = $GLOBALS[$_g];
            }
        }
        unset($_g);

        // Translator FQCN for the legacy header's $language::get(...) calls.
        $language = \XcVm\Core\Localization\Translator::class;

        $rootPath = dirname(__DIR__, 3);

        if ($scope === 'player') {
            require __DIR__ . '/player/header.php';
            return;
        }

        if ($scope === 'reseller') {
            require __DIR__ . '/reseller/header.php';
            return;
        }

        if (xc_admin_use_newui()) {
            require dirname(__DIR__) . '/admin/header.newui.php';
        } else {
            require dirname(__DIR__) . '/admin/header.php';
        }

        // header.php sets $rModal in local scope; propagate to $GLOBALS
        // so that renderUnifiedLayoutFooter() can read it later.
        if (isset($rModal)) {
            $GLOBALS['rModal'] = $rModal;
        }
    }
}
