<?php

/**
 * Legacy event contract for string-keyed EventDispatcher::publish() usage.
 *
 * @deprecated Prefer typed readonly event classes dispatched via EventDispatcher::dispatch().
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface EventInterface {
    public function getName(): string;
    public function getPayload(): mixed;
}
