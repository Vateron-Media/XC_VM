<?php

/**
 * Overrides модулей
 *
 * ModuleLoader автоматически обнаруживает все модули из modules/&lt;name&gt;/module.json.
 * По умолчанию все обнаруженные модули включены.
 *
 * Этот файл содержит только переопределения:
 *   - Отключение модуля:  'module-name' => ['enabled' => false]
 *   - Свой класс модуля:  'module-name' => ['class' => 'CustomModule']
 *
 * Пример:
 *   return [
 *       'theft-detection' => ['enabled' => false],
 *       'custom-module'   => ['class' => 'MyCustomModule'],
 *   ];
 *
 * Если массив пуст — все обнаруженные модули будут загружены.
 *
 * @see ModuleInterface
 * @see ModuleLoader
 */

return [
    // Пример отключения модуля:
    // 'theft-detection' => ['enabled' => false],
];
