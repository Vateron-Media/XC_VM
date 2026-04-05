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
 *
 * @package XC_VM_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

return [
    // Пример отключения модуля:
    // 'theft-detection' => ['enabled' => false],
];
