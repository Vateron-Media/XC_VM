<?php

use PHPUnit\Framework\TestCase;

/**
 * 1.16.42 — the episodes admin table (#episodes-table) shares the movies 12-column
 * layout (the Bootstrap Responsive control + bulk-select lead the row), but
 * handleEpisodes used a 9-entry order map with no leading false,false, so
 * DataTables column 2 (id) sorted stream_display_name and the default order
 * [[2,'desc']] landed on the wrong column. The map is now the aligned 12-entry
 * form, identical to handleMovies.
 */
final class ParchesEpisodesSortTest extends TestCase {

	public function testEpisodesSortMapAlignedToTwelveColumns(): void {
		$rTC = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/TableController.php');
		$rOld = '$rOrder = ["`streams`.`id`", false, "`streams`.`stream_display_name`", "`server_name`", "`clients`", "`streams_servers`.`stream_started`", false, false, "`streams_servers`.`bitrate`"];';
		$rAligned = '$rOrder = [false, false, "`streams`.`id`", false, "`streams`.`stream_display_name`", "`server_name`", "`clients`", "`streams_servers`.`stream_started`", false, false, false, "`streams_servers`.`bitrate`"];';
		$this->assertStringNotContainsString($rOld, $rTC, 'the misaligned 9-entry episodes map is removed');
		$this->assertGreaterThanOrEqual(2, substr_count($rTC, $rAligned), 'episodes now uses the same aligned 12-column map as movies');
	}
}
