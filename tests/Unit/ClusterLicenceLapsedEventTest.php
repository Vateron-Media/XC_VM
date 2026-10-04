<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\Cluster\ClusterLicenceLapsedEvent;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\LeaseService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * A lease the extension refuses for want of a licence dispatches
 * ClusterLicenceLapsedEvent (ADR 0004, Phase 9); a refusal for any other
 * reason does not, and neither does a lease signed. The token still goes out
 * without a lease whatever a listener does.
 */
final class ClusterLicenceLapsedEventTest extends TestCase {
	private TestDb $rDb;

	/** @var list<ClusterLicenceLapsedEvent> */
	private array $rSeen = [];

	private const NODE = ['node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'server_id' => 5, 'gen' => 2];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int NOT NULL, `server_id` int, `actor` varchar(64), `event` varchar(64) NOT NULL, `detail` text, `ip` varchar(45))');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['lb_partition_tolerance_h' => 6]);
		ClusterClock::fix(1_800_000_000_000);
		EventDispatcher::resetInstance();
		EventDispatcher::listen(ClusterLicenceLapsedEvent::class, function (ClusterLicenceLapsedEvent $rEvent): void {
			$this->rSeen[] = $rEvent;
		});
	}

	protected function tearDown(): void {
		EventDispatcher::resetInstance();
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	public function testALicenceRefusalDispatchesTheEvent(): void {
		$rCrypto = new FakeClusterCrypto();
		$rCrypto->rLicensed = false;
		$this->assertNull(LeaseService::issue($rCrypto, self::NODE, 1_800_003_600));
		$this->assertCount(1, $this->rSeen);
		$this->assertSame(5, $this->rSeen[0]->serverId);
		$this->assertSame(1_800_003_600, $this->rSeen[0]->tokenExp);
		$this->assertSame(6, $this->rSeen[0]->toleranceH);
		$this->assertSame(LeaseService::LICENCE, $this->rSeen[0]->reason);
		$this->rDb->query('SELECT `event`, `server_id` FROM `cluster_audit`;');
		$this->assertSame([['event' => 'node.lease_refused', 'server_id' => 5]], array_map(static fn(array $rRow): array => ['event' => $rRow['event'], 'server_id' => (int) $rRow['server_id']], $this->rDb->get_rows()), 'audited as before');
	}

	public function testOtherRefusalsDoNot(): void {
		foreach (['CLOCK', 'REVOKED'] as $rReason) {
			$rCrypto = new FakeClusterCrypto();
			$rCrypto->rRefuseLease = $rReason;
			$this->assertNull(LeaseService::issue($rCrypto, self::NODE, 1_800_003_600), $rReason);
		}
		$this->assertSame([], $this->rSeen);
	}

	public function testASignedLeaseDoesNot(): void {
		$this->assertNotNull(LeaseService::issue(new FakeClusterCrypto(), self::NODE, 1_800_003_600));
		$this->assertSame([], $this->rSeen);
	}

	public function testAFailingListenerCostsTheNodeNothing(): void {
		EventDispatcher::listen(ClusterLicenceLapsedEvent::class, static function (): void {
			throw new \RuntimeException('a listener broke');
		});
		$rCrypto = new FakeClusterCrypto();
		$rCrypto->rRefuseLease = LeaseService::LICENCE;
		$this->assertNull(LeaseService::issue($rCrypto, self::NODE, 1_800_003_600), 'the token goes out without a lease');
	}
}
