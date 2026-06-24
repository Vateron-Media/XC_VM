<?php

namespace XcVm\Core\Exception\Container;
use XcVm\Core\Exception\XcVmException;

use XcVm\Core\Container\Psr\ContainerExceptionInterface;
use XcVm\Core\Container\ServiceContainer;
/**
 * Base exception for ServiceContainer errors.
 *
 * Implements PSR-11 ContainerExceptionInterface so it is a valid
 * container exception under the PSR spec.
 *
 * @package XC_VM_Core_Exception_Container
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ContainerException extends XcVmException implements ContainerExceptionInterface {}
