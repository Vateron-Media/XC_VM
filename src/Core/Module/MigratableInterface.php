<?php

namespace XcVm\Core\Module;

/**
 * MigratableInterface — module upgrade migrations contract.
 *
 * Modules returning a non-empty map from getMigrations() receive
 * incremental migration execution instead of a full re-install when
 * ModuleManager::updateModule() is called.
 *
 * Each entry runs exactly once, in ascending semver order, only when
 * the target version is strictly greater than the recorded installed
 * version and at most equal to the new module version.
 *
 * Example:
 *   public function getMigrations(): array {
 *       return [
 *           '1.1.0' => function (\XcVm\Core\Container\ServiceContainer $c) { $c->get('db')->execute('ALTER TABLE ...'); },
 *           '2.0.0' => function (\XcVm\Core\Container\ServiceContainer $c) { $c->get('db')->execute('CREATE TABLE ...'); },
 *       ];
 *   }
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface MigratableInterface {
    /**
     * Return module migrations keyed by target version string.
     *
     * Keys are semver strings (e.g. "1.1.0"). Values are callables
     * that perform the schema or data change for that version step.
     * Each callable receives the \XcVm\Core\Container\ServiceContainer — use it to access db, settings, etc.
     *
     * @return array<string, callable(\XcVm\Core\Container\ServiceContainer): void>
     */
    public function getMigrations(): array;
}
