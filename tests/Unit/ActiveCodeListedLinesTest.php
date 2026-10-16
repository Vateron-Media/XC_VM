<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Line\ActiveCodeService;

/**
 * An activation code makes a line of its own, kept switched off until the code
 * is redeemed. The Lines tables left every such line out, so once a subscriber
 * had redeemed a code (a trial's among them) the line could be found under
 * Active Codes only. They list a code's line from the moment it is redeemed:
 * only the ones that still wait are stock, not subscribers.
 */
final class ActiveCodeListedLinesTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `lines` (`id` int PRIMARY KEY, `is_activecode` tinyint NULL, `is_trial` tinyint DEFAULT 0, `exp_date` int NULL)');
		$this->rDb->exec('CREATE TABLE `activation_codes` (`id` int PRIMARY KEY AUTO_INCREMENT, `subscriber_id` int NOT NULL DEFAULT 0, `activated_at` int NULL)');
		$this->rDb->exec('INSERT INTO `lines` (`id`, `is_activecode`, `is_trial`, `exp_date`) VALUES'
			. ' (1, 0, 0, 1900000000),'     // a line made on the Lines page
			. ' (2, NULL, 1, 1900000000),'  // a trial made there, before the column had a default
			. ' (3, 1, 0, NULL),'           // an official code nobody redeemed
			. ' (4, 1, 1, NULL),'           // a trial code nobody redeemed
			. ' (5, 1, 1, 1900000000),'     // a trial code, redeemed
			. ' (6, 1, 0, NULL),'           // a redeemed code of a package with no expiry
			. ' (7, 1, 0, 1900000000)');    // a code's line given a date by hand
		$this->rDb->exec('INSERT INTO `activation_codes` (`subscriber_id`, `activated_at`) VALUES (3, NULL), (4, NULL), (5, 1800000000), (6, 1800000000), (7, NULL)');
	}

	public function testALineIsListedFromTheMomentItsCodeIsRedeemed(): void {
		$this->rDb->query('SELECT `id` FROM `lines` WHERE ' . ActiveCodeService::LISTED_LINES . ' ORDER BY `id`');
		$this->assertSame([1, 2, 5, 6, 7], array_map('intval', array_column($this->rDb->get_rows(), 'id')));
	}

	/** The condition is lineAwaitsRedemption() in SQL: the two never disagree. */
	public function testItListsExactlyTheLinesThatDoNotAwaitRedemption(): void {
		$rService = new ReflectionProperty(ActiveCodeService::class, 'db');
		$rWas = $rService->getValue();
		$rService->setValue(null, $this->rDb);
		try {
			$this->rDb->query('SELECT `id` FROM `lines` WHERE ' . ActiveCodeService::LISTED_LINES);
			$rListed = array_map('intval', array_column($this->rDb->get_rows(), 'id'));
			$this->rDb->query('SELECT * FROM `lines`');
			foreach ($this->rDb->get_rows() as $rLine) {
				$this->assertSame(!ActiveCodeService::lineAwaitsRedemption($rLine), in_array((int) $rLine['id'], $rListed, true), 'line ' . $rLine['id']);
			}
		} finally {
			$rService->setValue(null, $rWas);
		}
	}

	/** Both Lines tables, the administrator's and the reseller's, take the one rule. */
	public function testBothLinesTablesUseIt(): void {
		foreach (['Public/Controllers/Admin/TableController.php', 'Public/Controllers/Reseller/ResellerTableController.php'] as $rFile) {
			$rSource = (string) file_get_contents(MAIN_HOME . $rFile);
			$this->assertStringContainsString('ActiveCodeService::LISTED_LINES', $rSource, $rFile);
			$this->assertStringNotContainsString('`is_activecode` = 0 OR `lines`.`is_activecode` IS NULL)', $rSource, $rFile);
		}
	}
}
