<?php

use PHPUnit\Framework\TestCase;

final class AuditPlayerLiveSearchTest extends TestCase {
	private function searchPage(string $query): string {
		$typeFilter = 'all';
		$total = 0;
		$counts = ['live' => 0, 'movies' => 0, 'series' => 0, 'episodes' => 0, 'radio' => 0];
		$results = [];

		ob_start();
		require MAIN_HOME . 'Public/Views/player_v2/search.php';
		return (string) ob_get_clean();
	}

	public function testSearchTextIsRenderedAsText(): void {
		$rSearch = 'news"><img data-injected src=x>';
		$rOut = $this->searchPage($rSearch);

		$this->assertStringNotContainsString('<img data-injected', $rOut);
		$this->assertStringContainsString('news&quot;&gt;&lt;img data-injected src=x&gt;', $rOut);
	}

	public function testWithoutASearchTheInitialPromptIsShown(): void {
		$this->assertStringContainsString('Search the Entire Catalog', $this->searchPage(''));
	}
}
