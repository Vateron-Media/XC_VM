<?php

namespace XcVm\Core\Events;

use XcVm\Core\Events\Contract\StoppableEventInterface;

/**
 * Base class for stoppable events.
 *
 * Extend this when the event must be able to halt listener propagation.
 * For plain informational events prefer a readonly class without inheritance.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class AbstractEvent implements StoppableEventInterface {

    private bool $propagationStopped = false;

    /**
     * Whether a listener has stopped propagation of this event.
     *
     * @return bool True if propagation was stopped.
     */
    public function isPropagationStopped(): bool {
        return $this->propagationStopped;
    }

    /**
     * Stop propagation so no further listeners receive this event.
     *
     * @return void
     */
    public function stopPropagation(): void {
        $this->propagationStopped = true;
    }
}
