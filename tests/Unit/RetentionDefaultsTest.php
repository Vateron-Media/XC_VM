<?php

use PHPUnit\Framework\TestCase;

/**
 * A new install keeps activity and client logs 180 days and login, error and
 * restart logs a year, each a value the settings form offers. The form's
 * option that keeps 196 days says so, and a true 28 days is offered.
 */
final class RetentionDefaultsTest extends TestCase {
	/** @return array<string, string> the install's settings row, column => value as written */
	private static function installSettings(): array {
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/INSERT INTO `settings` \((.*?)\) VALUES\s*\((.*?)\);\s*$/ms', $rSql, $rMatch);
		$rColumns = array_map(static fn(string $rColumn): string => trim($rColumn, " `\n"), explode(',', $rMatch[1]));
		$rValues = array_map('trim', str_getcsv($rMatch[2], ',', "'", '\\'));
		return array_combine($rColumns, $rValues);
	}

	/** @return array<int|string, string> the keep periods the form offers, seconds => label */
	private static function formOptions(): array {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		preg_match('/\$rLogKeepOptions = \[(.*?)\];/s', $rView, $rMatch);
		preg_match_all('/(\d+)\s*=>\s*"([^"]+)"/', $rMatch[1], $rOptions);
		return array_combine(array_map('intval', $rOptions[1]), $rOptions[2]);
	}

	public function testANewInstallKeepsLogsFor180DaysOrAYear(): void {
		$rSettings = self::installSettings();
		$rOptions = self::formOptions();
		foreach (['keep_activity' => 15552000, 'keep_client' => 15552000, 'keep_login' => 31536000, 'keep_errors' => 31536000, 'keep_restarts' => 31536000] as $rKey => $rSeconds) {
			$this->assertSame((string) $rSeconds, $rSettings[$rKey], $rKey);
			$this->assertArrayHasKey($rSeconds, $rOptions, $rKey . ' is a choice of the form');
		}
	}

	public function testEachOptionSaysHowLongItKeeps(): void {
		$rOptions = self::formOptions();
		$this->assertSame('28 Days', $rOptions[2419200]);
		$this->assertSame('196 Days', $rOptions[16934400]);
		foreach ($rOptions as $rSeconds => $rLabel) {
			if (preg_match('/^(\d+) (Hour|Day)s?$/', $rLabel, $rMatch)) {
				$this->assertSame((int) $rMatch[1] * ($rMatch[2] === 'Hour' ? 3600 : 86400), $rSeconds, $rLabel);
			}
		}
	}
}
