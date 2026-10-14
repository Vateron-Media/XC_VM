<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression locks for the safe batch of fixes ported from the "Parches" module
 * (verified still-present in 2.6.7). Each assertion fails on the pre-fix code and
 * passes after the fix; behaviour is covered by the full suite. These guard the
 * exact root-cause change so a revert is caught (cf. the PR #400 weak-anchor miss).
 */
final class ParchesSafeBatchTest extends TestCase {

	/** 1.11.17 — the two unguarded igbinary_unserialize readers now guard the mGet result and each row. */
	public function testConnectionTrackerDecodeIsGuarded(): void {
		$rCT = (string) file_get_contents(MAIN_HOME . 'Domain/Stream/ConnectionTracker.php');
		$this->assertSame(0, substr_count($rCT, '$rRow = igbinary_unserialize($rRow);'), 'no unguarded raw-row unserialize remains (getFirst/getStream/getRedis connections)');
		$this->assertGreaterThanOrEqual(3, substr_count($rCT, '? igbinary_unserialize($rRow) : false;'), 'the Redis-row readers guard the decode');
		$this->assertStringContainsString("if (is_array(\$rRow) && isset(\$rRow['user_id'])) {", $rCT);
	}

	/** 1.16.101 — getArchiveFiles no longer indexes ['data'] on a possibly-null decode. */
	public function testGetArchiveFilesGuardsNullDecode(): void {
		$rSS = (string) file_get_contents(MAIN_HOME . 'Domain/Stream/StreamService.php');
		$this->assertStringContainsString("is_array(\$rResult) && isset(\$rResult['data']) ? \$rResult['data'] : []", $rSS);
		$this->assertStringNotContainsString("'stream_id' => \$rStreamID]), true)['data'];", $rSS, 'the bare ["data"] offset-on-null is gone');
	}

	/** 1.16.116 — created-channel list is written directly, not piped through the shell (E2BIG >128KB). */
	public function testCreatedListWrittenDirectly(): void {
		$rCC = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/CreatedCommand.php');
		$this->assertStringContainsString("file_put_contents(CREATED_PATH . intval(\$rStreamID) . '_.list', \$rOutputList);", $rCC);
		$this->assertStringNotContainsString('base64 --decode', $rCC, 'no shell base64 round-trip remains');
	}

	/** 1.16.39 — a vanished VOD file now errors instead of an empty branch + unguarded filesize. */
	public function testVodVanishedFileErrors(): void {
		$rVod = (string) file_get_contents(MAIN_HOME . 'Public/stream/vod.php');
		$this->assertMatchesRegularExpression('/if \(!file_exists\(\$rRequest\)\) \{\s*generateError\(/', $rVod, 'the vanished-file branch now sends an error');
		$this->assertDoesNotMatchRegularExpression('/if \(!file_exists\(\$rRequest\)\) \{\s*\} else \{/', $rVod, 'no empty vanished-file branch remains');
	}

	/** 1.16.103 — catch-up retention counts segments only (not their .offset companions) and removes the companion. */
	public function testArchiveRetentionCountsSegmentsOnly(): void {
		$rAC = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ArchiveCommand.php');
		$this->assertStringContainsString("!str_ends_with(\$rName, '.offset')", $rAC, 'retention counts segments, not .offset index files');
		$this->assertStringContainsString("@unlink(\$rDir . \$rSegments[\$i] . '.offset');", $rAC, 'the companion .offset is removed with its segment');
		$this->assertStringNotContainsString("scandir(ARCHIVE_PATH . \$rStreamID . '/')) - 2", $rAC, 'the doubled scandir()-2 count is gone');
	}
}
