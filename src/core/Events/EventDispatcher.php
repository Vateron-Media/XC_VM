<?php

/**
 * Event dispatcher with PSR-14-style typed events and priority-ordered listeners.
 *
 * ──────────────────────────────────────────────────────────────────
 * New API (preferred):
 * ──────────────────────────────────────────────────────────────────
 *
 *   // Register a listener (class-based event)
 *   EventDispatcher::listen(StreamStartedEvent::class, function(StreamStartedEvent $e): void {
 *       // handle
 *   }, priority: 10);
 *
 *   // Dispatch — returns the event object (possibly mutated or stopped)
 *   $event = EventDispatcher::dispatch(new StreamStartedEvent(...));
 *
 *   // Stoppable event
 *   $event = EventDispatcher::dispatch(new StreamStartingEvent(...));
 *   if ($event->isPropagationStopped()) {
 *       // a listener aborted the stream
 *   }
 *
 * ──────────────────────────────────────────────────────────────────
 * Legacy API (kept for backward compatibility):
 * ──────────────────────────────────────────────────────────────────
 *
 *   EventDispatcher::subscribe('some.event', $listener);
 *   EventDispatcher::publish('some.event', $payload);
 *
 * ──────────────────────────────────────────────────────────────────
 * Module registration via ModuleLoader:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Modules declare subscribers in getEventSubscribers():
 *
 *   public function getEventSubscribers(): array {
 *       return [
 *           StreamStartedEvent::class => [$this, 'onStreamStarted'],
 *           StreamStartedEvent::class => [[$this, 'onStreamStarted'], 20], // with priority
 *       ];
 *   }
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class EventDispatcher {

    private static ?ListenerProvider $provider = null;

    /** @var array<string, callable[]> Legacy string-keyed listeners */
    private static array $legacyListeners = [];

    // ─────────────────────────────────────────────────────────
    //  PSR-14-style API
    // ─────────────────────────────────────────────────────────

    /**
     * Dispatch a typed event object to all registered listeners.
     *
     * Listeners are called in priority order (highest first).
     * Stops early if event implements StoppableEventInterface and propagation was stopped.
     *
     * @template T of object
     * @param T $event
     * @return T The same event, possibly mutated by listeners
     */
    public static function dispatch(object $event): object {
        $provider = self::getProvider();

        foreach ($provider->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }

    /**
     * Register a typed listener.
     *
     * @param class-string $eventClass Fully-qualified event class name
     * @param callable     $listener   Receives the event object as sole argument
     * @param int          $priority   Higher = called first (default 0)
     */
    public static function listen(string $eventClass, callable $listener, int $priority = 0): void {
        self::getProvider()->addListener($eventClass, $listener, $priority);
    }

    /**
     * Remove a typed listener, or all listeners for an event class.
     *
     * @param class-string  $eventClass
     * @param callable|null $listener
     */
    public static function unlisten(string $eventClass, ?callable $listener = null): void {
        self::getProvider()->removeListener($eventClass, $listener);
    }

    /**
     * Check if any typed listeners are registered for an event class.
     *
     * @param class-string $eventClass
     */
    public static function hasListeners(string $eventClass): bool {
        return self::getProvider()->hasListeners($eventClass);
    }

    // ─────────────────────────────────────────────────────────
    //  Legacy API (string-keyed events)
    // ─────────────────────────────────────────────────────────

    /**
     * @deprecated Use listen() with a class-based event instead.
     */
    public static function subscribe(string $eventName, callable $listener): bool {
        if (strlen($eventName) === 0) {
            return false;
        }
        self::$legacyListeners[$eventName][] = $listener;
        return true;
    }

    /**
     * @deprecated Use unlisten() with a class-based event instead.
     */
    public static function unsubscribe(string $eventName, ?callable $listener = null): void {
        if (!isset(self::$legacyListeners[$eventName])) {
            return;
        }

        if ($listener === null) {
            unset(self::$legacyListeners[$eventName]);
            return;
        }

        self::$legacyListeners[$eventName] = array_values(
            array_filter(self::$legacyListeners[$eventName], fn($l) => $l !== $listener)
        );
    }

    /**
     * @deprecated Use dispatch() with a typed event object instead.
     *
     * Accepts either a string event name + payload, or an EventInterface object.
     *
     * @return array Results from each listener
     */
    public static function publish(string|EventInterface $event, mixed $payload = null): array {
        if ($event instanceof EventInterface) {
            $payload   = $event->getPayload();
            $eventName = $event->getName();
        } else {
            $eventName = $event;
        }

        if (!isset(self::$legacyListeners[$eventName])) {
            return [];
        }

        $results = [];
        foreach (self::$legacyListeners[$eventName] as $listener) {
            $results[] = $listener($payload, $eventName);
        }

        return $results;
    }

    /**
     * @deprecated Use unlisten() or clear().
     */
    public static function getListeners(?string $eventName = null): array {
        if ($eventName === null) {
            return self::$legacyListeners;
        }
        return self::$legacyListeners[$eventName] ?? [];
    }

    // ─────────────────────────────────────────────────────────
    //  Utility
    // ─────────────────────────────────────────────────────────

    /**
     * Clear all listeners — both typed and legacy (primarily for testing).
     */
    public static function clear(): void {
        self::getProvider()->clear();
        self::$legacyListeners = [];
    }

    /**
     * Return the underlying ListenerProvider (for introspection).
     */
    public static function getProvider(): ListenerProvider {
        if (self::$provider === null) {
            self::$provider = new ListenerProvider();
        }
        return self::$provider;
    }

    /**
     * Replace the ListenerProvider (for testing).
     */
    public static function setProvider(ListenerProvider $provider): void {
        self::$provider = $provider;
    }
}
