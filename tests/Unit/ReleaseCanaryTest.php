<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutBinaryCommand;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\ReleaseCanary;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The fleet canary for the binaries every server takes from GitHub: MAIN
 * raises the pin to the canary's release once the canary has run it for the
 * hours set, active and heard throughout (ReleaseCanary), and every other
 * server takes the newest release at or below the pin (FanoutBinaryCommand).
 */
final class ReleaseCanaryTest extends TestCase {
	private const T = 1800000000;

	private const ON = ['lb_binary_canary_server' => 7, 'lb_binary_canary_hours' => 24];

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 2, `gen` int NOT NULL DEFAULT 1, `agent_version` varchar(32), `last_seen_at` bigint DEFAULT NULL)");
		$this->rDb->exec("CREATE TABLE `settings` (`id` int, `lb_release_pin` varchar(32) DEFAULT '')");
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, '')");
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`) VALUES (7, ?)', '0f8fad5b-d9cb-469f-a165-70867728950e');
		DatabaseFactory::set($this->rDb);
		foreach ([NodeRegistry::class, ClusterMeta::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/** The canary runs $rVersion, heard $rAgo s before $rAt. */
	private function canary(?string $rVersion, int $rAt, int $rAgo = 2, string $rState = 'active'): void {
		$this->rDb->query('UPDATE `cluster_nodes` SET `agent_version` = ?, `last_seen_at` = ?, `state` = ? WHERE `server_id` = 7', $rVersion, ($rAt - $rAgo) * 1000, $rState);
		ClusterClock::fix($rAt * 1000);
	}

	private function pin(): string {
		return (string) $this->rDb->pdo->query('SELECT `lb_release_pin` FROM `settings`')->fetchColumn();
	}

	private function tick(array $rSettings = self::ON, ?int $rAt = null): ?string {
		return ReleaseCanary::tick($rSettings + ['lb_release_pin' => $this->pin()], $rAt);
	}

	/** @return list<string> */
	private function pins(): array {
		return $this->rDb->pdo->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'release.pin' ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testThePinFollowsWhatTheCanaryHasRunLongEnough(): void {
		$this->canary('0.14.5', self::T);
		$this->assertNull($this->tick(rAt: self::T), 'first heard on it');
		$this->assertSame('0.14.5 ' . self::T, ClusterMeta::get(ReleaseCanary::SEEN));
		$this->canary('0.14.5', self::T + 24 * 3600 - 1);
		$this->assertNull($this->tick(rAt: self::T + 24 * 3600 - 1), 'not yet');
		$this->canary('0.14.5', self::T + 24 * 3600);
		$this->assertSame('0.14.5', $this->tick(rAt: self::T + 24 * 3600));
		$this->assertSame('0.14.5', $this->pin());
		$this->assertNull($this->tick(rAt: self::T + 24 * 3600 + 60), 'once');

		// A new release starts the count again; a rollback lowers nothing.
		$rAt = self::T + 30 * 3600;
		$this->canary('v0.14.6', $rAt);
		$this->tick(rAt: $rAt);
		$this->canary('0.14.6', $rAt + 24 * 3600);
		$this->assertSame('0.14.6', $this->tick(rAt: $rAt + 24 * 3600));
		$this->canary('0.14.5', $rAt + 25 * 3600);
		$this->tick(rAt: $rAt + 25 * 3600);
		$this->canary('0.14.5', $rAt + 50 * 3600);
		$this->assertNull($this->tick(rAt: $rAt + 50 * 3600), 'run.sh put the previous agent back: the pin stays');
		$this->assertSame('0.14.6', $this->pin());
		$this->assertSame(['{"pin":"0.14.5","was":""}', '{"pin":"0.14.6","was":"0.14.5"}'], $this->pins());
	}

	public function testACanaryNotHeardOrNotActiveStartsTheCountAgain(): void {
		$this->canary('0.14.5', self::T);
		$this->tick(rAt: self::T);
		$this->canary('0.14.5', self::T + 3600, 31);
		$this->assertNull($this->tick(rAt: self::T + 3600), 'silent past cluster_offline_after_sec');
		$this->assertNull(ClusterMeta::get(ReleaseCanary::SEEN));
		$this->canary('0.14.5', self::T + 7200);
		$this->tick(rAt: self::T + 7200);
		$this->canary('0.14.5', self::T + 7200 + 24 * 3600, 2, 'quarantined');
		$this->assertNull($this->tick(rAt: self::T + 7200 + 24 * 3600));
		$this->assertNull(ClusterMeta::get(ReleaseCanary::SEEN));
		$this->canary(null, self::T + 9000);
		$this->assertNull($this->tick(rAt: self::T + 9000), 'no version reported');
		$this->tick(['lb_binary_canary_server' => 8] + self::ON, self::T + 9000);
		$this->assertSame('', $this->pin(), 'a canary that is no node holds the fleet where it is');
	}

	public function testOffThePinGoes(): void {
		$this->rDb->exec("UPDATE `settings` SET `lb_release_pin` = '0.14.5'");
		ClusterMeta::set(ReleaseCanary::SEEN, '0.14.5 ' . self::T);
		$this->assertNull($this->tick(['lb_binary_canary_server' => 0]));
		$this->assertSame('', $this->pin());
		$this->assertNull(ClusterMeta::get(ReleaseCanary::SEEN));
		$this->assertSame(['{"pin":"","was":"0.14.5"}'], $this->pins());
	}

	public function testEachServerTakesTheNewestReleaseAtOrBelowThePin(): void {
		$rTags = ['0.15.0', 'v0.14.6', '0.14.5', '0.14.4'];
		$this->assertSame('0.15.0', FanoutBinaryCommand::releaseFor($rTags, [], 3), 'no canary: the newest');
		$this->assertSame('0.15.0', FanoutBinaryCommand::releaseFor($rTags, self::ON, 7), 'the canary itself');
		$this->assertNull(FanoutBinaryCommand::releaseFor($rTags, self::ON, 3), 'nothing pinned yet: held');
		$this->assertSame('0.14.5', FanoutBinaryCommand::releaseFor($rTags, self::ON + ['lb_release_pin' => '0.14.5'], 3));
		$this->assertSame('v0.14.6', FanoutBinaryCommand::releaseFor($rTags, self::ON + ['lb_release_pin' => 'v0.14.6'], 1), 'MAIN too');
		$this->assertSame('0.14.4', FanoutBinaryCommand::releaseFor($rTags, self::ON + ['lb_release_pin' => '0.14.4.9'], 3));
		$this->assertNull(FanoutBinaryCommand::releaseFor($rTags, self::ON + ['lb_release_pin' => '0.13.0'], 3), 'none so old is listed');
	}

	public function testAServerPastThePinIsNotTakenBack(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/FanoutBinaryCommand.php');
		$this->assertSame(2, substr_count($rSource, "&& \$rInstalled !== null && version_compare(\$rInstalled, \$rLatest, '>')) {"), 'xc_fanout and xc_agent');
		$this->assertStringContainsString("if (\$rHeld && \$rHealthy && \$rInstalled !== null && version_compare(\$rInstalled, \$rLatest, '>')) {", $rSource);
		$this->assertStringContainsString("if (\$rHeld && \$rInstalled !== null && version_compare(\$rInstalled, \$rLatest, '>')) {", $rSource);
	}
}
