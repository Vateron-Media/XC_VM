<?php

/**
 * Thrown when a service identifier is not found in the container.
 *
 * @package XC_VM_Core_Container
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface {}
