<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\UpdateCommand;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\SignalSink;

/**
 * MAIN → node calls go through two catalogued seams: NodeRpc (system API
 * request/response) and NodeActions (root actions queued for the node's root
 * cron). The legacy transports are unchanged, and an action outside the
 * catalogue is refused.
 */
final class NodeRpcActionsTest extends TestCase {

	protected function tearDown(): void {
		NodeRpc::useTransport(null);
		SignalDispatcher::useSink(null);
	}

	private function captureSignals(): object {
		$rSink = new class implements SignalSink {
			/** @var list<array<string, mixed>> */
			public array $rRows = [];
			public function insert(array $rRows): bool {
				array_push($this->rRows, ...$rRows);
				return true;
			}
			public function pending(int $rServerID, string $rCustomData): bool {
				return false;
			}
		};
		SignalDispatcher::useSink($rSink);
		return $rSink;
	}

	public function testRpcGoesThroughTheTransport(): void {
		$rCalls = [];
		NodeRpc::useTransport(function (string $rMode, array $rIDs, array $rData, int $rTimeout) use (&$rCalls) {
			$rCalls[] = [$rMode, $rIDs, $rData, $rTimeout];
			return $rMode === 'request' ? '{"ok":1}' : ['result' => true];
		});
		$this->assertSame('{"ok":1}', NodeRpc::request(3, ['action' => 'get_pids'], 9));
		$this->assertSame(['result' => true], NodeRpc::broadcast(['4', 5], ['action' => 'force_stream', 'stream_id' => 1]));
		$this->assertSame([['request', [3], ['action' => 'get_pids'], 9], ['broadcast', [4, 5], ['action' => 'force_stream', 'stream_id' => 1], 0]], $rCalls);
	}

	public function testUnknownRpcActionIsRefused(): void {
		NodeRpc::useTransport(fn() => $this->fail('must not be sent'));
		$this->expectException(InvalidArgumentException::class);
		NodeRpc::request(1, ['action' => 'rm_rf']);
	}

	public function testRootActionsQueueTheSamePayloads(): void {
		$rSink = $this->captureSignals();
		NodeActions::flushBlocklist(2);
		NodeActions::setRamdisk(2, false);
		NodeActions::setPorts(2, 1, [80, 81], true);
		NodeActions::rollback(2, '2.3.1');
		class_exists(\XcVm\Core\Config\ConstantsInitializer::class); // defines XC_VM_VERSION
		NodeActions::update(2);
		NodeActions::certbot(2, ['a.example']);
		NodeActions::send(2, '{"action":"install_module","name":"x"}');
		$this->assertSame([
			'{"action":"flush"}', // RootSignalsCronJob matches this exact string
			'{"action":"disable_ramdisk"}',
			'{"action":"set_port","type":1,"ports":[80,81],"reload":true}',
			'{"action":"rollback","version":"2.3.1"}',
			(string) json_encode(['action' => 'update', 'version' => XC_VM_VERSION]), // MAIN's release, installed exactly
			'{"action":"certbot_generate","domain":["a.example"]}',
			'{"action":"install_module","name":"x"}',
		], array_column($rSink->rRows, 'custom_data'));
		$this->assertSame([2], array_values(array_unique(array_column($rSink->rRows, 'server_id'))));
	}

	/** An update names MAIN's release; only an x.y.z version is ever passed to the updater. */
	public function testAnUpdateNamesMainsReleaseOnly(): void {
		$this->assertSame('2.6.0', UpdateCommand::pinned('2.6.0'));
		$this->assertSame('2.6.0', UpdateCommand::pinned(' 2.6.0 '));
		foreach ([null, '', '2.6', 'v2.6.0', '2.6.0; rm -rf /', '2.6.0-beta', 260, ['2.6.0']] as $rBad) {
			$this->assertNull(UpdateCommand::pinned($rBad), var_export($rBad, true));
		}
		$rUpdate = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/UpdateCommand.php');
		$this->assertStringContainsString("\$gitRelease->getVersionFile('lb_update', \$rPinned)", $rUpdate, 'that release exactly, not the newest');
		$this->assertStringContainsString("version_compare(\$rPinned, XC_VM_VERSION, '<=')", $rUpdate, 'never down to it: that is a rollback');
	}

	public function testUnknownRootActionIsRefused(): void {
		$this->captureSignals();
		$this->expectException(InvalidArgumentException::class);
		NodeActions::send(1, ['action' => 'format_disk']);
	}

	public function testEveryCataloguedRootActionHasAHandler(): void {
		$rHandler = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		foreach (NodeActions::ROOT_ACTIONS as $rAction) {
			if ($rAction === 'flush') {
				// The legacy row is matched by its exact payload; a node.root flush runs as a case.
				$this->assertStringContainsString('{\\"action\\":\\"flush\\"}', $rHandler);
			}
			if ($rAction === XcVm\Core\Config\OpensslExtra::SIGNAL_ACTION) {
				$this->assertStringContainsString('case OpensslExtra::SIGNAL_ACTION:', $rHandler);
				continue;
			}
			$this->assertStringContainsString("case '" . $rAction . "':", $rHandler, $rAction);
		}
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testCallSitesUseCataloguedRpcActionsOnly(): void {
		$rRoot = MAIN_HOME;
		$rUsed = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS)) as $rFile) {
			$rPath = $rFile->getPathname();
			if ($rFile->getExtension() !== 'php' || str_contains($rPath, '/vendor/')) {
				continue;
			}
			$rSource = (string) file_get_contents($rPath);
			$this->assertDoesNotMatchRegularExpression('/ApiClient::(systemRequest|asyncRequest)\(/', str_ends_with($rPath, 'Cluster/NodeRpc.php') || str_ends_with($rPath, 'Http/ApiClient.php') ? '' : $rSource, $rPath);
			$this->assertStringNotContainsString('SignalDispatcher::rootAction(', str_contains($rPath, '/Core/Cluster/') ? '' : $rSource, $rPath);
			// A raw multi-request to the nodes' /api skips NodeRpc's signed commands.
			$this->assertStringNotContainsString('CurlClient::getMultiCURL(', str_ends_with($rPath, 'Http/ApiClient.php') ? '' : $rSource, $rPath);
			preg_match_all("/NodeRpc::(?:request|broadcast)\([^;]*?'action' => '([A-Za-z_]+)'/", $rSource, $rM);
			array_push($rUsed, ...$rM[1]);
		}
		$this->assertNotEmpty($rUsed);
		$this->assertSame([], array_values(array_diff(array_unique($rUsed), NodeRpc::ACTIONS)));
	}
}
