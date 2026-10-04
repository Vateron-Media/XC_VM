<?php

use XcVm\Domain\Server\ServerRepository;
use PHPUnit\Framework\TestCase;

/**
 * ServerRepository::getStreamingSimple / getProxySimple — server lists for the
 * admin/reseller UI, filtered by permissions.
 *
 * Regression: these run during admin-API boot on the login page, where the
 * $rPermissions global is still null. A strict `array $rPermissions` hint made
 * that a fatal TypeError at boot; the parameter must accept null (the methods
 * only read it via isset()). Also covers the online filter and reseller name
 * masking. Driven against the TestDb via setDb().
 */
final class ServerRepositorySimpleTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$now = time();
		$this->db->exec(
			'CREATE TABLE servers (id INTEGER PRIMARY KEY AUTO_INCREMENT, server_type INTEGER, status INTEGER, last_check_ago INTEGER, is_main INTEGER, `order` INTEGER, server_name TEXT);'
		);
		$this->db->query(
			'INSERT INTO servers (id, server_type, status, last_check_ago, is_main, `order`, server_name) VALUES
			 (1, 0, 1, ?, 0, 0, "Stream-1"),
			 (2, 0, 2, ?, 0, 0, "Stream-2"),
			 (3, 1, 1, ?, 0, 0, "Proxy-1");',
			$now,
			$now - 100000,
			$now
		);
		ServerRepository::setDb($this->db);
	}

	protected function tearDown(): void {
		// The injected connection would outlive this test and stand in for the next one's.
		(new ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
	}

	public function testGetStreamingSimpleAcceptsNullPermissions(): void {
		// Regression: null perms (login-page boot) must not throw.
		$servers = ServerRepository::getStreamingSimple(null);

		$this->assertSame([1], array_keys($servers), 'only the online streaming server');
		$this->assertSame('Stream-1', $servers[1]['server_name'], 'name not masked without reseller perms');
	}

	public function testGetStreamingSimpleAllTypeReturnsOfflineToo(): void {
		$servers = ServerRepository::getStreamingSimple(null, 'all');
		$this->assertSame([1, 2], array_keys($servers), 'both streaming servers, proxy excluded');
	}

	public function testGetStreamingSimpleMasksNamesForResellers(): void {
		$servers = ServerRepository::getStreamingSimple(['is_reseller' => 1], 'all');
		$this->assertSame('Server #1', $servers[1]['server_name']);
		$this->assertSame('Server #2', $servers[2]['server_name']);
	}

	public function testGetProxySimpleAcceptsNullPermissions(): void {
		$proxies = ServerRepository::getProxySimple(null);
		$this->assertSame([3], array_keys($proxies), 'only proxy-type servers');
	}
}
