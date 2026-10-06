<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CommandInterface;
use XcVm\Cli\Commands\ModuleMigrateCommand;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\ModuleManager;

/**
 * An update that replaces the files of a module the process has already
 * loaded (a store update, an archive uploaded over it, a git or url update)
 * runs the steps of the version now on disk: its own install() and
 * getMigrations(). PHP keeps the class it loaded first, so those steps run in
 * a console.php process of their own (module:migrate). What that process
 * records is what the update goes on with; when it fails the update fails,
 * and the files that were installed go back.
 *
 * Each test runs the update in a child PHP that stands in for the admin
 * request: MAIN_HOME is a work directory holding the modules, their state and
 * a console.php that runs the real command.
 */
final class AuditModuleMigrateTest extends TestCase {
	private const MASTER = 'CREATE TABLE IF NOT EXISTS `amm` (`id` int)';
	private const DELTA  = 'ALTER TABLE `amm` ADD COLUMN `one` int';

	private string $rWork;

	protected function setUp(): void {
		$this->rWork = sys_get_temp_dir() . '/xc_vm_migrate_' . bin2hex(random_bytes(6));
		mkdir($this->rWork . '/Modules', 0775, true);
		mkdir($this->rWork . '/config', 0775, true);
		$this->scripts();
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rWork));
	}

	// ── A module the request has loaded ──────────────────────────────

	public function testAnArchiveUploadedOverALoadedModuleRunsTheStepsOfTheNewVersion(): void {
		$rHash = str_repeat('a1b2', 8);
		$this->module($this->rWork . '/Modules/amm-up_a1b2a', 'amm-up', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['amm-up' => ['installed_version' => '1.0.0']]);

		$rError = $this->request('upload', $this->archive('amm-up', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => self::DELTA . ';',
		], ['1.1.0' => ''], $rHash));

		$this->assertSame('', $rError);
		$this->assertSame(['migration 1.1.0 by 1.1.0', 'install by 1.1.0'], $this->lines('ran.log'));
		$this->assertSame(['module:migrate install amm-up'], $this->lines('console.log'));
		$this->assertSame([self::DELTA, self::MASTER], $this->lines('sql.log'));
		// What the other process recorded is kept by the steps that follow it here.
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('amm-up'));
		$this->assertSame('1.1.0', $this->versionIn($this->rWork . '/Modules/amm-up_a1b2a'));
		$this->assertSame([], $this->backups());
	}

	public function testAStoreUpdateOfALoadedModuleRunsTheStepsOfTheNewVersion(): void {
		$rHash = str_repeat('c3d4', 8);
		$this->module($this->rWork . '/Modules/amm-store_c3d4c', 'amm-store', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['amm-store' => ['installed_version' => '1.0.0', 'source' => 'platform']]);
		// What the store serves as 1.1.0, as the extension unpacks it.
		$this->module($this->rWork . '/served/amm-store', 'amm-store', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => self::DELTA . ';',
		], ['1.1.0' => ''], $rHash);

		$rError = $this->request('store', 'amm-store');

		$this->assertSame('', $rError);
		$this->assertSame(['migration 1.1.0 by 1.1.0', 'install by 1.1.0'], $this->lines('ran.log'));
		$this->assertSame(['module:migrate install amm-store 1.1.0'], $this->lines('console.log'));
		$this->assertSame([self::DELTA, self::MASTER], $this->lines('sql.log'));
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('amm-store'));
		$this->assertSame('1.1.0', $this->versionIn($this->rWork . '/Modules/amm-store_c3d4c'));
		$this->assertSame([], $this->backups());
	}

	public function testAGitOrUrlUpdateOfALoadedModuleRunsTheStepsOfTheNewVersion(): void {
		$rHash = str_repeat('e5f6', 8);
		$rDir  = $this->rWork . '/Modules/amm-git_e5f6e';
		$this->module($rDir, 'amm-git', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['amm-git' => ['installed_version' => '1.0.0']]);
		// The fetched 1.1.0, which the update copies over the installed files.
		$this->module($this->rWork . '/fetched', 'amm-git', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => self::DELTA . ';',
		], ['1.1.0' => ''], $rHash);

		$rError = $this->request('update', 'amm-git', $this->rWork . '/fetched', $rDir);

		$this->assertSame('', $rError);
		$this->assertSame(['module:migrate update amm-git'], $this->lines('console.log'));
		// An update runs the deltas, not the master schema or install().
		$this->assertSame(['migration 1.1.0 by 1.1.0'], $this->lines('ran.log'));
		$this->assertSame([self::DELTA], $this->lines('sql.log'));
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('amm-git'));

		// The git and url update itself downloads its archive, so it cannot run
		// here: its source is all that shows it hands over the same way.
		$this->assertMatchesRegularExpression(
			'/\$this->copyDirectory\(\$moduleDir, \$targetDir\);\s+\$this->migrateReplaced\(\$name, \'update\'\);/',
			(string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/Module/ModuleManager.php')
		);
	}

	// ── The process that runs the steps ──────────────────────────────

	public function testTheStepsRunBeforeTheNewVersionBoots(): void {
		$rHash = str_repeat('b0b0', 8);
		$this->module($this->rWork . '/Modules/amm-boot_b0b0b', 'amm-boot', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		// A module that depends on it, and one that does not.
		$this->module($this->rWork . '/Modules/amm-needs', 'amm-needs', '1.0.0', [], [], null, "\\AuditMigrateLog::add('amm-needs booted');", ['amm-boot']);
		$this->module($this->rWork . '/Modules/amm-apart', 'amm-apart', '1.0.0', [], [], null, "\\AuditMigrateLog::add('amm-apart booted');");
		$this->overrides(['amm-boot' => ['installed_version' => '1.0.0']]);

		// 1.1.0 reads, when it boots, the column its own delta adds.
		$rBoot  = "if (strpos((string) @file_get_contents(MAIN_HOME . 'sql.log'), 'ADD COLUMN `one`') === false) { throw new \\RuntimeException('Unknown column one'); }";
		$rError = $this->request('upload', $this->archive('amm-boot', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => self::DELTA . ';',
		], ['1.1.0' => ''], $rHash, $rBoot));

		$this->assertSame('', $rError);
		$this->assertSame([self::DELTA, self::MASTER], $this->lines('sql.log'));
		// The process boots neither the module nor the one that depends on it;
		// the rest boot as for any command.
		$this->assertSame(['amm-apart booted', 'migration 1.1.0 by 1.1.0', 'install by 1.1.0'], $this->lines('ran.log'));
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('amm-boot'));
	}

	public function testOnlyTheProcessThatRunsAModulesStepsLeavesItOut(): void {
		$rModules = $this->rWork . '/scope';
		$this->module($rModules . '/amm-held', 'amm-held', '1.0.0');
		$this->module($rModules . '/amm-leans', 'amm-leans', '1.0.0', [], [], null, '', ['amm-held']);
		$this->module($rModules . '/amm-free', 'amm-free', '1.0.0');

		$rLoaded = static function (array $rArgv) use ($rModules): array {
			$rOwn = $_SERVER['argv'] ?? null;
			$_SERVER['argv'] = $rArgv;
			try {
				$rLoader = (new ModuleLoader())->loadAll($rModules);
			} finally {
				$_SERVER['argv'] = $rOwn;
			}
			return array_values(array_filter(['amm-free', 'amm-held', 'amm-leans'], [$rLoader, 'isLoaded']));
		};

		$this->assertSame(['amm-free'], $rLoaded(['console.php', 'module:migrate', 'update', 'amm-held']));
		$this->assertSame(['amm-free', 'amm-held'], $rLoaded(['console.php', 'module:migrate', 'install', 'amm-leans', '1.1.0']));
		// Any other command line loads them all, the module's name on it or not.
		foreach ([['console.php', 'module:delete', 'amm-held'], ['console.php', 'cron:amm', 'update', 'amm-held'], ['console.php']] as $rArgv) {
			$this->assertSame(['amm-free', 'amm-held', 'amm-leans'], $rLoaded($rArgv), json_encode($rArgv));
		}
	}

	/** @return array<string, array{0: string}> [what a step that succeeds prints] */
	public static function waysToPrint(): array {
		// More than is read of the process's output.
		$rALot = 'echo str_repeat("progress line\n", 6000);';
		return [
			'a lot' => [$rALot],
			'a lot, then into a buffer of its own that it leaves open' => [$rALot . ' ob_start(); echo "more\n";'],
			'into a buffer it leaves open that cannot be removed' => ['ob_start(null, 0, 0); echo "more\n";'],
		];
	}

	/** @dataProvider waysToPrint */
	public function testWhatTheStepsPrintDoesNotDecideWhetherTheyRan(string $rStep): void {
		$rHash = str_repeat('d1e2', 8);
		$rDir  = $this->rWork . '/Modules/amm-loud_d1e2d';
		$this->module($rDir, 'amm-loud', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['amm-loud' => ['installed_version' => '1.0.0']]);

		$rError = $this->request('upload', $this->archive('amm-loud', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => self::DELTA . ';',
		], ['1.1.0' => $rStep], $rHash));

		$this->assertSame('', $rError);
		$this->assertSame(['migration 1.1.0 by 1.1.0', 'install by 1.1.0'], $this->lines('ran.log'));
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('amm-loud'));
		$this->assertSame('1.1.0', $this->versionIn($rDir));
	}

	public function testAStepThatFailsIsNamedBeforeWhatItPrinted(): void {
		$rHash = str_repeat('f3a4', 8);
		$rDir  = $this->rWork . '/Modules/amm-says_f3a4f';
		$this->module($rDir, 'amm-says', '1.0.0', [], [], $rHash);
		$this->overrides(['amm-says' => ['installed_version' => '1.0.0']]);

		$rError = $this->request('upload', $this->archive('amm-says', '1.1.0', [], [
			'1.1.0' => 'echo str_repeat("progress line\n", 6000), "row 41 has no owner\n"; throw new \RuntimeException("step 1.1.0 refused");',
		], $rHash));

		$this->assertStringStartsWith('step 1.1.0 refused', $rError);
		// With the end of what the step printed, not all of it.
		$this->assertStringEndsWith('row 41 has no owner', $rError);
		$this->assertLessThan(8192, strlen($rError));
		$this->assertSame('1.0.0', $this->versionIn($rDir));
		$this->assertSame(['installed_version' => '1.0.0'], $this->recorded('amm-says'));
	}

	// ── A step that fails ────────────────────────────────────────────

	public function testAnUploadWhoseNewStepFailsPutsTheInstalledFilesBack(): void {
		$rHash = str_repeat('0a9b', 8);
		$rDir  = $this->rWork . '/Modules/amm-fail_0a9b0';
		$this->module($rDir, 'amm-fail', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['amm-fail' => ['installed_version' => '1.0.0']]);

		$rError = $this->request('upload', $this->archive('amm-fail', '1.1.0', ['database.sql' => self::MASTER . ';'], [
			'1.1.0' => "throw new \\RuntimeException('step 1.1.0 refused');",
		], $rHash));

		$this->assertStringContainsString('step 1.1.0 refused', $rError);
		$this->assertSame([], $this->lines('ran.log'), 'nothing after the failing step runs');
		$this->assertSame([$rDir], glob($this->rWork . '/Modules/*'));
		$this->assertSame('1.0.0', $this->versionIn($rDir));
		$this->assertSame([], $this->backups());
		$this->assertSame(['installed_version' => '1.0.0'], $this->recorded('amm-fail'), 'and it loads again');
	}

	public function testAStoreUpdateWhoseNewStepFailsIsRolledBack(): void {
		$rHash = str_repeat('1c2d', 8);
		$rDir  = $this->rWork . '/Modules/amm-back_1c2d1';
		$this->module($rDir, 'amm-back', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['amm-back' => ['installed_version' => '1.0.0', 'source' => 'platform']]);
		$this->module($this->rWork . '/served/amm-back', 'amm-back', '1.2.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => self::DELTA . ';',
		], ['1.2.0' => "throw new \\RuntimeException('step 1.2.0 refused');"], $rHash);

		$rError = $this->request('store', 'amm-back');

		$this->assertStringContainsString('rolled back to previous version 1.0.0', $rError);
		$this->assertStringContainsString('step 1.2.0 refused', $rError);
		$this->assertSame([$rDir], glob($this->rWork . '/Modules/*'));
		$this->assertSame('1.0.0', $this->versionIn($rDir));
		$this->assertSame([], $this->backups());
		// The delta that landed stays on record beside the version put back.
		$this->assertSame([self::DELTA], $this->lines('sql.log'));
		$this->assertSame(['installed_version' => '1.0.0', 'schema_version' => '1.1.0'], $this->recorded('amm-back'));
	}

	/** @return array<string, array{0: string, 1: string}> [what a step does, what the update fails with] */
	public static function waysToEndHalfWay(): array {
		return [
			'with a failure status' => ['exit(7);', 'status 7'],
			// exit('message') ends with status 0, as the database layer does when it cannot connect.
			'with status 0 and a message' => ["exit('MySQL: cannot connect');", 'MySQL: cannot connect'],
			'with status 0 and no word' => ['exit(0);', 'status 0'],
		];
	}

	/** @dataProvider waysToEndHalfWay */
	public function testAStepProcessThatEndsHalfWayFailsTheUpdateAndPutsTheInstalledFilesBack(string $rStep, string $rExpected): void {
		$rHash = str_repeat('3e4f', 8);
		$rDir  = $this->rWork . '/Modules/amm-dies_3e4f3';
		$this->module($rDir, 'amm-dies', '1.0.0', [], [], $rHash);
		$this->overrides(['amm-dies' => ['installed_version' => '1.0.0']]);

		// It leaves the module recorded as installing.
		$rError = $this->request('upload', $this->archive('amm-dies', '1.1.0', [], ['1.1.0' => $rStep], $rHash));

		$this->assertStringContainsString($rExpected, $rError);
		$this->assertSame('1.0.0', $this->versionIn($rDir));
		$this->assertSame([], $this->backups());
		$this->assertSame(['installed_version' => '1.0.0'], $this->recorded('amm-dies'));
	}

	// ── Where no other process is needed, or would be the wrong one ──

	public function testAModuleTheRequestHasNotLoadedIsInstalledInIt(): void {
		$rHash = str_repeat('5a6b', 8);
		$this->module($this->rWork . '/Modules/amm-off_5a6b5', 'amm-off', '1.0.0', [], [], $rHash);
		// Switched off, so the request did not load it: its class comes from the new files.
		$this->overrides(['amm-off' => ['installed_version' => '1.0.0', 'state' => 'disabled']]);

		$rError = $this->request('upload', $this->archive('amm-off', '1.1.0', [], ['1.1.0' => ''], $rHash));

		$this->assertSame('', $rError);
		$this->assertSame([], $this->lines('console.log'));
		$this->assertSame(['migration 1.1.0 by 1.1.0', 'install by 1.1.0'], $this->lines('ran.log'));
		$this->assertSame(['installed_version' => '1.1.0', 'state' => 'disabled'], $this->recorded('amm-off'));
	}

	public function testAManagerOfOtherDirectoriesDoesNotHandOverToThePanelsConsole(): void {
		$rHash = str_repeat('7c8d', 8);
		// The same module in the panel's directory, loaded by the request, and in
		// another one: console.php would run the steps on the panel's copy.
		$this->module($this->rWork . '/Modules/amm-else_7c8d7', 'amm-else', '1.0.0', [], [], $rHash);
		$this->module($this->rWork . '/other/Modules/amm-else_7c8d7', 'amm-else', '1.0.0', [], [], $rHash);
		file_put_contents($this->rWork . '/other/modules.php', "<?php\n\nreturn ['amm-else' => ['installed_version' => '1.0.0']];\n");

		$rError = $this->request('elsewhere', $this->rWork . '/other', $this->archive('amm-else', '1.1.0', [], [], $rHash));

		$this->assertSame('', $rError);
		$this->assertSame([], $this->lines('console.log'));
		$this->assertSame('1.1.0', (require $this->rWork . '/other/modules.php')['amm-else']['installed_version']);
		$this->assertSame('1.0.0', $this->versionIn($this->rWork . '/Modules/amm-else_7c8d7'));
	}

	// ── The command ──────────────────────────────────────────────────

	public function testTheConsoleFindsTheCommand(): void {
		$rCommand = new ModuleMigrateCommand();

		$this->assertInstanceOf(CommandInterface::class, $rCommand);
		$this->assertSame('module:migrate', $rCommand->getName());
		// console.php registers every command class in this directory.
		$this->assertStringEndsWith(
			'/Cli/Commands/ModuleMigrateCommand.php',
			(string) (new ReflectionClass($rCommand))->getFileName()
		);
	}

	public function testTheCommandRunsOnlyAnInstallOrAnUpdateOfANamedModule(): void {
		foreach ([[], ['install'], ['update', ''], ['uninstall', 'amm-up'], ['amm-up']] as $rArgs) {
			ob_start();
			$rStatus = (new ModuleMigrateCommand())->execute($rArgs);
			$rOutput = (string) ob_get_clean();

			$this->assertSame(1, $rStatus, json_encode($rArgs));
			$this->assertStringContainsString('Usage:', $rOutput);
		}
	}

	public function testTheCommandSaysWhetherTheStepsRan(): void {
		$this->module($this->rWork . '/Modules/amm-ok', 'amm-ok', '1.0.0', ['database.sql' => self::MASTER . ';']);
		$this->module($this->rWork . '/Modules/amm-cmd', 'amm-cmd', '1.0.0', ['database.sql' => 'BOOM;']);

		[$rStatus, $rOutput] = $this->child([PHP_BINARY, $this->rWork . '/console.php', 'module:migrate', 'install', 'amm-ok']);

		$this->assertSame(0, $rStatus);
		$this->assertStringEndsWith(ModuleManager::STEPS_DONE . "\n", $rOutput);
		$this->assertSame(['installed_version' => '1.0.0'], $this->recorded('amm-ok'));

		[$rStatus, $rOutput] = $this->child([PHP_BINARY, $this->rWork . '/console.php', 'module:migrate', 'install', 'amm-cmd']);

		$this->assertSame(1, $rStatus);
		$this->assertStringContainsString('database.sql', $rOutput);
		$this->assertStringNotContainsString(ModuleManager::STEPS_DONE, $rOutput);
		$this->assertSame(['state' => 'failed'], $this->recorded('amm-cmd'));
	}

	// ── Fixtures ─────────────────────────────────────────────────────

	/**
	 * Run one module action as an admin request does, the request having
	 * loaded every enabled module first.
	 *
	 * @return string The message the action failed with, '' when it did not fail.
	 */
	private function request(string ...$rArgs): string {
		// A warm opcode cache, as in a php-fpm worker: a file is served from it
		// until it is invalidated, config/modules.php included.
		$rCache = ['-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=60', '-d', 'opcache.file_update_protection=0'];
		[, $rOutput] = $this->child([...xcvm_test_child_php(), ...$rCache, $this->rWork . '/request.php', ...$rArgs]);
		$rResult = json_decode($rOutput, true);
		$this->assertIsArray($rResult, $rOutput);
		return $rResult['error'];
	}

	/**
	 * @param list<string> $rArgv
	 * @return array{0: int, 1: string} [exit status, stdout and stderr]
	 */
	private function child(array $rArgv): array {
		$rProc = proc_open(
			$rArgv,
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
			$rPipes,
			$this->rWork,
			['PATH' => (string) getenv('PATH')]
		);
		$this->assertIsResource($rProc);
		$rOutput = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		return [proc_close($rProc), $rOutput];
	}

	/** The work directory's stand-ins for the panel's bootstrap, console.php and an admin request. */
	private function scripts(): void {
		$rAutoload = var_export(MAIN_HOME . 'vendor/autoload.php', true);
		file_put_contents($this->rWork . '/common.php', <<<PHP
			<?php
			use XcVm\Core\Container\ServiceContainer;

			define('MAIN_HOME', __DIR__ . '/');
			define('CONFIG_PATH', __DIR__ . '/config/');
			define('PHP_BIN', PHP_BINARY);
			ini_set('error_log', '/dev/null');
			require {$rAutoload};

			// What the modules' hooks ran, whichever process ran them.
			class AuditMigrateLog {
				public static function add(string \$rLine): void {
					file_put_contents(MAIN_HOME . 'ran.log', \$rLine . "\\n", FILE_APPEND);
				}
			}

			// Records each statement of a module schema file; refuses BOOM.
			ServiceContainer::getInstance()->set('db', new class {
				public function query(string \$rSql): bool {
					file_put_contents(MAIN_HOME . 'sql.log', rtrim(\$rSql, ';') . "\\n", FILE_APPEND);
					return strpos(\$rSql, 'BOOM') === false;
				}
			});
			PHP);

		file_put_contents($this->rWork . '/console.php', <<<'PHP'
			<?php
			use XcVm\Cli\CommandRegistry;
			use XcVm\Cli\Commands\ModuleMigrateCommand;
			use XcVm\Core\Container\ServiceContainer;
			use XcVm\Core\Module\ModuleLoader;

			require __DIR__ . '/common.php';
			// The panel's php.ini: an error that ends the process is not printed.
			ini_set('display_errors', '0');
			// Here only: a process that never ends would hold the tests.
			set_time_limit(30);
			file_put_contents(MAIN_HOME . 'console.log', implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);

			$rRegistry = new CommandRegistry();
			$rRegistry->register(new ModuleMigrateCommand());

			// As the panel's console.php: the modules are loaded, their commands
			// registered and they are booted before the command runs.
			$rLoader = new ModuleLoader();
			$rLoader->loadAll();
			$rLoader->registerAllCommands($rRegistry);
			$rLoader->bootAll(ServiceContainer::getInstance());

			exit($rRegistry->dispatch($argv));
			PHP);

		file_put_contents($this->rWork . '/request.php', <<<'PHP'
			<?php
			use XcVm\Core\Container\ServiceContainer;
			use XcVm\Core\Module\ModuleLoader;
			use XcVm\Core\Module\ModuleManager;

			// xcvm_core, as far as these actions ask it: this is MAIN, and the store
			// unpacks `served/{slug}` into the modules directory.
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 1, 'is_lb' => 0];
				}

				public static function panel_register(string $rKey): array {
					return ['ok' => true];
				}

				public static function module_install(string $rSlug, string $rVersion, string $rKey): array {
					$rPath = MAIN_HOME . 'Modules/' . $rSlug;
					exec('cp -r ' . escapeshellarg(MAIN_HOME . 'served/' . $rSlug) . ' ' . escapeshellarg($rPath));
					$rMeta = json_decode((string) file_get_contents($rPath . '/module.json'), true);
					return ['ok' => true, 'module' => $rSlug, 'version' => $rMeta['version'], 'path' => $rPath];
				}
			}

			require __DIR__ . '/common.php';

			// An admin request loads every enabled module before it handles the action.
			(new ModuleLoader())->loadAll();

			$rManager = new ModuleManager(container: ServiceContainer::getInstance());
			$rError   = '';
			try {
				switch ($argv[1]) {
					case 'upload':
						$rManager->uploadAndInstall($argv[2]);
						break;
					case 'store':
						$rManager->downloadFromPlatform($argv[2], '', 'key');
						break;
					case 'update':
						// The step of a git or url update that follows its download.
						exec('cp -r ' . escapeshellarg($argv[3] . '/.') . ' ' . escapeshellarg($argv[4]));
						(new ReflectionMethod(ModuleManager::class, 'migrateReplaced'))->invoke($rManager, $argv[2], 'update');
						break;
					case 'elsewhere':
						(new ModuleManager($argv[2] . '/Modules', $argv[2] . '/modules.php', ServiceContainer::getInstance()))
							->uploadAndInstall($argv[3]);
						break;
				}
			} catch (\Throwable $e) {
				$rError = $e->getMessage();
			}
			echo json_encode(['error' => $rError]);
			PHP);
	}

	private function overrides(array $rOverrides): void {
		file_put_contents($this->rWork . '/config/modules.php', "<?php\n\nreturn " . var_export($rOverrides, true) . ";\n");
	}

	/** The module's entry in config/modules.php. */
	private function recorded(string $rName): array {
		$rFile  = $this->rWork . '/config/modules.php';
		$rData  = is_file($rFile) ? require $rFile : [];
		$rEntry = $rData[$rName] ?? [];
		unset($rEntry['source']);
		ksort($rEntry);
		return $rEntry;
	}

	/** @return string[] The lines of a log in the work directory. */
	private function lines(string $rLog): array {
		$rFile = $this->rWork . '/' . $rLog;
		return is_file($rFile) ? file($rFile, FILE_IGNORE_NEW_LINES) : [];
	}

	private function versionIn(string $rDir): ?string {
		$rMeta = json_decode((string) @file_get_contents($rDir . '/module.json'), true);
		return is_array($rMeta) ? ($rMeta['version'] ?? null) : null;
	}

	/** @return string[] What is left in the area installed copies are set aside in. */
	private function backups(): array {
		return glob($this->rWork . '/.module_backups/*') ?: [];
	}

	/**
	 * Write a module into $rDir. Its hooks log what ran and the version of the
	 * class that ran it.
	 *
	 * @param array<string, string> $rFiles     Schema files, by path inside the module.
	 * @param array<string, string> $rCallables Programmatic migrations: version => PHP to run before it logs.
	 * @param string                $rBoot      PHP its boot() runs.
	 * @param string[]              $rRequires  The modules it depends on.
	 */
	private function module(string $rDir, string $rName, string $rVersion, array $rFiles = [], array $rCallables = [], ?string $rHashId = null, string $rBoot = '', array $rRequires = []): void {
		$rPascal = implode('', array_map('ucfirst', explode('-', $rName)));
		$rSteps  = '';
		foreach ($rCallables as $rStep => $rBody) {
			$rSteps .= "'{$rStep}' => function () { {$rBody} \\AuditMigrateLog::add('migration {$rStep} by {$rVersion}'); }, ";
		}
		$rFiles['module.json'] = (string) json_encode(array_filter([
			'name'          => $rName,
			'hash_id'       => $rHashId,
			'version'       => $rVersion,
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => $rRequires,
		], static fn($rValue) => $rValue !== null));
		$rFiles[$rPascal . 'Module.php'] = "<?php\nnamespace XcVm\\Module\\{$rPascal};\n"
			. "use XcVm\\Core\\Container\\ServiceContainer;\n"
			. "use XcVm\\Core\\Module\\BaseModule;\n"
			. "class {$rPascal}Module extends BaseModule {\n"
			. "\tpublic function getName(): string { return '{$rName}'; }\n"
			. "\tpublic function getVersion(): string { return '{$rVersion}'; }\n"
			. "\tpublic function boot(ServiceContainer \$rContainer): void { {$rBoot} }\n"
			. "\tpublic function install(): void { \\AuditMigrateLog::add('install by {$rVersion}'); }\n"
			. "\tpublic function getMigrations(): array { return [{$rSteps}]; }\n"
			. "}\n";
		foreach ($rFiles as $rPath => $rContent) {
			@mkdir(dirname($rDir . '/' . $rPath), 0775, true);
			file_put_contents($rDir . '/' . $rPath, $rContent);
		}
	}

	/** A .tar holding the module under a `{name}/` prefix; returns its path. */
	private function archive(string $rName, string $rVersion, array $rFiles = [], array $rCallables = [], ?string $rHashId = null, string $rBoot = ''): string {
		$rStage = $this->rWork . '/archive-' . $rName . '-' . $rVersion;
		$this->module($rStage . '/' . $rName, $rName, $rVersion, $rFiles, $rCallables, $rHashId, $rBoot);
		$rPath = $rStage . '.tar';
		(new PharData($rPath))->buildFromDirectory($rStage);
		return $rPath;
	}
}
