<?php

/**
 * BaseModule — abstract base class for module implementations.
 *
 * Provides no-op defaults for all optional ModuleInterface methods so
 * concrete modules only need to override what they actually use.
 *
 * getName() and getVersion() remain abstract — they are identity
 * contracts and must be unique per module.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class BaseModule implements ModuleInterface, MigratableInterface, CronProviderInterface {

    abstract public function getName(): string;

    abstract public function getVersion(): string;

    public function boot(ServiceContainer $container): void {}

    public function getEventSubscribers(): array {
        return [];
    }

    public function registerRoutes(Router $router): void {}

    public function registerCommands(CommandRegistry $registry): void {}

    public function registerNavbar(NavbarRegistry $registry): void {}

    public function install(): void {}

    public function uninstall(): void {}

    public function getMigrations(): array {
        return [];
    }

    public function getCronEntries(): array {
        return [];
    }
}
