<?php

use PHPUnit\Framework\TestCase;

/**
 * The profile page of the web player offers the playlist generator only where
 * the panel allows playlist downloads from the player, as the first player does.
 *
 * The view runs in a child PHP with the data the controller hands it for a line
 * of this panel; bouquet ordering is allowed throughout.
 */
final class AuditReviewPlayersPlaylistTabTest extends TestCase {
	private const VIEW = MAIN_HOME . 'Public/Views/player_v2/profile.php';

	private function page(string $rAllowed): DOMXPath {
		$rData = [
			'lineData' => ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'created_at' => 1700000000, 'max_connections' => 1, 'is_trial' => 0, 'bouquet' => [1]],
			'activationCode' => '',
			'activeConsCount' => 0,
			'activeSessions' => [],
			'userBouquets' => [['id' => 1, 'name' => 'Sports', 'channels_count' => 2, 'series_count' => 0]],
			'outputDevices' => [],
			'serverPublicUrl' => 'http://panel.test',
			'expTimestamp' => null,
			'isExpired' => false,
			'isExpiringSoon' => false,
			'daysRemaining' => null,
			'totalLive' => 0,
			'totalVod' => 0,
			'totalSeries' => 0,
			'totalRadio' => 0,
		];
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(0);'
			. '$_SERVER["XC_CODE"] = "play";'
			. '\XcVm\Core\Config\SettingsManager::set(' . var_export(['player_allow_playlist' => $rAllowed, 'player_allow_bouquet' => '1'], true) . ');'
			. 'extract(' . var_export($rData, true) . ');'
			. 'require ' . var_export(self::VIEW, true) . ';';

		$rProc = proc_open([PHP_BINARY, '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$rDom = new DOMDocument();
		@$rDom->loadHTML('<?xml encoding="UTF-8">' . $rOut);

		return new DOMXPath($rDom);
	}

	/**
	 * The panes the tab buttons of the page open, and the panes that sit side by side in its tab area.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	private function tabs(DOMXPath $rPage): array {
		$rButtons = [];
		foreach ($rPage->query('//button[starts-with(@data-bs-target, "#tab-")]') as $rButton) {
			$rButtons[] = substr($rButton->getAttribute('data-bs-target'), 1);
		}
		$rPanes = [];
		foreach ($rPage->query('//div[@id="tab-overview"]/parent::div[contains(@class, "tab-content")]/div[contains(@class, "tab-pane")]') as $rPane) {
			$rPanes[] = $rPane->getAttribute('id');
		}

		return [$rButtons, $rPanes];
	}

	public function testThePlaylistTabIsOfferedOnlyWhenThePanelAllowsIt(): void {
		$rAllowed = $this->page('1');
		[$rButtons, $rPanes] = $this->tabs($rAllowed);
		$this->assertContains('tab-playlist', $rButtons, 'the tab button is missing where playlists are allowed');
		$this->assertContains('tab-playlist', $rPanes, 'the tab pane is missing where playlists are allowed');
		$this->assertSame(2, $rAllowed->query('//input[@id="playlist-generated-url" or @id="epg-generated-url"]')->length);

		$rRefused = $this->page('0');
		[$rButtonsLeft, $rPanesLeft] = $this->tabs($rRefused);
		$this->assertSame(array_values(array_diff($rButtons, ['tab-playlist'])), $rButtonsLeft, 'the setting hides the button of the playlist tab and of no other');
		$this->assertSame(array_values(array_diff($rPanes, ['tab-playlist'])), $rPanesLeft, 'the setting hides the pane of the playlist tab and of no other');
		$this->assertSame(0, $rRefused->query('//input[@id="playlist-generated-url" or @id="epg-generated-url"]')->length, 'a playlist link is still on the page');
	}
}
