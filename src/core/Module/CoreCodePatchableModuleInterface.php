<?php

/**
 * Optional extension for modules that need to patch core files.
 *
 * The module returns a list of full-file replacements that should be applied
 * on install and rolled back on uninstall.
 *
 * @temporary This is a stopgap until proper hook/integration points exist
 *            in core. When core provides native extension points for the
 *            functionality your module needs, migrate away from patching.
 *
 * @package XC_VM_Core_Module
 * @author  obscuremind <https://github.com/obscuremind>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface CoreCodePatchableModuleInterface extends ModuleInterface {
    /**
     * Return file patch definitions.
     *
     * Supported item keys:
     * - target (string, required): file path relative to MAIN_HOME.
     * - content (string, optional): replacement file content.
     * - source (string, optional): path relative to module root with replacement content.
     *
     * Exactly one of content/source should be provided per patch.
     *
     * @return array<int, array<string, string>>
     */
    public function getCoreCodePatches(): array;
}
