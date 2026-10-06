<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Public\Controllers\Player\ListingsController;

/**
 * The web player's `listings` endpoint answers with JSON and nothing else: it
 * declares that before it prints anything, and the channel id it gives back is
 * a number whatever the request carried.
 */
final class AuditPlayerListingsTest extends TestCase {
	private array $rRequest;
	private string $rTimezone;

	protected function setUp(): void {
		$this->rRequest = RequestManager::getAll();
		$this->rTimezone = date_default_timezone_get(); // index() sets the viewer's
		$GLOBALS['rUserInfo'] = ['channel_ids' => [7]];
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		date_default_timezone_set($this->rTimezone);
		unset($GLOBALS['rUserInfo']);
	}

	private function listings(array $rRequest): string {
		RequestManager::set($rRequest);
		ob_start();
		try {
			(new ListingsController())->index();
		} finally {
			$rOut = (string) ob_get_clean();
		}
		return $rOut;
	}

	public function testTheChannelIdComesBackAsANumber(): void {
		$rOut = $this->listings(['id' => '8<img src=x>']);

		$this->assertSame(8, json_decode($rOut, true)['id']);
		$this->assertStringNotContainsString('<', $rOut);
	}

	public function testTheResponseIsDeclaredAsJsonBeforeAnyOutput(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Player/ListingsController.php');
		$rBody = substr($rSource, (int) strpos($rSource, 'public function index()'));

		$rType = strpos($rBody, "header('Content-Type: application/json; charset=utf-8');");
		$rSniff = strpos($rBody, "header('X-Content-Type-Options: nosniff');");
		$this->assertNotFalse($rType, 'no JSON content type');
		$this->assertNotFalse($rSniff, 'the browser may still guess the type');

		// A timezone PHP does not know makes date_default_timezone_set() report it.
		$rFirstOutput = min(strpos($rBody, 'date_default_timezone_set('), strpos($rBody, 'echo '));
		$this->assertLessThan($rFirstOutput, max($rType, $rSniff), 'the headers come after output may have started');
	}
}
