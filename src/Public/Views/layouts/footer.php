<?php

/**
 * Unified Layout Footer
 *
 * Единая точка входа для footer/layout admin/reseller/player.
 * На текущем этапе используется как совместимая обёртка над legacy footer.php,
 * чтобы начать миграцию страниц без риска регрессий.
 *
 * Параметры:
 * - $scope: 'admin' | 'reseller' | 'player'
 * - $vars:  набор переменных страницы (опционально)
 *
 * Пример:
 *   renderUnifiedLayoutFooter('admin');
 */

if (!function_exists('renderUnifiedLayoutFooter')) {
    function renderUnifiedLayoutFooter($scope = 'admin', array $vars = []) {
        foreach ($vars as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            // Explicit page data must win over stale globals and remain in the
            // local scope used by the legacy footer required below.
            $GLOBALS[$key] = $value;
            ${$key} = $value;
        }

        // Legacy footer.php expects these variables in file scope.
        foreach ([
            'rUserInfo', 'rSettings', 'rThemes', 'rMobile', 'rHues',
            'db', 'rServers', 'allServers', 'rUpdate',
            '_TITLE', 'rModal', 'rProxyServers', 'rPermissions',
            'rServerError', 'allServersHealthy', '_PAGE', '_SETUP',
            'rStreamIDs', 'rFilterBy', 'rSortArray', 'rFilterArray',
            'rSearchBy', 'rURLs', 'rSubtitles', 'rLegacy', 'rSeries',
            'rYearStart', 'rYearEnd', 'rRatingStart', 'rRatingEnd',
            'rRegisteredUsers', 'rLine',
        ] as $_g) {
            if (array_key_exists($_g, $GLOBALS)) {
                $$_g = $GLOBALS[$_g];
            }
        }
        unset($_g);

        // Translator FQCN for the legacy footer's $language::get(...) calls.
        $language = \XcVm\Core\Localization\Translator::class;

        $rootPath = dirname(__DIR__, 3);

        if ($scope === 'player') {
            require __DIR__ . '/player/footer.php';
            return;
        }

        if ($scope === 'reseller') {
            if (function_exists('xc_reseller_use_newui') && xc_reseller_use_newui()) {
                require __DIR__ . '/reseller/footer.newui.php';
            } else {
                require __DIR__ . '/reseller/footer.php';
            }
            return;
        }

        // Admin is fully on the Bootstrap 5 shell (setup + modals handled inside
        // admin/footer.php), so the admin scope always renders the new-UI footer.
        require dirname(__DIR__) . '/admin/footer.php';
    }
}
