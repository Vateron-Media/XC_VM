<?php

use PHPUnit\Framework\TestCase;

final class BackupTransientDataTest extends TestCase {
	public function testDumpsExcludeTransientTransportDataButKeepPersistentConfiguration(): void {
		$code = <<<'CHILD'
class XC_VM {
    public static function db_dump(string $filename, array $ignore): bool {
        $GLOBALS['ignored'] = $ignore;
        file_put_contents($filename, '-- dump');
        return true;
    }
}
require %SERVICE%;
$file = tempnam(sys_get_temp_dir(), 'xcvm-dump-policy-');
try {
    $ok = \XcVm\Core\Backup\BackupService::create($file);
    echo json_encode(['ok'=>$ok, 'ignored'=>$GLOBALS['ignored']]);
} finally {
    unlink($file);
}
CHILD;
		$code = str_replace('%SERVICE%', var_export(MAIN_HOME . 'Core/Backup/BackupService.php', true), $code);
		$process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $error);
		$result = json_decode($output, true);
		$this->assertTrue($result['ok']);
		foreach (['cluster_commands', 'cluster_nonces', 'cluster_reservations', 'lines_live', 'lines_activity'] as $table) {
			$this->assertContains($table, $result['ignored']);
		}
		foreach (['settings', 'lines', 'streams', 'servers', 'cluster_nodes', 'cluster_node_epochs', 'cluster_meta', 'cluster_audit'] as $table) {
			$this->assertNotContains($table, $result['ignored']);
		}
	}
}
