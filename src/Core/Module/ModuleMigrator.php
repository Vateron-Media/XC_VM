<?php

namespace XcVm\Core\Module;

/**
 * ModuleMigrator — file-based database migrations for modules.
 *
 * Each module ships its schema in a `migrations/` sub-directory as paired
 * version files:
 *
 *     Modules/<name>/migrations/<semver>.up.sql    — apply (CREATE/ALTER/INSERT)
 *     Modules/<name>/migrations/<semver>.down.sql  — reverse (DROP/ALTER)
 *
 * Migrations are applied in ascending semver order on install/update and
 * reversed in descending order on uninstall. The module's recorded
 * installed_version (config/modules.php) is the watermark — no separate
 * per-module tracking table is needed:
 *
 *   install   →  up(null, targetVersion)        (every up file ≤ target)
 *   update    →  up(fromVersion, targetVersion) (up files in (from, target])
 *   uninstall →  down(installedVersion)          (every down file ≤ installed, desc)
 *
 * Statement splitting mirrors {@see \XcVm\Core\Database\MigrationRunner}: the
 * file is split on `;`, lines beginning with `--` are stripped, and each
 * remaining statement is executed individually. A failing statement throws so
 * the caller's install transaction rolls back.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleMigrator {

    /**
     * Apply `up` migrations for versions in the (`$from`, `$to`] range.
     *
     * @param string      $modulePath Absolute path of the module directory.
     * @param object      $db         Database handler (query()/affected API).
     * @param string|null $from       Already-applied version, or null for a fresh install.
     * @param string      $to         Target version (inclusive).
     * @return string[] Versions whose up migration ran, in apply order.
     */
    public static function up(string $modulePath, object $db, ?string $from, string $to): array {
        $applied = [];
        foreach (self::discover($modulePath, 'up') as [$version, $file]) {
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
     * Apply `down` migrations for every version ≤ `$upTo`, newest first.
     *
     * @param string $modulePath Absolute path of the module directory.
     * @param object $db         Database handler.
     * @param string $upTo       Highest version to reverse (inclusive).
     * @return string[] Versions whose down migration ran, in apply order.
     */
    public static function down(string $modulePath, object $db, string $upTo): array {
        $migrations = self::discover($modulePath, 'down');
        usort($migrations, static fn($a, $b) => version_compare($b[0], $a[0])); // descending

        $applied = [];
        foreach ($migrations as [$version, $file]) {
            if (version_compare($version, $upTo, '>')) {
                continue;
            }
            self::runFile($db, $file);
            $applied[] = $version;
        }
        return $applied;
    }

    /**
     * Whether the module ships any migration files at all.
     */
    public static function has(string $modulePath): bool {
        $dir = $modulePath . '/migrations';
        return is_dir($dir) && !empty(glob($dir . '/*.{up,down}.sql', GLOB_BRACE));
    }

    /**
     * Discover migration files of one direction, sorted ascending by version.
     *
     * @return array<int, array{0: string, 1: string}> [version, absolute path]
     */
    private static function discover(string $modulePath, string $direction): array {
        $dir = $modulePath . '/migrations';
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob($dir . '/*.' . $direction . '.sql') ?: [] as $file) {
            $version = basename($file, '.' . $direction . '.sql');
            // Accept dotted numeric semver only (e.g. 1.0.0, 2.1); ignore anything else.
            if (preg_match('/^\d+(?:\.\d+)*$/', $version)) {
                $out[] = [$version, $file];
            }
        }
        usort($out, static fn($a, $b) => version_compare($a[0], $b[0])); // ascending
        return $out;
    }

    /**
     * Execute every statement in a migration file. Throws on the first failure.
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
