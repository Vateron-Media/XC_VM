<?php

use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Events\ListensTo;
use PHPUnit\Framework\TestCase;

final class ListensToTestEventAlpha {}
final class ListensToTestEventBeta {}

final class ListensToAttributeTest extends TestCase {

    protected function setUp(): void {
        ServiceContainer::resetInstance();
        EventDispatcher::resetInstance();

        $dispatcher = new EventDispatcher();
        EventDispatcher::setInstance($dispatcher);
        ServiceContainer::getInstance()->set('events', $dispatcher);
    }

    protected function tearDown(): void {
        ServiceContainer::resetInstance();
        EventDispatcher::resetInstance();
    }

    // ── Attribute shape ───────────────────────────────────────────

    public function testListensToIsAttribute(): void {
        $rc = new \ReflectionClass(ListensTo::class);
        $attrs = $rc->getAttributes(\Attribute::class);
        $this->assertNotEmpty($attrs, 'ListensTo must carry #[\Attribute]');
    }

    public function testListensToConstructorSetsEventClass(): void {
        $a = new ListensTo(ListensToTestEventAlpha::class, priority: 5);
        $this->assertSame(ListensToTestEventAlpha::class, $a->eventClass);
        $this->assertSame(5, $a->priority);
    }

    public function testListensToPriorityDefaultsToZero(): void {
        $a = new ListensTo(ListensToTestEventAlpha::class);
        $this->assertSame(0, $a->priority);
    }

    // ── ModuleLoader integration ──────────────────────────────────

    public function testAttributeListenerIsRegistered(): void {
        $root = $this->createRoot();
        $this->createAttributeModule($root, 'listens-alpha');

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll(ServiceContainer::getInstance());

        $this->assertTrue(
            EventDispatcher::hasListeners(ListensToTestEventAlpha::class),
            'Listener registered via #[ListensTo] must appear in EventDispatcher',
        );

        $this->cleanRoot($root);
    }

    public function testModuleWithoutAttributesDoesNotFail(): void {
        $root = $this->createRoot();
        $this->createPlainModule($root, 'listens-plain');

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll(ServiceContainer::getInstance());

        $this->assertFalse(EventDispatcher::hasListeners(ListensToTestEventBeta::class));

        $this->cleanRoot($root);
    }

    public function testBothMechanismsWorkSimultaneously(): void {
        $root = $this->createRoot();
        $this->createHybridModule($root, 'listens-hybrid');

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll(ServiceContainer::getInstance());

        $this->assertTrue(
            EventDispatcher::hasListeners(ListensToTestEventAlpha::class),
            '#[ListensTo] path must be registered',
        );
        $this->assertTrue(
            EventDispatcher::hasListeners(ListensToTestEventBeta::class),
            'getEventSubscribers() path must be registered',
        );

        $this->cleanRoot($root);
    }

    public function testRepeatableAttributeRegistersBothEvents(): void {
        $root = $this->createRoot();
        $this->createRepeatableModule($root, 'listens-repeat');

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll(ServiceContainer::getInstance());

        $this->assertTrue(
            EventDispatcher::hasListeners(ListensToTestEventAlpha::class),
            'First #[ListensTo] must be registered',
        );
        $this->assertTrue(
            EventDispatcher::hasListeners(ListensToTestEventBeta::class),
            'Second #[ListensTo] must be registered',
        );

        $this->cleanRoot($root);
    }

    public function testNonExistentEventClassIsSkippedGracefully(): void {
        $root = $this->createRoot();
        $this->createMissingEventModule($root, 'listens-missing');

        $loader = new ModuleLoader();
        $loader->loadAll($root);
        $loader->bootAll(ServiceContainer::getInstance());

        $this->assertTrue(true, 'Boot must complete without exception for missing event class');

        $this->cleanRoot($root);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createRoot(): string {
        $path = sys_get_temp_dir() . '/xcvm_listens_' . bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        return $path;
    }

    private function cleanRoot(string $root): void {
        foreach (glob($root . '/*/*') ?: [] as $f) {
            is_file($f) ? unlink($f) : rmdir($f);
        }
        foreach (glob($root . '/*') ?: [] as $d) {
            is_dir($d) ? rmdir($d) : unlink($d);
        }
        rmdir($root);
    }

    private function writeManifest(string $root, string $name): void {
        $manifest = [
            'name'          => $name,
            'description'   => 'ListensTo test',
            'version'       => '1.0.0',
            'requires_core' => '>=2.0',
            'environment'   => 'main',
            'dependencies'  => [],
        ];
        file_put_contents(
            $root . '/' . $name . '/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function cls(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name))) . 'Module';
    }

    private function ns(string $name): string {
        return 'XcVm\\Module\\' . implode('', array_map('ucfirst', explode('-', $name)));
    }

    private function createAttributeModule(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);
        $cls = $this->cls($name);
        $ns  = $this->ns($name);
        file_put_contents($dir . '/' . $cls . '.php', <<<PHP
<?php
namespace {$ns};
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Events\ListensTo;
use ListensToTestEventAlpha;
class {$cls} extends BaseModule {
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    #[ListensTo(ListensToTestEventAlpha::class)]
    public function onEvent(ListensToTestEventAlpha \$e): void {}
}
PHP);
    }

    private function createPlainModule(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);
        $cls = $this->cls($name);
        $ns  = $this->ns($name);
        file_put_contents($dir . '/' . $cls . '.php', <<<PHP
<?php
namespace {$ns};
use XcVm\Core\Module\BaseModule;
class {$cls} extends BaseModule {
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
}
PHP);
    }

    private function createHybridModule(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);
        $cls = $this->cls($name);
        $ns  = $this->ns($name);
        file_put_contents($dir . '/' . $cls . '.php', <<<PHP
<?php
namespace {$ns};
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Events\ListensTo;
use ListensToTestEventAlpha;
use ListensToTestEventBeta;
class {$cls} extends BaseModule {
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getEventSubscribers(): array {
        return [ListensToTestEventBeta::class => function(ListensToTestEventBeta \$e): void {}];
    }
    #[ListensTo(ListensToTestEventAlpha::class)]
    public function onAlpha(ListensToTestEventAlpha \$e): void {}
}
PHP);
    }

    private function createRepeatableModule(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);
        $cls = $this->cls($name);
        $ns  = $this->ns($name);
        file_put_contents($dir . '/' . $cls . '.php', <<<PHP
<?php
namespace {$ns};
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Events\ListensTo;
use ListensToTestEventAlpha;
use ListensToTestEventBeta;
class {$cls} extends BaseModule {
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    #[ListensTo(ListensToTestEventAlpha::class)]
    #[ListensTo(ListensToTestEventBeta::class)]
    public function onBoth(object \$e): void {}
}
PHP);
    }

    private function createMissingEventModule(string $root, string $name): void {
        $dir = $root . '/' . $name;
        mkdir($dir, 0775, true);
        $this->writeManifest($root, $name);
        $cls = $this->cls($name);
        $ns  = $this->ns($name);
        file_put_contents($dir . '/' . $cls . '.php', <<<PHP
<?php
namespace {$ns};
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Events\ListensTo;
class {$cls} extends BaseModule {
    public function getName(): string    { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    #[ListensTo('XcVm\\\\Events\\\\NonExistentEventForTest')]
    public function onMissing(object \$e): void {}
}
PHP);
    }
}
