<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;

/**
 * An RTMP viewer's line, checked on MAIN for the server the viewer is on
 * (RtmpViewerAuth): by rtmp.php on MAIN and by the cluster API's rtmp_auth
 * for a load balancer. Each check rtmp.php made, in its order, with its log.
 * Run in a child PHP whose line lookup, GeoIP, stream redirect, client log
 * and guard are stand-ins, so each answer is the check's alone.
 */
final class RtmpViewerAuthTest extends TestCase {
	private const LINE = [
		'id' => 42, 'exp_date' => null, 'admin_enabled' => 1, 'enabled' => 1, 'allowed_ips' => [], 'forced_country' => '',
		'output_formats' => ['ts', 'rtmp'], 'channel_ids' => [100], 'isp_violate' => 0, 'isp_is_server' => 0, 'is_restreamer' => 0,
		'max_connections' => 2, 'pair_id' => null, 'con_isp_name' => 'Example ISP', 'isp_desc' => '', 'isp_asn' => '',
	];

	/**
	 * The answers of RtmpViewerAuth::check() for each case, with what the
	 * stand-ins saw: [answer, lookup asked, logs, guard calls, redirect's server].
	 *
	 * @param array<string, array{line?: array<string, mixed>|null, request?: array<string, string>, country?: string, redirect?: mixed, settings?: array<string, mixed>, restream?: bool}> $rCases
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(array $rCases): array {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
			. 'class StubLines { public static $rLine; public static $rAsked; public static function getStreamingUserInfo($rSettings, $rCached, $rBouquets, $rUserID, $rUsername, $rPassword) { self::$rAsked = [$rUsername, $rPassword]; return self::$rLine; } }' . "\n"
			. 'class_alias("StubLines", "XcVm\\\\Domain\\\\User\\\\UserRepository");' . "\n"
			. 'class StubGeo { public static $rCountry = ""; public static function getIPInfo($rIP) { return ["country" => ["iso_code" => self::$rCountry]]; } }' . "\n"
			. 'class_alias("StubGeo", "XcVm\\\\Core\\\\GeoIP\\\\GeoIPService");' . "\n"
			. 'class StubRedirect { public static $rOut; public static $rHere; public static function redirectStream(...$a) { self::$rHere = $a[9] ?? null; return self::$rOut; } }' . "\n"
			. 'class_alias("StubRedirect", "XcVm\\\\Streaming\\\\Delivery\\\\StreamRedirector");' . "\n"
			. 'class StubLog { public static $rLogs = []; public static function clientLog(...$a) { self::$rLogs[] = [$a[1], $a[2]]; } }' . "\n"
			. 'class_alias("StubLog", "XcVm\\\\Core\\\\Logging\\\\DatabaseLogger");' . "\n"
			. 'class StubGuard { public static $rCalls = []; public static function checkBruteforce(...$a) { self::$rCalls[] = [$a[2], $a[4] ?? null]; } }' . "\n"
			. 'class_alias("StubGuard", "XcVm\\\\Core\\\\Auth\\\\BruteforceGuard");' . "\n"
			. 'if (!defined("OPENSSL_EXTRA")) { define("OPENSSL_EXTRA", "extra"); }' . "\n"
			. '$rDb = new TestDb(); $rDb->exec("CREATE TABLE `lines` (`id` int, `admin_enabled` int)"); $rDb->query("INSERT INTO `lines` VALUES (42, 1)");' . "\n"
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($rDb);' . "\n"
			. '$rOut = [];' . "\n"
			. 'foreach (' . var_export($rCases, true) . ' as $rName => $rCase) {' . "\n"
			. '	StubLines::$rLine = array_key_exists("line", $rCase) ? $rCase["line"] : ' . var_export(self::LINE, true) . ';' . "\n"
			. '	StubLines::$rAsked = null; StubLog::$rLogs = []; StubGuard::$rCalls = []; StubRedirect::$rHere = null;' . "\n"
			. '	StubGeo::$rCountry = $rCase["country"] ?? "";' . "\n"
			. '	StubRedirect::$rOut = array_key_exists("redirect", $rCase) ? $rCase["redirect"] : ["redirect_id" => 7];' . "\n"
			. '	$rSettings = ($rCase["settings"] ?? []) + ["allow_countries" => ["ALL"], "detect_restream_block_user" => 0, "live_streaming_pass" => "stream-secret"];' . "\n"
			. '	$rAnswer = \XcVm\Domain\User\RtmpViewerAuth::check($rSettings, false, [], [], 100, "203.0.113.9", $rCase["request"] ?? ["username" => "viewer", "password" => "secret"], $rCase["restream"] ?? false, 7);' . "\n"
			. '	$rDb->query("SELECT `admin_enabled` FROM `lines` WHERE `id` = 42"); $rEnabled = (int) $rDb->get_row()["admin_enabled"]; $rDb->query("UPDATE `lines` SET `admin_enabled` = 1");' . "\n"
			. '	$rOut[$rName] = ["answer" => $rAnswer, "asked" => StubLines::$rAsked, "logs" => StubLog::$rLogs, "guard" => StubGuard::$rCalls, "here" => StubRedirect::$rHere, "line_enabled" => $rEnabled];' . "\n"
			. '}' . "\n"
			. 'echo json_encode($rOut);';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $rCode], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rStdout = (string) stream_get_contents($rPipes[1]);
		$rStderr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rStderr . $rStdout);
		$this->assertSame('', $rStderr);
		$rResults = json_decode($rStdout, true);
		$this->assertIsArray($rResults, $rStdout);
		return $rResults;
	}

	public function testAGoodLineOnTheViewersServerIsAdmittedWithWhatTheNodeRecords(): void {
		$rOut = $this->cases([
			'plain' => ['country' => 'FR'],
			'redirect without a server' => ['redirect' => ['redirect_id' => null]],
			'an access token' => ['request' => ['token' => str_repeat('ab', 16)]],
			'a playlist token' => ['request' => ['token' => Encryption::mintToken('viewer/secret', 'stream-secret', OPENSSL_EXTRA, true)]],
		]);
		$this->assertSame(['ok' => true, 'user' => ['id' => 42, 'max_connections' => 2, 'pair_id' => null, 'con_isp_name' => 'Example ISP', 'is_restreamer' => 0], 'country_code' => 'FR', 'channel' => [
			'stream_id' => 100, 'redirect_id' => 7, 'originator_id' => null, 'pid' => null, 'on_demand' => 0, 'llod' => 0, 'monitor_pid' => null, 'proxy' => 0,
		]], $rOut['plain']['answer'], 'the channel on the viewer\'s server, as a token\'s channel_info, for MAIN\'s mint');
		$this->assertSame(['viewer', 'secret'], $rOut['plain']['asked']);
		$this->assertSame(7, $rOut['plain']['here'], 'the stream is placed for the server the viewer is on, not the one checking');
		$this->assertSame([], $rOut['plain']['logs']);
		$this->assertTrue($rOut['redirect without a server']['answer']['ok']);
		$this->assertSame([str_repeat('ab', 16), null], $rOut['an access token']['asked']);
		$this->assertSame(['viewer', 'secret'], $rOut['a playlist token']['asked'], 'sealed with the stream secret, opened on MAIN');
	}

	public function testCredentialsThatNameNoLineAreCountedAgainstTheAddress(): void {
		$rOut = $this->cases([
			'a name and password' => ['line' => null],
			'an access token' => ['line' => null, 'request' => ['token' => str_repeat('ab', 16)]],
		]);
		foreach ($rOut as $rName => $rCase) {
			$this->assertSame(['ok' => false, 'reason' => 'AUTH_FAILED'], $rCase['answer'], $rName);
			$this->assertSame([[0, 'AUTH_FAILED']], $rCase['logs'], $rName);
		}
		$this->assertSame([['viewer', 'secret']], $rOut['a name and password']['guard'], 'the name and the password go to the guard');
		$this->assertSame([], $rOut['an access token']['guard'], 'a token names no one to count');
	}

	public function testEachOfRtmpPhpsRefusalsIsMadeAndLogged(): void {
		$rLine = static fn(array $rOver): array => ['line' => $rOver + self::LINE];
		$rOut = $this->cases([
			'USER_EXPIRED' => $rLine(['exp_date' => 1000]),
			'USER_BAN' => $rLine(['admin_enabled' => 0]),
			'USER_DISABLED' => $rLine(['enabled' => 0]),
			'IP_BAN' => $rLine(['allowed_ips' => ['198.51.100.1']]),
			'COUNTRY_DISALLOW forced' => $rLine(['forced_country' => 'DE']) + ['country' => 'FR'],
			'COUNTRY_DISALLOW allowed list' => ['country' => 'FR', 'settings' => ['allow_countries' => ['DE']]],
			'USER_ALREADY_CONNECTED' => $rLine(['ip_limit_reached' => 1]),
			'USER_DISALLOW_EXT' => $rLine(['output_formats' => ['ts']]),
			'NOT_IN_BOUQUET' => $rLine(['channel_ids' => [101]]),
			'ISP_LOCK_FAILED' => $rLine(['isp_violate' => 1]),
			'BLOCKED_ASN' => $rLine(['isp_is_server' => 1]),
			'RESTREAM_DETECT' => ['restream' => true],
		]);
		foreach ($rOut as $rName => $rCase) {
			$rReason = explode(' ', $rName)[0];
			$this->assertSame(['ok' => false, 'reason' => $rReason], $rCase['answer'], $rName);
			$this->assertSame([[42, $rReason]], $rCase['logs'], $rName);
		}
		$this->assertSame(1, $rOut['RESTREAM_DETECT']['line_enabled'], 'the line is left enabled unless the setting says to block it');
	}

	public function testWhatTheChecksLetThrough(): void {
		$rLine = static fn(array $rOver): array => ['line' => $rOver + self::LINE];
		$rOut = $this->cases([
			'a forced country of ALL' => $rLine(['forced_country' => 'ALL']) + ['country' => 'FR', 'settings' => ['allow_countries' => ['DE']]],
			'the forced country' => $rLine(['forced_country' => 'FR']) + ['country' => 'FR', 'settings' => ['allow_countries' => ['DE']]],
			'a country GeoIP does not know' => ['settings' => ['allow_countries' => ['DE']]],
			'a restreamer on a server address' => $rLine(['isp_is_server' => 1, 'is_restreamer' => 1]) + ['restream' => true],
			'an allowed address' => $rLine(['allowed_ips' => ['203.0.113.9']]),
		]);
		foreach ($rOut as $rName => $rCase) {
			$this->assertTrue($rCase['answer']['ok'], $rName);
		}
	}

	public function testARestreamerDetectedIsBlockedWhereTheSettingSaysSo(): void {
		$rOut = $this->cases(['blocked' => ['restream' => true, 'settings' => ['detect_restream_block_user' => 1]]]);
		$this->assertSame('RESTREAM_DETECT', $rOut['blocked']['answer']['reason']);
		$this->assertSame(0, $rOut['blocked']['line_enabled']);
	}

	public function testAStreamThisServerMayNotServeIsRefusedUnlogged(): void {
		$rOut = $this->cases([
			'another server' => ['redirect' => ['redirect_id' => 9]],
			'no server' => ['redirect' => false],
			'no capacity' => ['redirect' => []],
		]);
		foreach ($rOut as $rName => $rCase) {
			$this->assertSame(['ok' => false, 'reason' => 'NO_SERVER'], $rCase['answer'], $rName);
			$this->assertSame([], $rCase['logs'], $rName);
		}
	}
}
