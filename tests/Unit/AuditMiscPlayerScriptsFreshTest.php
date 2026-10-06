<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;

/**
 * The panel's assets carry no version in their address: an access code's
 * nginx location for them answers `Cache-Control: no-cache`, so a browser asks
 * again on every load and takes a changed file at once. The web player's
 * pages load every script from that location.
 */
final class AuditMiscPlayerScriptsFreshTest extends TestCase {
	public function testTheAssetsLocationOfAnAccessCodeIsRevalidated(): void {
		$rTemplate = (string) file_get_contents(MAIN_HOME . 'bin/nginx/conf/codes/template');
		$this->assertSame(1, preg_match('~location \^\~ /#CODE#/assets/ \{([^}]*)\}~', $rTemplate, $rLocation));
		$this->assertStringContainsString('alias /home/xc_vm/Public/assets/#TYPE#/;', $rLocation[1]);
		$this->assertStringContainsString('add_header Cache-Control "no-cache";', $rLocation[1]);

		// The web player's code type is served from the directory its scripts are in.
		$this->assertDirectoryExists(MAIN_HOME . 'Public/assets/' . AuthRepository::codeLocation(8)[0] . '/js');
	}

	public function testTheWebPlayerLoadsItsScriptsFromThatLocation(): void {
		$rPages = array_merge(glob(MAIN_HOME . 'Public/Views/player_v2/*.php'), glob(MAIN_HOME . 'Public/Views/layouts/player_v2/*.php'));
		$rScripts = 0;
		$rElsewhere = [];
		foreach ($rPages as $rPage) {
			preg_match_all('/<script[^>]*\ssrc="([^"]*)"/', (string) file_get_contents($rPage), $rTags);
			foreach ($rTags[1] as $rAddress) {
				$rScripts++;
				if (!str_starts_with($rAddress, '<?= $assetsPath ?>')) {
					$rElsewhere[] = basename($rPage) . ': ' . $rAddress;
				}
			}
			if ($rTags[1] !== []) {
				$this->assertStringContainsString("\$assetsPath = \$baseUrl . 'assets/';", (string) file_get_contents($rPage), basename($rPage));
			}
		}
		$this->assertGreaterThan(20, $rScripts, 'the pages load their scripts with script tags');
		$this->assertSame([], $rElsewhere);
	}
}
