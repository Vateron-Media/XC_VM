<?php

use PHPUnit\Framework\TestCase;

/**
 * The Manage Servers cluster-nodes table is DOM-sourced, so every DataTables
 * columnDefs target must be a real column index (0 .. columns-1). A target past
 * the last column makes DataTables build a phantom column and request a cell no
 * row has — "DataTables warning: ... Requested unknown parameter N". Guards the
 * off-by-one shipped in 2.7.0 (target 14 against a 14-column table).
 */
final class ClusterNodesTableColumnsTest extends TestCase {

	public function testEveryColumnDefTargetIsAValidColumnIndex(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/partials/cluster_section.php');
		$rScript = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/partials/cluster_section_script.php');

		// Columns = the <th> of the cluster-nodes-table <thead> (<thead> itself does not match <th[\s>]).
		$this->assertSame(1, preg_match('/id="cluster-nodes-table".*?<thead>(.*?)<\/thead>/s', $rView, $rHead), 'found the cluster-nodes-table head');
		$rColumns = preg_match_all('/<th[\s>]/', $rHead[1]);
		$this->assertGreaterThan(0, $rColumns, 'the head has columns');

		// The first columnDefs targets after the table's DataTable() init.
		$this->assertSame(1, preg_match('/#cluster-nodes-table.*?targets:\s*\[([0-9,\s]+)\]/s', $rScript, $rDefs), 'found the columnDefs targets');
		$rTargets = array_map('intval', array_filter(array_map('trim', explode(',', $rDefs[1])), 'strlen'));
		$this->assertNotEmpty($rTargets, 'targets parsed');

		foreach ($rTargets as $rTarget) {
			$this->assertLessThan($rColumns, $rTarget, "columnDefs target {$rTarget} is past the last column index (" . ($rColumns - 1) . ") of the {$rColumns}-column table");
		}
	}
}
