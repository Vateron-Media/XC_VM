<?php

/**
 * XC_VM — Конфигурация модулей
 *
 * Определяет, какие модули включены в системе.
 * ModuleLoader читает этот файл и загружает модули при bootstrap.
 *
 * Формат:
 *   'module_name' => [
 *       'enabled' => true|false,    // Включён ли модуль
 *       'class'   => 'ClassName',   // Класс модуля (implements ModuleInterface)
 *   ]
 *
 * При удалении модуля из этого списка (или enabled => false),
 * система продолжает работать без него.
 *
 * @see ModuleInterface
 * @see ModuleLoader
 */

return [
    'plex' => [
        'enabled' => true,
        'class'   => 'PlexModule',
    ],
    'watch' => [
        'enabled' => true,
        'class'   => 'WatchModule',
    ],
    'tmdb' => [
        'enabled' => true,
        'class'   => 'TmdbModule',
    ],
    'ministra' => [
        'enabled' => true,
        'class'   => 'MinistraModule',
    ],
    'fingerprint' => [
        'enabled' => true,
        'class'   => 'FingerprintModule',
    ],
    'theft-detection' => [
        'enabled' => true,
        'class'   => 'TheftDetectionModule',
    ],
    'magscan' => [
        'enabled' => true,
        'class'   => 'MagscanModule',
    ],
];
