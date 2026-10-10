<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Cli\Commands\ClusterRootCommand;
use XcVm\Cli\Commands\FanoutBinaryCommand;
use XcVm\Cli\Commands\ModuleInstallCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Logging\FileLogger;
use XcVm\Streaming\Delivery\OffAirHandler;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * An artefact on the node (plan section 7, "Root artefacts": staged in
 * root-owned /etc/xc_vm/cluster/stage/ and checked for size and SHA-256
 * before exec, a mismatch refused and audited; section 14: "tampered
 * artefacts refused before extraction").
 *
 * The agent downloads what MAIN granted into its own directory
 * (config/cluster/artefacts/<cmd_id>), then hands the command on: an
 * off-air video's `artefact.fetch` to cluster:exec, which places it where
 * the node's off-air code plays it; a `node.root` that carries a grant to
 * cluster:root, which copies the download into its own stage and checks it
 * there before the action runs. Both check the bytes they use against the
 * size and SHA-256 of the signed grant, whatever the agent checked, and
 * refuse and audit anything else.
 */
final class ArtefactHashRefusalTest extends TestCase {
	private const NODE = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rBase;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rBase = sys_get_temp_dir() . '/artefact_node_' . bin2hex(random_bytes(4)) . '/';
		foreach (['etc', 'config/cluster/artefacts', 'video', 'bin/xc_agent', 'logs'] as $rDir) {
			mkdir($this->rBase . $rDir, 0755, true);
		}
		mkdir($this->rBase . 'config/cluster/root-inbox', 0700);
		file_put_contents($this->rBase . 'bin/xc_agent/xc_agent', 'the running agent');
		// The node's tree is xc_vm's (nobody here, when the suite runs as root); /etc's pin is root's.
		AgentUser::own($this->rBase . 'config', $this->rBase . 'video', $this->rBase . 'bin', $this->rBase . 'logs');
		RootPin::useDirs($this->rBase . 'etc/', $this->rBase . 'config/cluster/root-inbox/');
		ArtefactStage::useDirs($this->rBase . 'config/cluster/artefacts/', $this->rBase . 'video/cluster/', $this->rBase . 'bin/xc_agent/xc_agent');
		EventSpool::useDir($this->rBase . 'config/cluster/spool/');
		FileLogger::setLogFile($this->rBase . 'logs/error_log.log');
		$this->rCrypto = new FakeClusterCrypto();
		$this->assertTrue(RootPin::write($this->rCrypto->info()['panel_sign_pub'], self::NODE));
		$this->flows(NodeFlows::COMMANDS | NodeFlows::LOGS);
	}

	protected function tearDown(): void {
		RootPin::useDirs(null, null);
		ArtefactStage::useDirs(null, null);
		RootSignalsCronJob::useRunner(null);
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		FileLogger::setLogFile(null);
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rBase . 'config/cluster/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		AgentUser::own($this->rBase . 'config/cluster/flows.json');
		NodeFlows::usePath($this->rBase . 'config/cluster/flows.json');
	}

	/** A grant as MAIN signs it into the command, for these bytes. */
	private function grant(string $rId, string $rName, string $rBytes, array $rOver = []): array {
		return $rOver + ['id' => $rId, 'name' => $rName, 'size' => strlen($rBytes), 'sha256' => hash('sha256', $rBytes), 'mtime' => 1799990000, 'exp' => 1800003600];
	}

	/** A command as the agent hands it on: the signed document, decoded, and its wire form. */
	private function command(int $rSeq, string $rType, array $rArgs): array {
		// A root action is the envelope's `action`, as CommandBus signs it.
		$rAction = in_array($rType, \XcVm\Domain\Cluster\CommandBus::ACTION_TYPES, true) ? ['action' => $rArgs['action']] : [];
		$rArgs = array_diff_key($rArgs, $rAction);
		$rDoc = (string) json_encode(['v' => 1, 'type' => $rType] + $rAction + ['exp' => 1800003600, 'iat' => 1800000000, 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => $rSeq, 'node_uuid' => self::NODE, 'gen' => 1, 'dedupe_key' => null, 'args' => (object) $rArgs], JSON_UNESCAPED_SLASHES);
		return ['cmd' => json_decode($rDoc, true), 'wire' => ['doc' => $rDoc, 'sig' => Enc::b64url($this->rCrypto->sign('cmd', $rDoc))]];
	}

	/** What the agent wrote after its own download: config/cluster/artefacts/<cmd_id>, xc_vm's. */
	private function download(array $rCmd, string $rBytes): string {
		$rPath = $this->rBase . 'config/cluster/artefacts/' . $rCmd['cmd_id'];
		file_put_contents($rPath, $rBytes);
		AgentUser::own($rPath);
		return $rPath;
	}

	/** @return list<array{0: string, 1: string}> the system log lines spooled for MAIN: [type, error] */
	private function audited(): array {
		$rOut = [];
		foreach (glob($this->rBase . 'config/cluster/spool/p1/*.ndjson') ?: [] as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rEvent = json_decode($rLine, true);
				foreach ($rEvent['d']['rows'] ?? [] as $rRow) {
					$rOut[] = [$rEvent['type'] . ':' . $rRow['type'], (string) $rRow['error']];
				}
			}
		}
		return $rOut;
	}

	/** @return list<string> the stage's entries */
	private function staged(): array {
		return is_dir($this->rBase . 'etc/stage') ? array_values(array_diff(scandir($this->rBase . 'etc/stage') ?: [], ['.', '..'])) : [];
	}

	private function inbox(int $rSeq, array $rWire): void {
		file_put_contents($this->rBase . 'config/cluster/root-inbox/' . $rSeq . '.json', json_encode($rWire));
	}

	private function done(int $rSeq): array {
		return json_decode((string) file_get_contents($this->rBase . 'config/cluster/root-inbox/' . $rSeq . '.done'), true);
	}

	/** An xc_agent that starts: its `version` prints one, as the real one does. */
	private function runnableAgent(string $rVersion = '1.5.0'): string {
		return "#!/bin/sh\necho " . $rVersion . "\n# " . bin2hex(random_bytes(64)) . "\n";
	}

	/** Run an action as cluster:root does (ClusterRootCommand::runAction), on a node with no database to reach. */
	private function runAction(array $rAction): string {
		$rDb = new class {
			public function query(string $rQuery, mixed ...$rArgs): bool {
				throw new \RuntimeException('no database here: ' . $rQuery);
			}
		};
		ob_start();
		try {
			(new RootSignalsCronJob())->executeAction($rAction, [], $rDb);
		} finally {
			$rOutput = (string) ob_get_clean();
		}
		return $rOutput;
	}

	/** The happy path of an off-air video: placed where the node's off-air code plays it. */
	public function testAnOffAirVideoIsPlacedOnceItsSizeAndHashAreTheGrants(): void {
		$rBytes = random_bytes(7000);
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rBytes);
		$this->assertIsArray(ClusterExecCommand::verify($rOne['wire'], ['node_uuid' => self::NODE, 'panel_sign_pub' => base64_encode($this->rCrypto->info()['panel_sign_pub'])], 1800000000));
		ob_start();
		$rExit = ClusterExecCommand::run($rOne['cmd']);
		$rOut = json_decode((string) ob_get_clean(), true);
		$this->assertSame(0, $rExit);
		$this->assertSame(['placed' => 'custom_offline.ts', 'size' => 7000, 'sha256' => hash('sha256', $rBytes)], $rOut);
		$rPlaced = $this->rBase . 'video/cluster/custom_offline.ts';
		$this->assertSame($rBytes, file_get_contents($rPlaced));
		$this->assertSame(0644, fileperms($rPlaced) & 0777);
		if (AgentUser::root()) {
			$this->assertSame(AgentUser::UID, fileowner($rPlaced), 'the node\'s tree stays xc_vm\'s');
		}
		$this->assertFileDoesNotExist($rDownload, 'the download is spent');
		$this->assertSame(['custom_offline.ts'], array_values(array_diff(scandir($this->rBase . 'video/cluster'), ['.', '..'])), 'no temporary file left');
		$this->assertSame([], $this->audited());

		// MAIN's token names MAIN's path: the node plays its verified copy
		// when it has no file there, and its own file when it has one.
		$this->assertSame($rPlaced, OffAirHandler::localVideo('/home/xc_vm/content/video/custom_offline.ts'));
		$rLocal = $this->rBase . 'video/offline.ts';
		file_put_contents($rLocal, 'default');
		$this->assertSame($rLocal, OffAirHandler::localVideo($rLocal));
		foreach (['/home/xc_vm/content/video/other.ts', 'http://cdn/x/custom_offline.ts?a=1', '/x/../custom_offline.ts/', ''] as $rPath) {
			$this->assertSame($rPath, OffAirHandler::localVideo($rPath), $rPath);
		}

		// A token names the video, and the node finds it from its own
		// settings (the replica's): MAIN's path never has to be in it.
		$rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['not_on_air_video_path' => '/home/xc_vm/content/video/custom_offline.ts'];
		try {
			$this->assertSame($rPlaced, OffAirHandler::tokenVideo(['off_air' => 'not_on_air']));
			$this->assertSame($rPlaced, OffAirHandler::tokenVideo(['off_air' => 'not_on_air', 'video_path' => '/elsewhere/x.ts']), 'the name wins');
			$this->assertSame($rPlaced, OffAirHandler::tokenVideo(['video_path' => '/home/xc_vm/content/video/custom_offline.ts']), 'an older MAIN\'s token');
			$this->assertSame('/elsewhere/x.ts', OffAirHandler::tokenVideo(['off_air' => 'nope', 'video_path' => '/elsewhere/x.ts']), 'an unknown name: the path');
		} finally {
			$GLOBALS['rSettings'] = $rSettings;
		}
	}

	/** A download whose bytes are not the grant's is refused before it is placed, and audited for MAIN. */
	public function testATamperedVideoIsRefusedAndAudited(): void {
		$rBytes = str_repeat('v', 4000);
		file_put_contents($this->rBase . 'video/offline.ts', 'the default');
		mkdir($this->rBase . 'video/cluster');
		file_put_contents($this->rBase . 'video/cluster/custom_offline.ts', 'the one verified before');
		AgentUser::own($this->rBase . 'video');
		foreach ([
			'sha256 mismatch' => str_repeat('V', 4000),
			'size mismatch' => $rBytes . 'x',
			'size mismatch ' => substr($rBytes, 1),
		] as $rWhy => $rGot) {
			$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes)]);
			$rDownload = $this->download($rOne['cmd'], $rGot);
			$rRefused = ArtefactStage::placeOffAir($rOne['cmd']);
			$this->assertIsString($rRefused);
			$this->assertStringStartsWith('artefact refused: offair/not_on_air', $rRefused);
			$this->assertStringContainsString(trim($rWhy), $rRefused);
			$this->assertSame('the one verified before', file_get_contents($this->rBase . 'video/cluster/custom_offline.ts'), $rWhy . ': nothing replaced');
			$this->assertSame(['custom_offline.ts'], array_values(array_diff(scandir($this->rBase . 'video/cluster'), ['.', '..'])), 'no partial file left');
			$this->assertFileDoesNotExist($rDownload);
		}
		// Through cluster:exec: refused, so the agent acks it failed.
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes)]);
		$this->download($rOne['cmd'], str_repeat('V', 4000));
		ob_start();
		$rExit = ClusterExecCommand::run($rOne['cmd']);
		$this->assertSame('', ob_get_clean(), 'no result: the refusal goes to stderr');
		$this->assertSame(1, $rExit);
		$this->assertSame('the one verified before', file_get_contents($this->rBase . 'video/cluster/custom_offline.ts'));

		$rLines = $this->audited();
		$this->assertCount(4, $rLines);
		foreach ($rLines as [$rType, $rError]) {
			$this->assertSame('log.syslog:ARTEFACT', $rType);
			$this->assertStringStartsWith('Refused artefact offair/not_on_air (custom_offline.ts) for command ', $rError);
		}
		$this->assertStringContainsString('sha256 mismatch', $rLines[0][1]);

		// LOGS off: the panel's error log keeps it, never MAIN's database.
		$this->flows(NodeFlows::COMMANDS);
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/banned', 'custom_banned.ts', 'b')]);
		$this->download($rOne['cmd'], 'B');
		$this->assertIsString(ArtefactStage::placeOffAir($rOne['cmd']));
		$rLogged = array_map(static fn(string $rLine): array => json_decode((string) base64_decode($rLine), true), file($this->rBase . 'logs/error_log.log', FILE_IGNORE_NEW_LINES) ?: []);
		$this->assertSame('artefact', $rLogged[0]['type']);
		$this->assertStringContainsString('Refused artefact offair/banned', $rLogged[0]['message']);
	}

	/** Nothing is read or written for a grant the node cannot trust the shape of, nor through a link. */
	public function testAGrantItCannotTrustPlacesNothing(): void {
		$rBytes = 'vvvv';
		$rGood = $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes);
		foreach ([
			'no grant' => [],
			'a traversing id' => ['artefact' => ['id' => 'offair/../../etc/passwd'] + $rGood],
			'a traversing name' => ['artefact' => ['name' => '../../../etc/cron.d/x'] + $rGood],
			'a hidden name' => ['artefact' => ['name' => '.htaccess'] + $rGood],
			'a name with a slash' => ['artefact' => ['name' => 'a/b.ts'] + $rGood],
			'an empty artefact' => ['artefact' => ['size' => 0] + $rGood],
			'a malformed hash' => ['artefact' => ['sha256' => 'abc'] + $rGood],
			'no expiry' => ['artefact' => ['exp' => null] + $rGood],
			'a root artefact' => ['artefact' => ['id' => 'module/radio/1.0'] + $rGood],
		] as $rWhy => $rArgs) {
			$rOne = $this->command(1, 'artefact.fetch', $rArgs);
			$this->download($rOne['cmd'], $rBytes);
			$this->assertIsString(ArtefactStage::placeOffAir($rOne['cmd']), $rWhy);
			$this->assertFileDoesNotExist($this->rBase . 'video/cluster/custom_offline.ts', $rWhy);
			$this->assertFileDoesNotExist($this->rBase . 'etc/cron.d/x');
		}
		// No download at all.
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $rGood]);
		$this->assertStringContainsString('not downloaded', (string) ArtefactStage::placeOffAir($rOne['cmd']));
		// A link planted where the download should be is not followed.
		file_put_contents($this->rBase . 'elsewhere', $rBytes);
		symlink($this->rBase . 'elsewhere', $this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id']);
		$this->assertStringContainsString('not a file', (string) ArtefactStage::placeOffAir($rOne['cmd']));
		$this->assertFileDoesNotExist($this->rBase . 'video/cluster/custom_offline.ts');
		$this->assertSame($rBytes, file_get_contents($this->rBase . 'elsewhere'), 'what it named is untouched');
		$this->assertFalse(is_link($this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id']), 'the link itself is spent');
	}

	/**
	 * The happy path of a root artefact: root copies the agent's download
	 * into its own stage, checks it there, and only then runs the action,
	 * which takes the staged copy.
	 */
	public function testRootStagesAndChecksAnArtefactBeforeTheActionRuns(): void {
		$rBytes = 'PK' . random_bytes(3000);
		$rOne = $this->command(3, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rBytes);
		$this->inbox(3, $rOne['wire']);
		$rSeen = [];
		$rRun = function (array $rAction) use (&$rSeen, $rBytes, $rDownload): string {
			$rStaged = ArtefactStage::current();
			$this->assertNotNull($rStaged, 'the action is handed the staged copy');
			$rSeen = [$rAction['action'], $rStaged['path'], $rStaged['grant']['id']];
			$this->assertStringStartsWith($this->rBase . 'etc/stage/', $rStaged['path'], 'in root\'s own stage');
			$this->assertSame($rBytes, file_get_contents($rStaged['path']));
			$this->assertSame(0600, fileperms($rStaged['path']) & 0777);
			$this->assertSame(0700, fileperms($this->rBase . 'etc/stage') & 0777);
			$this->assertFileDoesNotExist($rDownload, 'the agent\'s copy is gone before the action runs');
			return 'installed';
		};
		$rDone = ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertSame('install_module', $rSeen[0] ?? null, json_encode($this->done(3)));
		$this->assertSame('module/radio/1.0', $rSeen[2] ?? null);
		$this->assertSame([['seq' => 3, 'ok' => true, 'detail' => 'install_module']], $rDone);
		$this->assertSame(['ok' => true, 'result' => 'installed'], $this->done(3));
		$this->assertNull(ArtefactStage::current(), 'only while its action runs');
		$this->assertSame([], $this->staged(), 'the staged copy goes once the action ran');
	}

	/** ArtefactHashRefusal: a tampered archive is refused before its action runs, the refusal audited. */
	public function testRootRefusesATamperedArtefactAndAuditsIt(): void {
		$rBytes = random_bytes(20000);
		$rTampered = $rBytes;
		$rTampered[100] = chr(ord($rTampered[100]) ^ 1);
		$rOne = $this->command(4, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rTampered);
		$this->inbox(4, $rOne['wire']);
		$rRan = false;
		$rDone = ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan, 'refused before exec');
		$this->assertFalse($rDone[0]['ok']);
		$rResult = $this->done(4);
		$this->assertFalse($rResult['ok']);
		$this->assertStringStartsWith('refused by root: artefact refused: module/radio/1.0', $rResult['result']);
		$this->assertStringContainsString('sha256 mismatch', $rResult['result']);
		$this->assertSame([], $this->staged(), 'nothing left staged');
		$this->assertFileDoesNotExist($rDownload);
		$this->assertSame(4, RootPin::highWater(), 'spent: a replay is refused too');
		$rLines = $this->audited();
		$this->assertCount(1, $rLines);
		$this->assertSame('log.syslog:ARTEFACT', $rLines[0][0]);
		$this->assertStringContainsString('module/radio/1.0', $rLines[0][1]);
		$this->assertStringContainsString($rOne['cmd']['cmd_id'], $rLines[0][1]);
	}

	/** Root's stage is its own: closed to others when it finds it open, and never a link. */
	public function testRootsStageIsItsOwn(): void {
		$rBytes = 'PK the archive';
		mkdir($this->rBase . 'etc/stage', 0755);
		chmod($this->rBase . 'etc/stage', 0755);
		$rOne = $this->command(7, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$this->download($rOne['cmd'], $rBytes);
		$this->inbox(7, $rOne['wire']);
		ClusterRootCommand::drain(static fn(): string => 'ok', 1800000000);
		$this->assertTrue($this->done(7)['ok']);
		$this->assertSame(0700, fileperms($this->rBase . 'etc/stage') & 0777, 'closed to others');

		// A link where the stage should be: refused, nothing written through it.
		rmdir($this->rBase . 'etc/stage');
		mkdir($this->rBase . 'elsewhere', 0700);
		symlink($this->rBase . 'elsewhere', $this->rBase . 'etc/stage');
		$rTwo = $this->command(8, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$this->download($rTwo['cmd'], $rBytes);
		$this->inbox(8, $rTwo['wire']);
		$rRan = false;
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('is not root\'s alone', $this->done(8)['result']);
		$this->assertSame([], array_values(array_diff(scandir($this->rBase . 'elsewhere'), ['.', '..'])));
	}

	/** Root never reads the agent's download with its own rights, nor follows a link planted there. */
	public function testRootNeverFollowsALinkTheAgentPlanted(): void {
		$rSecret = $this->rBase . 'root_only';
		file_put_contents($rSecret, 'root only');
		chmod($rSecret, 0600);
		$rOne = $this->command(5, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', 'root only')]);
		symlink($rSecret, $this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id']);
		$this->inbox(5, $rOne['wire']);
		$rRan = false;
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('artefact refused', $this->done(5)['result']);
		$this->assertSame([], $this->staged());
		$this->assertSame('root only', file_get_contents($rSecret));

		// Nor without a download: refused, not run.
		$rTwo = $this->command(6, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', 'PK')]);
		$this->inbox(6, $rTwo['wire']);
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('not downloaded', $this->done(6)['result']);
	}

	/** The actions that use an artefact take it only from root's stage, checked: never from a signals row or a path a payload names. */
	public function testActionsTakeOnlyAStagedArtefact(): void {
		$rDb = new class {
			public function query(string $rQuery, mixed ...$rArgs): bool {
				throw new \RuntimeException('no database here: ' . $rQuery);
			}
		};
		// The binaries an older MAIN pushed: no action of root's installs them (every node takes them from GitHub).
		foreach ([['action' => 'agent_binary', 'arch' => 'amd64', 'artefact_path' => $this->rBase . 'etc/x'], ['action' => 'fanout_binary', 'arch' => 'amd64'], ['action' => 'xcvm_core', 'group' => 'php8.1']] as $rAction) {
			$this->assertNotContains($rAction['action'], NodeActions::ROOT_ACTIONS);
			ob_start();
			(new RootSignalsCronJob())->executeAction($rAction, [], $rDb);
			ob_end_clean();
			$this->assertSame('the running agent', file_get_contents($this->rBase . 'bin/xc_agent/xc_agent'), $rAction['action']);
		}

		// module:install's archive: only root's stage, and only the grant's bytes.
		mkdir($this->rBase . 'etc/stage', 0700);
		$rGrant = $this->grant('module/radio/1.0', 'radio_1.0.zip', 'PK-archive');
		file_put_contents($this->rBase . 'etc/stage/' . str_repeat('a', 32), 'PK-archive');
		file_put_contents($this->rBase . 'etc/stage/' . str_repeat('b', 32), 'PK-evil!!!');
		file_put_contents($this->rBase . 'elsewhere.zip', 'PK-archive');
		$this->assertNull(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/' . str_repeat('a', 32), $rGrant));
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/' . str_repeat('b', 32), $rGrant), 'not the grant\'s bytes');
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'elsewhere.zip', $rGrant), 'outside the stage');
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/../elsewhere.zip', $rGrant), 'out of it by a path');
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/' . str_repeat('a', 32), null), 'no grant');

		// A crash's leftovers go after an hour.
		touch($this->rBase . 'etc/stage/' . str_repeat('b', 32), time() - ArtefactStage::STAGE_TTL - 1);
		ArtefactStage::pruneStage(time());
		$this->assertSame([str_repeat('a', 32)], $this->staged());
	}

	/** A copy root staged for a grant (as ArtefactStage::stage() leaves it), with the checked grant and its command's id. */
	private function stagedCopy(string $rId, string $rName, string $rBytes): array {
		if (!is_dir($this->rBase . 'etc/stage')) {
			mkdir($this->rBase . 'etc/stage', 0700);
		}
		$rOne = $this->command(1, 'node.root', ['action' => 'x', 'artefact' => $this->grant($rId, $rName, $rBytes)]);
		$rGrant = ArtefactStage::grant($rOne['cmd']);
		$this->assertIsArray($rGrant);
		$rPath = $this->rBase . 'etc/stage/' . $rGrant['cmd_id'];
		file_put_contents($rPath, $rBytes);
		chmod($rPath, 0600);
		return ['path' => $rPath, 'grant' => $rGrant];
	}

	/** A release asset root downloaded and verified (as fanout_binary hands it to installAgent): its path, size and SHA-256. */
	private function asset(string $rBytes): array {
		$rPath = $this->rBase . 'etc/asset_' . bin2hex(random_bytes(4));
		file_put_contents($rPath, $rBytes);
		chmod($rPath, 0600);
		return ['path' => $rPath, 'grant' => ['size' => strlen($rBytes), 'sha256' => hash('sha256', $rBytes)]];
	}

	/**
	 * An agent whose `version` says `features: reached` is on trial for
	 * reaching MAIN (run.sh's reach check); one that does not is judged by its
	 * starts alone. PREV stays the last binary that proved itself: an install
	 * over one still on trial keeps the PREV before it.
	 */
	public function testTheTrialAsksReachOfAnAgentThatSaysItAndPrevStaysTheLastProvenOne(): void {
		$rAgent = $this->rBase . 'bin/xc_agent/xc_agent';
		$rReaching = "#!/bin/sh\necho 1.6.0\necho 'features: reached'\n# " . bin2hex(random_bytes(64)) . "\n";
		$this->assertNull(ArtefactStage::installAgent($this->asset($rReaching), $rAgent));
		$this->assertMatchesRegularExpression('/^\d+ 0 reach$/', trim((string) file_get_contents($rAgent . ArtefactStage::TRIAL)));
		$this->assertSame('the running agent', file_get_contents($rAgent . ArtefactStage::PREV));

		// Another install while 1.6.0 is still on trial: it never proved itself.
		$rOlder = $this->runnableAgent('1.7.0');
		$this->assertNull(ArtefactStage::installAgent($this->asset($rOlder), $rAgent));
		$this->assertSame('the running agent', file_get_contents($rAgent . ArtefactStage::PREV), 'the last proven binary stays');
		$this->assertMatchesRegularExpression('/^\d+ 0$/', trim((string) file_get_contents($rAgent . ArtefactStage::TRIAL)), 'no reach check for an agent that does not say it');

		// run.sh ended 1.7.0's trial: the next install keeps it.
		unlink($rAgent . ArtefactStage::TRIAL);
		$this->assertNull(ArtefactStage::installAgent($this->asset($this->runnableAgent('1.8.0')), $rAgent));
		$this->assertSame($rOlder, file_get_contents($rAgent . ArtefactStage::PREV));
	}

	/** A binary that does not start on this node is never put where run.sh would restart it every 2 s. */
	public function testAnAgentThatDoesNotRunIsNeverInstalled(): void {
		$rBytes = random_bytes(4000);
		$rStaged = $this->asset($rBytes);
		$rWhy = ArtefactStage::installAgent($rStaged, $this->rBase . 'bin/xc_agent/xc_agent');
		$this->assertIsString($rWhy);
		$this->assertStringContainsString('does not run on this node', $rWhy);
		$this->assertSame('the running agent', file_get_contents($this->rBase . 'bin/xc_agent/xc_agent'));
		$this->assertSame([], glob($this->rBase . 'bin/xc_agent/.*.new') ?: [], 'nothing left aside');
	}

	/**
	 * The binary's trial run (`timeout 10 <it> version`, through sudo as the
	 * directory's owner when root) has no shell: a directory whose name holds
	 * shell syntax (`;`, `$(…)`, backticks, quotes, spaces, a newline, `|`,
	 * `&`) runs the binary alone, its path one argument, with `version`.
	 */
	public function testTheTrialRunPassesTheBinarysPathAsOneArgument(): void {
		$rMark = $this->rBase . 'ran_';
		$rDir = $this->rBase . 'bin/a;touch ' . $rMark . 'semicolon;b $(touch ' . $rMark . 'subst) `touch ' . $rMark . 'backtick` \'q\' "d" |touch ' . $rMark . "pipe& \n newline/";
		mkdir($rDir, 0755, true);
		$rRecord = $this->rBase . 'logs/argv';
		// Records who ran it and with what, NUL-separated (the path holds a newline).
		$rBytes = "#!/bin/sh\nprintf '%s\\0' \"\$(id -u)\" \"\$#\" \"\$0\" \"\$@\" > '" . $rRecord . "'\necho 1.5.0\n";
		AgentUser::own($this->rBase . 'bin', $this->rBase . 'logs');
		$rStaged = $this->asset($rBytes);
		$this->assertNull(ArtefactStage::installAgent($rStaged, $rDir . 'xc_agent'));
		$this->assertSame($rBytes, file_get_contents($rDir . 'xc_agent'));
		$rRan = explode("\0", rtrim((string) file_get_contents($rRecord), "\0"));
		$this->assertSame([(string) (AgentUser::root() ? AgentUser::UID : posix_geteuid()), '1', $rDir . '.xc_agent.new', 'version'], $rRan);
		$this->assertSame([], glob($rMark . '*') ?: [], 'nothing else ran');
	}

	/**
	 * The trial run takes the binary only when it exits 0 and the first line
	 * of its stdout is not blank; stderr is not read. A binary that writes a
	 * lot to either still installs: stderr goes to /dev/null and stdout is
	 * read to its end, so it never blocks on a full pipe.
	 */
	public function testTheTrialRunNeedsExitZeroAndAFirstLine(): void {
		$rAgent = $this->rBase . 'bin/xc_agent/xc_agent';
		foreach ([
			'exits 1' => "#!/bin/sh\necho 1.5.0\nexit 1\n",
			'a blank first line' => "#!/bin/sh\necho\necho 1.5.0\n",
			'its line on stderr' => "#!/bin/sh\necho 1.5.0 >&2\n",
		] as $rWhy => $rBytes) {
			$rStaged = $this->asset($rBytes);
			$this->assertStringContainsString('does not run on this node', (string) ArtefactStage::installAgent($rStaged, $rAgent), $rWhy);
			$this->assertSame('the running agent', file_get_contents($rAgent), $rWhy);
		}
		// 1 MiB to stderr first, then 1 MiB to stdout after its line: head's
		// status (a SIGPIPE, a timeout) would be the script's.
		$rBytes = "#!/bin/sh\necho 1.5.0\nhead -c 1048576 /dev/zero >&2\nhead -c 1048576 /dev/zero\n";
		$this->assertNull(ArtefactStage::installAgent($this->asset($rBytes), $rAgent));
		$this->assertSame($rBytes, file_get_contents($rAgent));
	}

	/**
	 * Where no agent runs to judge it (a node not enrolled yet), the binary
	 * is installed without a trial: one left for run.sh would be judged at the
	 * node's enrolment, long past its install, and rolled back.
	 */
	public function testAnAgentInstalledWhereNoneRunsIsNotOnTrial(): void {
		$rAgent = $this->rBase . 'bin/xc_agent/xc_agent';
		file_put_contents($rAgent . ArtefactStage::TRIAL, "1 0 reach\n");
		$rBytes = $this->runnableAgent('1.6.0');
		$this->assertNull(ArtefactStage::installAgent($this->asset($rBytes), $rAgent, false));
		$this->assertSame($rBytes, file_get_contents($rAgent));
		$this->assertFileDoesNotExist($rAgent . ArtefactStage::TRIAL, 'a stale trial goes too');
		$this->assertFileDoesNotExist($rAgent . ArtefactStage::PREV);
	}

	/**
	 * fanout_binary keeps the agent at its release's version: nothing is
	 * fetched while it runs that version, nor for a version it already tried
	 * here that did not stay (run.sh put the previous one back), until a
	 * newer one is out or `force` asks.
	 */
	public function testTheAgentFromItsReleaseIsFetchedOnlyWhenNewAndNotTriedHere(): void {
		$rAgent = $this->rBase . 'bin/xc_agent/xc_agent';
		file_put_contents($rAgent, $this->runnableAgent('v1.5.0'));
		chmod($rAgent, 0755);
		AgentUser::own($this->rBase . 'bin');
		$this->assertSame('1.5.0', ArtefactStage::agentVersion());
		// No release reachable: a fetch would fail, so only a skip succeeds.
		$rNowhere = 'https://127.0.0.1:1/';
		ob_start();
		$this->assertTrue(FanoutBinaryCommand::agent($rNowhere, 'amd64', '1.5.0', false));
		$this->assertStringContainsString('up to date (1.5.0)', (string) ob_get_clean());

		file_put_contents($this->rBase . 'bin/xc_agent/' . FanoutBinaryCommand::AGENT_TRIED, "1.6.0\n");
		ob_start();
		$this->assertTrue(FanoutBinaryCommand::agent($rNowhere, 'amd64', '1.6.0', false));
		$this->assertStringContainsString('1.6.0 did not stay on this node', (string) ob_get_clean());

		foreach (['a newer release' => ['1.7.0', false], 'force' => ['1.6.0', true]] as $rWhy => [$rLatest, $rForce]) {
			ob_start();
			$this->assertFalse(FanoutBinaryCommand::agent($rNowhere, 'amd64', $rLatest, $rForce), $rWhy);
			$this->assertStringContainsString('Failed to download xc_agent-linux-amd64', (string) ob_get_clean(), $rWhy . ': fetched');
		}
		$this->assertSame("1.6.0\n", file_get_contents($this->rBase . 'bin/xc_agent/' . FanoutBinaryCommand::AGENT_TRIED), 'a failed download is not a try');
		$this->assertSame('1.5.0', ArtefactStage::agentVersion());
	}

	/** Root's stage is trusted only while it is root's: one the agent's user owns (and could swap a checked copy in) is refused. */
	public function testRootTrustsOnlyAStageOfItsOwn(): void {
		if (!AgentUser::root()) {
			$this->markTestSkipped('the stage is root\'s: needs a run as root');
		}
		mkdir($this->rBase . 'etc/stage', 0700);
		AgentUser::own($this->rBase . 'etc/stage');
		$rBytes = 'PK the archive';
		$rOne = $this->command(9, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rBytes);
		$this->inbox(9, $rOne['wire']);
		$rRan = false;
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('is not root\'s alone', $this->done(9)['result']);
		$this->assertSame([], $this->staged());
		$this->assertFileDoesNotExist($rDownload, 'spent');
	}

	/** Root opens the agent's download with the agent's rights: a hard link to a file only root may read stages nothing. */
	public function testRootReadsTheDownloadOnlyWithTheAgentsRights(): void {
		if (!AgentUser::root()) {
			$this->markTestSkipped('the agent\'s rights differ from root\'s only in a run as root');
		}
		$rBytes = 'what only root may read';
		$rSecret = $this->rBase . 'root_only';
		file_put_contents($rSecret, $rBytes);
		chmod($rSecret, 0600);
		$rOne = $this->command(10, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$rDownload = $this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id'];
		// A hard link: lstat sees a regular file, so only the rights stop it.
		$this->assertTrue(link($rSecret, $rDownload));
		$this->inbox(10, $rOne['wire']);
		$rRan = false;
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('cannot be read with the agent\'s rights', $this->done(10)['result']);
		$this->assertSame([], $this->staged(), 'nothing staged');
		$this->assertSame($rBytes, file_get_contents($rSecret));
		$this->assertFileDoesNotExist($rDownload, 'only the link is spent');
	}

	/**
	 * root.seq only goes up, so a root command handed over after a later one
	 * is refused as a replay: the agent hands `node.root` commands over in
	 * seq order, a later one waiting while an earlier one's artefact
	 * downloads (ADR 0004's contract). Handed over out of order, the earlier
	 * one never runs, and its download is spent.
	 */
	public function testARootCommandHandedOverAfterALaterOneIsRefused(): void {
		$rBytes = 'PK' . random_bytes(3000);
		$rFive = $this->command(5, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'demo', 'version' => '1.0.0', 'artefact' => $this->grant('module/demo/1.0.0', 'demo_1.0.0.zip', $rBytes)]);
		$rSix = $this->command(6, 'node.root', ['action' => 'flush']);
		$rRan = [];
		$rRun = static function (array $rAction) use (&$rRan): string {
			$rRan[] = $rAction['action'];
			return 'ok';
		};
		$this->inbox(6, $rSix['wire']);
		ClusterRootCommand::drain($rRun, 1800000000);
		$rDownload = $this->download($rFive['cmd'], $rBytes);
		$this->inbox(5, $rFive['wire']);
		ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertSame(['flush'], $rRan, 'the module is not installed');
		$this->assertSame(['ok' => false, 'result' => 'refused by root: seq not above 6'], $this->done(5));
		$this->assertFileDoesNotExist($rDownload, 'its download is spent, not left for 25 hours');
		$this->assertSame([], $this->staged());
		$this->assertSame(6, RootPin::highWater());
	}

	/**
	 * install_module with a staged archive: only this module's, run from
	 * root's copy with the checked grant before the stage is emptied, and
	 * what module:install refused or failed is the command's failure.
	 * Without one (a signals row), the legacy way, as before: run to its end
	 * (exec() read the old `… 2>&1 &` line's output until module:install
	 * closed it), its exit status and output not the command's. A payload
	 * never names the archive, and module:install gets it as one argument.
	 */
	public function testInstallModuleTakesOnlyItsOwnStagedArchive(): void {
		$rAction = ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0'];
		$rPayload = function (array $rArgv): array {
			$this->assertSame(['sudo', PHP_BIN, MAIN_HOME . 'console.php', 'module:install'], array_slice($rArgv, 0, 4), 'the node\'s own PHP and console.php');
			$this->assertCount(5, $rArgv, 'the payload is one argument');
			return json_decode((string) base64_decode((string) $rArgv[4], true), true);
		};
		$rArgv = RootSignalsCronJob::moduleInstallArgv($rAction + ['archive' => '/etc/shadow', 'artefact' => ['id' => 'module/radio/1.0']], null);
		$this->assertSame($rAction, $rPayload($rArgv), 'a signals row names no archive');
		$rStaged = $this->stagedCopy('module/radio/1.0', 'radio_1.0.zip', 'PK-archive');
		$rArgv = RootSignalsCronJob::moduleInstallArgv($rAction + ['archive' => '/etc/shadow'], $rStaged);
		$this->assertSame(['archive' => $rStaged['path'], 'artefact' => $rStaged['grant']] + $rAction, $rPayload($rArgv));

		$rRuns = [];
		$rAnswer = [0, "module:install: 'radio' installed."];
		RootSignalsCronJob::useRunner(static function (array $rRun) use (&$rRuns, &$rAnswer): array {
			$rRuns[] = $rRun;
			return $rAnswer;
		});
		$rOut = ArtefactStage::withStaged($rStaged, fn() => $this->runAction($rAction + ['archive' => '/etc/shadow']));
		$this->assertStringContainsString("'radio' installed", $rOut);
		$this->assertSame([$rArgv], $rRuns);
		// module:install refused it: the command fails with its refusal.
		$rAnswer = [1, 'module:install: artefact refused: module/radio/1.0 (radio_1.0.zip): the archive is not the one MAIN granted'];
		try {
			ArtefactStage::withStaged($rStaged, fn() => $this->runAction($rAction));
			$this->fail('acked ok');
		} catch (\RuntimeException $rE) {
			$this->assertSame($rAnswer[1], $rE->getMessage());
		}
		// Another module's archive: refused and audited before module:install runs.
		$rRuns = [];
		$rOther = $this->stagedCopy('module/other/2.0', 'other_2.0.zip', 'PK-other');
		try {
			ArtefactStage::withStaged($rOther, fn() => $this->runAction($rAction));
			$this->fail('installed');
		} catch (\RuntimeException $rE) {
			$this->assertStringStartsWith('artefact refused: module/other/2.0 (other_2.0.zip): not the archive of module/radio/1.0', $rE->getMessage());
		}
		$this->assertSame([], $rRuns);
		$rAudited = $this->audited();
		$this->assertSame('log.syslog:ARTEFACT', end($rAudited)[0] ?? null);
		// No staged archive (a signals row): the legacy way, whatever it
		// names, and what module:install answers is not the action's.
		$rAnswer = [1, 'module:install: cannot reach MAIN'];
		$rOut = $this->runAction($rAction + ['archive' => $rStaged['path']]);
		$this->assertCount(1, $rRuns);
		$this->assertSame($rAction, $rPayload($rRuns[0]));
		$this->assertStringNotContainsString('cannot reach MAIN', $rOut);
	}

	/**
	 * Root's commands run with no shell: each argument reaches the program
	 * as it is, whatever shell syntax it holds (`;`, `$(…)`, backticks,
	 * quotes, spaces, a newline, `|`, `&`, an empty one), stderr joins
	 * stdout as the old `2>&1` did, and the exit status is the program's.
	 * An install_module payload full of shell syntax is one argument of
	 * module:install.
	 */
	public function testRootsCommandsRunWithNoShell(): void {
		$rMark = $this->rBase . 'ran_';
		$rArgs = [
			'a; touch ' . $rMark . 'semicolon',
			'$(touch ' . $rMark . 'subst)',
			'`touch ' . $rMark . 'backtick`',
			'it\'s "quoted"',
			'with  spaces ',
			"new\nline; touch " . $rMark . 'newline',
			'|touch ' . $rMark . 'pipe &',
			'',
		];
		// Each line in one write: stdout and stderr are two pipes read as they
		// come, so a line written in two (the text, then its newline) can have
		// the other pipe's line land in the middle of it.
		$rProgram = 'fwrite(STDERR, "to stderr\n"); echo json_encode(array_slice($argv, 1)) . "\n"; exit(3);';
		$rRun = new \ReflectionMethod(RootSignalsCronJob::class, 'run');
		[$rCode, $rOutput] = $rRun->invoke(null, array_merge([PHP_BINARY, '-n', '-r', $rProgram, '--'], $rArgs));
		$this->assertSame(3, $rCode);
		$this->assertEqualsCanonicalizing(['to stderr', json_encode($rArgs)], explode("\n", $rOutput), 'stderr joins stdout');
		$this->assertSame([], glob($rMark . '*') ?: [], 'nothing else ran');

		$rAction = ['action' => 'install_module', 'source' => $rArgs[3], 'name' => $rArgs[0], 'version' => $rArgs[5], 'url' => $rArgs[1] . $rArgs[2] . $rArgs[6]];
		$rArgv = RootSignalsCronJob::moduleInstallArgv($rAction, null);
		$this->assertCount(5, $rArgv);
		$this->assertSame($rAction, json_decode((string) base64_decode($rArgv[4], true), true));
	}

	/**
	 * run() reads stdout and stderr as they come, so a command that fills
	 * one pipe while the other is still open never blocks (`timeout 10`
	 * turns a regression into a failure, not a hung suite), and returns the
	 * output as exec() did: each line without its trailing whitespace, no
	 * trailing empty line.
	 */
	public function testRootsCommandsNeverBlockOnAFullPipe(): void {
		$rRun = new \ReflectionMethod(RootSignalsCronJob::class, 'run');
		[$rCode, $rOutput] = $rRun->invoke(null, ['timeout', '10', PHP_BINARY, '-n', '-r', 'fwrite(STDERR, str_repeat("e", 1 << 20)); echo str_repeat("o", 1 << 20);']);
		$this->assertSame(0, $rCode, 'neither pipe fills while the other is read');
		$this->assertSame(2 << 20, strlen($rOutput));
		$this->assertSame(1 << 20, substr_count($rOutput, 'e'));

		[, $rOutput] = $rRun->invoke(null, [PHP_BINARY, '-n', '-r', 'echo "a \\t\\r\\nb  \\n\\n";']);
		$this->assertSame("a\nb\n", $rOutput, 'as exec() returned it');
	}

	/** module:install takes an archive only from root's stage, only with the grant's bytes; anything else is refused and audited, and nothing deploys. */
	public function testModuleInstallRefusesAnArchiveThatIsNotTheGrants(): void {
		mkdir($this->rBase . 'etc/stage', 0700);
		$rGrant = ['cmd_id' => str_repeat('c', 32)] + $this->grant('module/radio/1.0', 'radio_1.0.zip', 'PK-archive');
		file_put_contents($this->rBase . 'etc/stage/' . str_repeat('a', 32), 'PK-archive');
		file_put_contents($this->rBase . 'etc/stage/' . str_repeat('b', 32), 'PK-evil!!!');
		file_put_contents($this->rBase . 'elsewhere.zip', 'PK-archive');
		foreach ([
			'outside the stage' => [$this->rBase . 'elsewhere.zip', $rGrant, 'not in root\'s stage'],
			'not the grant\'s bytes' => [$this->rBase . 'etc/stage/' . str_repeat('b', 32), $rGrant, 'not the one MAIN granted'],
			'no grant' => [$this->rBase . 'etc/stage/' . str_repeat('a', 32), null, 'not the one MAIN granted'],
		] as $rWhy => [$rPath, $rWith, $rSays]) {
			$rPayload = ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'archive' => $rPath] + ($rWith === null ? [] : ['artefact' => $rWith]);
			ob_start();
			$rCode = (new ModuleInstallCommand())->execute([base64_encode((string) json_encode($rPayload))]);
			$rOut = (string) ob_get_clean();
			$this->assertSame(1, $rCode, $rWhy);
			$this->assertStringContainsString('artefact refused: ', $rOut, $rWhy);
			$this->assertStringContainsString($rSays, $rOut, $rWhy);
			$this->assertStringNotContainsString('Installing', $rOut, $rWhy . ': nothing deployed');
		}
		$rLines = $this->audited();
		$this->assertCount(3, $rLines);
		$this->assertStringContainsString('Refused artefact module/radio/1.0 (radio_1.0.zip) for command ' . str_repeat('c', 32), $rLines[0][1]);
	}

	/**
	 * Root's refusal reaches MAIN in the ack today's agent builds: on a
	 * non-zero exit it acks `cluster:exec: <error>: <stderr>` and drops
	 * stdout (xc_vm_fanout, ExecViaPHP), so cluster:exec writes root's
	 * failure to stderr too. MAIN audits an ack that says `artefact refused`
	 * as `artefact.refused` (ArtefactGrants::acked).
	 */
	public function testARootRefusalReachesTheAgentsAck(): void {
		$rBytes = 'PK' . random_bytes(3000);
		$rTampered = $rBytes;
		$rTampered[3] = 'X';
		$rOne = $this->command(11, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', $rBytes)]);
		$this->download($rOne['cmd'], $rTampered);
		$rScript = $this->rBase . 'exec.php';
		file_put_contents($rScript, "<?php\n"
			. 'require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ";\n"
			. '\XcVm\Core\Cluster\RootPin::useDirs(' . var_export($this->rBase . 'etc/', true) . ', ' . var_export($this->rBase . 'config/cluster/root-inbox/', true) . ");\n"
			. 'exit(\XcVm\Cli\Commands\ClusterExecCommand::handToRoot(' . var_export($rOne['wire'], true) . ", 11, 30));\n");
		$rProc = proc_open([PHP_BINARY, $rScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		fclose($rPipes[0]);
		// Root takes it from the inbox, as cluster:root does each second.
		for ($i = 0; $i < 200 && !is_file($this->rBase . 'config/cluster/root-inbox/11.json'); $i++) {
			usleep(50000);
		}
		ClusterRootCommand::drain(static fn(): string => 'ran', 1800000000);
		$rStdout = (string) stream_get_contents($rPipes[1]);
		$rStderr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		$this->assertSame(1, $rCode, $rStdout . $rStderr);
		$rResult = $rCode === 0 ? $rStdout : 'cluster:exec: exit status ' . $rCode . ': ' . trim($rStderr);
		$this->assertStringStartsWith('cluster:exec: exit status 1: cluster:exec: refused by root: artefact refused: module/radio/1.0 (radio_1.0.zip): sha256 mismatch', $rResult);
	}
}
