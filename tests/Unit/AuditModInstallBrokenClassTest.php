<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\ModuleLoader;

/**
 * A module whose class file cannot be included, or whose class cannot be
 * instantiated, is one broken module: the loader leaves it out with the
 * modules that require it and loads the rest.
 */
final class AuditModInstallBrokenClassTest extends TestCase {
	private string $rModules;

	protected function setUp(): void {
		$this->rModules = sys_get_temp_dir() . '/xc_vm_brokenclass_' . bin2hex(random_bytes(6));
		mkdir($this->rModules, 0775, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rModules));
	}

	/** @return array<string, array{0: string, 1: string}> what the class of the broken module also holds */
	public static function brokenClasses(): array {
		return [
			'a class file that does not parse'   => ['parse', "\tpublic function broken(): string { return 'x' }\n"],
			'a constructor that throws'          => ['ctor', "\tpublic function __construct() { throw new \\RuntimeException('no'); }\n"],
			'a constructor that raises an error' => ['error', "\tpublic function __construct() { \\XcVm\\Module\\NoSuchClass::boot(); }\n"],
		];
	}

	#[DataProvider('brokenClasses')]
	public function testTheLoaderLeavesOutTheModuleAndLoadsTheRest(string $rTag, string $rBody): void {
		$this->module("abc-{$rTag}-bad", [], $rBody);
		$this->module("abc-{$rTag}-needs", ["abc-{$rTag}-bad"]);
		$this->module("abc-{$rTag}-fine");

		$rLoader = new ModuleLoader();
		$rLoader->loadAll($this->rModules);

		$this->assertFalse($rLoader->isLoaded("abc-{$rTag}-bad"));
		$this->assertFalse($rLoader->isLoaded("abc-{$rTag}-needs"), 'a module that requires it goes with it');
		$this->assertTrue($rLoader->isLoaded("abc-{$rTag}-fine"));
	}

	public function testTheLogNamesTheFileAndLineOfTheReason(): void {
		// The class file is sound; a second file of the module does not parse.
		$this->module('abc-log-bad', [], "\tpublic function __construct() { new Sub\\Helper(); }\n");
		mkdir($this->rModules . '/abc-log-bad/Sub');
		file_put_contents(
			$this->rModules . '/abc-log-bad/Sub/Helper.php',
			"<?php\nnamespace XcVm\\Module\\AbcLogBad\\Sub;\nclass Helper {\n\tpublic function broken(): string { return 'x' }\n}\n"
		);

		$rLog = $this->rModules . '/error.log';
		$rOld = ini_set('error_log', $rLog);
		try {
			(new ModuleLoader())->loadAll($this->rModules);
		} finally {
			ini_set('error_log', (string) $rOld);
		}

		$rLines = (string) file_get_contents($rLog);
		$this->assertStringContainsString("module 'abc-log-bad' cannot be loaded", $rLines);
		$this->assertStringContainsString('/abc-log-bad/Sub/Helper.php:4', $rLines);
	}

	public function testLoadingOneModuleStillReportsWhy(): void {
		// An install loads the one module it was asked for and shows the reason.
		$this->module('abc-one-bad', [], self::brokenClasses()['a class file that does not parse'][1]);

		$this->expectException(ParseError::class);
		(new ModuleLoader())->load('abc-one-bad', $this->rModules . '/abc-one-bad');
	}

	/** A module in a bare `{name}` directory; $rBody is added to its class. */
	private function module(string $rName, array $rDependencies = [], string $rBody = ''): void {
		$rDir    = $this->rModules . '/' . $rName;
		$rPascal = implode('', array_map('ucfirst', explode('-', $rName)));
		mkdir($rDir, 0775, true);
		file_put_contents($rDir . '/module.json', json_encode([
			'name'          => $rName,
			'version'       => '1.0.0',
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => $rDependencies,
		]));
		file_put_contents($rDir . '/' . $rPascal . 'Module.php', "<?php\nnamespace XcVm\\Module\\{$rPascal};\n"
			. "use XcVm\\Core\\Module\\BaseModule;\n"
			. "class {$rPascal}Module extends BaseModule {\n"
			. "\tpublic function getName(): string { return '{$rName}'; }\n"
			. $rBody
			. "\tpublic function getVersion(): string { return '1.0.0'; }\n"
			. "}\n");
	}
}
