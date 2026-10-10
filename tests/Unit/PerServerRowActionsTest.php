<?php

use PHPUnit\Framework\TestCase;

/**
 * A row of a per-server table acts on its own server. The per-server view
 * (`single`) switches the grouping off for the table, but the radio and movie
 * rows read the panel's setting again and tagged every row "all servers"
 * (server_col_id -1): Delete on one server's row removed the stream from
 * every server. A server's own page asked with `simple`, which nothing reads,
 * so its Stop, Restart and Kill did the same.
 */
final class PerServerRowActionsTest extends TestCase {
	public function testRowsTakeTheTablesOwnGrouping(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/TableController.php');
		$this->assertStringNotContainsString('SettingsManager::getAll()["streams_grouped"]', $rSource, 'a row builder reading the panel\'s grouping instead of its table\'s');
		$this->assertGreaterThanOrEqual(3, substr_count($rSource, '$rGrouped   = ($rSettings["streams_grouped"] == 1);'), 'radios, movies and episodes');
	}

	public function testAServersPageAsksForItsOwnRows(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/server_view.php');
		$this->assertStringContainsString('d.single = true;', $rView);
		$this->assertStringNotContainsString('d.simple', $rView, 'a flag ./table does not read');
	}
}
