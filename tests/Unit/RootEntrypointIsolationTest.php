<?php

use PHPUnit\Framework\TestCase;

/** Root dispatch must stay functional even when every writable module hook is hostile. */
final class RootEntrypointIsolationTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-root-entry-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'Cli/Commands', 0755, true);
		mkdir($this->rDir . 'Cli/CronJobs', 0755, true);
		mkdir($this->rDir . 'Modules', 0755, true);
		mkdir($this->rDir . 'config', 0755, true);
		copy(dirname(__DIR__, 2) . '/src/root.php', $this->rDir . 'root.php');
	}

	protected function tearDown(): void {
		exec('rm -rf -- ' . escapeshellarg($this->rDir));
	}

	public function testWritablePhpCannotExecuteInTheRootEntrypoint(): void {
		$rMarker = $this->rDir . 'executed';
		file_put_contents($this->rDir . 'config/modules.php', '<?php file_put_contents(' . var_export($rMarker, true) . ', "config");');
		file_put_contents($this->rDir . 'Modules/Evil.php', '<?php file_put_contents(' . var_export($rMarker, true) . ', "module");');
		file_put_contents($this->rDir . 'bootstrap.php', <<<'PHP'
<?php
namespace XcVm\Cli {
	interface CommandInterface { public function getName(): string; public function getDescription(): string; public function execute(array $args): int; }
	class CommandRegistry {
		private array $commands = [];
		public function register(CommandInterface $command): void { $this->commands[$command->getName()] = $command; }
		public function dispatch(array $argv): int { return $this->commands[$argv[1]]->execute(array_slice($argv, 2)); }
	}
}
namespace XcVm\Core\Cluster { class ReplicaBoot { public static function forArgv(array $argv): bool { return false; } } }
namespace {
	require __DIR__ . '/Cli/Commands/StatusCommand.php';
	class XC_Bootstrap { public const CONTEXT_CLI = 'cli'; public static function boot(string $context, array $options): void {} }
}
PHP);
		file_put_contents($this->rDir . 'Cli/Commands/StatusCommand.php', <<<'PHP'
<?php
namespace XcVm\Cli\Commands;
final class StatusCommand implements \XcVm\Cli\CommandInterface {
	public function getName(): string { return 'status'; }
	public function getDescription(): string { return 'status'; }
	public function execute(array $args): int { echo "status\n"; return 0; }
}
PHP);
		file_put_contents($this->rDir . 'Cli/Commands/InjectedCommand.php', '<?php file_put_contents(' . var_export($rMarker, true) . ', "core");');

		$rCommand = [PHP_BINARY, $this->rDir . 'root.php', 'status'];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$rCode = proc_close($rProc);

		$this->assertSame(0, $rCode, $rErr);
		$this->assertSame("status\n", $rOut);
		$this->assertFileDoesNotExist($rMarker);
	}

	public function testLegacyConsoleRedirectsRootBeforeNormalBootstrap(): void {
		$rSource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/console.php');
		$this->assertLessThan(strpos($rSource, "require_once __DIR__ . '/bootstrap.php'"), strpos($rSource, "require __DIR__ . '/root.php'"));
		$this->assertStringContainsString('refusing module-aware CLI boot', $rSource);
		$rRoot = (string) file_get_contents(dirname(__DIR__, 2) . '/src/root.php');
		$this->assertStringNotContainsString('glob(', $rRoot);
		$this->assertStringContainsString('StatusCommand::class', $rRoot);
		$this->assertStringNotContainsString('ModuleInstallCommand::class', $rRoot);
	}
}
