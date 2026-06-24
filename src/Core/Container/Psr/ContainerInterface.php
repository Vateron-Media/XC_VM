<?php

namespace XcVm\Core\Container\Psr;

/**
 * PSR-11 ContainerInterface.
 *
 * @see https://www.php-fig.org/psr/psr-11/
 *
 * @package XC_VM_Core_Container
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface ContainerInterface {

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @throws NotFoundExceptionInterface  No entry was found for this identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     */
    public function get(string $id): mixed;

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool;
}
