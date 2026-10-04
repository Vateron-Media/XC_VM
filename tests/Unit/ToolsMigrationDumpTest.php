<?php

use XcVm\Cli\Commands\ToolsCommand;
use PHPUnit\Framework\TestCase;

/**
 * `tools migration` restores a copy of the dump without its USE / CREATE
 * DATABASE statements, so a dump made with --databases still lands in
 * xc_vm_migrate instead of the database it names.
 */
final class ToolsMigrationDumpTest extends TestCase {

	private string $source;
	private string $target;

	protected function setUp(): void {
		$this->source = tempnam(sys_get_temp_dir(), 'dump_in');
		$this->target = tempnam(sys_get_temp_dir(), 'dump_out');
	}

	protected function tearDown(): void {
		@unlink($this->source);
		@unlink($this->target);
	}

	public function testDropsDatabaseSwitchesAndKeepsEverythingElse(): void {
		file_put_contents($this->source, implode("\n", [
			'-- MariaDB dump',
			'CREATE DATABASE /*!32312 IF NOT EXISTS*/ `xui` /*!40100 DEFAULT CHARACTER SET utf8 */;',
			'',
			'USE `xui`;',
			'  use xc_vm;',
			'DROP TABLE IF EXISTS `users`;',
			"INSERT INTO `users` VALUES (1,'USE this','CREATE DATABASE x');",
			'CREATE TABLE `use_log` (`id` int);',
			'',
		]));

		$this->assertSame(3, ToolsCommand::stripDatabaseSwitches($this->source, $this->target));
		$this->assertSame(implode("\n", [
			'-- MariaDB dump',
			'',
			'DROP TABLE IF EXISTS `users`;',
			"INSERT INTO `users` VALUES (1,'USE this','CREATE DATABASE x');",
			'CREATE TABLE `use_log` (`id` int);',
			'',
		]), file_get_contents($this->target));
	}

	public function testAPlainDumpIsCopiedUnchanged(): void {
		$rDump = "DROP TABLE IF EXISTS `streams`;\nINSERT INTO `streams` VALUES (1);\n";
		file_put_contents($this->source, $rDump);

		$this->assertSame(0, ToolsCommand::stripDatabaseSwitches($this->source, $this->target));
		$this->assertSame($rDump, file_get_contents($this->target));
	}

	public function testAMissingDumpIsReported(): void {
		$this->assertNull(ToolsCommand::stripDatabaseSwitches($this->source . '.missing', $this->target));
	}
}
