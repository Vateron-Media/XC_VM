<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\TmpCronJob;

/**
 * cron:tmp removes files ten minutes old from tmp/ and tmp/crons/. Files that
 * are state were swept with the leftovers: the root cron's hourly and daily
 * stamps (every check of the node's binaries ran, and asked GitHub, every ~11
 * minutes), and the updater's archive, which post-update reads again.
 */
final class TmpSweepKeepsStateTest extends TestCase {
	public function testEveryStampOfTheRootCronOutlivesItsPeriod(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		// A stamp, and the longest period the lines right after it compare its age with.
		preg_match_all("/CRONS_TMP_PATH \. '(\w+)';\n(?:[^\n]*\n){0,2}?[^\n]*\b(86400|3600)\b/", $rSource, $rStamps, PREG_SET_ORDER);
		$rFound = array_column($rStamps, 2, 1);
		$this->assertSame(['fanout_binary_check', 'ffmpeg_check', 'xcvm_core_check', 'ytdlp_check'], (static function (array $rNames): array {
			sort($rNames);
			return $rNames;
		})(array_keys($rFound)), 'the stamps the root cron throttles its checks with');
		foreach ($rFound as $rName => $rPeriod) {
			$this->assertGreaterThan((int) $rPeriod, TmpCronJob::maxAge($rName), $rName . ' is not swept before its check is due again');
		}
		$this->assertGreaterThan(86400, TmpCronJob::maxAge('ytdlp_check'), 'a daily check');
		$this->assertGreaterThan(86400, TmpCronJob::maxAge('ffmpeg_check'), 'a daily check');
	}

	public function testTheUpdatersArchiveOutlivesASlowUpdateAndLeftoversDoNot(): void {
		$this->assertGreaterThan(3600, TmpCronJob::maxAge('.update.tar.gz'));
		$this->assertSame(600, TmpCronJob::maxAge('lock_0cc175b9c0f1b6a831c399e269772661'));
		$this->assertSame(600, TmpCronJob::maxAge('config_2.enc'));
	}
}
