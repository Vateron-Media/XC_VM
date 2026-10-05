<?php

use PHPUnit\Framework\TestCase;

/**
 * The TV Guide's engine (assets/js/listings.js) hangs on window.XC_VM.Listings
 * and needs that object there when it loads. The admin and the reseller guide
 * loaded it without: the script stopped at its first assignment
 * ("Cannot set properties of undefined") and the grid stayed empty.
 */
final class ListingsNamespaceTest extends TestCase {
	public function testEveryPageThatLoadsTheEngineMakesItsNamespaceFirst(): void {
		$rPages = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAIN_HOME . 'Public/Views', FilesystemIterator::SKIP_DOTS)) as $rFile) {
			$rBody = (string) file_get_contents((string) $rFile);
			$rLoads = strpos($rBody, 'js/listings.js"></script>');
			if ($rLoads === false) {
				continue;
			}
			$rPages[] = substr((string) $rFile, strlen(MAIN_HOME . 'Public/Views/'));
			$rMade = strpos($rBody, 'window.XC_VM.Listings = window.XC_VM.Listings || {};');
			$this->assertNotFalse($rMade, end($rPages) . ' loads the engine without its namespace');
			$this->assertLessThan($rLoads, $rMade, end($rPages) . ' makes the namespace after the engine loaded');
		}
		sort($rPages);
		$this->assertSame(['admin/epg_view.php', 'layouts/player/footer.php', 'reseller/epg_view.php'], $rPages);
	}
}
