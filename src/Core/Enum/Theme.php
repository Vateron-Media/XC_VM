<?php

declare(strict_types=1);

namespace XcVm\Core\Enum;

/**
 * Admin/reseller UI theme, stored as an integer index on the user profile.
 *
 * Replaces the legacy `$rThemes` global (a positional array of
 * `['name' => ..., 'dark' => ..., 'image' => ...]` rows) with an
 * exhaustive enum. The backing int equals the legacy array index, so
 * existing `profile.theme` values map straight through Theme::fromId().
 *
 * @package XC_VM_Core_Enum
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
enum Theme: int {
    /** Default light theme. */
    case Light = 0;

    /** Dark theme. */
    case Dark = 1;

    /**
     * Return true when this theme renders the dark layout variant.
     */
    public function isDark(): bool {
        return $this === self::Dark;
    }

    /**
     * Human-readable theme name (as shown in the profile selector).
     */
    public function label(): string {
        return match ($this) {
            self::Light => 'Light',
            self::Dark  => 'Dark',
        };
    }

    /**
     * Resolve a stored profile theme value to a Theme, defaulting to Light
     * for missing or out-of-range values.
     *
     * @param mixed $id The raw `theme` value from the user profile.
     */
    public static function fromId(mixed $id): self {
        return self::tryFrom((int) $id) ?? self::Light;
    }

    /**
     * Ordered id => label map for building the theme selector, preserving
     * the legacy `$rThemes` ordering (0 => Light, 1 => Dark).
     *
     * @return array<int, string>
     */
    public static function options(): array {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }
}
