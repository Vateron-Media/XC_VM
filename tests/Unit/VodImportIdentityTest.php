<?php

use PHPUnit\Framework\TestCase;

final class VodImportIdentityTest extends TestCase {
	public function testMigrationEnforcesIdentityAcrossConcurrentConnectionsAndRollsBack(): void {
		$db = new TestDb();
		$db->exec('CREATE TABLE streams (id INT PRIMARY KEY AUTO_INCREMENT, type INT, stream_source TEXT)');
		$up = file_get_contents(MAIN_HOME . 'migrations/database/up/102_add_vod_import_source_hash.sql');
		$db->exec($up);
		$db->exec($up); // interrupted/repeated migration is safe
		$db->exec('INSERT INTO streams(type) VALUES(2),(2)');
		$schema = $db->pdo->query('SELECT DATABASE()')->fetchColumn();
		$root = sys_get_temp_dir() . '/xcvm-vod-identity-' . bin2hex(random_bytes(4));
		mkdir($root);
		$script = $root . '/writer.php';
		$ready = $root . '/ready';
		$hash = str_repeat('a', 64);
		file_put_contents($script, '<?php require ' . var_export(__DIR__ . '/../bootstrap.php', true) . '; $db = TestDb::connect(' . var_export($schema, true) . '); file_put_contents(' . var_export($ready, true) . ', "ready"); try { $db->exec("INSERT INTO streams(type,import_source_hash) VALUES(2,\'' . $hash . '\')"); echo "inserted"; } catch (PDOException $e) { echo ($e->errorInfo[1] ?? 0) === 1062 ? "duplicate" : $e->getMessage(); }');
		$db->pdo->beginTransaction();
		$db->pdo->exec("INSERT INTO streams(type,import_source_hash) VALUES(2,'$hash')");
		$process = proc_open(['timeout', '10', PHP_BINARY, $script], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
		try {
			for ($i = 0; $i < 100 && !is_file($ready); $i++) { usleep(10000); }
			$this->assertFileExists($ready);
			$this->assertTrue(proc_get_status($process)['running'], 'second insert waits for the first transaction');
			$db->pdo->commit();
			$this->assertSame('duplicate', stream_get_contents($pipes[1]));
			$this->assertSame('', stream_get_contents($pipes[2]));
		} finally {
			if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
			fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
			@unlink($ready); unlink($script); rmdir($root);
		}
		$this->assertSame(3, (int) $db->pdo->query('SELECT COUNT(*) FROM streams')->fetchColumn());
		$db->exec(file_get_contents(MAIN_HOME . 'migrations/database/down/102_add_vod_import_source_hash.sql'));
		$this->assertSame([], $db->pdo->query("SHOW COLUMNS FROM streams LIKE 'import_source_hash'")->fetchAll());
	}
}
