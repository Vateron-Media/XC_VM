<?php

namespace XcVm\Core\Exception\Container;

/**
 * Thrown when a service factory throws during ServiceContainer::get().
 *
 * Wraps the original factory exception as $previous so the full stack
 * trace is preserved.
 *
 * @package XC_VM_Core_Exception_Container
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ServiceCreationException extends ContainerException {}
