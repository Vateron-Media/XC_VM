<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCorePin;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\CorePins;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Pinning MAIN's panel key in each node's xcvm_core (core.pin; ADR 0004,
 * Phase 9), so the node's extension judges its lease itself.
 *
 * The node's root (NodeCorePin, `node.root pin_core`) against a fake
 * extension; MAIN (CorePins) against the TestDb: which nodes are offered, when,
 * with the pin or the question for the install_id, and what an ack records.
 */
final class CorePinTest extends TestCase {
	private const PANEL_PUB = "\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11";

	private const OTHER_PUB = "\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22";

	private const IID = '6c1b5bd2-2c86-4a4a-9a57-0f6e6d3b1f10';

	private TestDb $rDb;

	private string $rDir;

	private int $rNow = 1800000000;

	/** @var array<string, mixed> The fake extension's state: pinned key, install_id, what cluster_pin does. */
	private array $rExt = [];

	/** @var list<array{0: string, 1: list<mixed>}> */
	private array $rCalls = [];

	/** @var list<array{0: int, 1: ?string}> What MAIN queued: server id, blob (null: the question). */
	private array $rSent = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-corepin-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0700, true);
		$this->rExt = ['pinned' => null, 'install_id' => self::IID, 'pin_to' => self::PANEL_PUB, 'pin_error' => null, 'verdict' => true];
		NodeCorePin::useExtension(function (string $rMethod, mixed ...$rArgs): mixed {
			$this->rCalls[] = [$rMethod, $rArgs];
			switch ($rMethod) {
				case 'install_id':
					return $this->rExt['install_id'];
				case 'cluster_pinned':
					return $this->rExt['pinned'] === null ? false : ['panel_sign_pub' => $this->rExt['pinned']];
				case 'cluster_pin':
					if ($this->rExt['pin_error'] !== null) {
						return false;
					}
					if ($this->rExt['pinned'] !== null && $this->rExt['pinned'] !== $this->rExt['pin_to'] && !$rArgs[1]) {
						$this->rExt['pin_error_now'] = 'PIN_MISMATCH';
						return false;
					}
					$this->rExt['pinned'] = $this->rExt['pin_to'];
					file_put_contents($this->rDir . NodeCorePin::FILE, 'XCL1sealed');
					return ['panel_sign_pub' => $this->rExt['pin_to']];
				case 'cluster_last_error':
					return $this->rExt['pin_error'] ?? ($this->rExt['pin_error_now'] ?? null);
				case 'has':
					return $this->rExt['verdict'];
			}
			return null;
		}, $this->rDir);

		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec('CREATE TABLE `cluster_commands` (`server_id` int, `cmd_id` char(32), `type` varchar(32), `payload` text)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 1, `flows` int NOT NULL DEFAULT 0, `root_ready` int NOT NULL DEFAULT 1, `gen` int NOT NULL DEFAULT 1, `install_id` varchar(64) DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		foreach ([7, 8] as $rSid) {
			$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `flows`) VALUES (?, ?, ?)', $rSid, sprintf('0f8fad5b-d9cb-469f-a165-70867728950%d', $rSid), 2);
		}
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
		ClusterMeta::set('panel_sign_pub', base64_encode(self::PANEL_PUB));
	}

	protected function tearDown(): void {
		NodeCorePin::useExtension(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function send(): \Closure {
		return function (int $rSid, ?string $rBlob): bool {
			$this->rSent[] = [$rSid, $rBlob];
			return true;
		};
	}

	private static function pack(): \Closure {
		return static fn(string $rID): string => 'PIN-FOR-' . $rID;
	}

	private function command(string $rCmdID, array $rArgs): void {
		$this->rDb->query('INSERT INTO `cluster_commands` (`server_id`, `cmd_id`, `type`, `payload`) VALUES (7, ?, ?, ?)', $rCmdID, 'node.root', json_encode(['type' => 'node.root', 'action' => NodeCorePin::ACTION, 'args' => $rArgs]));
	}

	private function events(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): string => $rRow['event'] . ' ' . $rRow['detail'], $this->rDb->get_rows());
	}

	// ── The node's root ─────────────────────────────────────────────────────

	public function testTheActionIsCataloguedClusterOnlyAndRunByRoot(): void {
		$this->assertContains(NodeCorePin::ACTION, NodeActions::ROOT_ACTIONS);
		$this->assertContains(NodeCorePin::ACTION, NodeActions::CLUSTER_ONLY);
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("case 'pin_core':", $rSrc);
		$this->assertStringContainsString('NodeCorePin::run($rData, RootPin::read())', $rSrc);
	}

	public function testStepOneReportsTheInstallIdAndWhatIsPinned(): void {
		file_put_contents($this->rDir . 'install_id', self::IID);
		$this->assertSame(['install_id' => self::IID, 'pinned' => null, 'verdict' => true], NodeCorePin::outcome(NodeCorePin::run(['action' => 'pin_core'], null)));
		$this->rExt['pinned'] = self::PANEL_PUB;
		$this->assertSame(hash('sha256', self::PANEL_PUB), NodeCorePin::outcome(NodeCorePin::run(['action' => 'pin_core'], null))['pinned']);
	}

	public function testStepOneNeverCreatesAnInstallId(): void {
		$this->expectExceptionMessage('this node has no install_id');
		NodeCorePin::run(['action' => 'pin_core'], null);
	}

	public function testStepTwoPinsOnlyRootsKey(): void {
		$rRoot = ['pub' => self::PANEL_PUB, 'node' => 'x'];
		$rOut = NodeCorePin::outcome(NodeCorePin::run(['action' => 'pin_core', 'blob' => base64_encode(str_repeat('b', 100))], $rRoot));
		$this->assertSame(hash('sha256', self::PANEL_PUB), $rOut['pinned']);
		$this->assertSame(['cluster_pinned', 'cluster_pin', 'has'], array_column($this->rCalls, 0));
		$this->assertFalse($this->rCalls[1][1][1], 'nothing pinned before: no replace');

		// A blob for another key than root's: refused, and what it pinned is removed.
		$this->rExt = ['pinned' => null, 'pin_to' => self::OTHER_PUB, 'pin_error' => null, 'verdict' => true] + $this->rExt;
		try {
			NodeCorePin::run(['action' => 'pin_core', 'blob' => base64_encode(str_repeat('b', 100))], $rRoot);
			$this->fail('pinned another key');
		} catch (\RuntimeException $rE) {
			$this->assertStringContainsString('another panel key than root', $rE->getMessage());
		}
		$this->assertFileDoesNotExist($this->rDir . NodeCorePin::FILE);
	}

	public function testAPinOfAnotherPanelIsReplacedOneOfRootsIsKept(): void {
		$rRoot = ['pub' => self::PANEL_PUB, 'node' => 'x'];
		$this->rExt['pinned'] = self::OTHER_PUB; // a MAIN replaced since
		NodeCorePin::run(['action' => 'pin_core', 'blob' => base64_encode(str_repeat('b', 100))], $rRoot);
		$this->assertTrue($this->rCalls[1][1][1], 'replace');
		$this->assertSame(self::PANEL_PUB, $this->rExt['pinned']);

		$this->rCalls = [];
		$this->rExt['pin_to'] = self::OTHER_PUB; // a blob for another key, root's pinned
		try {
			NodeCorePin::run(['action' => 'pin_core', 'blob' => base64_encode(str_repeat('b', 100))], $rRoot);
			$this->fail('replaced root\'s pin');
		} catch (\RuntimeException $rE) {
			$this->assertStringContainsString('PIN_MISMATCH', $rE->getMessage());
		}
		$this->assertFalse($this->rCalls[1][1][1]);
		$this->assertSame(self::PANEL_PUB, $this->rExt['pinned']);
	}

	public function testStepTwoRefusals(): void {
		foreach ([
			[null, base64_encode(str_repeat('b', 100)), 'root\'s pin of the panel key is not in place'],
			[['pub' => self::PANEL_PUB, 'node' => 'x'], '', 'no pin blob'],
			[['pub' => self::PANEL_PUB, 'node' => 'x'], 'not base64!', 'no pin blob'],
			[['pub' => self::PANEL_PUB, 'node' => 'x'], base64_encode('short'), 'no pin blob'],
		] as [$rRoot, $rBlob, $rWhy]) {
			try {
				NodeCorePin::run(['action' => 'pin_core', 'blob' => $rBlob], $rRoot);
				$this->fail($rWhy);
			} catch (\RuntimeException $rE) {
				$this->assertStringContainsString($rWhy, $rE->getMessage());
			}
		}
		$this->rExt['pin_error'] = 'CRYPTO';
		$this->expectExceptionMessage('refused by xcvm_core: CRYPTO');
		NodeCorePin::run(['action' => 'pin_core', 'blob' => base64_encode(str_repeat('b', 100))], ['pub' => self::PANEL_PUB, 'node' => 'x']);
	}

	public function testAnOldExtensionRefusesCleanly(): void {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'cluster_pin')) {
			$this->markTestSkipped('this PHP loads an xcvm_core with the cluster API');
		}
		NodeCorePin::useExtension(null, $this->rDir);
		$this->expectExceptionMessage('has no install_id() (update xcvm_core first)');
		NodeCorePin::run(['action' => 'pin_core'], null);
	}

	// ── MAIN ────────────────────────────────────────────────────────────────

	public function testOfferAsksForTheInstallIdOrSendsThePin(): void {
		$this->rDb->exec("UPDATE `cluster_nodes` SET `install_id` = '" . self::IID . "' WHERE `server_id` = 8");
		$this->assertSame(2, CorePins::offer($this->send(), $this->rNow, self::pack()));
		$this->assertSame([[7, null], [8, 'PIN-FOR-' . self::IID]], $this->rSent);
		$this->assertSame(['node.core_pin {"step":"install_id","queued":true}', 'node.core_pin {"step":"pin","queued":true}'], $this->events());
		// Within the retry wait nothing is sent again; after it, again.
		$this->assertSame(0, CorePins::offer($this->send(), $this->rNow + 60, self::pack()));
		$this->assertSame(2, CorePins::offer($this->send(), $this->rNow + CorePins::RETRY_SEC, self::pack()));
	}

	public function testOnlyNodesThatTakeRootCommandsAndAreNotPinned(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `root_ready` = 0 WHERE `server_id` = 7');
		$this->rDb->exec("UPDATE `cluster_nodes` SET `state` = 'quarantined' WHERE `server_id` = 8");
		$this->assertSame(0, CorePins::offer($this->send(), $this->rNow, self::pack()));
		$this->rDb->exec("UPDATE `cluster_nodes` SET `root_ready` = 1, `state` = 'active'");
		$this->rDb->exec('UPDATE `cluster_nodes` SET `flows` = 0 WHERE `server_id` = 8'); // no COMMANDS
		$this->assertSame(1, CorePins::offer($this->send(), $this->rNow, self::pack()));
		$this->assertTrue(CorePins::recorded(7, hash('sha256', self::PANEL_PUB)));
		$this->assertTrue(CorePins::current(NodeRegistry::byServer(7)));
		$this->assertSame(0, CorePins::offer($this->send(), $this->rNow + 2 * CorePins::RETRY_SEC, self::pack()), 'pinned: done');
		// A new generation (a re-enrolment), or a new panel key: offered again.
		$this->rDb->exec('UPDATE `cluster_nodes` SET `gen` = 2 WHERE `server_id` = 7');
		$this->assertFalse(CorePins::current(NodeRegistry::byServer(7)));
		$this->rDb->exec('UPDATE `cluster_nodes` SET `gen` = 1 WHERE `server_id` = 7');
		ClusterMeta::set('panel_sign_pub', base64_encode(self::OTHER_PUB));
		$this->assertFalse(CorePins::current(NodeRegistry::byServer(7)));
	}

	public function testNoPanelKeyNoOffer(): void {
		$this->rDb->exec('DELETE FROM `cluster_meta`');
		$this->assertSame(0, CorePins::offer($this->send(), $this->rNow, self::pack()));
		$this->assertSame('cluster_pin_core_no_root_key', CorePins::request(7, 'admin', $this->send(), self::pack()));
	}

	public function testTheInstallIdAnswerIsRecordedAndThePinSent(): void {
		$this->command(str_repeat('a', 32), []);
		$rResult = json_encode(['core' => ['install_id' => self::IID, 'pinned' => null, 'verdict' => true]]);
		$this->assertFalse(CorePins::acked(7, str_repeat('a', 32), true, $rResult, self::pack(), $this->send()));
		$this->assertSame(self::IID, CorePins::installId(7));
		$this->assertSame([[7, 'PIN-FOR-' . self::IID]], $this->rSent);
	}

	public function testThePinnedAnswerIsRecorded(): void {
		$this->command(str_repeat('b', 32), ['blob' => 'x']);
		$this->assertTrue(CorePins::acked(7, str_repeat('b', 32), true, "pinned\n" . json_encode(['core' => ['pinned' => hash('sha256', self::PANEL_PUB), 'verdict' => true]])));
		$this->assertNotNull(CorePins::state(7)['pinned_at']);
		$this->assertContains('node.core_pinned {"fp":"' . substr(hash('sha256', self::PANEL_PUB), 0, 16) . '"}', $this->events());
	}

	public function testAPinThatDidNotOpenForgetsTheInstallId(): void {
		$this->rDb->exec("UPDATE `cluster_nodes` SET `install_id` = '" . self::IID . "' WHERE `server_id` = 7");
		$this->command(str_repeat('c', 32), ['blob' => 'x']);
		$this->assertFalse(CorePins::acked(7, str_repeat('c', 32), false, 'pin_core: refused by xcvm_core: CRYPTO'));
		$this->assertNull(CorePins::installId(7), 'asked again next time');
		$this->assertStringStartsWith('node.core_pin_failed', $this->events()[0]);
	}

	public function testOtherAcksAreNotRead(): void {
		$this->rDb->query('INSERT INTO `cluster_commands` VALUES (7, ?, ?, ?)', str_repeat('d', 32), 'node.root', json_encode(['action' => 'reboot']));
		$this->assertFalse(CorePins::acked(7, str_repeat('d', 32), true, json_encode(['core' => ['pinned' => hash('sha256', self::PANEL_PUB)]])));
		$this->command(str_repeat('e', 32), []);
		$this->assertFalse(CorePins::acked(7, str_repeat('e', 32), true, '{"queued":true}'), 'root had not finished');
		$this->assertSame([], $this->rSent);
	}

	public function testRequestNeedsRootCommands(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `root_ready` = 0 WHERE `server_id` = 7');
		$this->assertSame('cluster_pin_core_no_root', CorePins::request(7, 'admin', $this->send(), self::pack()));
		$this->assertSame('cluster_not_enrolled', CorePins::request(99, 'admin', $this->send(), self::pack()));
		$this->assertNull(CorePins::request(8, 'admin:2', $this->send(), self::pack()));
		$this->assertSame([[8, null]], $this->rSent);
	}

	public function testPackForUsesTheRecordedInstallId(): void {
		$rCrypto = new FakeClusterCrypto();
		$this->assertNull(CorePins::packFor($rCrypto, 7));
		$this->assertTrue(CorePins::rememberInstallId(7, self::IID));
		$this->assertFalse(CorePins::rememberInstallId(7, 'bad id!'));
		$this->assertStringContainsString(self::IID, (string) CorePins::packFor($rCrypto, 7));
	}
}
