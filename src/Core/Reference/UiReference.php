<?php

declare(strict_types=1);

namespace XcVm\Core\Reference;

/**
 * Static UI reference data (accent colour "hues") for admin/reseller UI.
 *
 * Replaces the legacy `$rHues` global from
 * resources/data/admin_constants.php with a typed accessor.
 *
 * @package XC_VM_Core_Reference
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class UiReference {
    /** CSS accent class => human-readable colour name (leading "" = Default). */
    private const HUES = array('' => 'Default', 'primary' => 'Blue', 'info' => 'Light Blue', 'success' => 'Green', 'danger' => 'Red', 'warning' => 'Orange', 'purple' => 'Purple', 'pink' => 'Pink', 'dark' => 'Dark Grey', 'secondary' => 'Light Grey');

    /**
     * CSS accent class => human-readable colour name for the profile
     * hue selector (leading empty key = "Default").
     *
     * @return array<string, string>
     */
    public static function hues(): array {
        return self::HUES;
    }
}
