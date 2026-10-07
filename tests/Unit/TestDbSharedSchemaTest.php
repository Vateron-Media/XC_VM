<?php

use PHPUnit\Framework\TestCase;

/**
 * TestDb's shared schema: one database several processes use (XC_VM_Fanout's
 * interop harness), which no instance drops.
 */
final class TestDbSharedSchemaTest extends TestCase {
	public function testInstancesShareTheSchemaAndNoneDropsIt(): void {
		$rName = 'xcvm_t' . getmypid() . '_9' . random_int(100000, 999999);
		$rFirst = new TestDb($rName);
		try {
			$rFirst->exec('CREATE TABLE `kept` (`id` int)');
			$rFirst->exec('INSERT INTO `kept` VALUES (7)');
			$rSecond = new TestDb($rName);
			$this->assertSame(7, (int) $rSecond->pdo->query('SELECT `id` FROM `kept`')->fetchColumn(), 'the other instance sees it');
			unset($rSecond);
			$this->assertSame(7, (int) $rFirst->pdo->query('SELECT `id` FROM `kept`')->fetchColumn(), 'an instance gone leaves the schema');
		} finally {
			$rFirst->pdo->exec('DROP DATABASE IF EXISTS `' . $rName . '`');
		}
	}

	public function testOnlyAnOrphanCleanupNameIsTaken(): void {
		$this->expectException(InvalidArgumentException::class);
		new TestDb('interop');
	}
}
