<?php

declare(strict_types=1);

namespace XcVm\Core\Enum;

/**
 * Reseller activity-log action type.
 *
 * Replaces the legacy `$rResellerActions` global (key => label map) with
 * a string-backed enum. The backing value equals the legacy array key
 * (the `action` column persisted in the reseller log).
 *
 * @package XC_VM_Core_Enum
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
enum ResellerAction: string {
    case New           = 'new';
    case Extend        = 'extend';
    case Convert       = 'convert';
    case Edit          = 'edit';
    case Enable        = 'enable';
    case Disable       = 'disable';
    case Delete        = 'delete';
    case SendEvent     = 'send_event';
    case AdjustCredits = 'adjust_credits';

    /**
     * Human-readable action label (as shown in log filters).
     */
    public function label(): string {
        return match ($this) {
            self::New           => 'Create',
            self::Extend        => 'Extend',
            self::Convert       => 'Convert',
            self::Edit          => 'Edit',
            self::Enable        => 'Enable',
            self::Disable       => 'Disable',
            self::Delete        => 'Delete',
            self::SendEvent     => 'MAG Event',
            self::AdjustCredits => 'Adjust Credits',
        };
    }

    /**
     * Ordered key => label map for building select/filter dropdowns,
     * preserving the legacy `$rResellerActions` ordering.
     *
     * @return array<string, string>
     */
    public static function options(): array {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
