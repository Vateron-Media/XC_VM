<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\OnlineConnectionInfo;
use XcVm\Tests\Support\InstallSchema;

final class OnlineConnectionInfoTest extends TestCase {
	public function testSqlChoosesOneActiveConnectionAndScopesTheRequestedUsers(): void {
		$db = new TestDb();
		$db->exec(InstallSchema::table('lines_live'));
		foreach ([[1, '192.0.2.1', 'Old ISP', 10, 0], [1, '192.0.2.2', 'Current ISP', 20, 0], [1, '192.0.2.3', 'Ended ISP', 30, 1], [2, '192.0.2.4', 'Other reseller', 40, 0], [1, '192.0.2.5', 'Delayed old row', 5, 0]] as $row) {
			$db->query('INSERT INTO lines_live (user_id, user_ip, isp, date_start, hls_end) VALUES (?, ?, ?, ?, ?)', ...$row);
		}
		$this->assertSame([1 => ['online_ip' => '192.0.2.2', 'online_isp' => 'Current ISP', 'online_since' => 20]], OnlineConnectionInfo::forUsers($db, [1, 1, 0], false));
		$this->assertSame([], OnlineConnectionInfo::forUsers($db, [], false));
	}

	public function testRedisPayloadNormalizationRejectsEndedConnections(): void {
		$this->assertSame([3 => ['online_ip' => '2001:db8::1', 'online_isp' => '', 'online_since' => 42]], OnlineConnectionInfo::normalize([
			['user_id' => 3, 'user_ip' => '2001:db8::1', 'date_start' => 42, 'hls_end' => 0],
			['user_id' => 4, 'user_ip' => '192.0.2.4', 'hls_end' => 1],
			false,
		]));
	}
}
