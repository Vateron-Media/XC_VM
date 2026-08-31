<?php

declare(strict_types=1);

namespace XcVm\Core\Reference;

/**
 * Static device reference data (supported MAG STB models) for admin UI.
 *
 * Replaces the legacy `$rMAGs` global from
 * resources/data/admin_constants.php with a typed accessor.
 *
 * @package XC_VM_Core_Reference
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class DeviceReference {
    /** Supported MAG / Aura STB model identifiers. */
    private const MAG_MODELS = array('AuraHD', 'AuraHD2', 'AuraHD3', 'AuraHD4', 'AuraHD5', 'AuraHD6', 'AuraHD7', 'AuraHD8', 'AuraHD9', 'MAG200', 'MAG245', 'MAG245D', 'MAG250', 'MAG254', 'MAG255', 'MAG256', 'MAG257', 'MAG260', 'MAG270', 'MAG275', 'MAG322', 'MAG323', 'MAG324', 'MAG325', 'MAG349', 'MAG350', 'MAG351', 'MAG352', 'MAG420', 'WR320', 'TH100', 'MAG424', 'MAG424W3');

    /**
     * Supported MAG / Aura STB model identifiers.
     *
     * @return array<int, string>
     */
    public static function magModels(): array {
        return self::MAG_MODELS;
    }
}
