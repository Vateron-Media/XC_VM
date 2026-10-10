<?php

use PHPUnit\Framework\TestCase;

/**
 * Every page's own `esc()` (the admin and reseller views build table cells
 * and attributes as strings) escapes quotes too. The DOM's innerHTML and
 * jQuery's .text().html() leave `"` and `'` alone, and inside title="…" or
 * data-x="…" a value's first quote ended the attribute: a viewer's user agent
 * (Client Logs), a reseller's username or notes (Users) became markup in the
 * admin's page.
 */
final class AdminViewEscapeHelpersTest extends TestCase {
	public function testEveryEscapeHelperEscapesQuotes(): void {
		$rRoot = MAIN_HOME . 'Public/';
		$rFiles = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot . 'Views')), '/\.php$/');
		$rBlind = [];
		$rSeen = 0;
		foreach ($rFiles as $rFile) {
			$rSource = (string) file_get_contents((string) $rFile);
			if (!preg_match_all('/(?:(?:var|let|const)\s+esc(?:Html)?\s*=\s*(?:function\s*\(\w*\)|\(?\w*\)?\s*=>)|function\s+esc(?:Html)?\s*\(\w*\))/', $rSource, $rMatches, PREG_OFFSET_CAPTURE)) {
				continue;
			}
			foreach ($rMatches[0] as [, $rAt]) {
				$rSeen++;
				$rBody = substr($rSource, $rAt, 420);
				$rBody = preg_match('/\n\s*\}\s*;?\s*\n/', $rBody, $rEnd, PREG_OFFSET_CAPTURE) ? substr($rBody, 0, $rEnd[0][1]) : strtok($rBody, "\n");
				if (!preg_match('/&quot;|&#0?39;|&#34;|ESC_MAP/', (string) $rBody)) {
					$rBlind[] = substr((string) $rFile, strlen($rRoot)) . ':' . (substr_count($rSource, "\n", 0, $rAt) + 1);
				}
			}
		}
		$this->assertGreaterThan(40, $rSeen, 'the helpers were found');
		$this->assertSame([], $rBlind, 'esc() helpers that leave quotes alone');
	}
}
