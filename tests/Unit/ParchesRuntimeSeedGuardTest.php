<?php

use PHPUnit\Framework\TestCase;

/**
 * 1.16.101 — a huge-catalogue node (the SERIES node, ~110k streams_servers rows,
 * 345 MB of stream_info) FATAL-ed with "Allowed memory size exhausted" every
 * seed: StreamRuntime::mainRows() reads every row before the MAX_STREAMS count
 * check, so the store never gets its "seeded" marker and the per-minute crons
 * die and re-read 345 MB from MAIN. seedFits() measures cheaply first (a COUNT
 * and the byte sums of the big state columns, no row bodies) and refuses before
 * the load, so the node degrades gracefully. MAIN-reading path only.
 */
final class ParchesRuntimeSeedGuardTest extends TestCase {

	public function testSeedChecksItFitsBeforeLoadingEveryRow(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Core/Cluster/StreamRuntime.php');
		$rGuard = strpos($rSrc, 'if (!self::seedFits($rDb, $rServerID)) {');
		$rLoad = strpos($rSrc, '$rRows = self::mainRows($rDb, $rServerID);');
		$this->assertIsInt($rGuard, 'seed() guards with seedFits()');
		$this->assertIsInt($rLoad);
		$this->assertLessThan($rLoad, $rGuard, 'seedFits must gate before mainRows() loads every row');
		// It estimates from an aggregate over the big state columns, not row bodies.
		$this->assertStringContainsString('SUM(LENGTH(`stream_info`))', $rSrc);
		$this->assertStringContainsString('SUM(LENGTH(`cc_info`))', $rSrc);
		$this->assertStringContainsString('function memoryLimit()', $rSrc);
	}
}
