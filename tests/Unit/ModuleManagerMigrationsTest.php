<?php

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\ModuleState;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Module\BaseModule;
use PHPUnit\Framework\TestCase;

// Static tracker used by generated module migration callables.
class MigrationCallTracker {
    public static array $calls = [];

    public static function record(string $version): void {
        self::$calls[] = $version;
    }

    public static function reset(): void {
        self::$calls = [];
    }
}

// Minimal db double for module schema files: it can be told to fail on a given
// statement, which is what makes the update/uninstall failure paths reachable.
class FakeModuleDb {
    public string $failOn = '';
    public array $statements = [];

    public function query(string $sql): bool {
        $this->statements[] = $sql;
        return $this->failOn === '' || strpos($sql, $this->failOn) === false;
    }
}

final class ModuleManagerMigrationsTest extends TestCase {

    private string $modulesPath;
    private string $overridesPath;
    private string $workDir;
    private FakeModuleDb $db;

    protected function setUp(): void {
        $this->workDir      = sys_get_temp_dir() . '/xc_vm_mgr_' . bin2hex(random_bytes(6));
        $this->modulesPath  = $this->workDir . '/modules';
        $this->overridesPath = $this->workDir . '/modules.php';
        mkdir($this->modulesPath, 0775, true);
        $this->db = new FakeModuleDb();
        ServiceContainer::getInstance()->set('db', $this->db);
        MigrationCallTracker::reset();
    }

    protected function tearDown(): void {
        ServiceContainer::getInstance()->remove('db');
        $this->deleteDir($this->workDir);
        MigrationCallTracker::reset();
    }

    // ── installModule() records installed_version ─────────────────────────

    public function testInstallRecordsInstalledVersion(): void {
        $this->createModule('install-record', '1.0.0');

        $manager = $this->manager();
        $manager->installModule('install-record');

        $overrides = $this->readOverrides();
        $this->assertSame('1.0.0', $overrides['install-record']['installed_version'] ?? null);
    }

    public function testInstallsModuleLivingInHashSuffixedDirectory(): void {
        // Reproduces the upload flow: the module was placed in `{name}_{hash5}`,
        // never a bare `{name}` dir. installModule() must resolve the real directory
        // to load the class — otherwise the module unpacks but fails to install.
        $name  = 'hashinstall';
        $pascal = $this->pascal($name);
        $dir   = $this->modulesPath . '/' . $name . '_ab123';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode([
            'name'          => $name,
            'hash_id'       => 'ab123def4567890ab123def4567890cc',
            'version'       => '2.1.0',
            'requires_core' => '>=2.0',
            'environment'   => 'main',
            'dependencies'  => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dir . '/' . $pascal . "Module.php",
            "<?php\nnamespace XcVm\\Module\\{$pascal};\n"
            . "use XcVm\\Core\\Module\\BaseModule;\n"
            . "class {$pascal}Module extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '2.1.0'; }\n"
            . "}\n"
        );

        $this->manager()->installModule($name);

        // Version comes from the manifest in the hashed dir (proves readModuleManifest
        // resolved it too), and state is recorded under the canonical name.
        $this->assertSame('2.1.0', $this->readOverrides()[$name]['installed_version'] ?? null);
    }

    // ── uninstallModule() clears installed_version ────────────────────────

    public function testUninstallClearsInstalledVersion(): void {
        $this->createModule('uninstall-clear', '1.0.0');
        $this->writeOverrides(['uninstall-clear' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->uninstallModule('uninstall-clear');

        $overrides = $this->readOverrides();
        $this->assertArrayNotHasKey('installed_version', $overrides['uninstall-clear'] ?? []);
    }

    // ── deleteModule() — removes directory + override ────────────────────

    public function testDeleteRemovesModuleDirectoryAndOverride(): void {
        $this->createModule('delete-me', '1.0.0');
        $this->writeOverrides(['delete-me' => ['installed_version' => '1.0.0']]);
        $dir = $this->modulesPath . '/delete-me';
        $this->assertDirectoryExists($dir);

        $this->manager()->deleteModule('delete-me');

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertArrayNotHasKey('delete-me', $this->readOverrides());
    }

    public function testDeleteBlockedByInstalledDependent(): void {
        $this->createModuleWithDeps('del-base', '1.0.0', []);
        $this->createModuleWithDeps('del-consumer', '1.0.0', ['del-base']);
        $this->writeOverrides([
            'del-base'     => ['installed_version' => '1.0.0'],
            'del-consumer' => ['installed_version' => '1.0.0'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->manager()->deleteModule('del-base');
    }

    // ── updateModule() — fallback to installModule() ──────────────────────

    public function testUpdateWithNoInstalledVersionFallsBackToInstall(): void {
        $this->createModule('update-fallback', '2.0.0');

        $manager = $this->manager();
        $manager->updateModule('update-fallback');

        $overrides = $this->readOverrides();
        $this->assertSame('2.0.0', $overrides['update-fallback']['installed_version'] ?? null);
    }

    // ── updateModule() — already up-to-date ──────────────────────────────

    public function testUpdateWithSameVersionSkipsMigrations(): void {
        $this->createMigratableModule('update-same', '1.0.0', ['1.0.0']);
        $this->writeOverrides(['update-same' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-same');

        // Migration for 1.0.0 must NOT run — fromVersion == toVersion
        $this->assertSame([], MigrationCallTracker::$calls);
    }

    // ── updateModule() — runs pending migrations in semver order ─────────

    public function testUpdateRunsPendingMigrationsInOrder(): void {
        $this->createMigratableModule('update-order', '1.3.0', ['0.9.0', '1.1.0', '1.3.0', '1.2.0']);
        $this->writeOverrides(['update-order' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-order');

        // 0.9.0 <= 1.0.0 → excluded. 1.1.0, 1.2.0, 1.3.0 → ascending semver order.
        $this->assertSame(['1.1.0', '1.2.0', '1.3.0'], MigrationCallTracker::$calls);
    }

    public function testUpdateExcludesMigrationsAboveToVersion(): void {
        $this->createMigratableModule('update-cap', '1.1.0', ['1.1.0', '1.2.0', '2.0.0']);
        $this->writeOverrides(['update-cap' => ['installed_version' => '1.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-cap');

        // Module version is 1.1.0 → migrations > 1.1.0 must be excluded
        $this->assertSame(['1.1.0'], MigrationCallTracker::$calls);
    }

    // ── updateModule() — records new version after migrations ─────────────

    public function testUpdateRecordsNewVersionAfterMigrations(): void {
        $this->createMigratableModule('update-record', '2.5.0', ['2.0.0', '2.5.0']);
        $this->writeOverrides(['update-record' => ['installed_version' => '1.9.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-record');

        $overrides = $this->readOverrides();
        $this->assertSame('2.5.0', $overrides['update-record']['installed_version'] ?? null);
    }

    public function testUpdateWithNoMigrationsStillRecordsNewVersion(): void {
        $this->createMigratableModule('update-nomir', '3.0.0', []);
        $this->writeOverrides(['update-nomir' => ['installed_version' => '2.0.0']]);

        $manager = $this->manager();
        $manager->updateModule('update-nomir');

        $overrides = $this->readOverrides();
        $this->assertSame('3.0.0', $overrides['update-nomir']['installed_version'] ?? null);
    }

    // ── findModuleByHashId() — stable identity lookup (P5 foundation) ─────

    public function testFindModuleByHashId(): void {
        $dir = $this->modulesPath . '/id-mod';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode([
            'name'                  => 'id-mod',
            'hash_id'               => 'abc123def4567890abc123def4567890',
            'version'               => '1.0.0',
            'environment'           => 'main',
            'dependencies'          => [],
            'optional_dependencies' => [],
        ]));

        $manager = $this->manager();
        $this->assertSame('id-mod', $manager->findModuleByHashId('abc123def4567890abc123def4567890'));
        $this->assertNull($manager->findModuleByHashId('does-not-exist'));
        $this->assertNull($manager->findModuleByHashId(''));
    }

    // ── migrateLegacyModuleDirs() — bare {name} → {name}_{hash5} ──────────

    public function testMigrateLegacyDirGeneratesHashAndRenames(): void {
        // Legacy bare directory with NO hash_id in the manifest.
        $dir = $this->modulesPath . '/legacy-mod';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode([
            'name'         => 'legacy-mod',
            'version'      => '1.0.0',
            'environment'  => 'main',
            'dependencies' => [],
        ]));

        $migrated = $this->manager()->migrateLegacyModuleDirs();

        $this->assertSame(['legacy-mod'], $migrated);
        $this->assertDirectoryDoesNotExist($dir, 'bare directory must be gone');

        $hashed = glob($this->modulesPath . '/legacy-mod_*');
        $this->assertCount(1, $hashed, 'exactly one {name}_{hash5} directory must exist');

        $meta = json_decode((string) file_get_contents($hashed[0] . '/module.json'), true);
        $hash = (string) ($meta['hash_id'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash, 'a 32-hex hash_id must be written');
        $this->assertSame('legacy-mod_' . substr($hash, 0, 5), basename($hashed[0]), 'suffix = first 5 of hash_id');
    }

    public function testMigrateLegacyDirDropsStaleBareDuplicate(): void {
        // Both a hashed copy (canonical, active) and a bare dup exist for the same
        // module — the bare one must be removed, the hashed one kept untouched.
        $hash = 'aa11bb22cc33dd44ee55ff6600778899';
        $hash5 = substr($hash, 0, 5);

        $bare = $this->modulesPath . '/dup-mod';
        mkdir($bare, 0775, true);
        file_put_contents($bare . '/module.json', json_encode([
            'name' => 'dup-mod', 'hash_id' => $hash, 'version' => '1.0.0',
            'environment' => 'main', 'dependencies' => [],
        ]));

        $hashed = $this->modulesPath . '/dup-mod_' . $hash5;
        mkdir($hashed, 0775, true);
        file_put_contents($hashed . '/module.json', json_encode([
            'name' => 'dup-mod', 'hash_id' => $hash, 'version' => '1.0.0',
            'environment' => 'main', 'dependencies' => [],
        ]));

        $migrated = $this->manager()->migrateLegacyModuleDirs();

        $this->assertSame(['dup-mod'], $migrated);
        $this->assertDirectoryDoesNotExist($bare, 'stale bare duplicate must be removed');
        $this->assertDirectoryExists($hashed, 'canonical hashed directory must be kept');
    }

    public function testMigrateLegacyDirLeavesHashedDirsUntouched(): void {
        // An already-canonical {name}_{hash5} directory must not be migrated again.
        $hash = 'abc123def4567890abc123def4567890';
        $dir  = $this->modulesPath . '/already_' . substr($hash, 0, 5);
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode([
            'name' => 'already', 'hash_id' => $hash, 'version' => '1.0.0',
            'environment' => 'main', 'dependencies' => [],
        ]));

        $this->assertSame([], $this->manager()->migrateLegacyModuleDirs());
        $this->assertDirectoryExists($dir);
    }

    // ── updateModuleFromSource() — bundled path (no network) ──────────────

    public function testUpdateFromSourceBundledRunsMigrationsAndRecordsVersion(): void {
        // No `update` block → source is `bundled`: files ship with the panel, so
        // updateModuleFromSource() just runs the incremental migrations (no fetch).
        $this->createMigratableModule('bundled-upd', '1.2.0', ['1.1.0', '1.2.0']);
        $this->writeOverrides(['bundled-upd' => ['installed_version' => '1.0.0']]);

        $this->manager()->updateModuleFromSource('bundled-upd');

        $this->assertSame(['1.1.0', '1.2.0'], MigrationCallTracker::$calls);
        $this->assertSame('1.2.0', $this->readOverrides()['bundled-upd']['installed_version'] ?? null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    // ── listModules() dependency diagnostics ──────────────────────────────

    public function testListModulesFlagsDisabledRequiredDependency(): void {
        // dep-consumer requires dep-base, which is present but disabled.
        $this->createModuleWithDeps('dep-base', '1.0.0', []);
        $this->createModuleWithDeps('dep-consumer', '1.0.0', ['dep-base']);
        $this->writeOverrides(['dep-base' => ['state' => 'disabled']]);

        $byName = $this->modulesByName();

        $this->assertSame([], $byName['dep-base']['dependency_warnings']);
        $this->assertCount(1, $byName['dep-consumer']['dependency_warnings']);
        $this->assertStringContainsString('dep-base', $byName['dep-consumer']['dependency_warnings'][0]);
        $this->assertStringContainsString('not enabled', $byName['dep-consumer']['dependency_warnings'][0]);
    }

    public function testListModulesFlagsMissingRequiredDependency(): void {
        $this->createModuleWithDeps('needs-absent', '1.0.0', ['ghost-module']);

        $byName = $this->modulesByName();

        $this->assertCount(1, $byName['needs-absent']['dependency_warnings']);
        $this->assertStringContainsString('missing', $byName['needs-absent']['dependency_warnings'][0]);
    }

    public function testListModulesNoWarningWhenDependencyEnabled(): void {
        $this->createModuleWithDeps('ok-base', '1.0.0', []);
        $this->createModuleWithDeps('ok-consumer', '1.0.0', ['ok-base']);

        $byName = $this->modulesByName();

        $this->assertSame([], $byName['ok-consumer']['dependency_warnings']);
    }

    // ── install keeps the admin's on/off decision ─────────────────────────

    public function testReinstallKeepsAModuleTheAdminDisabled(): void {
        // A store update lands in installModule(), which used to finish with an
        // unconditional Enabled — silently switching a disabled module back on.
        $this->createModule('keep-off', '1.0.0');
        $this->writeOverrides(['keep-off' => ['state' => 'disabled', 'installed_version' => '1.0.0']]);

        $this->manager()->installModule('keep-off');

        $overrides = $this->readOverrides();
        $this->assertSame('disabled', $overrides['keep-off']['state'] ?? null);
        $this->assertSame('1.0.0', $overrides['keep-off']['installed_version'] ?? null);
    }

    public function testFirstInstallEnablesTheModule(): void {
        $this->createModule('turn-on', '1.0.0');

        $this->manager()->installModule('turn-on');

        // Enabled is the clean default, recorded by the ABSENCE of a state key.
        $this->assertArrayNotHasKey('state', $this->readOverrides()['turn-on']);
    }

    public function testReinstallRestoresDisabledEvenWithADependent(): void {
        // Restoring Disabled is a lifecycle transition, not an admin "disable",
        // so the dependents guard must not veto it and fail the install.
        $this->createModuleWithDeps('dep-off', '1.0.0', []);
        $this->createModuleWithDeps('dep-user', '1.0.0', ['dep-off']);
        $this->writeOverrides([
            'dep-off'  => ['state' => 'disabled', 'installed_version' => '1.0.0'],
            'dep-user' => ['installed_version' => '1.0.0'],
        ]);

        $this->manager()->installModule('dep-off');

        $this->assertSame('disabled', $this->readOverrides()['dep-off']['state'] ?? null);
    }

    // ── update/uninstall failure containment ──────────────────────────────

    public function testUpdateKeepsWatermarkAtLastCompletedVersionOnFailure(): void {
        // Deltas 1.1.0 and 1.2.0 pending; the second one fails. The recorded
        // version must stop at 1.1.0 so a retry resumes instead of replaying it.
        $this->createModule('resume-mod', '1.2.0');
        $dir = $this->modulesPath . '/resume-mod/migrations';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/1.1.0.sql', 'SELECT 1;');
        file_put_contents($dir . '/1.2.0.sql', 'BOOM;');
        $this->writeOverrides(['resume-mod' => ['installed_version' => '1.0.0']]);

        $this->db->failOn = 'BOOM';

        try {
            $this->manager()->updateModule('resume-mod');
            $this->fail('expected the failing delta to throw');
        } catch (RuntimeException $e) {
            // expected
        }

        $overrides = $this->readOverrides();
        $this->assertSame('1.1.0', $overrides['resume-mod']['installed_version'] ?? null);
        $this->assertSame('failed', $overrides['resume-mod']['state'] ?? null);
    }

    public function testUpdateRecordsTargetVersionWhenEveryDeltaApplies(): void {
        $this->createModule('resume-ok', '1.2.0');
        $dir = $this->modulesPath . '/resume-ok/migrations';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/1.1.0.sql', 'SELECT 1;');
        file_put_contents($dir . '/1.2.0.sql', 'SELECT 2;');
        $this->writeOverrides(['resume-ok' => ['installed_version' => '1.0.0']]);

        $this->manager()->updateModule('resume-ok');

        $overrides = $this->readOverrides();
        $this->assertSame('1.2.0', $overrides['resume-ok']['installed_version'] ?? null);
        $this->assertArrayNotHasKey('state', $overrides['resume-ok']);
    }

    public function testUninstallMarksModuleFailedWhenTeardownThrows(): void {
        $this->createModule('teardown-mod', '1.0.0');
        file_put_contents($this->modulesPath . '/teardown-mod/database_drop.sql', 'BOOM;');
        $this->writeOverrides(['teardown-mod' => ['installed_version' => '1.0.0']]);

        $this->db->failOn = 'BOOM';

        try {
            $this->manager()->uninstallModule('teardown-mod');
            $this->fail('expected the failing teardown to throw');
        } catch (RuntimeException $e) {
            // expected
        }

        $overrides = $this->readOverrides();
        $this->assertSame('failed', $overrides['teardown-mod']['state'] ?? null);
        // Still recorded as installed — its tables were not dropped.
        $this->assertSame('1.0.0', $overrides['teardown-mod']['installed_version'] ?? null);
    }

    // ── one directory per module ──────────────────────────────────────────

    public function testUploadPlacesModuleInHashedDirAndDropsLegacyBareCopy(): void {
        // A stale bare `{name}` copy must not survive beside the canonical one —
        // two dirs for one module is what made the platform flow lose track of it.
        $legacy = $this->modulesPath . '/upl-mod';
        mkdir($legacy, 0775, true);
        file_put_contents($legacy . '/module.json', json_encode(['name' => 'upl-mod', 'version' => '0.9.0']));

        try {
            $this->manager()->uploadAndInstall($this->makeModuleTar('upl-mod', '1.0.0', str_repeat('ab12', 8)));
        } catch (Error $e) {
            // uploadAndInstall ends by distributing to load balancers through the
            // xcvm_core extension, which is absent here. Extraction, placement and
            // install have all already run by then — that is what this asserts.
            $this->assertStringContainsString('XC_VM', $e->getMessage());
        }

        $this->assertDirectoryExists($this->modulesPath . '/upl-mod_ab12a');
        $this->assertDirectoryDoesNotExist($legacy);
        $this->assertSame('1.0.0', $this->readOverrides()['upl-mod']['installed_version'] ?? null);
    }

    // ── requires_core ─────────────────────────────────────────────────────

    public function testUploadRefusesAModuleForAnotherCoreAndKeepsTheInstalledCopy(): void {
        $this->createModule('core-mod', '1.0.0');

        try {
            $this->manager()->uploadAndInstall($this->makeModuleTar('core-mod', '2.0.0', str_repeat('cd34', 8), '>=99.0'));
            $this->fail('An upload for another core must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs core >=99.0', $e->getMessage());
        }

        $this->assertDirectoryExists($this->modulesPath . '/core-mod');
        $this->assertSame('1.0.0', json_decode((string) file_get_contents($this->modulesPath . '/core-mod/module.json'), true)['version']);
        $this->assertDirectoryDoesNotExist($this->modulesPath . '/core-mod_cd34c');
    }

    public function testARefusedCopyInsideTheModulesDirectoryIsRemoved(): void {
        // The platform extension extracts straight into the modules directory.
        $pulled = $this->modulesPath . '/pulled-mod';
        mkdir($pulled, 0775, true);
        file_put_contents($pulled . '/module.json', json_encode(['name' => 'pulled-mod', 'version' => '2.0.0', 'requires_core' => '>=99.0']));

        $place = new ReflectionMethod(ModuleManager::class, 'placeModuleFiles');
        try {
            $place->invoke($this->manager(), $pulled);
            $this->fail('A module for another core must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs core', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($pulled);
    }

    public function testAGitUpdateMustBeTheSameModuleAndFitThisCore(): void {
        $check  = new ReflectionMethod(ModuleManager::class, 'assertSameModuleForThisCore');
        $update = $this->workDir . '/update';
        mkdir($update, 0775, true);
        $write = function (array $meta) use ($update): void {
            file_put_contents($update . '/module.json', json_encode($meta));
        };
        $installed = array('name' => 'git-mod', 'hash_id' => str_repeat('a', 32));

        $write(array('name' => 'git-mod', 'hash_id' => str_repeat('a', 32), 'requires_core' => '>=2.0'));
        $check->invoke($this->manager(), $update, $installed, 'git-mod');

        $write(array('name' => 'git-mod', 'hash_id' => str_repeat('b', 32), 'requires_core' => '>=2.0'));
        try {
            $check->invoke($this->manager(), $update, $installed, 'git-mod');
            $this->fail('Another module must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hash_id mismatch', $e->getMessage());
        }

        $write(array('name' => 'git-mod', 'hash_id' => str_repeat('a', 32), 'requires_core' => '>=99.0'));
        try {
            $check->invoke($this->manager(), $update, $installed, 'git-mod');
            $this->fail('A release for another core must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs core >=99.0', $e->getMessage());
        }
        $this->assertDirectoryExists($update, 'an update outside the modules directory is left to its caller');
    }

    public function testListModulesFlagsAModuleForAnotherCore(): void {
        $this->createModule('core-ok', '1.0.0');
        $this->createModule('core-ahead', '1.0.0');
        $manifest = json_decode((string) file_get_contents($this->modulesPath . '/core-ahead/module.json'), true);
        $manifest['requires_core'] = '>=99.0';
        file_put_contents($this->modulesPath . '/core-ahead/module.json', json_encode($manifest));

        $byName = $this->modulesByName();

        $this->assertSame([], $byName['core-ok']['dependency_warnings']);
        $this->assertCount(1, $byName['core-ahead']['dependency_warnings']);
        $this->assertStringContainsString('needs core >=99.0', $byName['core-ahead']['dependency_warnings'][0]);
    }

    public function testListModulesShowsOneRowWhenBareAndHashedCopiesCoexist(): void {
        // The platform flow used to extract into a bare `{name}` dir beside the
        // canonical `{name}_{hash5}` one; the table then listed the module twice.
        $this->createModuleWithDeps('dupe-mod', '1.0.0', []);
        $hashed = $this->modulesPath . '/dupe-mod_ab123';
        mkdir($hashed, 0775, true);
        copy($this->modulesPath . '/dupe-mod/module.json', $hashed . '/module.json');

        $names = array_column($this->manager()->listModules(), 'name');

        $this->assertSame(['dupe-mod'], $names);
    }

    // ── setState() disable guard ──────────────────────────────────────────

    public function testDisableBlockedByWorkingDependent(): void {
        $this->createModuleWithDeps('guard-base', '1.0.0', []);
        $this->createModuleWithDeps('guard-consumer', '1.0.0', ['guard-base']);
        $this->writeOverrides([
            'guard-base'     => ['installed_version' => '1.0.0'],
            'guard-consumer' => ['installed_version' => '1.0.0'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('guard-consumer');
        $this->manager()->setState('guard-base', ModuleState::Disabled);
    }

    public function testDisableAllowedWhenDependentIsAlreadyBrokenByDisabledDependency(): void {
        // Chain: top -> middle -> bottom. bottom is disabled, so the loader already
        // prunes middle AND top. Disabling middle breaks nothing and must succeed —
        // the guard used to count top as an enabled dependent and refuse.
        $this->createModuleWithDeps('chain-bottom', '1.0.0', []);
        $this->createModuleWithDeps('chain-middle', '1.0.0', ['chain-bottom']);
        $this->createModuleWithDeps('chain-top', '1.0.0', ['chain-middle']);
        $this->writeOverrides([
            'chain-bottom' => ['state' => 'disabled', 'installed_version' => '1.0.0'],
            'chain-middle' => ['installed_version' => '1.0.0'],
            'chain-top'    => ['installed_version' => '1.0.0'],
        ]);

        $this->manager()->setState('chain-middle', ModuleState::Disabled);

        $this->assertSame('disabled', $this->readOverrides()['chain-middle']['state'] ?? null);
    }

    public function testDisableAllowedWhenDependentIsItselfDisabled(): void {
        $this->createModuleWithDeps('solo-base', '1.0.0', []);
        $this->createModuleWithDeps('solo-consumer', '1.0.0', ['solo-base']);
        $this->writeOverrides([
            'solo-base'     => ['installed_version' => '1.0.0'],
            'solo-consumer' => ['state' => 'disabled', 'installed_version' => '1.0.0'],
        ]);

        $this->manager()->setState('solo-base', ModuleState::Disabled);

        $this->assertSame('disabled', $this->readOverrides()['solo-base']['state'] ?? null);
    }

    private function manager(): ModuleManager {
        return new ModuleManager($this->modulesPath, $this->overridesPath, ServiceContainer::getInstance());
    }

    /** @return array<string, array> listModules() keyed by module name. */
    private function modulesByName(): array {
        $byName = [];
        foreach ($this->manager()->listModules() as $module) {
            $byName[$module['name']] = $module;
        }
        return $byName;
    }

    /** Build a .tar holding one module under a `{name}/` prefix; returns its path. */
    private function makeModuleTar(string $name, string $version, string $hashId, string $requiresCore = '>=2.0'): string {
        $pascal   = $this->pascal($name);
        $manifest = $this->manifest($name, $version);
        $manifest['hash_id'] = $hashId;
        $manifest['requires_core'] = $requiresCore;

        $tarPath = $this->workDir . '/' . $name . '.tar';
        @unlink($tarPath);
        $tar = new PharData($tarPath);
        $tar->addFromString(
            $name . '/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $tar->addFromString($name . '/' . $pascal . 'Module.php',
            "<?php\n"
            . "namespace XcVm\Module\\{$pascal};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$pascal}Module extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '{$version}'; }\n"
            . "}\n"
        );
        unset($tar);

        return $tarPath;
    }

    /** Create a plain module whose manifest declares the given required dependencies. */
    private function createModuleWithDeps(string $name, string $version, array $dependencies): void {
        $dir       = $this->modulesPath . '/' . $name;
        $pascal    = $this->pascal($name);
        $className = $pascal . 'Module';
        $namespace = 'XcVm\\Module\\' . $pascal;
        mkdir($dir, 0775, true);

        $manifest = $this->manifest($name, $version);
        $manifest['dependencies'] = $dependencies;

        file_put_contents(
            $dir . '/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($dir . '/' . $className . '.php',
            "<?php\n"
            . "namespace {$namespace};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$className} extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '{$version}'; }\n"
            . "}\n"
        );
    }

    /** Create a plain module (no migrations) at the given version. */
    private function createModule(string $name, string $version): void {
        $dir       = $this->modulesPath . '/' . $name;
        $pascal    = $this->pascal($name);
        $className = $pascal . 'Module';
        $namespace = 'XcVm\\Module\\' . $pascal;
        mkdir($dir, 0775, true);

        file_put_contents(
            $dir . '/module.json',
            json_encode($this->manifest($name, $version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($dir . '/' . $className . '.php',
            "<?php\n"
            . "namespace {$namespace};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$className} extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '{$version}'; }\n"
            . "}\n"
        );
    }

    /**
     * Create a module that tracks migration calls via MigrationCallTracker.
     *
     * @param string   $name     Module name (kebab-case).
     * @param string   $version  Module version (reported by getVersion()).
     * @param string[] $migrationVersions Version strings to include as migration keys.
     */
    private function createMigratableModule(string $name, string $version, array $migrationVersions): void {
        $dir       = $this->modulesPath . '/' . $name;
        $pascal    = $this->pascal($name);
        $className = $pascal . 'Module';
        $namespace = 'XcVm\\Module\\' . $pascal;
        mkdir($dir, 0775, true);

        file_put_contents(
            $dir . '/module.json',
            json_encode($this->manifest($name, $version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $migLines = '';
        foreach ($migrationVersions as $ver) {
            $migLines .= "\t\t\t'{$ver}' => function(\$c) { \\MigrationCallTracker::record('{$ver}'); },\n";
        }

        file_put_contents($dir . '/' . $className . '.php',
            "<?php\n"
            . "namespace {$namespace};\n"
            . "use XcVm\Core\Module\BaseModule;\n"
            . "class {$className} extends BaseModule {\n"
            . "\tpublic function getName(): string { return '{$name}'; }\n"
            . "\tpublic function getVersion(): string { return '{$version}'; }\n"
            . "\tpublic function getMigrations(): array {\n"
            . "\t\treturn [\n"
            . $migLines
            . "\t\t];\n"
            . "\t}\n"
            . "}\n"
        );
    }

    private function manifest(string $name, string $version): array {
        return [
            'name'                  => $name,
            'description'           => 'migration test module',
            'version'               => $version,
            'requires_core'         => '>=2.0',
            'environment'           => 'main',
            'dependencies'          => [],
            'optional_dependencies' => [],
            'has_navbar'            => false,
            'has_settings'          => false,
            'priority'              => 0,
        ];
    }

    private function writeOverrides(array $overrides): void {
        $content = "<?php\n\nreturn " . var_export($overrides, true) . ";\n";
        file_put_contents($this->overridesPath, $content);
    }

    private function readOverrides(): array {
        if (!file_exists($this->overridesPath)) {
            return [];
        }
        $data = require $this->overridesPath;
        return is_array($data) ? $data : [];
    }

    private function pascal(string $name): string {
        return implode('', array_map('ucfirst', explode('-', $name)));
    }

    private function deleteDir(string $path): void {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteDir($path . '/' . $item);
        }
        @rmdir($path);
    }
}
