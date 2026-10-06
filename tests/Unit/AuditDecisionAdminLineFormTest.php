<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\LineController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The admin line form shows the line as stored: it escapes what it prints
 * and posts it back, so a value read through the row cleaner ('&lt;' for
 * '<') would come back and be saved escaped.
 */
final class AuditDecisionAdminLineFormTest extends TestCase {
	private mixed $rDb;

	protected function setUp(): void {
		$this->rDb = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $rDb = new TestDb();
		$rDb->exec(InstallSchema::table('lines'));
		$rDb->query("INSERT INTO `lines` (`id`, `username`, `password`, `admin_notes`) VALUES (5, 'us<er>', 'pa<ss> & co', 'a <b>note</b>')");
	}

	protected function tearDown(): void {
		$GLOBALS['db'] = $this->rDb;
	}

	/**
	 * The page prints the stored row: every value of it it prints is escaped
	 * (htmlspecialchars), a number (an int cast, a date) or only decides what
	 * is printed (a checked or selected attribute, an empty test); no layout
	 * prints the row.
	 */
	public function testThePagePrintsNoValueOfTheLineUnescaped(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/line.php');
		preg_match_all('/<\?=(.*?)\?>/s', $rView, $rPrints);
		$rChecked = 0;
		foreach ($rPrints[1] as $rPrint) {
			if (!str_contains($rPrint, '$rLine[')) {
				continue;
			}
			$rChecked++;
			$this->assertMatchesRegularExpression("/htmlspecialchars\\(|\\(int\\) \\\$rLine\\[|date\\(|!empty\\(\\\$rLine\\[|\\? '(checked|selected)'/", $rPrint, 'printed as it is: ' . trim($rPrint));
		}
		$this->assertGreaterThan(10, $rChecked);
		foreach (glob(MAIN_HOME . 'Public/Views/layouts/*/*.php') ?: [] as $rLayout) {
			$this->assertStringNotContainsString('$rLine', (string) file_get_contents($rLayout), basename(dirname($rLayout)) . '/' . basename($rLayout));
		}
	}

	public function testTheFormGetsTheLineAsStored(): void {
		$rLine = LineController::storedLine('5');

		$this->assertSame(['us<er>', 'pa<ss> & co', 'a <b>note</b>'], [$rLine['username'], $rLine['password'], $rLine['admin_notes']]);
		$this->assertNull(LineController::storedLine('6'));
		$this->assertNull(LineController::storedLine('x'));
	}
}
