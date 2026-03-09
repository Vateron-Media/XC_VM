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

if (!function_exists('renderUnifiedLayoutHeader')) {
    function renderUnifiedLayoutHeader($scope = 'admin', array $vars = []) {
        foreach ($vars as $key => $value) {
            if (!array_key_exists($key, $GLOBALS)) {
                $GLOBALS[$key] = $value;
            }
        }

        // Legacy header.php expects these variables in file scope.
        // Since we require it from inside a function, pull them from $GLOBALS.
        foreach ([
            'rUserInfo', 'rSettings', 'rThemes', 'rMobile', 'rHues',
            'db', 'language', 'allServersHealthy', 'rServerError',
            'rServers', 'allServers', 'rUpdate', '_TITLE', 'rModal',
            'rProxyServers', 'rPermissions', '_PAGE',
        ] as $_g) {
            if (array_key_exists($_g, $GLOBALS)) {
                $$_g = $GLOBALS[$_g];
            }
        }
        unset($_g);

        $rootPath = dirname(__DIR__, 3);

        if ($scope === 'player') {
            require __DIR__ . '/player/header.php';
            return;
        }

        if ($scope === 'reseller') {
            require __DIR__ . '/reseller/header.php';
            return;
        }

        require $rootPath . '/admin/header.php';
    }
}
