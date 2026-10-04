<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExportKeysCommand;
use XcVm\Cli\Commands\ClusterImportKeysCommand;
use XcVm\Cli\Commands\ClusterPassphrase;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Disaster recovery of MAIN's cluster root: cluster:export-keys writes the
 * extension's passphrase-protected bundle, cluster:import-keys takes it on a
 * replacement MAIN. Opt-in, the real extension round-trips a bundle between
 * two machines (two throwaway config dirs):
 *
 *   XCVM_EXT_SO=/path/to/test-hooks/xcvm_core.so php tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterDrTest
 */
final class ClusterDrTest extends TestCase {
	private const PASS = 'correct horse battery staple';

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql') . file_get_contents(MAIN_HOME . 'migrations/database/up/032_create_cluster_audit.sql'));
		DatabaseFactory::set($this->rDb);
		$this->rDir = sys_get_temp_dir() . '/xcvm-dr-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return resource */
	private function input(string $rText) {
		$rIn = fopen('php://memory', 'r+');
		fwrite($rIn, $rText);
		rewind($rIn);
		return $rIn;
	}

	private function runCommand(object $rCommand, array $rArgs): array {
		ob_start();
		$rCode = $rCommand->execute($rArgs);
		return [$rCode, (string) ob_get_clean()];
	}

	private function audit(string $rEvent): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = ?', $rEvent);
		return (int) $this->rDb->get_row()['n'];
	}

	public function testExportAndImportOntoAReplacementMain(): void {
		$rOld = new FakeClusterCrypto();
		$rOld->init();
		$rFile = $this->rDir . '/root.xcdr';
		[$rCode, $rOut] = $this->runCommand(new ClusterExportKeysCommand($rOld, $this->input(self::PASS . "\n")), [$rFile]);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(0600, fileperms($rFile) & 0777);
		$this->assertSame(1, $this->audit('cluster.export_keys'));
		$this->assertStringNotContainsString(self::PASS, $rOut);

		$rNew = new FakeClusterCrypto();
		$rNew->rSeed = str_repeat("\x55", 32); // another machine, no root yet
		[$rCode, $rOut] = $this->runCommand(new ClusterImportKeysCommand($rNew, $this->input(self::PASS . "\n")), [$rFile]);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(bin2hex($rOld->info()['panel_fp']), ClusterMeta::get('panel_fp'), 'the replacement serves the same panel keys');
		$this->assertSame(1, $this->audit('cluster.import_keys'));
		$this->assertStringContainsString('old MAIN is retired', $rOut);

		// The same bundle again changes nothing.
		[$rCode, $rOut] = $this->runCommand(new ClusterImportKeysCommand($rNew, $this->input(self::PASS . "\n")), [$rFile]);
		$this->assertSame(0, $rCode);
		$this->assertStringContainsString('Already imported', $rOut);
	}

	public function testExportRefusals(): void {
		$rCrypto = new FakeClusterCrypto();
		$rFile = $this->rDir . '/root.xcdr';
		[$rCode, $rOut] = $this->runCommand(new ClusterExportKeysCommand($rCrypto, $this->input("short\n")), [$rFile]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('too weak', $rOut);
		$this->assertFileDoesNotExist($rFile);

		file_put_contents($rFile, 'keep me');
		[$rCode] = $this->runCommand(new ClusterExportKeysCommand($rCrypto, $this->input(self::PASS . "\n")), [$rFile]);
		$this->assertSame(1, $rCode, 'never overwrites');
		$this->assertSame('keep me', file_get_contents($rFile));

		[$rCode] = $this->runCommand(new ClusterExportKeysCommand($rCrypto, $this->input('')), [$this->rDir . '/other']);
		$this->assertSame(1, $rCode, 'no passphrase');
	}

	public function testImportRefusals(): void {
		$rOld = new FakeClusterCrypto();
		$rFile = $this->rDir . '/root.xcdr';
		file_put_contents($rFile, $rOld->exportKeys(self::PASS));

		$rNew = new FakeClusterCrypto();
		$rNew->rSeed = str_repeat("\x55", 32);
		[$rCode, $rOut] = $this->runCommand(new ClusterImportKeysCommand($rNew, $this->input("wrong horse battery staple\n")), [$rFile]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('does not open', $rOut);

		$rNew->init(); // this machine already made its own root
		[$rCode, $rOut] = $this->runCommand(new ClusterImportKeysCommand($rNew, $this->input(self::PASS . "\n")), [$rFile]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('different cluster root', $rOut);
		$this->assertNull(ClusterMeta::get('panel_fp'), 'nothing recorded');
	}

	public function testPassphraseSources(): void {
		$rFile = $this->rDir . '/pass';
		file_put_contents($rFile, self::PASS . "\n");
		$this->assertSame(self::PASS, ClusterPassphrase::read(['passphrase-file' => $rFile], true));
		$this->assertSame(self::PASS, ClusterPassphrase::read([], true, $this->input(self::PASS . "\r\n")), 'one line from a pipe');
		$this->assertNull(ClusterPassphrase::read([], false, $this->input("\n")));
		$this->assertNull(ClusterPassphrase::read(['passphrase-file' => $this->rDir . '/missing'], false));
	}

	/** Export on one machine, import on another, with the real extension (the KDF shrunk by its test hook). */
	public function testWithTheRealExtension(): void {
		$rSo = (string) getenv('XCVM_EXT_SO');
		if ($rSo === '' || !is_file($rSo)) {
			$this->markTestSkipped('XCVM_EXT_SO not set');
		}
		$rPhp = static function (string $rConfigDir, string $rCode) use ($rSo): string {
			$rEnv = 'XCVM_CONFIG_DIR=' . escapeshellarg($rConfigDir) . ' XCVM_TEST_DR_MEM_KIB=262144';
			return (string) shell_exec($rEnv . ' ' . escapeshellarg(PHP_BINARY) . ' -d extension=' . escapeshellarg($rSo) . ' -r ' . escapeshellarg($rCode) . ' 2>/dev/null');
		};
		foreach (['a', 'b', 'c'] as $rName) {
			mkdir($this->rDir . '/' . $rName, 0700);
		}
		$rBundle = $this->rDir . '/root.xcdr';
		$rFpA = $rPhp($this->rDir . '/a', 'XC_VM::cluster_init(); $b = XC_VM::cluster_export_keys(' . var_export(self::PASS, true) . '); file_put_contents(' . var_export($rBundle, true) . ', $b ?: ""); echo bin2hex(XC_VM::cluster_info()["panel_fp"]), $b === false ? " " . XC_VM::cluster_last_error() : "";');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32,64}$/', $rFpA, 'export on machine A: ' . $rFpA);

		$rOutB = $rPhp($this->rDir . '/b', '$r = XC_VM::cluster_import_keys(file_get_contents(' . var_export($rBundle, true) . '), ' . var_export(self::PASS, true) . '); echo $r ? bin2hex($r["panel_fp"]) : XC_VM::cluster_last_error();');
		$this->assertSame($rFpA, $rOutB, 'machine B serves A\'s panel keys');

		$rOutC = $rPhp($this->rDir . '/c', 'XC_VM::cluster_init(); $r = XC_VM::cluster_import_keys(file_get_contents(' . var_export($rBundle, true) . '), ' . var_export(self::PASS, true) . '); echo $r ? "imported" : XC_VM::cluster_last_error();');
		$this->assertSame('ROOT_EXISTS', $rOutC, 'a machine with its own root refuses');
	}
}
