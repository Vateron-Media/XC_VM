<?php

namespace XcVm\Core\Exception\Container;

use XcVm\Core\Container\ServiceContainer;

/**
 * Thrown when ServiceContainer detects a circular dependency chain.
 *
 * Example: service A factory requests B, B factory requests A.
 *
 * @package XC_VM_Core_Exception_Container
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CircularDependencyException extends ContainerException {}
