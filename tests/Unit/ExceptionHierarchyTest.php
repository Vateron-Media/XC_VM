<?php

declare(strict_types=1);
use XcVm\Core\Container\Psr\NotFoundException;
use XcVm\Core\Container\Psr\NotFoundExceptionInterface;
use XcVm\Core\Container\Psr\ContainerExceptionInterface;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the exception hierarchy introduced in R3-1.
 *
 * These tests act as a regression guard: if any class is renamed,
 * moved, or changes its parent, the test suite catches it immediately.
 */
class ExceptionHierarchyTest extends TestCase
{
    // ── Base ──────────────────────────────────────────────────

    public function testXcVmExceptionExtendsRuntimeException(): void {
        $this->assertTrue(
            is_a(XcVmException::class, RuntimeException::class, true),
            'XcVmException must extend RuntimeException for backward compat'
        );
    }

    // ── Container ─────────────────────────────────────────────

    public function testContainerExceptionExtendsXcVmException(): void {
        $this->assertTrue(is_a(ContainerException::class, XcVmException::class, true));
    }

    public function testContainerExceptionImplementsPsr11Interface(): void {
        $this->assertTrue(is_a(ContainerException::class, ContainerExceptionInterface::class, true));
    }

    public function testCircularDependencyExceptionExtendsContainerException(): void {
        $this->assertTrue(is_a(CircularDependencyException::class, ContainerException::class, true));
    }

    public function testServiceCreationExceptionExtendsContainerException(): void {
        $this->assertTrue(is_a(ServiceCreationException::class, ContainerException::class, true));
    }

    public function testNotFoundExceptionExtendsContainerException(): void {
        $this->assertTrue(is_a(NotFoundException::class, ContainerException::class, true));
    }

    public function testNotFoundExceptionImplementsPsr11Interface(): void {
        $this->assertTrue(is_a(NotFoundException::class, NotFoundExceptionInterface::class, true));
    }

    // ── Module ────────────────────────────────────────────────

    public function testModuleExceptionExtendsXcVmException(): void {
        $this->assertTrue(is_a(ModuleException::class, XcVmException::class, true));
    }

    public function testModuleNotFoundExceptionExtendsModuleException(): void {
        $this->assertTrue(is_a(ModuleNotFoundException::class, ModuleException::class, true));
    }

    public function testModuleCycleExceptionExtendsModuleException(): void {
        $this->assertTrue(is_a(ModuleCycleException::class, ModuleException::class, true));
    }

    public function testModuleLoadExceptionExtendsModuleException(): void {
        $this->assertTrue(is_a(ModuleLoadException::class, ModuleException::class, true));
    }

    public function testModuleManifestExceptionExtendsModuleException(): void {
        $this->assertTrue(is_a(ModuleManifestException::class, ModuleException::class, true));
    }

    // ── Catchability ──────────────────────────────────────────

    public function testCircularDependencyIsCatchableAsXcVmException(): void {
        $caught = false;
        try {
            throw new CircularDependencyException('test');
        } catch (XcVmException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'CircularDependencyException must be catchable as XcVmException');
    }

    public function testModuleCycleIsCatchableAsModuleException(): void {
        $caught = false;
        try {
            throw new ModuleCycleException('test');
        } catch (ModuleException) {
            $caught = true;
        }
        $this->assertTrue($caught);
    }

    public function testModuleExceptionIsCatchableAsRuntimeException(): void {
        $caught = false;
        try {
            throw new ModuleException('test');
        } catch (RuntimeException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'ModuleException must be catchable as RuntimeException for backward compat');
    }
}
