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
            if (!array_key_exists($key, $GLOBALS)) {
                $GLOBALS[$key] = $value;
            }
        }

        // Legacy footer.php expects these variables in file scope.
        foreach ([
            'rUserInfo', 'rSettings', 'rThemes', 'rMobile', 'rHues',
            'db', 'language', 'rServers', 'allServers', 'rUpdate',
            '_TITLE', 'rModal', 'rProxyServers', 'rPermissions',
            'rServerError', 'allServersHealthy', '_PAGE',
            'rStreamIDs', 'rFilterBy', 'rSortArray', 'rFilterArray',
            'rSearchBy', 'rURLs', 'rSubtitles', 'rLegacy', 'rSeries',
            'rYearStart', 'rYearEnd', 'rRatingStart', 'rRatingEnd',
        ] as $_g) {
            if (array_key_exists($_g, $GLOBALS)) {
                $$_g = $GLOBALS[$_g];
            }
        }
        unset($_g);

        $rootPath = dirname(__DIR__, 3);

        if ($scope === 'player') {
            require __DIR__ . '/player/footer.php';
            return;
        }

        if ($scope === 'reseller') {
            require __DIR__ . '/reseller/footer.php';
            return;
        }

        require $rootPath . '/admin/footer.php';
    }
}
