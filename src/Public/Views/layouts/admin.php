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

if (!function_exists('xc_reseller_use_newui')) {
    /**
     * Per-page opt-in to the reseller Bootstrap 5 shell.
     *
     * Mirrors xc_admin_use_newui(): the reseller redesign migrates pages one at a
     * time, so the shell stays legacy for every reseller page not yet rebuilt.
     * Only pages listed in the $migratedReseller allowlist render inside
     * reseller/header.newui.php / reseller/footer.newui.php.
     *
     * Forced back to the legacy shell when XC_ADMIN_LEGACY_UI is defined (global
     * kill-switch) or the request is a modal (no reseller modal is migrated yet).
     */
    function xc_reseller_use_newui(): bool {
        if (defined('XC_ADMIN_LEGACY_UI')) {
            return false;
        }
        // No reseller modal (iframe) edit form has been migrated to the new shell
        // yet — keep the legacy chrome for every modal request.
        if (isset($_GET['modal'])) {
            return false;
        }
        $page = \XcVm\Core\Util\AdminHelpers::getPageName();
        // Pilot allowlist — seed with the reseller dashboard only.
        static $migratedReseller = ['dashboard', 'lines', 'streams', 'movies', 'radios', 'users', 'mags', 'enigmas', 'live_connections', 'line_activity', 'user_logs', 'episodes', 'created_channels', 'epg_view', 'tickets', 'ticket', 'ticket_view', 'edit_profile', 'line', 'user', 'mag', 'enigma'];
        return in_array($page, $migratedReseller, true);
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
            if (xc_reseller_use_newui()) {
                require __DIR__ . '/reseller/header.newui.php';
            } else {
                require __DIR__ . '/reseller/header.php';
            }
            // header may set $rGenTrials/$rModal in local scope; propagate to
            // $GLOBALS so the footer/renderer can read it later.
            if (isset($rGenTrials)) {
                $GLOBALS['rGenTrials'] = $rGenTrials;
            }
            if (isset($rModal)) {
                $GLOBALS['rModal'] = $rModal;
            }
            return;
        }

        // Every admin page is migrated to the Bootstrap 5 shell (setup + modals
        // included via header.php's own $_SETUP / ?modal branches), so the admin
        // scope always renders the new-UI header.
        require dirname(__DIR__) . '/admin/header.php';

        // header.php sets $rModal in local scope; propagate to $GLOBALS
        // so that renderUnifiedLayoutFooter() can read it later.
        if (isset($rModal)) {
            $GLOBALS['rModal'] = $rModal;
        }
    }
}
