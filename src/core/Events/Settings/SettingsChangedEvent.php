<?php

/**
 * Fired after panel settings are saved.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class SettingsChangedEvent {
    /**
     * @param array $previous  Settings before the change.
     * @param array $current   Settings after the change.
     * @param int   $changedBy User id that made the change.
     * @param float $changedAt Unix timestamp (with microseconds) of the change.
     */
    public function __construct(
        public readonly array  $previous,
        public readonly array  $current,
        public readonly int    $changedBy,
        public readonly float  $changedAt,
    ) {}

    /**
     * Keys whose values differ between previous and current settings.
     *
     * @return string[]
     */
    public function changedKeys(): array {
        return array_keys(array_diff_assoc($this->current, $this->previous));
    }
}
