<?php

namespace XcVm\Tests\Support;

/**
 * Tables of the install schema (src/bin/install/database.sql) and the
 * migrations, exactly as production creates them, so a test works against
 * every column, key and type a real install has.
 */
final class InstallSchema {
	/** The `servers` table's CREATE statement. */
	public static function serversTable(): string {
		return self::table('servers');
	}

	/** Any table's CREATE statement from the install schema. */
	public static function table(string $rTable): string {
		preg_match('/CREATE TABLE IF NOT EXISTS (`' . $rTable . '` \(.*?\) ENGINE=[^;]*);/s', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rMatch);
		return 'CREATE TABLE ' . ($rMatch[1] ?? '`' . $rTable . '` (missing)');
	}

	/**
	 * Every column of a table: the install schema's and the migrations'.
	 *
	 * @return list<string>
	 */
	public static function columns(string $rTable): array {
		preg_match_all('/^\s*`(\w+)` /m', self::body($rTable), $rCols);
		$rOut = $rCols[1];
		foreach (glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: [] as $rFile) {
			$rSql = (string) file_get_contents($rFile);
			if (str_contains($rSql, 'ALTER TABLE `' . $rTable . '`')) {
				preg_match_all('/ADD COLUMN (?:IF NOT EXISTS )?`(\w+)`/', $rSql, $rAdd);
				$rOut = array_merge($rOut, $rAdd[1]);
			}
		}
		return array_values(array_unique($rOut));
	}

	/**
	 * Every column of `servers`: the install schema's and the migrations'.
	 *
	 * @return list<string>
	 */
	public static function serverColumns(): array {
		return self::columns('servers');
	}

	/** A migration's DDL (`up/<name>.sql`). */
	public static function migration(string $rName): string {
		return (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
	}

	private static function body(string $rTable): string {
		preg_match('/CREATE TABLE IF NOT EXISTS `' . $rTable . '` \((.*?)\) ENGINE=/s', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rMatch);
		return $rMatch[1] ?? '';
	}
}
