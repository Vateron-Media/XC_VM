<?php

/**
 * Fired after panel settings are saved.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final readonly class SettingsChangedEvent {
    public function __construct(
        public array  $previous,
        public array  $current,
        public int    $changedBy,
        public float  $changedAt,
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
