<?php

namespace XcVm\Core\Module;

/**
 * ModuleMigrator — file-based database schema for modules.
 *
 * A module ships its schema the same way core does — one master file plus a
 * folder of forward version deltas, and a single teardown file:
 *
 *     Modules/<name>/database.sql              — master schema (full current CREATE/seed)
 *     Modules/<name>/database_drop.sql         — teardown (DROP every table the module owns)
 *     Modules/<name>/migrations/<semver>.sql   — forward delta applied between versions
 *
 * The three roles map onto the lifecycle:
 *
 *   install   →  database.sql                       (fresh install = the current schema)
 *   update    →  migrations/<semver>.sql in (from, to]   (only the deltas since installed)
 *   uninstall →  database_drop.sql                  (one file drops everything)
 *
 * The master `database.sql` must always reflect the LATEST schema (every delta
 * folded in), exactly like core's `bin/install/database.sql`. Fresh installs run
 * ONLY the master; the recorded installed_version (config/modules.php) is the
 * watermark, so the deltas never replay. Migrations exist purely to upgrade
 * panels installed on an older version. There is a fallback: a module that ships
 * no master (delta-only) still installs by replaying every delta ≤ target.
 *
 * Statement splitting mirrors {@see \XcVm\Core\Database\MigrationRunner}: the
 * file is split on `;`, lines beginning with `--` are stripped, and each
 * remaining statement is executed individually. A failing statement throws.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleMigrator {

    /**
     * Apply the module's master schema on a fresh install.
     *
     * Runs `database.sql` (the full current schema) when present. A module that
     * ships no master but only version deltas falls back to replaying every
     * `migrations/<semver>.sql` up to `$to` — this keeps delta-only modules
     * installable.
     *
     * @param string $modulePath Absolute path of the module directory.
     * @param object $db         Database handler (query() API).
     * @param string $to         Target version (used only by the delta fallback).
     * @return string[] What ran: `['database.sql']`, or the replayed delta versions.
     */
    public static function install(string $modulePath, object $db, string $to): array {
        $master = $modulePath . '/database.sql';
        if (is_file($master)) {
            self::runFile($db, $master);
            return ['database.sql'];
        }
        // No master schema — replay forward deltas up to the target version.
        return self::up($modulePath, $db, null, $to);
    }

    /**
     * Tear the module's schema down on uninstall.
     *
     * Runs the single `database_drop.sql` when present. No-op if the module ships
     * no teardown file.
     *
     * @param string $modulePath Absolute path of the module directory.
     * @param object $db         Database handler.
     * @return string[] `['database_drop.sql']` if it ran, otherwise `[]`.
     */
    public static function uninstall(string $modulePath, object $db): array {
        $drop = $modulePath . '/database_drop.sql';
        if (is_file($drop)) {
            self::runFile($db, $drop);
            return ['database_drop.sql'];
        }
        return [];
    }

    /**
     * Apply forward version deltas for versions in the (`$from`, `$to`] range.
     *
     * Used on update to bring an already-installed panel up to the current
     * schema. Deltas are forward-only — teardown is handled by database_drop.sql.
     *
     * @param string      $modulePath Absolute path of the module directory.
     * @param object      $db         Database handler.
     * @param string|null $from       Already-applied version, or null to run all ≤ $to.
     * @param string      $to         Target version (inclusive).
     * @return string[] Versions whose delta ran, in apply order.
     */
    public static function up(string $modulePath, object $db, ?string $from, string $to): array {
        $applied = [];
        foreach (self::discover($modulePath) as [$version, $file]) {
            if ($from !== null && version_compare($version, $from, '<=')) {
                continue;
            }
            if (version_compare($version, $to, '>')) {
                continue;
            }
            self::runFile($db, $file);
            $applied[] = $version;
        }
        return $applied;
    }

    /**
     * Whether the module ships any schema files at all (master, teardown, or deltas).
     */
    public static function has(string $modulePath): bool {
        return is_file($modulePath . '/database.sql')
            || is_file($modulePath . '/database_drop.sql')
            || !empty(self::discover($modulePath));
    }

    /**
     * Discover forward delta files, sorted ascending by version.
     *
     * Files are `migrations/<semver>.sql` (forward-only; no `.up`/`.down` suffix —
     * teardown lives in database_drop.sql). Anything not matching dotted-numeric
     * semver is ignored.
     *
     * @return array<int, array{0: string, 1: string}> [version, absolute path]
     */
    private static function discover(string $modulePath): array {
        $dir = $modulePath . '/migrations';
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            // Accept dotted numeric semver only (e.g. 1.0.0, 2.1); ignore anything else.
            if (preg_match('/^\d+(?:\.\d+)*$/', $version)) {
                $out[] = [$version, $file];
            }
        }
        usort($out, static fn($a, $b) => version_compare($a[0], $b[0])); // ascending
        return $out;
    }

    /**
     * Execute every statement in a SQL file. Throws on the first failure.
     */
    private static function runFile(object $db, string $file): void {
        $sql = trim((string) file_get_contents($file));
        if ($sql === '') {
            return;
        }

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            // Drop comment-only lines so the residual statement is real SQL.
            $lines = array_filter(
                explode("\n", $statement),
                static fn($line) => strpos(ltrim($line), '--') !== 0
            );
            $statement = trim(implode("\n", $lines));
            if ($statement === '') {
                continue;
            }
            if (!$db->query($statement . ';')) {
                throw new \RuntimeException(
                    'Module migration failed in ' . basename($file) . ': ' . $statement
                );
            }
        }
    }
}
