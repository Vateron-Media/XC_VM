<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * verify_host's list (`allowed_domains`, read by HostVerificationStage and
 * the streaming bootstrap): the enabled servers' names and addresses and the
 * active resellers' DNS. Nothing had written it since the move off
 * CoreUtilities, so every host passed; cron:cache writes it again.
 */
final class AllowedDomainsTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-domains-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir));
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_ip` text, `private_ip` text, `domain_name` text, `enabled` int)');
		$rDb->exec("INSERT INTO `servers` VALUES (1, '203.0.113.1', '10.0.0.1', 'panel.example.com,tv.example.com', 1), (5, '203.0.113.5', '', '', 1), (6, '203.0.113.6', '', 'off.example.com', 0)");
		$rDb->exec('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `reseller_dns` text, `status` int)');
		$rDb->exec("INSERT INTO `users` VALUES (1, 'reseller.example.net', 1), (2, 'gone.example.net', 0), (3, '', 1)");
		DatabaseFactory::set($rDb);
		(new \ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
	}

	protected function tearDown(): void {
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		(new \ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testTheListIsBuiltAndCachedForVerifyHost(): void {
		$rWant = ['127.0.0.1', 'localhost', 'panel.example.com', 'tv.example.com', '203.0.113.1', '10.0.0.1', '203.0.113.5', 'reseller.example.net'];
		$this->assertEqualsCanonicalizing($rWant, array_values(ServerRepository::getAllowedDomains(true)), 'enabled servers and active resellers only');
		$this->assertEqualsCanonicalizing($rWant, array_values((array) FileCache::getCache('allowed_domains')), 'the cache HostVerificationStage reads');

		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/CacheCronJob.php');
		$this->assertStringContainsString('ServerRepository::getAllowedDomains(true);', $rCron, 'cron:cache writes it every minute');
		$this->assertStringContainsString("FileCache::delCache('allowed_domains');", $rCron, 'a node in mode 2 keeps none');
		$this->assertStringContainsString('if (!ReplicaApply::owns(ReplicaSections::SERVERS)) {', $rCron, 'unless its replica owns the servers: the replica writes the list');
	}

	public function testTheReplicasServersAndResellersMakeTheDatabasesList(): void {
		// What ReplicaApply builds on a node: the servers cache rows (every
		// server, a disabled one too) and the section's `reseller_dns`.
		$rRows = [
			1 => ['enabled' => 1, 'server_ip' => '203.0.113.1', 'private_ip' => '10.0.0.1', 'domain_name' => 'panel.example.com,tv.example.com'],
			5 => ['enabled' => 1, 'server_ip' => '203.0.113.5', 'private_ip' => null, 'domain_name' => null],
			6 => ['enabled' => 0, 'server_ip' => '203.0.113.6', 'private_ip' => '', 'domain_name' => 'off.example.com'],
		];
		$this->assertEqualsCanonicalizing(ServerRepository::getAllowedDomains(true), ServerRepository::allowedDomains($rRows, ['reseller.example.net']));
		$this->assertSame(['127.0.0.1', 'localhost'], ServerRepository::allowedDomains([], []));
		$this->assertSame(['127.0.0.1', 'localhost', 'a.example.net'], ServerRepository::allowedDomains([], ['a.example.net', 'localhost', '']), 'once each, never empty');
	}
}
