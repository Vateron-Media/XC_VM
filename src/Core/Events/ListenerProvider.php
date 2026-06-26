<?php

namespace XcVm\Core\Events;

/**
 * Registry of event listeners with per-event priority ordering.
 *
 * Listeners are stored as: eventClass → priority → callable[].
 * Higher priority value = called first.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ListenerProvider {

    /** @var array<string, array<int, callable[]>> */
    private array $listeners = [];

    /**
     * Register a listener for an event class.
     *
     * @param string   $eventClass Fully-qualified event class name
     * @param callable $listener
     * @param int      $priority   Higher value = called first (default 0)
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void {
        $this->listeners[$eventClass][$priority][] = $listener;
    }

    /**
     * Remove all listeners for an event class, or a specific listener.
     *
     * @param string        $eventClass
     * @param callable|null $listener   If null, removes all listeners for the event
     */
    public function removeListener(string $eventClass, ?callable $listener = null): void {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        if ($listener === null) {
            unset($this->listeners[$eventClass]);
            return;
        }

        foreach ($this->listeners[$eventClass] as $priority => $group) {
            $filtered = array_filter($group, fn($l) => $l !== $listener);
            if (empty($filtered)) {
                unset($this->listeners[$eventClass][$priority]);
            } else {
                $this->listeners[$eventClass][$priority] = array_values($filtered);
            }
        }
    }

    /**
     * Yield listeners for the given event in priority order (highest first).
     *
     * @param object $event
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable {
        $class = $event::class;
        if (!isset($this->listeners[$class])) {
            return;
        }

        $buckets = $this->listeners[$class];
        krsort($buckets);

        foreach ($buckets as $group) {
            yield from $group;
        }
    }

    /**
     * Check whether any listeners are registered for an event class.
     *
     * @param string $eventClass
     */
    public function hasListeners(string $eventClass): bool {
        return !empty($this->listeners[$eventClass]);
    }

    /**
     * Return all registered listeners (for debugging/introspection).
     *
     * @return array<string, array<int, callable[]>>
     */
    public function all(): array {
        return $this->listeners;
    }

    /**
     * Remove all listeners (for testing).
     */
    public function clear(): void {
        $this->listeners = [];
    }
}
