<?php

use XcVm\Core\Enum\ServerEnvironment;
use XcVm\Core\Enum\BootContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \XcVm\Core\Enum\BootContext
 * @covers \XcVm\Core\Enum\ServerEnvironment
 */
final class CoreEnumTest extends TestCase {

	public function testBootContextValues() {
		$this->assertSame('minimal', BootContext::Minimal->value);
		$this->assertSame('cli', BootContext::Cli->value);
		$this->assertSame('stream', BootContext::Stream->value);
		$this->assertSame('admin', BootContext::Admin->value);
		$this->assertSame('webapi', BootContext::WebApi->value);
	}

	public function testBootContextFromString() {
		$this->assertSame(BootContext::Admin, BootContext::from('admin'));
		$this->assertNull(BootContext::tryFrom('nope'));
		$this->assertCount(5, BootContext::cases());
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
