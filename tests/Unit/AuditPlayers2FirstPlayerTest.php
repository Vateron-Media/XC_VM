<?php

use PHPUnit\Framework\TestCase;

/**
 * The first web player works from the line as the panel stores it: a line
 * whose bouquets hold no channel gets an empty guide, and its bouquets are
 * listed in the order saved for it.
 */
final class AuditPlayers2FirstPlayerTest extends TestCase {
	/** What `listings` prints for $rRequest to a line with one bouquet and no channel in it. The guide ends in exit(), so it runs in a child PHP. */
	private function listings(array $rRequest): string {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. '$rUserInfo = ["id" => 7, "username" => "viewer", "password" => "secret", "bouquet" => [3], "channel_ids" => []];'
			. '$rBouquets = [3 => ["streams" => []]];'
			. '\XcVm\Core\Http\RequestManager::set(' . var_export($rRequest, true) . ');'
			. '(new \XcVm\Public\Controllers\Player\ListingsController())->index();';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		return $rOut;
	}

	public function testALineWithoutChannelsGetsAnEmptyGuide(): void {
		$this->assertSame('{"Channels":[]}', $this->listings(['channels' => '5,6']));
		$this->assertSame(
			['id' => 5, 'title' => 'LIVE TV', 'epg_title' => 'No Programme Information...', 'epg_description' => '', 'url' => null],
			json_decode($this->listings(['id' => '5']), true),
		);
	}

	/**
	 * The line's bouquets reach the profile page as getUserInfo() read them, in
	 * the stored order: the page lists them in that order and saves the order it
	 * lists. The bootstrap cannot run here (it boots the whole panel), so the
	 * rule is checked on its source.
	 */
	public function testThePlayerPagesGetTheBouquetsInTheStoredOrder(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Infrastructure/Bootstrap/PlayerScopeBootstrap.php');

		$this->assertStringContainsString('$rUserInfo = UserRepository::getUserInfo(', $rSource);
		$this->assertDoesNotMatchRegularExpression('/\b[a-z]*sort\s*\(\s*\$rUserInfo\[[\'"]bouquet[\'"]\]/', $rSource, 'the bouquets are put in another order than the stored one');

		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/player/profile.php');
		$this->assertMatchesRegularExpression('/foreach\s*\(\s*\$rUserInfo\[[\'"]bouquet[\'"]\]/', $rView, 'the profile page no longer lists the bouquets it was given');
	}
}
