<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\Totp;
use XcVm\Core\Auth\TwoFactor;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Validation\InputValidator;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Two-factor sign-in (Core\Auth\TwoFactor, Totp): the RFC 6238 codes, a code
 * good once, recovery codes spent on use, a sign-in held until its code, the
 * setup a group can require, and the lock after repeated wrong codes.
 */
final class TwoFactorTest extends TestCase {
	private const USER = ['id' => 7, 'username' => 'admin7'];

	private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of the RFC's "12345678901234567890"

	private TestDb $rDb;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('users_2fa'));
		$this->rDb->exec(InstallSchema::table('login_logs'));
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['save_login_logs' => 1]);
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$_SESSION = [];
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
		DatabaseFactory::reset();
		$_SESSION = [];
	}

	/** The session a password sign-in leaves (Authenticator::login()). */
	private static function signedIn(): void {
		$_SESSION = ['hash' => 7, 'ip' => '192.0.2.10', 'code' => 'admin', 'verify' => 'v'];
	}

	private function enrol(): void {
		$this->assertNotNull(TwoFactor::enable(7, self::SECRET, Totp::code(self::SECRET, intdiv(time(), Totp::STEP)), time()));
	}

	private function statuses(): array {
		$this->rDb->query('SELECT `status` FROM `login_logs` ORDER BY `id`;');
		return array_column($this->rDb->get_raw_rows(), 'status');
	}

	public function testTheCodesAreRfc6238s(): void {
		foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $rTime => $rCode) {
			$this->assertSame($rCode, Totp::code(self::SECRET, intdiv($rTime, Totp::STEP)), (string) $rTime);
		}
		$this->assertSame('12345678901234567890', Totp::base32Decode(strtolower(chunk_split(self::SECRET, 4, ' '))));
		$this->assertSame(32, strlen(Totp::newSecret()));
		$this->assertMatchesRegularExpression('/^otpauth:\/\/totp\/My%20Panel%3Aadmin7\?secret=' . self::SECRET . '&issuer=My%20Panel&digits=6&period=30$/', Totp::uri(self::SECRET, 'admin7', 'My Panel'));
	}

	public function testACodeIsTakenOneStepEitherSideAndOnlyOnce(): void {
		$rNow = 1800000000;
		$rStep = intdiv($rNow, Totp::STEP);
		$this->assertSame($rStep - 1, Totp::match(self::SECRET, Totp::code(self::SECRET, $rStep - 1), $rNow));
		$this->assertNull(Totp::match(self::SECRET, Totp::code(self::SECRET, $rStep - 2), $rNow), 'two steps late');
		$this->assertNull(Totp::match(self::SECRET, '12345', $rNow));

		$this->assertNotNull(TwoFactor::enable(7, self::SECRET, Totp::code(self::SECRET, $rStep), $rNow));
		$this->assertFalse(TwoFactor::check(7, Totp::code(self::SECRET, $rStep), $rNow), 'the code that set it up');
		$this->assertTrue(TwoFactor::check(7, Totp::code(self::SECRET, $rStep + 1), $rNow + 30));
		$this->assertFalse(TwoFactor::check(7, Totp::code(self::SECRET, $rStep + 1), $rNow + 30), 'replayed');
		$this->assertFalse(TwoFactor::check(7, Totp::code(self::SECRET, $rStep), $rNow + 30), 'an earlier step');
	}

	public function testARecoveryCodeSignsInOnce(): void {
		$rCodes = TwoFactor::enable(7, self::SECRET, Totp::code(self::SECRET, intdiv(time(), Totp::STEP)), time());
		$this->assertCount(10, $rCodes);
		$this->assertCount(10, array_unique($rCodes));
		$this->assertMatchesRegularExpression('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $rCodes[0]);

		$this->assertTrue(TwoFactor::check(7, strtoupper(str_replace('-', '', $rCodes[3])), time()), 'any case, without the dash');
		$this->assertFalse(TwoFactor::check(7, $rCodes[3], time()), 'spent');
		$this->assertCount(9, TwoFactor::of(7)['recovery']);
		$this->assertStringNotContainsString(str_replace('-', '', $rCodes[0]), (string) json_encode(TwoFactor::of(7)), 'only hashes are kept');

		$rNew = TwoFactor::renewRecovery(7);
		$this->assertFalse(TwoFactor::check(7, $rCodes[0], time()), 'replaced');
		$this->assertTrue(TwoFactor::check(7, $rNew[0], time()));
	}

	public function testWithoutASecondFactorTheSignInIsNotHeld(): void {
		self::signedIn();
		$this->assertFalse(TwoFactor::hold('admin', self::USER, ['require_2fa' => 0], 3));
		$this->assertSame(7, $_SESSION['hash']);
		$this->assertArrayNotHasKey('2fa', $_SESSION);
	}

	public function testASignInIsHeldUntilItsCode(): void {
		$this->enrol();
		self::signedIn();
		$this->assertTrue(TwoFactor::hold('admin', self::USER, ['require_2fa' => 0], 3));
		$this->assertArrayNotHasKey('hash', $_SESSION, 'not signed in while held');
		$this->assertArrayNotHasKey('verify', $_SESSION);
		$this->assertNull(TwoFactor::pending('reseller'), 'the other panel has nothing held');

		$this->assertSame(['status' => STATUS_2FA_INVALID], TwoFactor::confirm('admin', '000000'));
		$this->assertArrayNotHasKey('hash', $_SESSION);

		$rCode = Totp::code(self::SECRET, intdiv(time(), Totp::STEP) + 1);
		$this->assertSame(['status' => STATUS_SUCCESS], TwoFactor::confirm('admin', substr($rCode, 0, 3) . ' ' . substr($rCode, 3)));
		$this->assertSame(['hash' => 7, 'ip' => '192.0.2.10', 'code' => 'admin', 'verify' => 'v'], $_SESSION);
		$this->assertSame(['INVALID_2FA', 'SUCCESS'], $this->statuses());
	}

	/** Between the two requests the session passes through the request cleaning, which turns every value into a string. */
	public function testAHeldSignInSurvivesTheNextRequestsSessionCleaning(): void {
		$this->enrol();
		self::signedIn();
		TwoFactor::hold('admin', self::USER, [], null);
		InputValidator::cleanGlobals($_SESSION);
		$this->assertSame(['status' => STATUS_SUCCESS], TwoFactor::confirm('admin', Totp::code(self::SECRET, intdiv(time(), Totp::STEP) + 1)));
		$this->assertSame('7', (string) $_SESSION['hash']);

		$_SESSION = ['hash' => 7];
		TwoFactor::hold('admin', self::USER, ['require_2fa' => 0], null);
		InputValidator::cleanGlobals($_SESSION);
		$this->assertSame(['status' => STATUS_2FA_INVALID], TwoFactor::confirm('admin', '000000'));
		$this->rDb->query("SELECT `access_code` FROM `login_logs` WHERE `status` = 'INVALID_2FA';");
		$this->assertNull($this->rDb->get_raw_row()['access_code']);
	}

	public function testAHeldSignInFromAnotherAddressOrTooOldIsDropped(): void {
		$this->enrol();
		self::signedIn();
		TwoFactor::hold('reseller', self::USER, [], null);
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->assertNull(TwoFactor::pending('reseller'));
		$this->assertArrayNotHasKey('2fa', $_SESSION);

		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		TwoFactor::hold('reseller', self::USER, [], null);
		$_SESSION['2fa']['at'] -= TwoFactor::PENDING_TTL + 1;
		$this->assertSame(['status' => STATUS_FAILURE], TwoFactor::confirm('reseller', Totp::code(self::SECRET, intdiv(time(), Totp::STEP) + 1)));
	}

	public function testAGroupThatRequiresItSetsItUpAtSignIn(): void {
		self::signedIn();
		$this->assertTrue(TwoFactor::hold('admin', self::USER, ['require_2fa' => '1'], 3));
		$rSecret = TwoFactor::pending('admin')['enrol'];
		$this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $rSecret);

		$this->assertSame(['status' => STATUS_2FA_INVALID], TwoFactor::confirm('admin', '000000'));
		$this->assertNull(TwoFactor::of(7), 'nothing stored before a good code');

		$rOut = TwoFactor::confirm('admin', Totp::code($rSecret, intdiv(time(), Totp::STEP)));
		$this->assertSame(STATUS_SUCCESS, $rOut['status']);
		$this->assertCount(10, $rOut['recovery']);
		$this->assertSame($rSecret, TwoFactor::of(7)['secret']);
		$this->assertSame(7, $_SESSION['hash']);
	}

	public function testTenWrongCodesLockTheAccountForAWhile(): void {
		$this->enrol();
		for ($i = 0; $i < TwoFactor::MAX_FAILS; $i++) {
			$this->rDb->query("INSERT INTO `login_logs` (`type`, `user_id`, `status`, `login_ip`, `date`) VALUES ('ADMIN', 7, 'INVALID_2FA', '192.0.2.10', ?);", time() - 60);
		}
		self::signedIn();
		TwoFactor::hold('admin', self::USER, [], 3);
		$this->assertSame(['status' => STATUS_2FA_LOCKED], TwoFactor::confirm('admin', Totp::code(self::SECRET, intdiv(time(), Totp::STEP) + 1)));
		$this->assertArrayNotHasKey('hash', $_SESSION);
		$this->assertFalse(TwoFactor::tooManyFails(7, time() + TwoFactor::FAIL_WINDOW), 'the lock ends');
	}

	public function testTheProfileTurnsItOnAndOffWithACode(): void {
		$rStep = intdiv(time(), Totp::STEP);
		$this->assertSame(STATUS_2FA_INVALID, TwoFactor::manage(7, ['sub' => 'enable', 'secret' => 'not-base32', 'code' => Totp::code('not-base32', $rStep)])['status']);
		$rOn = TwoFactor::manage(7, ['sub' => 'enable', 'secret' => self::SECRET, 'code' => Totp::code(self::SECRET, $rStep)]);
		$this->assertTrue($rOn['result']);
		$this->assertCount(10, $rOn['recovery']);
		$this->assertFalse(TwoFactor::manage(7, ['sub' => 'enable', 'secret' => Totp::newSecret(), 'code' => '123456'])['result'], 'already on');

		$this->assertFalse(TwoFactor::manage(7, ['sub' => 'disable', 'code' => '000000'])['result']);
		$this->assertNotNull(TwoFactor::of(7));
		$this->assertTrue(TwoFactor::manage(7, ['sub' => 'disable', 'code' => $rOn['recovery'][0]])['result']);
		$this->assertNull(TwoFactor::of(7));
		$this->assertSame(STATUS_FAILURE, TwoFactor::manage(7, ['sub' => 'other'])['status']);
	}
}
