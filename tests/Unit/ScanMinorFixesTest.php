<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\Ajax\DeviceAjaxController;

/**
 * Small fixes from the whole-code scan whose code runs only inside a request
 * or a shell: each is pinned here by what it must still say.
 */
final class ScanMinorFixesTest extends TestCase {
	private static function source(string $rPath): string {
		return (string) file_get_contents(MAIN_HOME . $rPath);
	}

	/** One row per stream and per line: without GROUP BY every viewer was credited to one of them. */
	public function testTheSearchCountsViewersPerStreamAndPerLine(): void {
		$rSource = self::source('Public/Controllers/Admin/Ajax/SearchAjaxController.php');
		$this->assertStringContainsString("AND `hls_end` = 0 GROUP BY `stream_id`;", $rSource);
		$this->assertStringContainsString("AND `hls_end` = 0 GROUP BY `user_id`;", $rSource);
	}

	/** grep read bare brackets as a character class: the pattern never matched the title "Record[12]". */
	public function testDeletingARecordingFindsItsProcess(): void {
		$this->assertStringContainsString("grep 'Record\\\\[\" . intval(\$rID) . \"\\\\]'", self::source('Domain/Stream/RecordingService.php'));
		exec("printf 'php Record[12] x\\nRecord1\\n' | grep -c 'Record\\[12\\]'", $rOut);
		$this->assertSame(['1'], $rOut);
	}

	public function testTheRtmpShutdownSeesTheDatabaseHandle(): void {
		$rSource = self::source('Public/stream/rtmp.php');
		$rShutdown = substr($rSource, (int) strpos($rSource, 'function shutdown()'), 400);
		$this->assertStringContainsString('global $db;', $rShutdown);
	}

	/** The refusal was read after it had been taken out of the listing: every refused directory was a success. */
	public function testARefusedDirectoryIsAFailure(): void {
		$rSource = self::source('Public/Controllers/Api/AdminAPIWrapper.php');
		$this->assertLessThan(strpos($rSource, "unset(\$rData['result']);"), strpos($rSource, "\$rRefused = isset(\$rData['result']) && !\$rData['result'];"));
	}

	public function testThePublicUrlReadsTheHostConstant(): void {
		$rSource = self::source('Domain/Server/ServerRepository.php');
		$this->assertStringContainsString("defined('HOST') ? HOST : null", $rSource);
		$this->assertStringNotContainsString("defined('host')", $rSource);
	}

	/** Deleting was the answer to every bulk server action the handler did not name. */
	public function testOnlyDeleteDeletesServers(): void {
		$rSource = self::source('Public/Controllers/Admin/Ajax/MultiAjaxController.php');
		$this->assertMatchesRegularExpression('/\} elseif \(\$rSub == \'delete\'\) \{\s+foreach \(\$rRequestIDs as \$rServerID\) \{\s+if \(\$rServers\[\$rServerID\]\[\'is_main\'\] == 0\) \{\s+ServerRepository::deleteById/', $rSource);
	}

	public function testTheGuideReadsTheChannelsArchiveWindow(): void {
		$rSource = self::source('Public/Controllers/Player/ListingsController.php');
		$this->assertStringNotContainsString("\$rEPGItem['tv_archive_duration']", $rSource);
	}

	public function testRelatedMoviesMatchTheCategoryList(): void {
		$this->assertStringContainsString("WHERE JSON_CONTAINS(`category_id`, ?, \\'$\\') AND `type` = 2", self::source('Public/Controllers/PlayerV2/PlayerWatchController.php'));
	}

	public function testAMissingUserIsNotTheAddForm(): void {
		$this->assertStringContainsString("if (RequestManager::has('id') && \$rUser === null) {", self::source('Public/Controllers/Admin/UserController.php'));
	}

	/** Decoded before it is cleaned: cleaned first, a message with a quote in it did not decode and was dropped. */
	public function testAMagMessageKeepsItsQuotes(): void {
		$rEvent = DeviceAjaxController::eventData((string) json_encode(['id' => '5', 'type' => 'send_msg', 'message' => 'He said "hi" <script>x']));
		$this->assertSame('He said "hi" &#60;script>x', $rEvent['message']);
		$this->assertNull(DeviceAjaxController::eventData('not json'));
		$this->assertNull(DeviceAjaxController::eventData('{"message":"no id"}'));
	}

	/** A quote in a source's path ended it there: the concat list did not parse and the channel never started. */
	public function testACreatedChannelsListQuotesItsPaths(): void {
		$this->assertSame("file '/mnt/It'\\''s a film.mkv'\n", \XcVm\Cli\Commands\CreatedCommand::concatLine("/mnt/It's a film.mkv"));
		$this->assertSame("file '/mnt/a.mkv'\n", \XcVm\Cli\Commands\CreatedCommand::concatLine('/mnt/a.mkv'));
	}

	/** Only the stream's own directory: a recording's "<id>.ts" beside it was removed as that stream's archive. */
	public function testArchiveCleanupTakesTheStreamsDirectoryOnly(): void {
		$rSource = self::source('Cli/CronJobs/CleanupCronJob.php');
		$this->assertStringContainsString('basename($rStreamID) === (string) $rID && is_dir($rStreamID)', $rSource);
		$this->assertStringContainsString("exec('rm -rf ' . escapeshellarg(\$rStreamID));", $rSource);
	}

	/** Shown as stored, "s:<server>:<path>": shown without it and posted back, an edit lost the movie's server. */
	public function testAMovieEditKeepsItsSourceAsStored(): void {
		$rSource = self::source('Public/Controllers/Admin/MovieController.php');
		$this->assertStringNotContainsString('urldecode($parts[2])', $rSource);
		$this->assertStringContainsString('$rPathSources = $rSource;', $rSource);
	}

	/** file_get_contents() over https does not work under PHP-FPM here: the trailer and stills lookups use cURL. */
	public function testTmdbLookupsUseCurl(): void {
		foreach (['Domain/Vod/TMDbService.php', 'Domain/Vod/VodItemImporter.php'] as $rFile) {
			$this->assertStringNotContainsString('file_get_contents($rURL)', self::source($rFile), $rFile);
		}
	}
}
