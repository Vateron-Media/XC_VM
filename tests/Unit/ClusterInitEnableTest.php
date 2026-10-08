<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterInitCommand;
use XcVm\Core\Cluster\CredentialFreeConfig;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * `cluster:init --enable`, which the installer runs: a new panel starts on the
 * cluster API, and its new load balancers join in mode 2 when Settings would
 * let them (a credential-free config, the Redis connection handler off), else
 * in mode 1.
 */
final class ClusterInitEnableTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec("CREATE TABLE `settings` (`cluster_api_enabled` tinyint DEFAULT 0, `redis_handler` tinyint DEFAULT 0, `lb_new_node_mode` varchar(8) DEFAULT 'legacy')");
		$this->rDb->exec('INSERT INTO `settings` () VALUES ()');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		CredentialFreeConfig::useSeams(null);
		DatabaseFactory::reset();
	}

	/** @return array<string, mixed> */
	private function settings(): array {
		return $this->rDb->pdo->query('SELECT `cluster_api_enabled`, `lb_new_node_mode` FROM `settings`')->fetch(PDO::FETCH_ASSOC);
	}

	public function testNewNodesJoinInModeTwoWhenSettingsWouldAllowIt(): void {
		CredentialFreeConfig::useSeams(static fn(): bool => true);
		$this->assertSame(2, ClusterInitCommand::enable());
		$this->assertEquals(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api'], $this->settings());
	}

	public function testElseInModeOne(): void {
		CredentialFreeConfig::useSeams(static fn(): bool => false);
		$this->assertSame(1, ClusterInitCommand::enable(), 'an extension that packs no credential-free config');
		$this->assertEquals(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy'], $this->settings());

		CredentialFreeConfig::useSeams(static fn(): bool => true);
		$this->rDb->exec('UPDATE `settings` SET `redis_handler` = 1');
		$this->assertSame(1, ClusterInitCommand::enable(), 'the Redis connection handler on');
		$this->assertEquals(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy'], $this->settings());
	}

	public function testTheInstallerRunsItAndMainsDataPlaneAfter(): void {
		$rInstall = (string) file_get_contents(dirname(__DIR__, 2) . '/install');
		$this->assertMatchesRegularExpression('/cluster:init --enable && "\s*f"[^"]*cluster:main-dataplane on"/', $rInstall, 'MAIN\'s data plane only once the cluster API is on');
	}
}
