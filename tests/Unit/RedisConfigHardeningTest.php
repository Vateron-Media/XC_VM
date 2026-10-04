<?php

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Redis\RedisConfigHardening;

/**
 * The Redis admin commands XC_VM never uses are renamed away, in the shipped
 * template and — once — in an existing install's generated config.
 */
final class RedisConfigHardeningTest extends TestCase {
	public function testTheShippedTemplateAlreadyDisablesEveryCommand(): void {
		$rTemplate = file_get_contents(MAIN_HOME . 'bin/redis/redis.conf');
		[$rConfig, $rAdded] = RedisConfigHardening::apply($rTemplate);
		$this->assertSame([], $rAdded);
		$this->assertSame($rTemplate, $rConfig);
	}

	public function testAnOldConfigGetsEachCommandOnceAndKeepsWhatThePanelUses(): void {
		$rOld = "bind *\nrequirepass secret\nsave 60 1000\nserver-threads 4";
		[$rConfig, $rAdded] = RedisConfigHardening::apply($rOld);
		$this->assertSame(RedisConfigHardening::DISABLED, $rAdded);
		$this->assertStringStartsWith($rOld . "\nrename-command CONFIG \"\"\n", $rConfig);
		foreach (RedisConfigHardening::DISABLED as $rCommand) {
			$this->assertSame(1, substr_count($rConfig, "rename-command {$rCommand} \"\"\n"));
		}
		foreach (['FLUSHALL', 'FLUSHDB', 'EVAL'] as $rUsed) {
			$this->assertStringNotContainsString("rename-command {$rUsed} ", $rConfig);
		}
		$this->assertSame([$rConfig, []], RedisConfigHardening::apply($rConfig));
	}

	public function testAnOperatorsOwnRenameIsLeftAlone(): void {
		$rOld = "requirepass x\nrename-command config my-secret-config\n";
		[$rConfig, $rAdded] = RedisConfigHardening::apply($rOld);
		$this->assertNotContains('CONFIG', $rAdded);
		$this->assertStringContainsString('rename-command config my-secret-config', $rConfig);
		$this->assertStringNotContainsString('rename-command CONFIG ""', $rConfig);
		$this->assertStringContainsString('rename-command DEBUG ""', $rConfig);
	}
}
