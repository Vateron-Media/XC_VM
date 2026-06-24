<?php

namespace XcVm\Core\Container\Psr;
use XcVm\Core\Exception\Container\ContainerException;

/**
 * Thrown when a service identifier is not found in the container.
 *
 * @package XC_VM_Core_Container
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class NotFoundException extends \XcVm\Core\Exception\Container\ContainerException implements NotFoundExceptionInterface {}
