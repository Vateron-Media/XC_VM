<?php

use PHPUnit\Framework\TestCase;

/**
 * Saving the bouquet order from the web player rearranges the bouquets a line
 * has and nothing more: the line ends with exactly the bouquets it started
 * with, only when the panel allows ordering, and only for a line of this panel.
 *
 * The controllers answer and exit (or go on to draw a page), so each save runs
 * in a child PHP against its own empty database.
 */
final class AuditPlayerBouquetOrderTest extends TestCase {
	private const V1 = '(new \XcVm\Public\Controllers\Player\PlayerProfileController())->index();';
	private const V2 = '(new \XcVm\Public\Controllers\PlayerV2\ProfileController())->saveBouquets();';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-player-bouquets-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run $rCall with the request's order $rOrder as the line $rUserInfo, the line 7 holding the
	 * bouquets $rHeld, and return what it printed and the stored bouquets by line id.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private function request(string $rCall, mixed $rOrder, array $rUserInfo, int $rAllowed, string $rHeld): array {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(E_ERROR | E_PARSE);'
			. '$db = new TestDb();'
			. '$db->exec(\XcVm\Tests\Support\InstallSchema::table("lines"));'
			. '$db->exec(\XcVm\Tests\Support\InstallSchema::table("bouquets"));'
			. '$db->query("INSERT INTO `lines` (`id`, `bouquet`) VALUES (7, ?), (999999, ?)", ' . var_export($rHeld, true) . ', "[1]");'
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. 'define("CACHE_TMP_PATH", ' . var_export($this->rDir, true) . ');'
			. '$rServers = [1 => ["is_main" => 1]];'
			. '\XcVm\Core\Config\SettingsManager::set(["player_allow_bouquet" => ' . $rAllowed . ']);'
			. '$rUserInfo = ' . var_export($rUserInfo, true) . ';'
			// The order is saved from the form's POST (a GET only shows the page).
			. '$_SERVER["REQUEST_METHOD"] = "POST";'
			. '\XcVm\Core\Http\RequestManager::set(["bouquet_order" => ' . var_export($rOrder, true) . ']);'
			. 'register_shutdown_function(static function () use ($db) {'
			. ' $db->query("SELECT `id`, `bouquet` FROM `lines` ORDER BY `id`");'
			. ' echo "\n" . json_encode(array_column($db->get_rows(), "bouquet", "id"));'
			. '});'
			. $rCall;

		$rProc = proc_open([PHP_BINARY, '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rCut = (int) strrpos($rOut, "\n");
		$rStored = json_decode(substr($rOut, $rCut + 1), true);
		$this->assertIsArray($rStored, $rOut . $rErr);

		return [substr($rOut, 0, $rCut), $rStored];
	}

	/**
	 * Save $rOrder in the current player as the line $rUserInfo and return its answer and the stored bouquets by line id.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>}
	 */
	private function save(mixed $rOrder, array $rUserInfo = [], int $rAllowed = 1): array {
		[$rOut, $rStored] = $this->request(self::V2, $rOrder, $rUserInfo + ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'bouquet' => [1, 2, 3]], $rAllowed, '[3,1,2]');
		$rAnswer = json_decode($rOut, true);
		$this->assertIsArray($rAnswer, $rOut);

		return [$rAnswer, $rStored];
	}

	public function testTheOrderRearrangesTheBouquetsTheLineHas(): void {
		[$rAnswer, $rStored] = $this->save([2, 3, 1]);

		$this->assertSame('success', $rAnswer['status']);
		$this->assertSame('[2,3,1]', $rStored[7]);
	}

	public function testABouquetTheLineDoesNotHaveIsNotAdded(): void {
		[, $rStored] = $this->save('[2,9,3,1,5]');

		$this->assertSame('[2,3,1]', $rStored[7]);
	}

	public function testABouquetLeftOutOfTheOrderIsKept(): void {
		[, $rStored] = $this->save([2]);

		$this->assertSame('[2,3,1]', $rStored[7]);
	}

	public function testNothingIsSavedWhenThePanelDoesNotAllowOrdering(): void {
		[$rAnswer, $rStored] = $this->save([2, 3, 1], [], 0);

		$this->assertSame('error', $rAnswer['status']);
		$this->assertSame('[3,1,2]', $rStored[7]);
	}

	/** The first web player saves the order from its profile page, under the same rule. */
	public function testTheFirstPlayerKeepsTheLinesBouquetsToo(): void {
		$rLine = ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'bouquet' => [5, 7, 9]];

		[, $rStored] = $this->request(self::V1, '[true,9]', $rLine, 1, '[5,7,9]');
		$this->assertSame('[9,5,7]', $rStored[7], 'an entry that is not a bouquet id changed what the line has');

		[, $rStored] = $this->request(self::V1, '[9]', $rLine, 1, '[5,7,9]');
		$this->assertSame('[9,5,7]', $rStored[7], 'a bouquet left out of the order was dropped');

		[, $rStored] = $this->request(self::V1, '[9,5,7]', $rLine, 0, '[5,7,9]');
		$this->assertSame('[5,7,9]', $rStored[7], 'the order was saved although the panel does not allow ordering');

		[, $rStored] = $this->request(self::V1, '[5]', ['id' => 999999, 'is_external_xc' => true, 'bouquet' => []], 1, '[5,7,9]');
		$this->assertSame('[1]', $rStored[999999], 'a session on an external server wrote to a line of this panel');
	}

	/** The first player orders the bouquets stored for the line, not the copy of the line its page was drawn from. */
	public function testTheFirstPlayerOrdersTheStoredBouquets(): void {
		$rLine = ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'bouquet' => [5, 7, 9]];

		[, $rStored] = $this->request(self::V1, '[7]', $rLine, 1, '[5,7]');
		$this->assertSame('[7,5]', $rStored[7], 'a bouquet the line no longer has was saved with the order');
	}

	/** The page offers the ordering tab under the same setting the save checks. */
	public function testTheOrderingTabIsOfferedOnlyWhenThePanelAllowsIt(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/player_v2/profile.php');
		$rGuard = preg_quote("<?php if (SettingsManager::get('player_allow_bouquet')): ?>", '/');

		$this->assertMatchesRegularExpression('/' . $rGuard . '\s*<li class="nav-item" role="presentation">\s*<button[^>]*data-bs-target="#tab-bouquets"/', $rView, 'the tab button is always shown');
		$this->assertMatchesRegularExpression('/' . $rGuard . '\s*<div class="tab-pane fade" id="tab-bouquets"/', $rView, 'the tab pane is always shown');
	}

	/** A session on another provider's server carries a placeholder id, not a line of this panel. */
	public function testASessionOnAnExternalServerSavesNothing(): void {
		[$rAnswer, $rStored] = $this->save([5], ['id' => 999999, 'is_external_xc' => true, 'bouquet' => []]);

		$this->assertSame('error', $rAnswer['status']);
		$this->assertSame('[1]', $rStored[999999]);
	}
}
