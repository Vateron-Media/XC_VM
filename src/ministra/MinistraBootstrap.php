<?php

/**
 * Named bootstrap for the Ministra (Stalker Portal) boundary.
 *
 * Wraps StreamingRequestBootstrap::init('portal') so the entry point is
 * explicit and the boundary is identifiable without reading portal.php internals.
 *
 * What this initialises (via StreamingRequestBootstrap + StreamingBootstrap):
 *   - Constants, Paths, Logger
 *   - Flood-protection, host verification
 *   - Settings from file cache (no DB needed for settings)
 *   - Database connection (via StreamingBootstrap)
 *   - GeoIP, STB session
 *
 * What it deliberately does NOT initialise:
 *   - Router / ModuleLoader
 *   - NavbarRegistry
 *   - Translator / admin API
 *   - Full XC_Bootstrap::CONTEXT_ADMIN
 *
 * @package XC_VM_Ministra
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class MinistraBootstrap {

    /**
     * Boot the Ministra boundary.
     *
     * Must be called after autoload.php is loaded.
     */
    public static function boot(): void {
        StreamingRequestBootstrap::init('portal');
    }
}
