<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Device\DeviceUserAgentPolicy;

final class DeviceUserAgentPolicyTest extends TestCase {
	public function testDisabledLockLeavesExistingClientsUnaffected(): void {
		$this->assertTrue(DeviceUserAgentPolicy::allows([], ''));
	}

	public function testLockedPolicyRequiresAnExactNonemptyAllowlist(): void {
		foreach (['[]', 'invalid json', '{}', '[null]', '["Player\\nInjected"]'] as $rList) {
			$this->assertFalse(DeviceUserAgentPolicy::allows(['lock_user_agent' => 1, 'allowed_user_agents' => $rList], 'Player'));
		}
		$rPolicy = ['lock_user_agent' => 1, 'allowed_user_agents' => '["Player/1.0"]'];
		$this->assertTrue(DeviceUserAgentPolicy::allows($rPolicy, 'Player/1.0'));
		$this->assertFalse(DeviceUserAgentPolicy::allows($rPolicy, 'player/1.0'));
		$this->assertFalse(DeviceUserAgentPolicy::allows($rPolicy, 'Player/1.0 extra'));
	}

	public function testHeaderObservationRejectsArraysAndControlCharacters(): void {
		$rPrevious = $_SERVER['HTTP_USER_AGENT'] ?? null;
		try {
			foreach ([['Player'], "Player\nInjected", str_repeat('x', 1025)] as $rValue) {
				$_SERVER['HTTP_USER_AGENT'] = $rValue;
				$this->assertSame('', DeviceUserAgentPolicy::observed());
			}
		} finally {
			if ($rPrevious === null) { unset($_SERVER['HTTP_USER_AGENT']); }
			else { $_SERVER['HTTP_USER_AGENT'] = $rPrevious; }
		}
	}
}
