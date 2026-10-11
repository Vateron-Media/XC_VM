<?php

use XcVm\Core\Module\ModuleInterface;
use XcVm\Core\Module\ModuleLoader;

/**
 * Module overrides
 *
 * ModuleLoader discovers every module from Modules/<dir>/module.json, and every
 * discovered module is enabled unless this file says otherwise.
 *
 * Entries are keyed by the module name from its module.json ("name"), not by
 * its directory, which may carry a hash suffix (watch_2541a):
 *   - Disable a module:     'module-name' => ['state' => 'disabled']
 *                           (the older 'module-name' => ['enabled' => false] works too)
 *   - Custom module class:  'module-name' => ['class' => 'CustomModule']
 *
 * A disabled module is not loaded at all. Renaming or moving its directory does
 * not disable it: the loader still finds its module.json.
 *
 * The panel rewrites this file when modules are installed, updated, enabled or
 * disabled; it keeps the entries but drops these comments.
 *
 * Example:
 *   return [
 *       'example-module' => ['state' => 'disabled'],
 *       'custom-module'  => ['class' => 'MyCustomModule'],
 *   ];
 *
 * An empty array loads every discovered module.
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
	// Disable a module:
	// 'example-module' => ['state' => 'disabled'],
];
