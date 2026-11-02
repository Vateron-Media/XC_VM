<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Core\Process\ProcessManager;

/**
 * Which module owns a source URL (SourceDriverRegistry), including discovery
 * from module.json without booting modules, and how a driver's engine is
 * recognised as a stream's producer (ProcessManager::producerRunsStream).
 */
final class SourceDriverRegistryTest extends TestCase {
	private array $roots = [];

	protected function setUp(): void {
		SourceDriverRegistry::reset();
		SourceDriverRegistry::register(new TestSourceDriver(['acmedash']), 60);
	}

	protected function tearDown(): void {
		SourceDriverRegistry::reset();
		foreach ($this->roots as $root) {
			exec('rm -rf ' . escapeshellarg($root));
		}
	}

	public function testDriverOwnsItsScheme(): void {
		$this->assertInstanceOf(TestSourceDriver::class, SourceDriverRegistry::for('acmedash://prov/demo-001'));
		$this->assertInstanceOf(TestSourceDriver::class, SourceDriverRegistry::for('ACMEDASH://anything?at=all'), 'schemes are case-insensitive');
		$this->assertNull(SourceDriverRegistry::for('http://src.example/live.ts'));
		$this->assertNull(SourceDriverRegistry::for('/home/xc_vm/content/created/1_.list'), 'a path has no scheme');
	}

	public function testCoreSchemesCannotBeClaimed(): void {
		$this->assertSame(['http', 'udp', 'bad scheme'], SourceDriverRegistry::register(new TestSourceDriver(['http', 'udp', 'bad scheme', 'acmehls'])));
		$this->assertInstanceOf(TestSourceDriver::class, SourceDriverRegistry::for('acmehls://x'));
		$this->assertNull(SourceDriverRegistry::for('http://x'));
	}

	public function testFirstClaimKeepsTheScheme(): void {
		$this->assertSame(['acmedash'], SourceDriverRegistry::register(new TestSourceDriver(['acmedash'], 'other')));
		$this->assertSame('xcvm-test', SourceDriverRegistry::for('acmedash://x')->binary());
	}

	/** A scheme some bundled ffmpeg reads must never stop an existing stream. */
	public function testEveryFfmpegProtocolIsCore(): void {
		foreach (['ipfs', 'ipns', 'bluray', 'amqp', 'ffrtmphttp', 'mms', 'rtmpte', 'gophers', 'concatf', 'fd'] as $rScheme) {
			$this->assertNull(SourceDriverRegistry::refusal($rScheme . '://x/y'), $rScheme);
		}
	}

	public function testUnknownSchemeIsRefusedButCoreAndDriverOnesAreNot(): void {
		$this->assertSame('no source driver for otherdash:// on this node', SourceDriverRegistry::refusal('otherdash://p/c'));
		$this->assertNull(SourceDriverRegistry::refusal('acmedash://p/c'));
		$this->assertNull(SourceDriverRegistry::refusal('rtmp://src.example/live/key'));
		$this->assertNull(SourceDriverRegistry::refusal('/path/to/file.ts'));
	}

	public function testStartTimeoutAndBinaries(): void {
		$this->assertSame(60, SourceDriverRegistry::startTimeout('acmedash://p/c'));
		$this->assertSame(0, SourceDriverRegistry::startTimeout('http://p/c'));
		$this->assertSame(['xcvm-test'], SourceDriverRegistry::binaries());
	}

	public function testLbSupportRequiresOptIn(): void {
		$this->assertFalse(SourceDriverRegistry::supportsLoadBalancer('acmedash://p/c'));
		SourceDriverRegistry::register(new TestSourceDriver(['lbdash']), 0, true);
		$this->assertTrue(SourceDriverRegistry::supportsLoadBalancer('lbdash://p/c'));
	}

	public function testDiscoverReadsDriversFromModuleManifests(): void {
		$root = $this->modulesRoot();
		$this->createModule($root, 'dash-one', ['onedash'], 45);
		SourceDriverRegistry::discover($root);

		$this->assertNotNull(SourceDriverRegistry::for('onedash://p/c'));
		$this->assertSame(45, SourceDriverRegistry::startTimeout('onedash://p/c'));
		$this->assertNull(SourceDriverRegistry::for('acmedash://p/c'), 'discover replaces what was registered before');
	}

	public function testSchemeClaimedByTwoModulesGoesToNone(): void {
		$root = $this->modulesRoot();
		$this->createModule($root, 'dash-a', ['shareddash', 'adash']);
		$this->createModule($root, 'dash-b', ['shareddash']);
		$this->createModule($root, 'dash-c', ['shareddash']);
		SourceDriverRegistry::discover($root);

		$this->assertNull(SourceDriverRegistry::for('shareddash://p/c'), 'a third claimant must not inherit it either');
		$this->assertNotNull(SourceDriverRegistry::for('adash://p/c'));
	}

	public function testBrokenDriverClassIsSkipped(): void {
		$root = $this->modulesRoot();
		$this->createModule($root, 'dash-bad', [], 0, ['XcVm\\Module\\DashBad\\Missing', 'stdClass']);
		SourceDriverRegistry::discover($root);
		$this->assertSame([], SourceDriverRegistry::binaries());
	}

	public function testProducerRecognition(): void {
		$cmd = "/opt/xcvm-test\0-o\0/streams/42_.m3u8\0";
		$this->assertTrue(ProcessManager::producerRunsStream('xcvm-test', $cmd, 42));
		$this->assertFalse(ProcessManager::producerRunsStream('xcvm-test', $cmd, 43), 'another stream');
		$this->assertFalse(ProcessManager::producerRunsStream('unknown-bin', $cmd, 42), 'not a registered engine');
		$this->assertTrue(ProcessManager::producerRunsStream('ffmpeg', "ffmpeg\0-i\0x\0/streams/42_%d.ts\0", 42));
		$this->assertTrue(ProcessManager::producerRunsStream('xc_fanout', "xc_fanout\0remux\0/streams/42_.m3u8\0", 42));
		$this->assertFalse(ProcessManager::producerRunsStream('xc_fanout', "xc_fanout\0serve\0/streams/42_.m3u8\0", 42), 'the daemon itself');
	}

	private function modulesRoot(): string {
		$root = sys_get_temp_dir() . '/xcvm-sd-' . bin2hex(random_bytes(4));
		mkdir($root, 0775, true);
		$this->roots[] = $root;
		return $root;
	}

	/** A module whose driver class claims $schemes (or $classes as declared verbatim). */
	private function createModule(string $root, string $name, array $schemes, int $startTimeout = 0, ?array $classes = null): void {
		$path = $root . '/' . $name;
		mkdir($path, 0775, true);
		$pascal = implode('', array_map('ucfirst', explode('-', $name)));
		$ns = 'XcVm\\Module\\' . $pascal;

		file_put_contents($path . '/module.json', json_encode([
			'name' => $name, 'version' => '1.0.0', 'environment' => 'main',
			'source_drivers' => $classes ?? [$ns . '\\Driver'], 'start_timeout' => $startTimeout,
		]));
		file_put_contents($path . '/' . $pascal . 'Module.php', '<?php namespace ' . $ns . ';
			use XcVm\Core\Container\ServiceContainer; use XcVm\Cli\CommandRegistry; use XcVm\Core\Module\NavbarRegistry; use XcVm\Core\Http\Router;
			class ' . $pascal . 'Module implements \XcVm\Core\Module\ModuleInterface {
				public function getName(): string { return "' . $name . '"; }
				public function getVersion(): string { return "1.0.0"; }
				public function boot(ServiceContainer $c): void {}
				public function registerRoutes(Router $r): void {}
				public function registerCommands(CommandRegistry $r): void {}
				public function getEventSubscribers(): array { return []; }
				public function install(): void {}
				public function uninstall(): void {}
				public function registerNavbar(NavbarRegistry $r): void {}
			}');
		file_put_contents($path . '/Driver.php', '<?php namespace ' . $ns . ';
			class Driver extends \TestSourceDriver { public function __construct() { parent::__construct(' . var_export($schemes, true) . ', "' . $name . '"); } }');
	}
}
