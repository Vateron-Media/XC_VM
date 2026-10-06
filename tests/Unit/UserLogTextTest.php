<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserService;

/**
 * A reseller action log row says the same on the admin's and the reseller's
 * User Logs and on the reseller dashboard: one mapping (UserService::logText).
 */
final class UserLogTextTest extends TestCase {
	private const PACKAGES = [4 => ['package_name' => 'Gold <1M>']];

	private static function text(string $rAction, string $rType = 'line', ?int $rPackage = null, string $rGlue = ' '): string {
		return UserService::logText(['type' => $rType, 'action' => $rAction, 'package_id' => $rPackage, 'cost' => 3], self::PACKAGES, $rGlue);
	}

	public function testEachActionSaysWhatWasDone(): void {
		$this->assertSame('Created New User Line with Package: Gold <1M>', self::text('new', 'line', 4));
		$this->assertSame('Extended MAG Device with Package: Gold <1M>', self::text('extend', 'mag', 4));
		$this->assertSame('Created New Enigma2 Device', self::text('new', 'enigma'));
		$this->assertSame('Converted Device to User Line', self::text('convert', 'mag'));
		$this->assertSame('Edited Reseller', self::text('edit', 'user'));
		$this->assertSame('Enabled User Line', self::text('enable'));
		$this->assertSame('Disabled User Line', self::text('disable'));
		$this->assertSame('Deleted User Line', self::text('delete'));
		$this->assertSame('Sent Event to MAG Device', self::text('send_event', 'mag'));
		$this->assertSame('Adjusted Credits by 3', self::text('adjust_credits', 'user'));
		$this->assertSame('Additional Connection Added', self::text('connection'));
	}

	public function testAnUnknownActionOrDeviceSaysItsName(): void {
		$this->assertSame('generate', self::text('generate'));
		$this->assertSame('Edited radio', self::text('edit', 'radio'));
	}

	public function testADeletedPackageIsNotNamed(): void {
		$this->assertSame('Created New User Line', self::text('new', 'line', 9));
	}

	public function testTheSeparatorGoesBeforeThePackageName(): void {
		$this->assertSame("Extended User Line with Package:\nGold <1M>", self::text('extend', 'line', 4, "\n"));
		$this->assertSame('Edited User Line', self::text('edit', 'line', 4, "\n"));
	}
}
