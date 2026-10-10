<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Reference\StatusBadge;

/**
 * The episodes page names a row's status code itself (the table sends the
 * code). The codes are the VOD ones (StatusBadge::vod): 3 is a direct source
 * and 4 a file that failed, which the page showed as "Down" and "On Demand",
 * with the play button live on the broken one.
 */
final class EpisodesPageStatusTest extends TestCase {
	private function page(): string {
		return (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/episodes.php');
	}

	public function testTheStatusListNamesTheCodesAsTheVodBadgeDoes(): void {
		$this->assertSame(1, preg_match('/var STATUS = \{(.*?)\};/s', $this->page(), $rList));
		preg_match_all("/'(\\d)': \\['\\w+', '([^']+)'\\]/", $rList[1], $rRows, PREG_SET_ORDER);
		$this->assertCount(6, $rRows);

		foreach ($rRows as [, $rCode, $rLabel]) {
			$this->assertStringContainsString("title='" . $rLabel . "'", StatusBadge::vod((int) $rCode), 'code ' . $rCode);
		}
	}

	public function testOnlyAnEncodedOrDirectEpisodePlays(): void {
		$this->assertStringContainsString('var playable = (row.status === 1 || row.status === 3);', $this->page());
	}

	/** episodes?series=<id> opens the page on that series: the filter starts with it chosen. */
	public function testTheSeriesNamedInTheAddressIsTheFiltersChoice(): void {
		$this->assertSame(1, preg_match('/<select id="filter-series"[^>]*>(.*?)<\/select>/s', $this->page(), $rSelect));
		$rSeries = ['id' => '12', 'title' => 'A "quoted" <show>'];
		ob_start();
		eval('?>' . $rSelect[1]);
		$this->assertStringContainsString('<option value="12" selected>A &quot;quoted&quot; &lt;show&gt;</option>', (string) ob_get_clean());

		$rSeries = null;
		ob_start();
		eval('?>' . $rSelect[1]);
		$this->assertSame('', trim((string) ob_get_clean()));
	}
}
