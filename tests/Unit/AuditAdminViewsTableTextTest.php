<?php

use PHPUnit\Framework\TestCase;

/**
 * The connection and activity tables, and the table of ASNs, are fed by
 * ./table, which sends stored values as they are, and DataTables writes a
 * column that has no render function into its cell as markup, hidden columns
 * included. The player a viewer's device names itself as, the name of its
 * provider, the container a connection was recorded with, a stream's name
 * and a network's domain and type are text the panel did not write: they are
 * shown as text. The page's helper, esc, encodes the quote characters as
 * well, as the helper of the list views does, so what it returns is also safe
 * inside the attributes these pages put it in.
 */
final class AuditAdminViewsTableTextTest extends TestCase {
	/** Per view: the columns that carry such text. */
	private const TABLES = [
		'admin/live_connections' => ['player', 'isp', 'container'],
		'admin/line_activity' => ['player', 'isp', 'container'],
		'admin/stream_view' => ['player', 'isp', 'container'],
		'admin/server_view' => ['player', 'isp', 'container'],
		'admin/asns' => ['isp', 'domain', 'type'],
		'reseller/line_activity' => ['player', 'isp', 'container'],
		'reseller/live_connections' => ['player', 'isp', 'container'],
		'reseller/dashboard' => ['stream_name', 'player', 'isp', 'container'],
	];

	private function source(string $rView): string {
		return (string) file_get_contents(MAIN_HOME . 'Public/Views/' . $rView . '.php');
	}

	public function testStoredTextIsWrittenToTheTablesAsText(): void {
		$rAsMarkup = [];
		foreach (self::TABLES as $rView => $rText) {
			// One definition: `{ data: '<name>', ... }`, a render function's body being the only braces inside.
			preg_match_all('/\{\s*data: \'(\w+)\'((?:[^{}]|\{[^{}]*\})*)\}/', $this->source($rView), $rColumns, PREG_SET_ORDER);
			$rSeen = [];
			foreach ($rColumns as [, $rColumn, $rRest]) {
				if (in_array($rColumn, $rText, true)) {
					$rSeen[] = $rColumn;
					if (!preg_match('/render:\s*esc\b/', $rRest)) {
						$rAsMarkup[] = $rView . ': ' . $rColumn;
					}
				}
			}
			$this->assertSame([], array_values(array_diff($rText, $rSeen)), $rView . ' has these columns');
		}
		$this->assertSame([], $rAsMarkup);
	}

	public function testTheHelperEncodesTheQuoteCharactersToo(): void {
		$rLeft = [];
		foreach (array_keys(self::TABLES) as $rView) {
			$this->assertSame(1, preg_match('/\bvar esc = function\(s\) \{(.*?)\};/s', $this->source($rView), $rHelper), $rView . ' has one helper named esc');
			foreach (['"' => '/&quot;|&#0?34;/', "'" => '/&#0?39;|&apos;/'] as $rCharacter => $rEncoded) {
				if (!preg_match($rEncoded, $rHelper[1])) {
					$rLeft[] = $rView . ': ' . $rCharacter;
				}
			}
		}
		$this->assertSame([], $rLeft);
	}
}
