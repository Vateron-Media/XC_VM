<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers BootContext
 * @covers ServerEnvironment
 */
final class CoreEnumTest extends TestCase {

	public function testBootContextValues() {
		$this->assertSame('minimal', BootContext::Minimal->value);
		$this->assertSame('cli', BootContext::Cli->value);
		$this->assertSame('stream', BootContext::Stream->value);
		$this->assertSame('admin', BootContext::Admin->value);
	}

	public function testBootContextFromString() {
		$this->assertSame(BootContext::Admin, BootContext::from('admin'));
		$this->assertNull(BootContext::tryFrom('nope'));
		$this->assertCount(4, BootContext::cases());
	}

	public function testServerEnvironmentValues() {
		$this->assertSame('main', ServerEnvironment::Main->value);
		$this->assertSame('lb', ServerEnvironment::LoadBalancer->value);
	}

	public function testServerEnvironmentFromString() {
		$this->assertSame(ServerEnvironment::LoadBalancer, ServerEnvironment::from('lb'));
		$this->assertNull(ServerEnvironment::tryFrom('xxx'));
		$this->assertCount(2, ServerEnvironment::cases());
	}
}
