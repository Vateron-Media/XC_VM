<?php

use PHPUnit\Framework\TestCase;

/**
 * The web player's live page writes the search text into its inline script, as
 * the title closeChannel() puts back. Whatever the text holds, it stays one
 * string literal inside that script and reaches the page as text, not markup.
 *
 * The footer runs in a child PHP: it needs the player's global helpers, which
 * the test replaces with a stand-in.
 */
final class AuditPlayerLiveSearchTest extends TestCase {
	private function footer(?string $rSearch): string {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'define("XC_VM_VERSION", "0.0.0");'
			. 'function getOrderedCategories(array $rCategories, string $rType = "movie") { return []; }'
			. '\XcVm\Core\Util\LayoutRenderer::renderFooter("player", ' . var_export([
				'_PAGE' => 'live',
				'rStreamIDs' => [],
				'rFilterBy' => 'all',
				'rSortArray' => ['number' => 'Default'],
				'rFilterArray' => ['all' => 'All Channels'],
				'rUserInfo' => ['category_ids' => []],
				'rSearchBy' => $rSearch,
			], true) . ');';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		return $rOut;
	}

	public function testSearchTextStaysOneStringLiteralShownAsText(): void {
		$rSearch = "a\"b\\'</script><img src=x>\nc&d";
		$rPlain = $this->footer('news');
		$rOut = $this->footer($rSearch);

		$this->assertSame(substr_count(strtolower($rPlain), '</script'), substr_count(strtolower($rOut), '</script'), 'the search text ended the script block');
		$this->assertSame(1, preg_match('/\$\("#now__playing__title"\)\.text\((.*)\);\r\n\s+\$\("#now__playing__epg"\)\.html\("No Programme/', $rOut, $rMatch), 'the title is not put back as text');
		$this->assertSame(strtoupper($rSearch), json_decode($rMatch[1]), 'the literal does not read back as the search text');
		$this->assertSame(0, preg_match('/[<>&\']/', $rMatch[1]), 'markup characters are written as they came');
	}

	public function testWithoutASearchTheDefaultTitleIsPutBack(): void {
		$this->assertStringContainsString('$("#now__playing__title").text("LIVE TV");', $this->footer(null));
	}
}
