<?php

use PHPUnit\Framework\TestCase;

/**
 * 1.16.118 — on a node whose replica owns the stream but whose local state store
 * is not seeded (e.g. the huge-catalogue node that refuses to seed), serverRow()
 * returned the replica's bare row (no cc_info / pid / current_source), so created
 * channels replayed from episode 1 and source rotation lost its current source.
 * It now falls back to MAIN's streams_servers row (mainServerRow) when the store
 * is not seeded, before the bare row. Load-balancer scoped.
 */
final class ParchesRuntimeRowFallbackTest extends TestCase {

	public function testServerRowFallsBackToMainWhenUnseeded(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Stream/StreamSource.php');
		$this->assertStringContainsString("self::mainServerRow(\$rStreamID, \$rServerID) ?? (\$rEntry['server'] ?? null)", $rSrc, 'unseeded: MAIN row, then the bare row as before');
		$this->assertIsInt(strpos($rSrc, 'private static function mainServerRow('), 'the MAIN-row fallback helper exists');
		$this->assertStringContainsString('if (NodeRole::refusesConnects()) {', $rSrc, 'mode 2 reads no MAIN database');
	}
}
