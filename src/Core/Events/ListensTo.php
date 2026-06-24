<?php

declare(strict_types=1);
namespace XcVm\Core\Events;


/**
 * Declarative listener registration via PHP 8.1 attribute.
 *
 * Attach to any public module method to register it as a typed event listener
 * without adding the method to getEventSubscribers().
 *
 * Both mechanisms are additive — a module may use getEventSubscribers(),
 * #[ListensTo], or both simultaneously without conflict.
 *
 * Usage:
 *
 *   #[ListensTo(StreamStartedEvent::class)]
 *   public function onStreamStarted(StreamStartedEvent $event): void { ... }
 *
 *   // With priority (higher = called first):
 *   #[ListensTo(ModuleInstalledEvent::class, priority: 20)]
 *   public function onInstall(ModuleInstalledEvent $event): void { ... }
 *
 *   // IS_REPEATABLE: one method may listen to multiple event classes:
 *   #[ListensTo(StreamStartedEvent::class)]
 *   #[ListensTo(StreamStoppedEvent::class)]
 *   public function onStreamChange(object $event): void { ... }
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ListensTo {

    /**
     * @param class-string $eventClass Fully-qualified event class name to listen for.
     * @param int          $priority   Dispatch priority — higher value means called first (default 0).
     */
    public function __construct(
        public readonly string $eventClass,
        public readonly int    $priority = 0,
    ) {}
}
