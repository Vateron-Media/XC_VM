<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers StreamUtils
 */
final class StreamUtilsTest extends TestCase {

	public function testCustomOrderPutsInputArgumentsFirst() {
		$this->assertSame(-1, StreamUtils::customOrder('-i input.ts', 'something'));
		$this->assertSame(1, StreamUtils::customOrder('-c:v libx264', '-i input.ts'));
	}

	public function testDetectXcVmMatchesKnownStreamPaths() {
		$this->assertTrue(StreamUtils::detectXC_VM('http://host/live/user/123'));
	}

	public function testDetectXcVmRejectsUnrelatedPaths() {
		$this->assertFalse(StreamUtils::detectXC_VM('http://host/dashboard'));
	}
}
