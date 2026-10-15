<?php

use PHPUnit\Framework\TestCase;

/**
 * 1.16.101 — concurrent per-viewer divergence reports (conn.divergence) wrote
 * lines_divergence with one REPLACE of hundreds of rows in whatever order each
 * node listed its viewers, so two reports arriving together took the unique uuid
 * index locks in opposite orders and deadlocked (SQLSTATE 40001, the ingest pool
 * retried as 503). Ordering the uuids (SORT_STRING, byte by byte on hex uuids)
 * before the REPLACE makes every report lock in the same order. MAIN only.
 */
final class ParchesDivergenceOrderTest extends TestCase {

	public function testDivergenceReplaceIsUuidOrdered(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/ConnectionIngest.php');
		$rKsort = strpos($rSrc, 'ksort($rOwn, SORT_STRING);');
		$rReplace = strpos($rSrc, 'REPLACE INTO `lines_divergence`');
		$this->assertIsInt($rKsort, 'the uuids are ordered before the lines_divergence REPLACE');
		$this->assertIsInt($rReplace);
		$this->assertLessThan($rReplace, $rKsort, 'ksort must run before the REPLACE INTO lines_divergence');
	}
}
