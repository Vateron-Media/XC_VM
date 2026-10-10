<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\ResellerAPI;

/**
 * ResellerAPI::processData() — the whitelist every reseller save goes through.
 *
 * Two fields a reseller could use against other people's subscriptions are
 * decided here: `trial` (a trial is issued with a new subscription, never on an
 * edit, where the per-period trial quota — counted by created_at — does not
 * see it) and `pair_id` (pairing copies the paired line's subscription onto
 * this one, so only a line the reseller manages may be named).
 */
final class ResellerAPIProcessDataTest extends TestCase {

	protected function tearDown(): void {
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	public function testDropsKeysOutsideTheWhitelist(): void {
		$rData = ResellerAPI::processData('line', ['username' => 'u', 'member_group_id' => 1, 'exp_date' => 1]);
		$this->assertSame(['username' => 'u'], $rData);
	}

	public function testTrialOnlyWhenCreating(): void {
		foreach (['line', 'mag', 'enigma'] as $rType) {
			$this->assertSame('1', ResellerAPI::processData($rType, ['trial' => '1'])['trial'] ?? null, $rType . ' create');
			$this->assertArrayNotHasKey('trial', ResellerAPI::processData($rType, ['edit' => '7', 'trial' => '1']), $rType . ' edit');
		}
	}

	public function testPairIdNeedsALineTheResellerManages(): void {
		// No reseller context loaded: Authorization::check() answers false.
		foreach (['line', 'mag', 'enigma'] as $rType) {
			$this->assertArrayNotHasKey('pair_id', ResellerAPI::processData($rType, ['pair_id' => '42']), $rType);
		}
	}

	/**
	 * A line's restrictions (allowed addresses and user agents, the blocked
	 * user agents' bypass, the ISP lock) are a reseller's to set only with
	 * allow_restrictions. The save skipped its restrictions block without it,
	 * but a new line had already been filled from what was posted.
	 */
	public function testRestrictionsNeedThePermission(): void {
		$rPosted = ['username' => 'u', 'allowed_ips' => ['203.0.113.9'], 'allowed_ua' => ['x'], 'bypass_ua' => '1', 'is_isplock' => '1', 'isp_clear' => ''];
		$rBefore = ResellerAPI::$rPermissions;
		try {
			ResellerAPI::$rPermissions = ['allow_restrictions' => 0];
			foreach (['line', 'mag', 'enigma'] as $rType) {
				$rKept = array_keys(ResellerAPI::processData($rType, $rPosted));
				$this->assertSame([], array_values(array_intersect($rKept, ['allowed_ips', 'allowed_ua', 'bypass_ua', 'is_isplock', 'isp_clear'])), $rType);
			}
			ResellerAPI::$rPermissions = ['allow_restrictions' => 1];
			$this->assertSame($rPosted, ResellerAPI::processData('line', $rPosted), 'with the permission: as posted');
		} finally {
			ResellerAPI::$rPermissions = $rBefore;
		}
	}
}
