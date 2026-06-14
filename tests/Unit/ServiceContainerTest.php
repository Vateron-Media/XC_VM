<?php

use PHPUnit\Framework\TestCase;

final class ServiceContainerTest extends TestCase {

    protected function setUp(): void {
        ServiceContainer::resetInstance();
    }

    protected function tearDown(): void {
        ServiceContainer::resetInstance();
    }

    // ── PSR-11 compliance ──────────────────────────────────────

    public function testGetReturnsRegisteredValue(): void {
        $c = ServiceContainer::getInstance();
        $c->set('foo', 'bar');

        $this->assertSame('bar', $c->get('foo'));
    }

    public function testGetThrowsNotFoundExceptionForMissingService(): void {
        $c = ServiceContainer::getInstance();

        $this->expectException(NotFoundException::class);
        $c->get('does-not-exist');
    }

    public function testNotFoundExceptionImplementsContainerInterface(): void {
        $this->assertInstanceOf(NotFoundExceptionInterface::class, new NotFoundException());
        $this->assertInstanceOf(ContainerExceptionInterface::class, new NotFoundException());
    }

    public function testHasReturnsTrueForRegisteredService(): void {
        $c = ServiceContainer::getInstance();
        $c->set('exists', 42);

        $this->assertTrue($c->has('exists'));
    }

    public function testHasReturnsFalseForMissingService(): void {
        $c = ServiceContainer::getInstance();

        $this->assertFalse($c->has('missing'));
    }

    public function testContainerImplementsContainerInterface(): void {
        $this->assertInstanceOf(ContainerInterface::class, ServiceContainer::getInstance());
    }

    // ── Factory / singleton ────────────────────────────────────

    public function testFactoryCallableIsCalledOnce(): void {
        $c = ServiceContainer::getInstance();
        $calls = 0;
        $c->set('obj', function () use (&$calls) {
            $calls++;
            return new stdClass();
        });

        $a = $c->get('obj');
        $b = $c->get('obj');

        $this->assertSame(1, $calls);
        $this->assertSame($a, $b);
    }

    public function testFactoryMethodReturnsNewInstanceEachTime(): void {
        $c = ServiceContainer::getInstance();
        $c->factory('fresh', fn() => new stdClass());

        $a = $c->get('fresh');
        $b = $c->get('fresh');

        $this->assertNotSame($a, $b);
    }

    // ── Circular dependency detection ──────────────────────────

    public function testCircularDependencyThrowsRuntimeException(): void {
        $c = ServiceContainer::getInstance();
        $c->set('a', function ($c) {
            return $c->get('b');
        });
        $c->set('b', function ($c) {
            return $c->get('a');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/цикл/u');
        $c->get('a');
    }

    // ── Decoration ─────────────────────────────────────────────

    public function testDecorateWrapsService(): void {
        $c = ServiceContainer::getInstance();
        $c->set('value', fn() => 'original');
        $c->decorate('value', fn($inner, $c) => strtoupper($inner));

        $this->assertSame('ORIGINAL', $c->get('value'));
    }

    public function testDecoratePriorityOrderIsHighestOutermost(): void {
        $c = ServiceContainer::getInstance();
        $c->set('str', fn() => 'base');
        $c->decorate('str', fn($inner, $c) => "[low:{$inner}]",  priority: 10);
        $c->decorate('str', fn($inner, $c) => "[high:{$inner}]", priority: 50);

        // highest priority (50) wraps outermost → called first by caller
        $this->assertSame('[high:[low:base]]', $c->get('str'));
    }

    public function testDecorateProtectedServiceThrowsRuntimeException(): void {
        $c = ServiceContainer::getInstance();

        foreach (['db', 'settings', 'config', 'auth'] as $protected) {
            try {
                $c->decorate($protected, fn($inner) => $inner);
                $this->fail("Expected RuntimeException for protected service '{$protected}'");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($protected, $e->getMessage());
            }
        }
    }

    public function testDecorateUnregisteredServiceThrowsRuntimeException(): void {
        $c = ServiceContainer::getInstance();

        $this->expectException(RuntimeException::class);
        $c->decorate('not-registered', fn($inner) => $inner);
    }

    // ── getOrDefault ───────────────────────────────────────────

    public function testGetOrDefaultReturnsFallbackForMissingService(): void {
        $c = ServiceContainer::getInstance();

        $this->assertSame('fallback', $c->getOrDefault('missing', 'fallback'));
    }

    public function testGetOrDefaultReturnServiceWhenPresent(): void {
        $c = ServiceContainer::getInstance();
        $c->set('key', 'value');

        $this->assertSame('value', $c->getOrDefault('key', 'fallback'));
    }
}
