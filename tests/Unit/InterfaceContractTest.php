<?php

use XcVm\Module\Ministra\MinistraModule;
use XcVm\Cli\CommandRegistry;
use XcVm\Core\Enum\ModuleState;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\Contract\StreamMiddlewareProviderInterface;
use XcVm\Core\Module\Contract\ServiceProviderInterface;
use XcVm\Core\Module\Contract\RouteProviderInterface;
use XcVm\Core\Module\Contract\NavbarProviderInterface;
use XcVm\Core\Module\Contract\CronProviderInterface;
use XcVm\Core\Module\Contract\CommandProviderInterface;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\MigratableInterface;
use XcVm\Core\Module\ModuleInterface;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * Docs-in-CI: verify that all documented interface methods exist with
 * the expected signatures. Fails immediately if an interface is renamed
 * or a method is removed, so documentation and code can never silently diverge.
 *
 * Each test is named after the interface/class it protects. Adding a new
 * public method to an interface requires adding it to the corresponding
 * assertion here — this serves as the authoritative method manifest.
 */
final class InterfaceContractTest extends TestCase {

    // ── ModuleInterface ───────────────────────────────────────────

    public function testModuleInterfaceExtendsAllSubInterfaces(): void {
        $rc = new ReflectionClass(ModuleInterface::class);
        $this->assertTrue($rc->isInterface());

        $parents = array_map(
            fn(ReflectionClass $p) => $p->getName(),
            $rc->getInterfaces()
        );

        foreach ([
            ServiceProviderInterface::class,
            RouteProviderInterface::class,
            CommandProviderInterface::class,
            NavbarProviderInterface::class,
        ] as $expected) {
            $this->assertContains(
                $expected,
                $parents,
                "ModuleInterface must extend {$expected}"
            );
        }
    }

    public function testModuleInterfaceHasIdentityMethods(): void {
        $rc = new ReflectionClass(ModuleInterface::class);

        $this->assertInterfaceMethod($rc, 'getName',    [], 'string');
        $this->assertInterfaceMethod($rc, 'getVersion', [], 'string');
    }

    public function testModuleInterfaceHasLifecycleMethods(): void {
        $rc = new ReflectionClass(ModuleInterface::class);

        $this->assertInterfaceMethod($rc, 'install',   [], 'void');
        $this->assertInterfaceMethod($rc, 'uninstall', [], 'void');
    }

    // ── ServiceProviderInterface ──────────────────────────────────

    public function testServiceProviderInterfaceHasBoot(): void {
        $rc = new ReflectionClass(ServiceProviderInterface::class);
        $this->assertInterfaceMethod($rc, 'boot', ['XcVm\Core\Container\ServiceContainer'], 'void');
    }

    public function testServiceProviderInterfaceHasGetEventSubscribers(): void {
        $rc = new ReflectionClass(ServiceProviderInterface::class);
        $this->assertInterfaceMethod($rc, 'getEventSubscribers', [], 'array');
    }

    // ── RouteProviderInterface ────────────────────────────────────

    public function testRouteProviderInterfaceHasRegisterRoutes(): void {
        $rc = new ReflectionClass(RouteProviderInterface::class);
        $this->assertInterfaceMethod($rc, 'registerRoutes', ['XcVm\\Core\\Http\\Router'], 'void');
    }

    // ── CommandProviderInterface ──────────────────────────────────

    public function testCommandProviderInterfaceHasRegisterCommands(): void {
        $rc = new ReflectionClass(CommandProviderInterface::class);
        $this->assertInterfaceMethod($rc, 'registerCommands', ['XcVm\Cli\CommandRegistry'], 'void');
    }

    // ── NavbarProviderInterface ───────────────────────────────────

    public function testNavbarProviderInterfaceHasRegisterNavbar(): void {
        $rc = new ReflectionClass(NavbarProviderInterface::class);
        $this->assertInterfaceMethod($rc, 'registerNavbar', ['XcVm\\Core\\Module\\NavbarRegistry'], 'void');
    }

    // ── StreamMiddlewareProviderInterface ─────────────────────────

    public function testStreamMiddlewareProviderInterfaceHasGetStreamMiddleware(): void {
        $rc = new ReflectionClass(StreamMiddlewareProviderInterface::class);
        $this->assertInterfaceMethod($rc, 'getStreamMiddleware', [], 'array');
    }

    // ── MigratableInterface ───────────────────────────────────────

    public function testMigratableInterfaceHasGetMigrations(): void {
        $rc = new ReflectionClass(MigratableInterface::class);
        $this->assertTrue($rc->isInterface());
        $this->assertInterfaceMethod($rc, 'getMigrations', [], 'array');
    }

    // ── CronProviderInterface ─────────────────────────────────────

    public function testCronProviderInterfaceHasGetCronEntries(): void {
        $rc = new ReflectionClass(CronProviderInterface::class);
        $this->assertTrue($rc->isInterface());
        $this->assertInterfaceMethod($rc, 'getCronEntries', [], 'array');
    }

    // ── BaseModule ────────────────────────────────────────────────

    public function testBaseModuleIsAbstract(): void {
        $rc = new ReflectionClass(BaseModule::class);
        $this->assertTrue($rc->isAbstract(), 'BaseModule must be abstract');
    }

    public function testBaseModuleImplementsModuleInterface(): void {
        $rc = new ReflectionClass(BaseModule::class);
        $this->assertTrue(
            $rc->implementsInterface(ModuleInterface::class),
            'BaseModule must implement ModuleInterface'
        );
    }

    public function testBaseModuleImplementsMigratableInterface(): void {
        $rc = new ReflectionClass(BaseModule::class);
        $this->assertTrue(
            $rc->implementsInterface(MigratableInterface::class),
            'BaseModule must implement MigratableInterface'
        );
    }

    public function testBaseModuleImplementsCronProviderInterface(): void {
        $rc = new ReflectionClass(BaseModule::class);
        $this->assertTrue(
            $rc->implementsInterface(CronProviderInterface::class),
            'BaseModule must implement CronProviderInterface'
        );
    }

    public function testBaseModuleHasGetNameAndGetVersionAbstract(): void {
        $rc = new ReflectionClass(BaseModule::class);

        foreach (['getName', 'getVersion'] as $method) {
            $rm = $rc->getMethod($method);
            $this->assertTrue(
                $rm->isAbstract(),
                "BaseModule::{$method}() must be abstract (identity contract)"
            );
        }
    }

    public function testBaseModuleProvidesConcreteDefaultForOptionalMethods(): void {
        $rc = new ReflectionClass(BaseModule::class);

        $optionalMethods = [
            'boot', 'getEventSubscribers', 'registerRoutes', 'registerCommands',
            'registerNavbar', 'install', 'uninstall', 'getMigrations', 'getCronEntries',
        ];

        foreach ($optionalMethods as $method) {
            $rm = $rc->getMethod($method);
            $this->assertFalse(
                $rm->isAbstract(),
                "BaseModule::{$method}() must provide a concrete default implementation"
            );
        }
    }

    public function testBaseModuleGetMigrationsReturnsEmptyArray(): void {
        $module = new class extends BaseModule {
            public function getName(): string    { return 'test'; }
            public function getVersion(): string { return '1.0.0'; }
        };

        $this->assertSame([], $module->getMigrations());
    }

    public function testBaseModuleGetCronEntriesReturnsEmptyArray(): void {
        $module = new class extends BaseModule {
            public function getName(): string    { return 'test'; }
            public function getVersion(): string { return '1.0.0'; }
        };

        $this->assertSame([], $module->getCronEntries());
    }

    // ── EventDispatcher ───────────────────────────────────────────

    public function testEventDispatcherIsInstantiable(): void {
        $rc = new ReflectionClass(EventDispatcher::class);
        $this->assertFalse($rc->isAbstract(), 'EventDispatcher must be concrete');
        $this->assertTrue($rc->hasMethod('__construct'));
    }

    public function testEventDispatcherHasSingletonManagement(): void {
        $rc = new ReflectionClass(EventDispatcher::class);

        foreach (['getInstance', 'setInstance', 'resetInstance'] as $method) {
            $rm = $rc->getMethod($method);
            $this->assertTrue(
                $rm->isStatic(),
                "EventDispatcher::{$method}() must be static"
            );
        }
    }

    public function testEventDispatcherCoreApiIsStatic(): void {
        $rc = new ReflectionClass(EventDispatcher::class);

        foreach (['dispatch', 'listen', 'hasListeners', 'unlisten', 'clear'] as $method) {
            $rm = $rc->getMethod($method);
            $this->assertTrue(
                $rm->isStatic(),
                "EventDispatcher::{$method}() must be static (backward-compat bridge)"
            );
        }
    }

    // ── ModuleState enum ──────────────────────────────────────────

    public function testModuleStateEnumExists(): void {
        $rc = new \ReflectionEnum(ModuleState::class);
        $this->assertTrue($rc->isBacked());
        $this->assertSame('string', (string) $rc->getBackingType());
    }

    public function testModuleStateEnumHasAllFourCases(): void {
        $cases = ModuleState::cases();
        $names = array_map(fn($c) => $c->name, $cases);
        $this->assertContains('Enabled',    $names);
        $this->assertContains('Disabled',   $names);
        $this->assertContains('Installing', $names);
        $this->assertContains('Failed',     $names);
    }

    public function testModuleStateHasIsLoadable(): void {
        $rc = new \ReflectionEnum(ModuleState::class);
        $this->assertTrue($rc->hasMethod('isLoadable'));
        $rm = $rc->getMethod('isLoadable');
        $this->assertSame('bool', (string) $rm->getReturnType());
    }

    public function testModuleStateHasFromRaw(): void {
        $rc = new \ReflectionEnum(ModuleState::class);
        $this->assertTrue($rc->hasMethod('fromRaw'));
        $rm = $rc->getMethod('fromRaw');
        $this->assertTrue($rm->isStatic());
    }

    // ── ListensTo attribute ───────────────────────────────────────

    public function testListensToAttributeExists(): void {
        $rc = new ReflectionClass(ListensTo::class);
        $attrs = $rc->getAttributes(\Attribute::class);
        $this->assertNotEmpty($attrs, 'ListensTo must carry #[\Attribute]');
    }

    public function testListensToIsRepeatableAndTargetsMethod(): void {
        $rc = new ReflectionClass(ListensTo::class);
        $attrArgs = $rc->getAttributes(\Attribute::class)[0]->getArguments();
        $flags = $attrArgs[0] ?? 0;
        $this->assertSame(
            \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE,
            $flags,
        );
    }

    public function testListensToHasEventClassAndPriorityProperties(): void {
        $rc = new ReflectionClass(ListensTo::class);
        $this->assertTrue($rc->hasProperty('eventClass'));
        $this->assertTrue($rc->hasProperty('priority'));
    }

    // ── Helper ────────────────────────────────────────────────────

    /**
     * Assert that a method exists on the interface with the given parameter
     * type-hint short names and return type name.
     *
     * @param string[] $paramTypes Short class names of parameter types (in order).
     *                             Pass [] to assert no parameters.
     */
    private function assertInterfaceMethod(
        ReflectionClass $rc,
        string $methodName,
        array $paramTypes,
        string $returnType
    ): void {
        $this->assertTrue(
            $rc->hasMethod($methodName),
            "{$rc->getShortName()}::{$methodName}() must exist"
        );

        $rm = $rc->getMethod($methodName);

        // Return type
        $actual = (string) ($rm->getReturnType() ?? '');
        $this->assertSame(
            $returnType,
            $actual,
            "{$rc->getShortName()}::{$methodName}() must return {$returnType}"
        );

        // Parameter types (short names)
        $params = $rm->getParameters();
        $this->assertCount(
            count($paramTypes),
            $params,
            "{$rc->getShortName()}::{$methodName}() must have " . count($paramTypes) . " parameter(s)"
        );

        foreach ($paramTypes as $i => $expectedType) {
            $actualType = (string) ($params[$i]->getType() ?? '');
            $this->assertSame(
                $expectedType,
                $actualType,
                "{$rc->getShortName()}::{$methodName}() parameter {$i} must be typed {$expectedType}"
            );
        }
    }
}
