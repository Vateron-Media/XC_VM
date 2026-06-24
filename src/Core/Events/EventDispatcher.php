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
 * ──────────────────────────────────────────────────────────────────
 * Container / DI usage:
 * ──────────────────────────────────────────────────────────────────
 *
 *   $dispatcher = $container->get('events'); // EventDispatcher instance
 *   // Static calls (EventDispatcher::listen / dispatch / etc.) delegate to
 *   // the same instance — both paths share the same listener store.
 *
 * ──────────────────────────────────────────────────────────────────
 * Singleton bridge:
 * ──────────────────────────────────────────────────────────────────
 *
 *   All static methods delegate to EventDispatcher::getInstance().
 *   bootstrap.php creates the canonical instance with:
 *
 *     $dispatcher = new EventDispatcher();
 *     EventDispatcher::setInstance($dispatcher);
 *     $container->set('events', $dispatcher);
 *
 *   Tests create a per-test instance with setInstance() / resetInstance().
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

    // ── Singleton management ──────────────────────────────────────

    private static ?self $instance = null;

    /**
     * Return the active singleton instance, creating one on first call.
     *
     * In production: wired by bootstrap via setInstance(new EventDispatcher()).
     * In tests: call setInstance() with a fresh instance per-test.
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the active singleton (used by bootstrap and test setUp).
     */
    public static function setInstance(self $instance): void {
        self::$instance = $instance;
    }

    /**
     * Null-out the singleton so the next getInstance() call creates a fresh one.
     *
     * Primarily for test tearDown to prevent listener state leaking between tests.
     */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    // ── Instance state ────────────────────────────────────────────

    private ListenerProvider $provider;

    /**
     * Create a dispatcher backed by a fresh ListenerProvider.
     */
    public function __construct() {
        $this->provider = new ListenerProvider();
    }

    // ─────────────────────────────────────────────────────────
    //  PSR-14-style API — static methods delegate to getInstance()
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
        $i = self::getInstance();
        foreach ($i->provider->getListenersForEvent($event) as $listener) {
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
        self::getInstance()->provider->addListener($eventClass, $listener, $priority);
    }

    /**
     * Remove a typed listener, or all listeners for an event class.
     *
     * @param class-string  $eventClass
     * @param callable|null $listener
     */
    public static function unlisten(string $eventClass, ?callable $listener = null): void {
        self::getInstance()->provider->removeListener($eventClass, $listener);
    }

    /**
     * Check if any typed listeners are registered for an event class.
     *
     * @param class-string $eventClass
     */
    public static function hasListeners(string $eventClass): bool {
        return self::getInstance()->provider->hasListeners($eventClass);
    }

    // ─────────────────────────────────────────────────────────
    //  Utility
    // ─────────────────────────────────────────────────────────

    /**
     * Clear all listeners — both typed and legacy (primarily for testing).
     */
    public static function clear(): void {
        self::getInstance()->provider->clear();
    }

    /**
     * Return the underlying ListenerProvider (for introspection).
     */
    public static function getProvider(): ListenerProvider {
        return self::getInstance()->provider;
    }

    /**
     * Replace the ListenerProvider (for testing or custom provider injection).
     */
    public static function setProvider(ListenerProvider $provider): void {
        self::getInstance()->provider = $provider;
    }
}
