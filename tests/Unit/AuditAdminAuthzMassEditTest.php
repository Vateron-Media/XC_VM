<?php

use PHPUnit\Framework\TestCase;

/**
 * The Mass Edit Lines and Mass Edit Users tables are fed by ./table, which
 * sends stored values as they are, and DataTables writes a column that has no
 * render function into its cell as markup, hidden columns included. Text a
 * reseller or a subscriber chose (a username, a password, a note) is shown
 * as text: every column gets a render function unless the server sends it as
 * a number or a date it formatted itself. The page's helper, esc, encodes the
 * quote characters as well, as the helper of the list views does, so what it
 * returns is also safe inside an attribute.
 */
final class AuditAdminAuthzMassEditTest extends TestCase {
	/** Per view: the columns that carry stored text, and those the server sends as a number or its own date. */
	private const TABLES = [
		'line_mass' => [['username', 'password', 'owner_name', 'stream_display_name', 'notes'], ['id', 'member_id', 'last_str']],
		'user_mass' => [['username', 'owner_username', 'ip'], ['id', 'credits', 'user_count', 'user_lines', 'mag_lines', 'e2_lines', 'last_login']],
	];

	/**
	 * The columns of the view's table: name => the rest of its definition.
	 *
	 * @return array<string, string>
	 */
	private function columns(string $rView): array {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rView . '.php');
		$this->assertSame(1, preg_match('/\bcolumns: \[(.*?)\n\s*\],\n\s*rowCallback:/s', $rSource, $rList), $rView . ' has one column list');
		// One definition: `{ data: '<name>', ... }`, a render function's body being the only braces inside.
		preg_match_all('/\{\s*data: \'(\w+)\'((?:[^{}]|\{[^{}]*\})*)\}/', $rList[1], $rColumns, PREG_SET_ORDER);
		return array_column($rColumns, 2, 1);
	}

	public function testStoredTextIsWrittenToTheTablesAsText(): void {
		$rAsMarkup = [];
		foreach (self::TABLES as $rView => [$rText]) {
			$rColumns = $this->columns($rView);
			foreach ($rText as $rColumn) {
				$this->assertArrayHasKey($rColumn, $rColumns, $rView);
				// esc itself, or the page's helper or function that passes the value through it.
				if (!preg_match('/render:\s*(esc\b|dash\b|function\(d\) \{\s*return d \? esc\(d\) :)/', $rColumns[$rColumn])) {
					$rAsMarkup[] = $rView . ': ' . $rColumn;
				}
			}
		}
		$this->assertSame([], $rAsMarkup);
	}

	public function testTheHelperEncodesTheQuoteCharactersToo(): void {
		$rLeft = [];
		foreach (array_keys(self::TABLES) as $rView) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rView . '.php');
			$this->assertSame(1, preg_match('/\bvar esc = function\(s\) \{(.*?)\};/s', $rSource, $rHelper), $rView . ' has one helper named esc');
			foreach (['"' => '/&quot;|&#0?34;/', "'" => '/&#0?39;|&apos;/'] as $rCharacter => $rEncoded) {
				if (!preg_match($rEncoded, $rHelper[1])) {
					$rLeft[] = $rView . ': ' . $rCharacter;
				}
			}
		}
		$this->assertSame([], $rLeft);
	}

	public function testOnlyNumbersAndTheServersOwnDatesAreLeftWithoutARenderFunction(): void {
		$rLeft = [];
		foreach (self::TABLES as $rView => [, $rPlain]) {
			$rColumns = $this->columns($rView);
			$this->assertGreaterThan(10, count($rColumns), $rView);
			$rUnrendered = array_keys(array_filter($rColumns, static fn(string $rRest): bool => !str_contains($rRest, 'render:')));
			foreach (array_diff($rUnrendered, $rPlain) as $rColumn) {
				$rLeft[] = $rView . ': ' . $rColumn;
			}
		}
		$this->assertSame([], $rLeft);
	}
}
