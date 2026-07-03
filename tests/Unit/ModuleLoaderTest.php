<?php

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\ModuleInterface;
use XcVm\Core\Http\Router;
use PHPUnit\Framework\TestCase;

final class ModuleLoaderTest extends TestCase {
	public function testLoadAllSkipsModuleWithMissingDependencyInsteadOfThrowing(): void {
		$root = $this->createModulesRoot();
		// 'missing-alpha' requires a module that does not exist; a healthy sibling
		// must still load. A missing required dependency must not abort the whole
		// load (which would brick the panel and CLI).
		$this->createModule($root, 'missing-alpha', [
			'dependencies' => ['missing-module'],
		]);
		$this->createModule($root, 'healthy-beta');

		$loader = new ModuleLoader();
		$loader->loadAll($root);

		$this->assertFalse($loader->isLoaded('missing-alpha'));
		$this->assertTrue($loader->isLoaded('healthy-beta'));
	}

	// ── update-source manifest block (P2) ─────────────────────────────────

	public function testNormalizeUpdateBlockDefaultsToBundled(): void {
		$u = ModuleLoader::normalizeUpdateBlock(['name' => 'watch'], 'watch');
		$this->assertSame('bundled', $u['source']);
		$this->assertSame('watch', $u['slug']);   // slug defaults to the module name
		$this->assertSame('stable', $u['channel']);
		$this->assertSame('', $u['repository']);
	}

	public function testNormalizeUpdateBlockReadsGitSource(): void {
		$u = ModuleLoader::normalizeUpdateBlock([
			'name'   => 'watch',
			'update' => [
				'source'     => 'GIT',
				'repository' => 'https://github.com/Vateron-Media/xc_vm-module-watch',
				'channel'    => 'Beta',
			],
		], 'watch');
		$this->assertSame('git', $u['source']);
		$this->assertSame('https://github.com/Vateron-Media/xc_vm-module-watch', $u['repository']);
		$this->assertSame('beta', $u['channel']);
		$this->assertSame('watch', $u['slug']);
	}

	public function testNormalizeUpdateBlockUnknownSourceFallsBackToBundled(): void {
		$u = ModuleLoader::normalizeUpdateBlock(['name' => 'x', 'update' => ['source' => 'ftp']], 'x');
		$this->assertSame('bundled', $u['source']);
	}

	// ── {name}_{hash5} directory convention ───────────────────────────────

	public function testLoadsModuleFromHashSuffixedDirectory(): void {
		$root = $this->createModulesRoot();
		// Directory is {name}_{hash5}; the canonical name in the manifest is 'hashmod'.
		$dir = $root . '/hashmod_ab123';
		mkdir($dir, 0775, true);
		file_put_contents($dir . '/module.json', json_encode([
			'name'          => 'hashmod',
			'hash_id'       => 'ab123def4567890ab123def4567890ff',
			'version'       => '1.0.0',
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => [],
		]));
		file_put_contents($dir . '/HashmodModule.php',
			"<?php\nnamespace XcVm\\Module\\Hashmod;\n"
			. "use XcVm\\Core\\Module\\BaseModule;\n"
			. "class HashmodModule extends BaseModule {\n"
			. "\tpublic function getName(): string { return 'hashmod'; }\n"
			. "\tpublic function getVersion(): string { return '1.0.0'; }\n"
			. "}\n"
		);

		$loader = new ModuleLoader();
		$loader->loadAll($root);

		// Loaded under the canonical manifest name, not the hash-suffixed directory.
		$this->assertTrue($loader->isLoaded('hashmod'));
		$this->assertFalse($loader->isLoaded('hashmod_ab123'));
	}

	public function testLoadAllSkipsBrokenModuleInsteadOfBrickingThePanel(): void {
		$root = $this->createModulesRoot();
		// 'broken-mod' has a manifest but no class file → load() fails. A single
		// broken/half-deployed module must NOT abort the whole load (which would
		// brick the panel and CLI); a healthy sibling must still load.
		$dir = $root . '/broken-mod';
		mkdir($dir, 0775, true);
		file_put_contents($dir . '/module.json', json_encode([
			'name'          => 'broken-mod',
			'version'       => '1.0.0',
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => [],
		]));
		// No BrokenModModule.php on disk → class cannot be loaded.
		$this->createModule($root, 'healthy-sibling');

		$loader = new ModuleLoader();
		$loader->loadAll($root); // must not throw

		$this->assertFalse($loader->isLoaded('broken-mod'));
		$this->assertTrue($loader->isLoaded('healthy-sibling'));
	}

	public function testLoadAllSkipsDependentsOfBrokenModule(): void {
		$root = $this->createModulesRoot();
		// 'dependent' requires 'broken-base'; 'broken-base' fails to load (no class
		// file). The dependent must be skipped too — booting it without its
		// dependency present could fatal.
		$dir = $root . '/broken-base';
		mkdir($dir, 0775, true);
		file_put_contents($dir . '/module.json', json_encode([
			'name'          => 'broken-base',
			'version'       => '1.0.0',
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => [],
		]));
		$this->createModule($root, 'dependent', ['dependencies' => ['broken-base']]);

		$loader = new ModuleLoader();
		$loader->loadAll($root);

		$this->assertFalse($loader->isLoaded('broken-base'));
		$this->assertFalse($loader->isLoaded('dependent'));
	}

	public function testLoadAllSkipsTransitiveDependentsOfMissingDependency(): void {
		$root = $this->createModulesRoot();
		// chain-gamma -> chain-beta -> missing-module (absent). Both gamma and beta
		// must be skipped; an unrelated module must still load.
		$this->createModule($root, 'chain-beta', ['dependencies' => ['missing-module']]);
		$this->createModule($root, 'chain-gamma', ['dependencies' => ['chain-beta']]);
		$this->createModule($root, 'chain-solo');

		$loader = new ModuleLoader();
		$loader->loadAll($root);

		$this->assertFalse($loader->isLoaded('chain-beta'));
		$this->assertFalse($loader->isLoaded('chain-gamma'));
		$this->assertTrue($loader->isLoaded('chain-solo'));
	}

	public function testLoadAllThrowsWhenDependenciesAreCyclic(): void {
		$root = $this->createModulesRoot();
		$this->createModule($root, 'cycle-alpha', ['dependencies' => ['cycle-beta']]);
		$this->createModule($root, 'cycle-beta', ['dependencies' => ['cycle-alpha']]);

		$loader = new ModuleLoader();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('cyclic module dependency detected');
		$loader->loadAll($root);
	}

	public function testLoadAllSortsModulesByDependencies(): void {
		$root = $this->createModulesRoot();
		$this->createModule($root, 'order-base');
		$this->createModule($root, 'order-feature', ['dependencies' => ['order-base']]);

		$loader = new ModuleLoader();
		$loader->loadAll($root);

		$this->assertSame(['order-base', 'order-feature'], array_keys($loader->getModules()));
	}

	public function testLoadAllSkipsForeignEnvironmentModules(): void {
		$root = $this->createModulesRoot();
		$this->createModule($root, 'env-main-only', ['environment' => 'main']);
		$this->createModule($root, 'env-lb-only', ['environment' => 'lb']);

		$loader = new ModuleLoader();
		$loader->loadAll($root);

		$this->assertTrue($loader->isLoaded('env-main-only'));
		$this->assertFalse($loader->isLoaded('env-lb-only'));
	}

	private function createModulesRoot(): string {
		$path = sys_get_temp_dir() . '/xc_vm_modules_test_' . bin2hex(random_bytes(6));
		mkdir($path, 0775, true);
		return $path;
	}

	private function createModule(string $root, string $name, array $manifestOverrides = []): void {
		$modulePath = $root . '/' . $name;
		mkdir($modulePath, 0775, true);

		$pascal    = implode('', array_map('ucfirst', explode('-', $name)));
		$className = $pascal . 'Module';
		$namespace = 'XcVm\\Module\\' . $pascal;

		$manifest = array_merge([
			'name' => $name,
			'description' => 'test module',
			'version' => '1.0.0',
			'requires_core' => '>=2.0',
			'environment' => 'main',
			'dependencies' => [],
			'has_navbar' => false,
			'has_settings' => false,
		], $manifestOverrides);

		file_put_contents($modulePath . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		$php = '<?php' . "\n"
			. 'namespace ' . $namespace . ';' . "\n"
			. 'use XcVm\Core\Module\ModuleInterface;' . "\n"
			. 'use XcVm\Core\Container\ServiceContainer;' . "\n"
			. 'use Router;' . "\n"
			. 'use XcVm\Cli\CommandRegistry;' . "\n"
			. 'use XcVm\Core\Module\NavbarRegistry;' . "\n"
			. 'class ' . $className . ' implements ModuleInterface {' . "\n"
			. "\tpublic function getName(): string { return '{$name}'; }" . "\n"
			. "\tpublic function getVersion(): string { return '1.0.0'; }" . "\n"
			. "\tpublic function boot(ServiceContainer \$container): void {}" . "\n"
			. "\tpublic function registerRoutes(\\XcVm\\Core\\Http\\Router \$router): void {}" . "\n"
			. "\tpublic function registerCommands(CommandRegistry \$registry): void {}" . "\n"
			. "\tpublic function getEventSubscribers(): array { return []; }" . "\n"
			. "\tpublic function install(): void {}" . "\n"
			. "\tpublic function uninstall(): void {}" . "\n"
			. "\tpublic function registerNavbar(NavbarRegistry \$registry): void {}" . "\n"
			. '}' . "\n";

		file_put_contents($modulePath . '/' . $className . '.php', $php);
	}
}
