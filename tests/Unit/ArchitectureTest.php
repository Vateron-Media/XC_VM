<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Architecture guard — enforces structural rules for src/modules/.
 *
 * Rules checked here are invariants that must never regress:
 *   1. No module file may use ServiceContainer::getInstance() (Service Locator anti-pattern).
 *   2. No web-context module file may use `global $db` (DI boundary violation).
 *   3. Every module entry-point (*Module.php) must declare XcVm\Module\{Pascal} namespace.
 *   4. No `new DatabaseHandler()` outside DatabaseFactory, DatabaseStage,
 *      migrations and MAIN-only files; in the code a load balancer runs, no
 *      MySQL or Redis connect but behind ConnectAudit::guard() (cluster plan,
 *      section 10).
 *
 * Explicit exemptions are listed with a comment explaining WHY and referencing
 * the roadmap item that will eventually remove the exemption.
 */
#[Group('skip-on-panel')]
final class ArchitectureTest extends TestCase {

    private const MODULES_DIR = MAIN_HOME . 'Modules';

    private const SRC_DIR = MAIN_HOME . '.';

    protected function setUp(): void {
        // Static source guard: scans the repo's src/Modules tree. A flat panel
        // deployment (/home/xc_vm) has no such path and its module tree may hold
        // installed marketplace modules, so this only runs in the repo layout.
        if (!is_dir(self::MODULES_DIR)) {
            $this->markTestSkipped('module architecture guard runs only in the repo layout');
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Yields [relative-path => content] for every .php file under src/modules/,
     * optionally skipping top-level module subdirectories by name.
     *
     * @param string[] $excludeModuleDirs  Top-level dir names to skip (e.g. ['ministra']).
     * @return iterable<string, string>
     */
    private function moduleFiles(array $excludeModuleDirs = []): iterable {
        $baseReal = realpath(self::MODULES_DIR);

        $excludeReal = [];
        foreach ($excludeModuleDirs as $dir) {
            $p = realpath(self::MODULES_DIR . '/' . $dir);
            if ($p !== false) {
                $excludeReal[] = $p;
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = (string) $file->getRealPath();

            foreach ($excludeReal as $excluded) {
                if (str_starts_with($realPath, $excluded . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }

            $relative = substr($realPath, strlen($baseReal) + 1);
            yield $relative => (string) file_get_contents($realPath);
        }
    }

    /**
     * Resolve a module's on-disk directory basename by its canonical manifest name.
     *
     * Module directories use the `{name}_{hash5}` convention, so the basename is not
     * the canonical name. Tests that need a specific module must resolve it via its
     * module.json `name`, never by assuming the directory is named after the module.
     */
    private function moduleDirBasename(string $canonicalName): ?string {
        $dirs = new FilesystemIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS);
        foreach ($dirs as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }
            $manifest = $entry->getRealPath() . '/module.json';
            if (!is_file($manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && ($data['name'] ?? null) === $canonicalName) {
                return $entry->getBasename();
            }
        }
        return null;
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /**
     * No module file may resolve the container via the static singleton.
     * Modules receive ServiceContainer through boot(ServiceContainer $c).
     */
    public function testNoModuleUsesServiceLocator(): void {
        // Zero committed modules is a valid state (see testEveryModuleDirectory
        // HasManifest): the loop may find nothing to check — that is a pass.
        $this->addToAssertionCount(1);

        foreach ($this->moduleFiles() as $relative => $content) {
            $this->assertStringNotContainsString(
                'ServiceContainer::getInstance()',
                $content,
                "modules/{$relative} must not call ServiceContainer::getInstance() — use boot(ServiceContainer \$c) instead"
            );
        }
    }

    /**
     * Web-context module files must not use `global $db`.
     *
     * Exemptions:
     * - ministra/ — isolated subsystem with its own portal bootstrap (permanent)
     */
    public function testNoWebContextModuleUsesGlobalDb(): void {
        $ministraDir = $this->moduleDirBasename('ministra');
        $exclude     = $ministraDir !== null ? [$ministraDir] : [];

        // May legitimately find nothing to check (every non-exempt module
        // extracted to its own repo) — that is a pass, not a risky test.
        $this->addToAssertionCount(1);

        foreach ($this->moduleFiles(excludeModuleDirs: $exclude) as $relative => $content) {
            $this->assertStringNotContainsString(
                'global $db',
                $content,
                "modules/{$relative} must not use global \$db — inject via boot(ServiceContainer \$c)"
            );
        }
    }

    /**
     * Every module entry-point (*Module.php) must declare the canonical namespace.
     *
     * Convention: XcVm\Module\{Pascal} where Pascal = PascalCase of the canonical
     * manifest name (module.json `name`), NOT the `{name}_{hash5}` directory basename.
     * Non-entry-point files (controllers, services, cron) are not yet required — see R4-3.
     */
    public function testModuleEntryPointsHaveCorrectNamespace(): void {
        $modulesDir = new FilesystemIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS);

        $checked = 0;
        foreach ($modulesDir as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }

            $manifest = $entry->getRealPath() . '/module.json';
            if (!is_file($manifest)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($manifest), true);
            $name = is_array($data) ? (string) ($data['name'] ?? '') : '';
            if ($name === '') {
                continue;
            }

            $pascal     = implode('', array_map('ucfirst', explode('-', $name)));
            $moduleFile = $entry->getRealPath() . '/' . $pascal . 'Module.php';

            if (!file_exists($moduleFile)) {
                continue;
            }

            $expected = "namespace XcVm\\Module\\{$pascal};";
            $content  = (string) file_get_contents($moduleFile);

            $this->assertStringContainsString(
                $expected,
                $content,
                "{$pascal}Module.php must declare `{$expected}`"
            );

            $checked++;
        }

        // Zero committed modules is valid: modules may be git-source (installed
        // at runtime) and ministra now lives in core (src/Ministra). Finding
        // none to check is a pass, not a misconfigured MODULES_DIR.
        $this->addToAssertionCount(1);
    }

    /**
     * Paths under src/ the load balancer build strips (the Makefile's
     * LB_DIRS_TO_REMOVE and LB_FILES_TO_REMOVE): MAIN-only code.
     *
     * @return list<string>
     */
    private function mainOnlyPaths(): array {
        $makefile = (string) file_get_contents(self::SRC_DIR . '/../Makefile');
        $paths = [];
        foreach (['LB_DIRS_TO_REMOVE', 'LB_FILES_TO_REMOVE'] as $list) {
            $this->assertMatchesRegularExpression('/^' . $list . '\s*:?=/m', $makefile, $list . ' is in the Makefile');
            preg_match('/^' . $list . '\s*:?=(.*?)(?:\n\s*\n|\n[A-Z_]+\s*:?=)/ms', $makefile, $match);
            foreach (preg_split('/[\s\\\\]+/', $match[1] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $path) {
                $paths[] = $path;
            }
        }
        $this->assertNotEmpty($paths);
        return $paths;
    }

    /**
     * Every .php file under src/ outside vendor/, as [path relative to src/ =>
     * code without comments].
     *
     * @return iterable<string, string>
     */
    private function srcFiles(): iterable {
        $base = (string) realpath(self::SRC_DIR);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($base) + 1);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'vendor/')) {
                continue;
            }
            yield $relative => $this->withoutComments((string) file_get_contents($file->getPathname()));
        }
    }

    /** PHP source without its comments, which may name what the code must not do. */
    private function withoutComments(string $source): string {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** PHP code with each string literal emptied: a message may name a client ("a new Redis password"). */
    private function withoutStrings(string $code): string {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out .= "''";
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** @param list<string> $mainOnly */
    private function isMainOnly(string $relative, array $mainOnly): bool {
        foreach ($mainOnly as $path) {
            if ($relative === $path || str_starts_with($relative, rtrim($path, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cluster plan, section 10: every eager connect to MAIN's MySQL starts in
     * DatabaseFactory or the boot's DatabaseStage. A direct
     * `new DatabaseHandler()` is one more connect site to find; outside those
     * two it is allowed only in migrations and MAIN-only files (the ones the
     * load balancer build strips).
     */
    public function testNoDirectDatabaseHandlerOutsideTheFactory(): void {
        if (!is_file(self::SRC_DIR . '/../Makefile')) {
            $this->markTestSkipped('needs the repo\'s Makefile (the LB build lists)');
        }
        $mainOnly = $this->mainOnlyPaths();
        $allowed = ['Infrastructure/Database/DatabaseFactory.php', 'Core/Bootstrap/Stage/DatabaseStage.php'];
        $offenders = [];
        foreach ($this->srcFiles() as $relative => $content) {
            if (in_array($relative, $allowed, true) || preg_match('#(^|/)migrations?(/|_)#', $relative) || $this->isMainOnly($relative, $mainOnly)) {
                continue;
            }
            if (preg_match('/\bnew\s+\\\\?(?:XcVm\\\\Core\\\\Database\\\\)?(?:Database|DatabaseHandler)\b\s*[(;]/', $content)) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders, 'use DatabaseFactory::open() (or connect()/connectLazy()) instead of `new DatabaseHandler()`');
    }

    /**
     * The lower-level MySQL and Redis connects, each with the one file that
     * may open it, behind ConnectAudit::guard() (null: none may). PHP's class
     * and function names are case-insensitive, and a global-namespace file
     * (Public/stream/*.php) needs no leading backslash, so the patterns take
     * both forms.
     */
    private const LOW_LEVEL_CONNECTS = [
        '/(?<![\w\\\\])\\\\?XC_VM::db_connect\s*\(/i' => 'Core/Database/Database.php',
        '/\bnew\s+\\\\?PDO\b|(?<![\w\\\\])\\\\?PDO::connect\s*\(/i' => 'Core/Database/Database.php',
        '/(?<![\w\\\\])\\\\?XC_VM::redis_connect\s*\(/i' => 'Infrastructure/Redis/RedisManager.php',
        '/\bnew\s+\\\\?Redis\b/i' => 'Core/Cache/RedisCache.php',
        '/\bnew\s+\\\\?mysqli\b|(?<![\w\\\\])\\\\?mysqli_(?:connect|real_connect|init)\s*\(/i' => null,
    ];

    /**
     * The lower-level connects, in the code a load balancer runs: \XC_VM's
     * database and Redis connects, and PDO, \Redis and mysqli clients, only
     * where ConnectAudit::guard() stands in front of them (a node in mode 2
     * is refused there, and modes 1 and 2 count every attempt).
     */
    public function testLbShippedCodeConnectsOnlyBehindTheGuard(): void {
        if (!is_file(self::SRC_DIR . '/../Makefile')) {
            $this->markTestSkipped('needs the repo\'s Makefile (the LB build lists)');
        }
        $mainOnly = $this->mainOnlyPaths();
        foreach ($this->srcFiles() as $relative => $content) {
            if ($this->isMainOnly($relative, $mainOnly)) {
                continue;
            }
            $code = $this->withoutStrings($content);
            foreach (self::LOW_LEVEL_CONNECTS as $pattern => $home) {
                if ($relative !== $home) {
                    $this->assertDoesNotMatchRegularExpression($pattern, $code, $relative . ': ' . ($home === null ? 'no such connect on a load balancer' : 'connect through ' . $home));
                }
            }
        }
        foreach (array_unique(array_filter(self::LOW_LEVEL_CONNECTS)) as $home) {
            $this->assertStringContainsString('ConnectAudit::guard(', (string) file_get_contents(self::SRC_DIR . '/' . $home), $home);
        }
    }

    /** The patterns above catch each way PHP can spell those connects, and nothing else. */
    public function testTheConnectPatternsCatchEveryForm(): void {
        $caught = [
            '\\XC_VM::db_connect();', 'XC_VM::db_connect();', '\\xc_vm::DB_CONNECT ($a);',
            '\\XC_VM::redis_connect();', 'xc_vm::redis_connect();',
            'new \\PDO($dsn);', 'new pdo($dsn);', 'new \\Pdo\\Mysql($dsn);', '\\PDO::connect($dsn);',
            'new \\Redis();', 'new redis;', 'new \\REDIS ;',
            'new mysqli(\'h\');', 'new \\MySQLi;', 'mysqli_connect(\'h\');', '\\mysqli_real_connect($m, \'h\');', 'MYSQLI_INIT();',
        ];
        $clean = [
            'new RedisCache($host);', 'RedisManager::connect();', 'new PDOStatement();', '$rDb->db_connect();',
            'Database::db_connect();', 'Other\\XC_VM::db_connect();', 'MyXC_VM::redis_connect();', '$m->real_connect();',
        ];
        foreach ($caught as $code) {
            $hits = array_filter(array_keys(self::LOW_LEVEL_CONNECTS), static fn(string $pattern): bool => preg_match($pattern, '<?php ' . $code) === 1);
            $this->assertCount(1, $hits, $code);
        }
        foreach ($clean as $code) {
            foreach (array_keys(self::LOW_LEVEL_CONNECTS) as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, '<?php ' . $code, $code);
            }
        }
    }

    /**
     * Sanity: every module directory must contain a module.json manifest.
     *
     * Catches accidental directory clutter in src/modules/.
     */
    public function testEveryModuleDirectoryHasManifest(): void {
        $modulesDir = new FilesystemIterator(self::MODULES_DIR, FilesystemIterator::SKIP_DOTS);

        $checked = 0;
        foreach ($modulesDir as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }

            $manifest = $entry->getRealPath() . '/module.json';
            $this->assertFileExists(
                $manifest,
                "modules/{$entry->getBasename()}/ must contain a module.json manifest"
            );
            $checked++;
        }

        // As above: zero committed module directories is a valid state.
        $this->addToAssertionCount(1);
    }
}
