<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\ModuleManager;

// Records each statement of a module schema file; can refuse one.
class AuditSchemaDb {
	public string $failOn = '';
	public array $statements = [];

	public function query(string $rSql): bool {
		$this->statements[] = rtrim($rSql, ';');
		return $this->failOn === '' || strpos($rSql, $this->failOn) === false;
	}
}

// What the generated modules' programmatic migrations and install() hooks ran.
class AuditSchemaLog {
	public static array $ran = [];
}

/**
 * Installing a module over an older install of it (a store update, a store
 * rollback, an archive uploaded again) brings the schema from the recorded
 * version to the new one: the deltas in between run, each once, before the
 * master schema, and the recorded version follows them. An uploaded archive
 * that is refused or fails to install leaves the installed copy in place.
 * When the files go back (a rollback, a failed update) the recorded version
 * goes with them and the version the schema reached stays on record beside
 * it, so no delta is applied a second time.
 */
final class AuditModuleUpdateSchemaTest extends TestCase {
	private const MASTER = 'CREATE TABLE IF NOT EXISTS `aus` (`id` int)';

	private string $rWork;
	private string $rModules;
	private string $rOverrides;
	private AuditSchemaDb $rDb;

	protected function setUp(): void {
		$this->rWork      = sys_get_temp_dir() . '/xc_vm_schema_' . bin2hex(random_bytes(6));
		$this->rModules   = $this->rWork . '/modules';
		$this->rOverrides = $this->rWork . '/modules.php';
		mkdir($this->rModules, 0775, true);
		$this->rDb = new AuditSchemaDb();
		ServiceContainer::getInstance()->set('db', $this->rDb);
		AuditSchemaLog::$ran = [];
	}

	protected function tearDown(): void {
		ServiceContainer::getInstance()->remove('db');
		exec('rm -rf ' . escapeshellarg($this->rWork));
	}

	// ── installModule() over an existing install ─────────────────────

	public function testInstallingOverAnOlderInstallRunsTheDeltasInBetweenFirst(): void {
		$this->module($this->rModules . '/aus-over', 'aus-over', '1.2.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.0.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `zero` int;',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
			'migrations/1.2.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `two` int;',
		], ['1.0.0', '1.2.0']);
		$this->overrides(['aus-over' => ['installed_version' => '1.0.0']]);

		$this->manager()->installModule('aus-over', '1.2.0');

		$this->assertSame([
			'ALTER TABLE `aus` ADD COLUMN `one` int',
			'ALTER TABLE `aus` ADD COLUMN `two` int',
			self::MASTER,
		], $this->rDb->statements);
		$this->assertSame(['migration 1.2.0', 'install'], AuditSchemaLog::$ran);
		$this->assertSame(['installed_version' => '1.2.0'], $this->recorded('aus-over'));
	}

	public function testAFirstInstallRunsOnlyTheMasterSchema(): void {
		$this->module($this->rModules . '/aus-first', 'aus-first', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		], ['1.1.0']);

		$this->manager()->installModule('aus-first');

		$this->assertSame([self::MASTER], $this->rDb->statements);
		$this->assertSame(['install'], AuditSchemaLog::$ran);
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('aus-first'));
	}

	public function testInstallingTheRecordedOrAnOlderVersionRunsNoDelta(): void {
		$this->module($this->rModules . '/aus-back', 'aus-back', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		], ['1.1.0']);

		$this->overrides(['aus-back' => ['installed_version' => '1.1.0']]);
		$this->manager()->installModule('aus-back', '1.1.0');
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('aus-back'));

		// The store's rollback installs the version before the recorded one.
		// The schema is not taken back with it, and that stays on record.
		$this->manager()->installModule('aus-back', '1.0.0');
		$this->assertSame(['installed_version' => '1.0.0', 'schema_version' => '1.1.0'], $this->recorded('aus-back'));

		$this->assertSame([self::MASTER, self::MASTER], $this->rDb->statements);
		$this->assertSame(['install', 'install'], AuditSchemaLog::$ran);
	}

	public function testGoingBackAVersionAndForwardAgainAppliesNoDeltaTwice(): void {
		$this->module($this->rModules . '/aus-again', 'aus-again', '1.3.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.2.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `two` int;',
			'migrations/1.3.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `three` int;',
		], ['1.2.0', '1.3.0']);
		$this->overrides(['aus-again' => ['installed_version' => '1.2.0']]);

		// A rollback to 1.1.0, then the update to 1.2.0 again: its delta is in.
		$this->manager()->installModule('aus-again', '1.1.0');
		$this->manager()->installModule('aus-again', '1.2.0');

		$this->assertSame([self::MASTER, self::MASTER], $this->rDb->statements);
		$this->assertSame(['install', 'install'], AuditSchemaLog::$ran);
		$this->assertSame(['installed_version' => '1.2.0'], $this->recorded('aus-again'));

		// Going back again and on to a later version runs only what is new.
		$this->manager()->installModule('aus-again', '1.1.0');
		$this->rDb->statements = [];
		AuditSchemaLog::$ran = [];
		$this->manager()->installModule('aus-again', '1.3.0');

		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `three` int', self::MASTER], $this->rDb->statements);
		$this->assertSame(['migration 1.3.0', 'install'], AuditSchemaLog::$ran);
		$this->assertSame(['installed_version' => '1.3.0'], $this->recorded('aus-again'));
	}

	public function testAnUpdateResumesAfterTheSchemaVersionWhenTheFilesWentBack(): void {
		// A git or url update whose files were put back, tried again.
		$this->module($this->rModules . '/aus-git', 'aus-git', '1.2.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
			'migrations/1.2.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `two` int;',
		], ['1.1.0', '1.2.0']);
		$this->overrides(['aus-git' => ['installed_version' => '1.0.0', 'schema_version' => '1.1.0']]);

		$this->manager()->updateModule('aus-git');

		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `two` int'], $this->rDb->statements);
		$this->assertSame(['migration 1.2.0'], AuditSchemaLog::$ran);
		$this->assertSame(['installed_version' => '1.2.0'], $this->recorded('aus-git'));
	}

	public function testUninstallingForgetsTheSchemaVersion(): void {
		$this->module($this->rModules . '/aus-drop', 'aus-drop', '1.0.0', ['database.sql' => self::MASTER . ';']);
		$this->overrides(['aus-drop' => ['installed_version' => '1.0.0', 'schema_version' => '1.1.0']]);

		$this->manager()->uninstallModule('aus-drop');

		$this->assertSame(['state' => 'disabled'], $this->recorded('aus-drop'));
	}

	public function testAFailingDeltaStopsAtTheLastVersionApplied(): void {
		$this->module($this->rModules . '/aus-stop', 'aus-stop', '1.2.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
			'migrations/1.2.0.sql' => 'BOOM;',
		]);
		$this->overrides(['aus-stop' => ['installed_version' => '1.0.0']]);
		$this->rDb->failOn = 'BOOM';

		try {
			$this->manager()->installModule('aus-stop');
			$this->fail('The failing delta must stop the install.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('1.2.0.sql', $e->getMessage());
		}

		$this->assertSame(['installed_version' => '1.1.0', 'state' => 'failed'], $this->recorded('aus-stop'));
		$this->assertNotContains(self::MASTER, $this->rDb->statements);
		$this->assertSame([], AuditSchemaLog::$ran);
	}

	public function testAModuleWithoutAMasterSchemaRunsEachNewDeltaOnce(): void {
		$this->module($this->rModules . '/aus-deltas', 'aus-deltas', '1.1.0', [
			'migrations/1.0.0.sql' => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		]);
		$this->overrides(['aus-deltas' => ['installed_version' => '1.0.0']]);

		$this->manager()->installModule('aus-deltas');

		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `one` int'], $this->rDb->statements);
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('aus-deltas'));
	}

	// ── An archive uploaded over an installed module ─────────────────

	public function testAnArchiveUploadedOverAnInstalledModuleRunsItsDeltas(): void {
		$rHash = str_repeat('ab12', 8);
		$this->module($this->rModules . '/aus-up_ab12a', 'aus-up', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['aus-up' => ['installed_version' => '1.0.0', 'state' => 'disabled']]);

		$this->upload($this->archive('aus-up', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		], $rHash));

		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `one` int', self::MASTER], $this->rDb->statements);
		$this->assertSame('1.1.0', $this->recorded('aus-up')['installed_version']);
		$this->assertSame('disabled', $this->recorded('aus-up')['state'], 'a module the admin switched off stays off');
		$this->assertSame('1.1.0', $this->versionIn($this->rModules . '/aus-up_ab12a'));
		$this->assertSame([], $this->backups(), 'the copy set aside is gone once the new one is in');
	}

	public function testAnUploadThatFailsToInstallPutsTheInstalledCopyBack(): void {
		$rHash = str_repeat('cd34', 8);
		$this->module($this->rModules . '/aus-fail_cd34c', 'aus-fail', '1.0.0', [], [], $rHash);
		$this->overrides(['aus-fail' => ['installed_version' => '1.0.0', 'state' => 'disabled']]);
		$this->rDb->failOn = 'BOOM';

		try {
			$this->upload($this->archive('aus-fail', '1.2.0', [
				'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
				'migrations/1.2.0.sql' => 'BOOM;',
			], $rHash));
			$this->fail('The failing delta must stop the upload.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('1.2.0.sql', $e->getMessage());
		}

		$this->assertSame([$this->rModules . '/aus-fail_cd34c'], glob($this->rModules . '/*'));
		$this->assertSame('1.0.0', $this->versionIn($this->rModules . '/aus-fail_cd34c'));
		$this->assertSame([], $this->backups());
		// The recorded version is the one put back; the delta that did land is
		// on record beside it, so uploading again resumes after it.
		$this->assertSame(
			['installed_version' => '1.0.0', 'schema_version' => '1.1.0', 'state' => 'disabled'],
			$this->recorded('aus-fail')
		);
	}

	public function testAnUploadWhoseInstallHookFailsDoesNotApplyItsDeltasAgainWhenRetried(): void {
		$rHash  = str_repeat('5e6f', 8);
		$rFiles = [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		];
		$this->module($this->rModules . '/aus-hook_5e6f5', 'aus-hook', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['aus-hook' => ['installed_version' => '1.0.0']]);

		try {
			$this->upload($this->archive('aus-hook', '1.1.0', $rFiles + ['install-fails' => ''], $rHash));
			$this->fail('The failing install() must stop the upload.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('install() refused', $e->getMessage());
		}

		$this->assertSame('1.0.0', $this->versionIn($this->rModules . '/aus-hook_5e6f5'));
		$this->assertSame(['installed_version' => '1.0.0', 'schema_version' => '1.1.0'], $this->recorded('aus-hook'));

		$this->rDb->statements = [];
		$this->upload($this->archive('aus-hook', '1.1.0', $rFiles, $rHash));

		$this->assertSame([self::MASTER], $this->rDb->statements);
		$this->assertSame('1.1.0', $this->versionIn($this->rModules . '/aus-hook_5e6f5'));
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('aus-hook'));
	}

	public function testAFailedUploadUnderANewDirectoryNameLeavesOnlyTheInstalledCopy(): void {
		// An archive without a hash_id is given a new one, so a new directory.
		$this->module($this->rModules . '/aus-new_ef56e', 'aus-new', '1.0.0', [], [], str_repeat('ef56', 8));
		$this->overrides(['aus-new' => ['installed_version' => '1.0.0']]);
		$this->rDb->failOn = 'BOOM';

		try {
			$this->upload($this->archive('aus-new', '1.1.0', ['migrations/1.1.0.sql' => 'BOOM;']));
			$this->fail('The failing delta must stop the upload.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('1.1.0.sql', $e->getMessage());
		}

		$this->assertSame([$this->rModules . '/aus-new_ef56e'], glob($this->rModules . '/*'));
		$this->assertSame('1.0.0', $this->versionIn($this->rModules . '/aus-new_ef56e'));
		$this->assertSame(['installed_version' => '1.0.0'], $this->recorded('aus-new'), 'and it loads again');
	}

	public function testAFirstUploadThatFailsStaysOnDiskAsFailed(): void {
		$this->rDb->failOn = 'BOOM';

		try {
			$this->upload($this->archive('aus-once', '1.0.0', ['database.sql' => 'BOOM;'], str_repeat('0a1b', 8)));
			$this->fail('The failing schema must stop the upload.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('database.sql', $e->getMessage());
		}

		$this->assertSame('1.0.0', $this->versionIn($this->rModules . '/aus-once_0a1b0'));
		$this->assertSame(['state' => 'failed'], $this->recorded('aus-once'));
	}

	// ── A store update, through the platform extension ───────────────

	public function testAStoreUpdateRunsTheDeltasOfTheVersionItServes(): void {
		$rHash = str_repeat('9f8e', 8);
		$this->module($this->rModules . '/aus-store_9f8e9', 'aus-store', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['aus-store' => ['installed_version' => '1.0.0', 'source' => 'platform']]);
		// What the store serves as 1.1.0, as the extension unpacks it.
		$this->module($this->rWork . '/served/aus-store', 'aus-store', '1.1.0', [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		], ['1.1.0'], $rHash);

		$rResult = $this->storeInstall('aus-store', '1.1.0');

		$this->assertSame('', $rResult['error']);
		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `one` int', self::MASTER], $rResult['statements']);
		$this->assertSame(['migration 1.1.0', 'install'], $rResult['ran']);
		$this->assertSame('1.1.0', $this->recorded('aus-store')['installed_version']);
		$this->assertSame('1.1.0', $this->versionIn($this->rModules . '/aus-store_9f8e9'));
	}

	// ── An update that fails and is rolled back ──────────────────────

	public function testARolledBackStoreUpdateResumesAfterTheDeltasThatLanded(): void {
		$rHash = str_repeat('7c6d', 8);
		$this->module($this->rModules . '/aus-resume_7c6d7', 'aus-resume', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['aus-resume' => ['installed_version' => '1.0.0', 'source' => 'platform']]);
		$rServed = [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
			'migrations/1.2.0.sql' => 'BOOM;',
		];
		$this->module($this->rWork . '/served/aus-resume', 'aus-resume', '1.2.0', $rServed, [], $rHash);

		$rResult = $this->storeInstall('aus-resume', '1.2.0');

		$this->assertStringContainsString('rolled back', $rResult['error']);
		$this->assertSame('1.0.0', $this->versionIn($this->rModules . '/aus-resume_7c6d7'));
		$this->assertSame(['installed_version' => '1.0.0', 'schema_version' => '1.1.0'], $this->recorded('aus-resume'));

		// The store then serves a 1.2.0 whose delta applies.
		$rServed['migrations/1.2.0.sql'] = 'ALTER TABLE `aus` ADD COLUMN `two` int;';
		$this->module($this->rWork . '/served/aus-resume', 'aus-resume', '1.2.0', $rServed, [], $rHash);

		$rResult = $this->storeInstall('aus-resume', '1.2.0');

		$this->assertSame('', $rResult['error']);
		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `two` int', self::MASTER], $rResult['statements']);
		$this->assertSame(['installed_version' => '1.2.0'], $this->recorded('aus-resume'));
		$this->assertSame('1.2.0', $this->versionIn($this->rModules . '/aus-resume_7c6d7'));
	}

	public function testAStoreUpdateThatFailsAfterItsDeltasDoesNotApplyThemAgainWhenRetried(): void {
		$rHash = str_repeat('3b4c', 8);
		$this->module($this->rModules . '/aus-twice_3b4c3', 'aus-twice', '1.0.0', ['database.sql' => self::MASTER . ';'], [], $rHash);
		$this->overrides(['aus-twice' => ['installed_version' => '1.0.0', 'source' => 'platform']]);
		$rServed = [
			'database.sql'         => self::MASTER . ';',
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		];
		$this->module($this->rWork . '/served/aus-twice', 'aus-twice', '1.1.0', $rServed + ['install-fails' => ''], [], $rHash);

		$rResult = $this->storeInstall('aus-twice', '1.1.0');

		$this->assertStringContainsString('rolled back', $rResult['error']);
		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `one` int', self::MASTER], $rResult['statements']);
		$this->assertSame('1.0.0', $this->versionIn($this->rModules . '/aus-twice_3b4c3'));
		// Recorded at the version put back, so the update is still offered.
		$this->assertSame(['installed_version' => '1.0.0', 'schema_version' => '1.1.0'], $this->recorded('aus-twice'));

		// The store then serves a 1.1.0 that installs.
		unlink($this->rWork . '/served/aus-twice/install-fails');

		$rResult = $this->storeInstall('aus-twice', '1.1.0');

		$this->assertSame('', $rResult['error']);
		$this->assertSame([self::MASTER], $rResult['statements']);
		$this->assertSame(['installed_version' => '1.1.0'], $this->recorded('aus-twice'));
		$this->assertSame('1.1.0', $this->versionIn($this->rModules . '/aus-twice_3b4c3'));
	}

	public function testFilesPutBackAreRecordedAtTheirVersionAndTheSchemaWhereItGot(): void {
		// The step every rolled back update ends with, git and url ones too.
		$rRestore = new ReflectionMethod(ModuleManager::class, 'restoreModuleBackup');
		$rTarget  = $this->rModules . '/aus-reached_1a2b3';
		$rBackup  = $this->rWork . '/.module_backups/aus-reached_old';
		$this->module($rTarget, 'aus-reached', '1.2.0');
		$this->module($rBackup, 'aus-reached', '1.0.0');
		$this->overrides(['aus-reached' => ['installed_version' => '1.2.0']]);

		$this->assertTrue($rRestore->invoke($this->manager(), 'aus-reached', $rTarget, $rBackup, '1.0.0'));

		$this->assertSame('1.0.0', $this->versionIn($rTarget));
		$this->assertSame(['installed_version' => '1.0.0', 'schema_version' => '1.2.0'], $this->recorded('aus-reached'));
	}

	// ── A process that already loaded the old version ────────────────

	public function testFileDeltasDoNotDependOnWhichVersionOfTheClassIsLoaded(): void {
		$rHash  = str_repeat('8a9b', 8);
		$rFiles = ['database.sql' => self::MASTER . ';'];
		$this->module($this->rModules . '/aus-loaded_8a9b8', 'aus-loaded', '1.0.0', $rFiles, [], $rHash);
		$this->overrides(['aus-loaded' => ['installed_version' => '1.0.0']]);
		// An admin request loads every enabled module before it handles the upload.
		(new ModuleLoader())->loadAll($this->rModules);

		$this->upload($this->archive('aus-loaded', '1.1.0', $rFiles + [
			'migrations/1.1.0.sql' => 'ALTER TABLE `aus` ADD COLUMN `one` int;',
		], $rHash, ['1.1.0']));

		$this->assertSame(['ALTER TABLE `aus` ADD COLUMN `one` int', self::MASTER], $this->rDb->statements);
		$this->assertSame('1.1.0', $this->recorded('aus-loaded')['installed_version']);
		// Known limit: PHP keeps the class it loaded first, so install() is the
		// old version's and the new version's getMigrations() steps are not seen.
		$this->assertSame(['install'], AuditSchemaLog::$ran);
	}

	// ── Fixtures ─────────────────────────────────────────────────────

	private function manager(): ModuleManager {
		return new ModuleManager($this->rModules, $this->rOverrides, ServiceContainer::getInstance());
	}

	/** uploadAndInstall(), up to the hand-over to the load balancers. */
	private function upload(string $rArchive): void {
		try {
			$this->manager()->uploadAndInstall($rArchive);
		} catch (Error $e) {
			// The last step asks xcvm_core whether this is a load balancer, and
			// the extension is absent here; placing and installing are done by then.
			$this->assertStringContainsString('XC_VM', $e->getMessage());
		}
	}

	/**
	 * downloadFromPlatform() in a child PHP whose xcvm_core stand-in unpacks
	 * `served/{slug}` into the modules directory, as the extension does.
	 *
	 * @return array{error: string, statements: list<string>, ran: list<string>}
	 */
	private function storeInstall(string $rSlug, string $rVersion): array {
		$rPrepend = $this->rWork . '/prepend.php';
		file_put_contents($rPrepend, <<<'PHP'
			<?php
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 1, 'is_lb' => 0];
				}

				public static function panel_register(string $rKey): array {
					return ['ok' => true];
				}

				public static function module_install(string $rSlug, string $rVersion, string $rKey): array {
					$rPath = getenv('AUS_WORK') . '/modules/' . $rSlug;
					exec('cp -r ' . escapeshellarg(getenv('AUS_WORK') . '/served/' . $rSlug) . ' ' . escapeshellarg($rPath));
					return ['ok' => true, 'module' => $rSlug, 'version' => getenv('AUS_VERSION'), 'path' => $rPath];
				}
			}
			PHP);
		$rScript = $this->rWork . '/child.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Container\ServiceContainer;
			use XcVm\Core\Module\ModuleManager;

			require getenv('AUS_TESTS') . '/bootstrap.php';

			class AuditSchemaLog {
				public static array $ran = [];
			}
			$rDb = new class {
				public array $statements = [];

				public function query(string $rSql): bool {
					$this->statements[] = rtrim($rSql, ';');
					return strpos($rSql, 'BOOM') === false;
				}
			};
			ServiceContainer::getInstance()->set('db', $rDb);
			$rWork  = getenv('AUS_WORK');
			$rError = '';
			try {
				(new ModuleManager($rWork . '/modules', $rWork . '/modules.php', ServiceContainer::getInstance()))
					->downloadFromPlatform($argv[1], '', 'key');
			} catch (\Throwable $e) {
				$rError = get_class($e) . ': ' . $e->getMessage();
			}
			echo json_encode(['error' => $rError, 'statements' => $rDb->statements, 'ran' => AuditSchemaLog::$ran]);
			PHP);
		$rProc = proc_open(
			[...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $rPrepend, $rScript, $rSlug],
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$rPipes,
			$this->rWork,
			['AUS_WORK' => $this->rWork, 'AUS_VERSION' => $rVersion, 'AUS_TESTS' => dirname(__DIR__), 'PATH' => (string) getenv('PATH')]
		);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		proc_close($rProc);
		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);
		return $rResult;
	}

	private function overrides(array $rOverrides): void {
		file_put_contents($this->rOverrides, "<?php\n\nreturn " . var_export($rOverrides, true) . ";\n");
	}

	/** The module's entry in config/modules.php. */
	private function recorded(string $rName): array {
		$rData  = is_file($this->rOverrides) ? require $this->rOverrides : [];
		$rEntry = $rData[$rName] ?? [];
		unset($rEntry['source']);
		ksort($rEntry);
		return $rEntry;
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
	 * Write a module into $rDir.
	 *
	 * @param array<string, string> $rFiles     Schema files, by path inside the module; an
	 *                                          `install-fails` file makes install() throw.
	 * @param string[]              $rCallables Versions that have a programmatic migration.
	 */
	private function module(string $rDir, string $rName, string $rVersion, array $rFiles = [], array $rCallables = [], ?string $rHashId = null): void {
		$rPascal = implode('', array_map('ucfirst', explode('-', $rName)));
		$rSteps  = '';
		foreach ($rCallables as $rStep) {
			$rSteps .= "'{$rStep}' => function () { \\AuditSchemaLog::\$ran[] = 'migration {$rStep}'; }, ";
		}
		$rFiles['module.json'] = (string) json_encode(array_filter([
			'name'          => $rName,
			'hash_id'       => $rHashId,
			'version'       => $rVersion,
			'requires_core' => '>=2.0',
			'environment'   => 'main',
			'dependencies'  => [],
		], static fn($rValue) => $rValue !== null));
		$rFiles[$rPascal . 'Module.php'] = "<?php\nnamespace XcVm\\Module\\{$rPascal};\n"
			. "use XcVm\\Core\\Module\\BaseModule;\n"
			. "class {$rPascal}Module extends BaseModule {\n"
			. "\tpublic function getName(): string { return '{$rName}'; }\n"
			. "\tpublic function getVersion(): string { return '{$rVersion}'; }\n"
			. "\tpublic function install(): void {\n"
			. "\t\tif (is_file(__DIR__ . '/install-fails')) { throw new \\RuntimeException('install() refused'); }\n"
			. "\t\t\\AuditSchemaLog::\$ran[] = 'install';\n"
			. "\t}\n"
			. "\tpublic function getMigrations(): array { return [{$rSteps}]; }\n"
			. "}\n";
		foreach ($rFiles as $rPath => $rContent) {
			@mkdir(dirname($rDir . '/' . $rPath), 0775, true);
			file_put_contents($rDir . '/' . $rPath, $rContent);
		}
	}

	/** A .tar holding the module under a `{name}/` prefix; returns its path. */
	private function archive(string $rName, string $rVersion, array $rFiles = [], ?string $rHashId = null, array $rCallables = []): string {
		$rStage = $this->rWork . '/archive-' . $rName . '-' . $rVersion . '-' . bin2hex(random_bytes(3));
		$this->module($rStage . '/' . $rName, $rName, $rVersion, $rFiles, $rCallables, $rHashId);
		$rPath = $rStage . '.tar';
		(new PharData($rPath))->buildFromDirectory($rStage);
		return $rPath;
	}
}
