<?php

namespace XcVm\Core\Events\Contract;

/**
 * Marks an event as stoppable — listeners can halt further propagation.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface StoppableEventInterface {

    /**
     * Whether propagation has been stopped by a previous listener.
     *
     * When true, EventDispatcher must not call any further listeners.
     */
    public function isPropagationStopped(): bool;
}
