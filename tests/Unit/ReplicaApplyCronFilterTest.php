<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaApply;

/**
 * The node-side crontab filter: a serve-only node drops the cron rows its local
 * agent owns (per the fresh node_crons marker) and keeps the rest; a stale or
 * absent marker (the agent crashed/stopped/old) keeps every row — so the node
 * never ends up with neither owner draining a log (cutover sub-project 2).
 */
final class ReplicaApplyCronFilterTest extends TestCase {
	public static function setUpBeforeClass(): void {
		if (!defined('PHP_BIN')) {
			define('PHP_BIN', 'php');
		}
		if (!defined('MAIN_HOME')) {
			define('MAIN_HOME', '/home/xc_vm/');
		}
	}

	public function testCrontabLinesDropsOwnedKeepsRest(): void {
		$jobs = [
			['filename' => 'streams_logs', 'time' => '* * * * *'],
			['filename' => 'tmp', 'time' => '* * * * *'],
			['filename' => 'maxmind', 'time' => '0 4 * * 2'],
			['filename' => 'certbot', 'time' => '0 3 * * *'], // not owned -> kept
		];
		$owned = ['streams_logs', 'lines_logs', 'activity', 'errors', 'tmp', 'maxmind'];
		$text = ReplicaApply::crontabLines($jobs, $owned);
		$this->assertStringNotContainsString('cron:streams_logs', $text);
		$this->assertStringNotContainsString('cron:tmp ', $text);
		$this->assertStringNotContainsString('cron:maxmind', $text);
		$this->assertStringContainsString('cron:certbot', $text);
	}

	public function testCrontabLinesKeepsAllWhenNoneOwned(): void {
		$jobs = [['filename' => 'streams_logs', 'time' => '* * * * *']];
		$text = ReplicaApply::crontabLines($jobs, []);
		$this->assertStringContainsString('cron:streams_logs', $text);
	}

	public function testNodeOwnedCronsFreshStaleAbsent(): void {
		$dir = sys_get_temp_dir() . '/xcvm_crontest_' . uniqid();
		mkdir($dir);
		$marker = $dir . '/node_crons';

		file_put_contents($marker, json_encode(['crons' => ['streams_logs', 'tmp']]));
		$this->assertSame(['streams_logs', 'tmp'], ReplicaApply::nodeOwnedCrons($marker));

		touch($marker, time() - 3600); // stale -> the agent is gone, keep the PHP crons
		$this->assertSame([], ReplicaApply::nodeOwnedCrons($marker));

		$this->assertSame([], ReplicaApply::nodeOwnedCrons($dir . '/nope')); // absent

		@unlink($marker);
		@rmdir($dir);
	}
}
