<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\ModuleManager;

// Answers every statement: these modules ship no schema.
class AuditManifestDb {
	public function query(string $rSql): bool {
		return true;
	}
}

/**
 * A module.json the loader cannot use (not JSON, dependencies that are not a
 * list of names, an environment other than main, lb or any) rules out that
 * one module: the loader leaves it out and loads the rest, the modules list
 * says why, and the manager refuses to put such a module in place of an
 * installed one. It also refuses a module whose dependencies lead back to
 * itself through the modules on disk, since the loader stops on a cycle.
 */
final class AuditModuleManifestTest extends TestCase {
	private string $rWork;
	private string $rModules;
	private string $rOverrides;

	protected function setUp(): void {
		$this->rWork      = sys_get_temp_dir() . '/xc_vm_manifest_' . bin2hex(random_bytes(6));
		$this->rModules   = $this->rWork . '/modules';
		$this->rOverrides = $this->rWork . '/modules.php';
		mkdir($this->rModules, 0775, true);
		ServiceContainer::getInstance()->set('db', new AuditManifestDb());
	}

	protected function tearDown(): void {
		ServiceContainer::getInstance()->remove('db');
		exec('rm -rf ' . escapeshellarg($this->rWork));
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>|string}> */
	public static function unusableManifests(): array {
		return [
			'an environment that is not main, lb or any' => ['env', ['environment' => 'cloud']],
			'dependencies that are not a list'           => ['deps', ['dependencies' => 'watch']],
			'a dependency that is not a name'            => ['depname', ['dependencies' => [['watch']]]],
			'optional dependencies that are not a list'  => ['optdeps', ['optional_dependencies' => 'watch']],
			'a file that is not JSON'                    => ['json', '{"name": "amf-json-bad",'],
			'a file that holds no JSON object'           => ['scalar', '5'],
		];
	}

	// ── The loader ───────────────────────────────────────────────────

	#[DataProvider('unusableManifests')]
	public function testTheLoaderLeavesOutTheModuleAndLoadsTheRest(string $rTag, array|string $rManifest): void {
		$this->module("amf-{$rTag}-bad", '1.0.0', $rManifest);
		$this->module("amf-{$rTag}-needs", '1.0.0', ['dependencies' => ["amf-{$rTag}-bad"]]);
		$this->module("amf-{$rTag}-fine", '1.0.0');

		$rLoader = new ModuleLoader();
		$rLoader->loadAll($this->rModules);

		$this->assertFalse($rLoader->isLoaded("amf-{$rTag}-bad"));
		$this->assertFalse($rLoader->isLoaded("amf-{$rTag}-needs"), 'a module that requires it goes with it');
		$this->assertTrue($rLoader->isLoaded("amf-{$rTag}-fine"));
	}

	public function testTheLoaderReportsWhyAManifestCannotBeUsed(): void {
		$this->module('amf-why-fine', '1.0.0');
		$this->module('amf-why-bad', '1.0.0', ['environment' => 'cloud']);

		$this->assertNull(ModuleLoader::manifestError($this->rModules . '/amf-why-fine/module.json', 'amf-why-fine'));
		$this->assertStringContainsString(
			'invalid environment',
			(string) ModuleLoader::manifestError($this->rModules . '/amf-why-bad/module.json', 'amf-why-bad')
		);
	}

	// ── The manager ──────────────────────────────────────────────────

	#[DataProvider('unusableManifests')]
	public function testAnUploadIsRefusedAndTheInstalledCopyStays(string $rTag, array|string $rManifest): void {
		$rName = "amf-up-{$rTag}";
		$this->module($rName, '1.0.0');
		$this->overrides([$rName => ['installed_version' => '1.0.0']]);

		try {
			$this->manager()->uploadAndInstall($this->tar($rName, '2.0.0', $rManifest));
			$this->fail('A module the loader cannot use must be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('module.json', $e->getMessage());
		}

		$this->assertSame('1.0.0', $this->versionOnDisk($rName));
		$this->assertSame([$this->rModules . '/' . $rName], glob($this->rModules . '/*'), 'nothing was put beside it');
		$this->assertSame('1.0.0', (require $this->rOverrides)[$rName]['installed_version']);
	}

	public function testAFetchedUpdateIsRefused(): void {
		$rCheck  = new ReflectionMethod(ModuleManager::class, 'assertSameModuleForThisCore');
		$rUpdate = $this->rWork . '/update';
		mkdir($rUpdate, 0775, true);
		$rInstalled = ['name' => 'amf-git', 'hash_id' => str_repeat('a', 32)];

		file_put_contents($rUpdate . '/module.json', json_encode($rInstalled + ['environment' => 'main', 'dependencies' => []]));
		$rCheck->invoke($this->manager(), $rUpdate, $rInstalled, 'amf-git');

		file_put_contents($rUpdate . '/module.json', json_encode($rInstalled + ['environment' => 'main', 'dependencies' => 'watch']));
		try {
			$rCheck->invoke($this->manager(), $rUpdate, $rInstalled, 'amf-git');
			$this->fail('An update the loader cannot use must be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('module.json', $e->getMessage());
		}
	}

	public function testAPulledCopyInsideTheModulesDirectoryIsRemoved(): void {
		// The platform extension extracts straight into the modules directory.
		$rPulled = $this->rModules . '/amf-pulled';
		mkdir($rPulled, 0775, true);
		file_put_contents($rPulled . '/module.json', json_encode(['name' => 'amf-pulled', 'version' => '2.0.0', 'environment' => 'cloud']));

		$rPlace = new ReflectionMethod(ModuleManager::class, 'placeModuleFiles');
		try {
			$rPlace->invoke($this->manager(), $rPulled);
			$this->fail('A module the loader cannot use must be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('module.json', $e->getMessage());
		}

		$this->assertDirectoryDoesNotExist($rPulled);
	}

	// ── Dependency cycles ────────────────────────────────────────────

	/** @return array<string, array{0: string, 1: array<string, array<string, mixed>>, 2: array<string, mixed>}> */
	public static function manifestsThatCloseACycle(): array {
		return [
			'a module that requires itself' => [
				'amf-self', [], ['dependencies' => ['amf-self']],
			],
			'a module that requires one that requires it' => [
				'amf-ring-a', ['amf-ring-b' => ['dependencies' => ['amf-ring-a']]], ['dependencies' => ['amf-ring-b']],
			],
			'a cycle through a third module' => [
				'amf-far-a',
				['amf-far-b' => ['dependencies' => ['amf-far-c']], 'amf-far-c' => ['dependencies' => ['amf-far-a']]],
				['dependencies' => ['amf-far-b']],
			],
			'an optional dependency on a module that requires it' => [
				'amf-opt-a', ['amf-opt-b' => ['dependencies' => ['amf-opt-a']]], ['optional_dependencies' => ['amf-opt-b']],
			],
		];
	}

	#[DataProvider('manifestsThatCloseACycle')]
	public function testAnUploadThatClosesADependencyCycleIsRefused(string $rName, array $rOthers, array $rManifest): void {
		$this->module($rName, '1.0.0');
		foreach ($rOthers as $rOther => $rOtherManifest) {
			$this->module($rOther, '1.0.0', $rOtherManifest);
		}
		$this->overrides([$rName => ['installed_version' => '1.0.0']]);

		try {
			$this->manager()->uploadAndInstall($this->tar($rName, '1.1.0', $rManifest));
			$this->fail('A module that closes a dependency cycle must be refused.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('dependency cycle', $e->getMessage());
			$this->assertStringContainsString($rName . ' -> ', $e->getMessage());
		}

		$this->assertSame('1.0.0', $this->versionOnDisk($rName));
		$this->assertSame('1.0.0', (require $this->rOverrides)[$rName]['installed_version']);

		// What is on disk still loads.
		$rLoader = new ModuleLoader();
		$rLoader->loadAll($this->rModules);
		$this->assertTrue($rLoader->isLoaded($rName));
	}

	public function testAnUploadThatRequiresModulesOnDiskWithoutACycleIsAccepted(): void {
		$this->module('amf-chain-base', '1.0.0');
		$this->module('amf-chain-mid', '1.0.0', ['dependencies' => ['amf-chain-base']]);
		// A cycle among other modules is not this upload's doing.
		$this->module('amf-chain-x', '1.0.0', ['optional_dependencies' => ['amf-chain-y']]);
		$this->module('amf-chain-y', '1.0.0', ['optional_dependencies' => ['amf-chain-x'], 'environment' => 'lb']);

		$rUpload = ['dependencies' => ['amf-chain-mid', 'amf-chain-absent'], 'optional_dependencies' => ['amf-chain-x']];
		try {
			$this->manager()->uploadAndInstall($this->tar('amf-chain-top', '1.1.0', $rUpload));
		} catch (Error $e) {
			// The last step asks xcvm_core whether this is a load balancer, and
			// the extension is absent here; placing and installing are done by then.
			$this->assertStringContainsString('XC_VM', $e->getMessage());
		}

		$this->assertSame('1.1.0', (require $this->rOverrides)['amf-chain-top']['installed_version']);
	}

	// ── The modules list ─────────────────────────────────────────────

	#[DataProvider('unusableManifests')]
	public function testAModuleTheLoaderLeavesOutIsListedWithTheReason(string $rTag, array|string $rManifest): void {
		$this->module("amf-list-{$rTag}", '1.0.0', $rManifest);
		$this->module("amf-list-{$rTag}-fine", '1.0.0');

		$rRows = array_column($this->manager()->listModules(), null, 'name');

		$this->assertSame([], $rRows["amf-list-{$rTag}-fine"]['dependency_warnings']);
		$this->assertStringContainsString('Not loaded', implode(' ', $rRows["amf-list-{$rTag}"]['dependency_warnings']));
	}

	public function testAModuleTheLoaderLeavesOutCanBeDeleted(): void {
		$this->module('amf-gone', '1.0.0', ['dependencies' => [['watch']]]);
		$this->module('amf-gone-fine', '1.0.0');
		$this->overrides(['amf-gone' => ['installed_version' => '1.0.0']]);

		$this->manager()->deleteModule('amf-gone');

		$this->assertSame([$this->rModules . '/amf-gone-fine'], glob($this->rModules . '/*'));
	}

	// ── Fixtures ─────────────────────────────────────────────────────

	private function manager(): ModuleManager {
		return new ModuleManager($this->rModules, $this->rOverrides, ServiceContainer::getInstance());
	}

	private function overrides(array $rOverrides): void {
		file_put_contents($this->rOverrides, "<?php\n\nreturn " . var_export($rOverrides, true) . ";\n");
	}

	private function versionOnDisk(string $rName): ?string {
		$rMeta = json_decode((string) @file_get_contents($this->rModules . '/' . $rName . '/module.json'), true);
		return is_array($rMeta) ? ($rMeta['version'] ?? null) : null;
	}

	/** The module.json text: $rManifest laid over a sound manifest, or taken as it is. */
	private function manifestJson(string $rName, string $rVersion, array|string $rManifest): string {
		if (is_string($rManifest)) {
			return $rManifest;
		}
		return (string) json_encode($rManifest + [
			'name'          => $rName,
			'version'       => $rVersion,
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => [],
		]);
	}

	private function classFile(string $rName, string $rVersion): array {
		$rPascal = implode('', array_map('ucfirst', explode('-', $rName)));
		return [$rPascal . 'Module.php', "<?php\nnamespace XcVm\\Module\\{$rPascal};\n"
			. "use XcVm\\Core\\Module\\BaseModule;\n"
			. "class {$rPascal}Module extends BaseModule {\n"
			. "\tpublic function getName(): string { return '{$rName}'; }\n"
			. "\tpublic function getVersion(): string { return '{$rVersion}'; }\n"
			. "}\n"];
	}

	/** A module in a bare `{name}` directory. */
	private function module(string $rName, string $rVersion, array|string $rManifest = []): void {
		$rDir = $this->rModules . '/' . $rName;
		mkdir($rDir, 0775, true);
		file_put_contents($rDir . '/module.json', $this->manifestJson($rName, $rVersion, $rManifest));
		[$rFile, $rCode] = $this->classFile($rName, $rVersion);
		file_put_contents($rDir . '/' . $rFile, $rCode);
	}

	/** A .tar holding one module under a `{name}/` prefix; returns its path. */
	private function tar(string $rName, string $rVersion, array|string $rManifest): string {
		$rPath = $this->rWork . '/' . $rName . '.tar';
		$rTar  = new PharData($rPath);
		$rTar->addFromString($rName . '/module.json', $this->manifestJson($rName, $rVersion, $rManifest));
		[$rFile, $rCode] = $this->classFile($rName, $rVersion);
		$rTar->addFromString($rName . '/' . $rFile, $rCode);
		unset($rTar);
		return $rPath;
	}
}
