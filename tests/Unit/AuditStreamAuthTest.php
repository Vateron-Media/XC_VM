<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;

/**
 * What the stream gateway checks before it hands a viewer on: the catch-up
 * window it copies into a token, the stream a probe may ask about, the
 * parameters of an HMAC link, and what happens to a line that has no notice
 * clip to be shown.
 *
 * The entry scripts are procedural, so the lines under test are read from the
 * script and run in a child PHP, where an error answer is thrown instead of
 * ending the process (ErrorResponder::$throwInsteadOfExit).
 */
final class AuditStreamAuthTest extends TestCase {
	private const AUTH = 'Public/stream/auth.php';
	private const PROBE = 'Public/stream/probe.php';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-stream-auth-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->rDir . '*') ?: []);
		rmdir($this->rDir);
	}

	/** The source of $rFile from $rFrom up to $rTo, the first ones after $rAfter. */
	private function lines(string $rFile, string $rFrom, string $rTo, string $rAfter = ''): string {
		$rSource = (string) file_get_contents(MAIN_HOME . $rFile);
		$rStart = strpos($rSource, $rFrom, (int) strpos($rSource, $rAfter));
		$this->assertNotFalse($rStart, $rFile . ' has no: ' . $rFrom);
		$rEnd = strpos($rSource, $rTo, $rStart);
		$this->assertNotFalse($rEnd, $rFile . ' has no: ' . $rTo);
		return substr($rSource, $rStart, $rEnd - $rStart);
	}

	/**
	 * Runs $rLines in a child PHP with the imports of the entry script $rFile,
	 * once per case and with the case's variables. Per case: what $rResult is
	 * after them, or the error they answered with.
	 *
	 * @param array<string, array<string, mixed>> $rCases
	 * @return array<string, mixed>
	 */
	private function child(string $rFile, string $rLines, array $rCases, string $rResult, string $rSetup = ''): array {
		preg_match_all('/^use [^;]+;$/m', (string) file_get_contents(MAIN_HOME . $rFile), $rUses);
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
			. implode("\n", $rUses[0]) . "\n"
			. '\XcVm\Core\Error\ErrorResponder::$throwInsteadOfExit = true;' . "\n"
			. $rSetup . "\n"
			. '$rRun = static function (array $rVars) { extract($rVars);' . "\n" . $rLines . "\n" . 'return ' . $rResult . '; };' . "\n"
			. '$rOut = [];' . "\n"
			. 'foreach (' . var_export($rCases, true) . ' as $rName => $rVars) {' . "\n"
			. '	ob_start();' . "\n"
			. '	try { $rOut[$rName] = $rRun($rVars); } catch (\XcVm\Core\Error\ErrorResponseException $e) { $rOut[$rName] = "refused " . ($e->errorCode ?: "404"); }' . "\n"
			. '	ob_end_clean();' . "\n"
			. '}' . "\n"
			. 'echo json_encode($rOut);';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('', $rErr);

		return json_decode($rOut, true);
	}

	/** The catch-up case of auth.php, from `start` to the token. */
	private function catchUp(array $rRequests): array {
		$rCases = [];
		foreach ($rRequests as $rName => $rRequest) {
			$rCases[$rName] = ['rRequest' => $rRequest];
		}
		return $this->child(self::AUTH, $this->lines(self::AUTH, '$rStartDate = ', 'switch ($rExtension) {'), $rCases, '[$rStartDate, $rDuration]');
	}

	public function testCatchUpStartIsOneOfTheFormsTheArchiveReads(): void {
		// The last form with seconds too: only its minute is read.
		$rRead = ['1700000000', '20250101-13', '20250101-5', '2025-01-01:13-30', '2025-01-01:13-30:00', '2025-01-01:13-30-00'];
		$rNot = ['2025-01-01:13-30:00:00', '2025-01-01:13-30/1', '1700000000_1', '99999999999', 'yesterday', '', ['1700000000']];

		$rRequests = [];
		foreach (array_merge($rRead, $rNot) as $rIndex => $rStart) {
			$rRequests['s' . $rIndex] = ['start' => $rStart, 'duration' => '60'];
		}
		$rOut = $this->catchUp($rRequests + ['none' => ['duration' => '60']]);

		foreach ($rRead as $rIndex => $rStart) {
			$this->assertSame([$rStart, 60], $rOut['s' . $rIndex], $rStart);
		}
		foreach ($rNot as $rIndex => $rStart) {
			$this->assertSame('refused NO_TIMESTAMP', $rOut['s' . (count($rRead) + $rIndex)], json_encode($rStart));
		}
		$this->assertSame('refused NO_TIMESTAMP', $rOut['none']);
	}

	public function testCatchUpDurationIsNoLongerThanAUtcLinksWindow(): void {
		$rOut = $this->catchUp([
			'programme' => ['start' => '1700000000', 'duration' => '60'],
			'utc' => ['start' => '1700000000', 'duration' => (string) (3600 * 6)],
			'longer' => ['start' => '1700000000', 'duration' => '99999999'],
		]);

		$this->assertSame(60, $rOut['programme'][1]);
		$this->assertSame(3600 * 6, $rOut['utc'][1]);
		$this->assertSame(3600 * 6, $rOut['longer'][1]);
	}

	public function testProbeAnswersOnlyForAStreamInTheLinesBouquets(): void {
		file_put_contents($this->rDir . 'bouquet_map', igbinary_serialize([5 => [1], 6 => [2]]));
		$rLine = ['exp_date' => null, 'admin_enabled' => 1, 'enabled' => 1, 'is_restreamer' => 1, 'bouquet' => [1]];

		$rOut = $this->child(
			self::PROBE,
			$this->lines(self::PROBE, 'if ($rStreamID && $rUserInfo) {', '$rChannelInfo = StreamRedirector::redirectStream(') . '$rAnswered = true; }',
			[
				'its own' => ['rStreamID' => 5, 'rUserInfo' => $rLine],
				'another bouquet' => ['rStreamID' => 6, 'rUserInfo' => $rLine],
				'no bouquet' => ['rStreamID' => 7, 'rUserInfo' => $rLine],
				'not a restreamer' => ['rStreamID' => 5, 'rUserInfo' => ['is_restreamer' => 0] + $rLine],
			],
			'$rAnswered ?? false',
			'define("CACHE_TMP_PATH", ' . var_export($this->rDir, true) . ');'
		);

		$this->assertSame(['its own' => true, 'another bouquet' => 'refused 404', 'no bouquet' => 'refused 404', 'not a restreamer' => 'refused 404'], $rOut);
	}

	public function testProbeCountsANameThatMatchesNoLineAsAuthDoes(): void {
		$rSettings = ['live_streaming_pass' => 'streaming-pass', 'ignore_invalid_users' => 0, 'enable_cache' => 1];
		$rPaths = [
			'no such line' => '/live/guess/secret/5.ts',
			'no such line, short link' => '/guess/secret/5',
			'no such line, playlist link' => '/play/' . Encryption::seal('live/guess/secret/5', 'streaming-pass', OPENSSL_EXTRA),
			'its own' => '/live/reseller/secret/5.ts',
			'a token' => '/live/' . str_repeat('a', 32) . '/5.ts',
		];

		// Each case asks from its own address, so its count is its own.
		$rCases = [];
		foreach (array_keys($rPaths) as $rIndex => $rName) {
			$rCases[$rName] = ['rRequest' => ['data' => base64_encode($rPaths[$rName])], 'rIP' => '203.0.113.' . $rIndex, 'rSettings' => $rSettings, 'rCached' => false, 'rBouquets' => []];
		}
		$rCases['where auth ignores it'] = ['rIP' => '203.0.113.9', 'rSettings' => ['ignore_invalid_users' => 1] + $rSettings] + $rCases['no such line'];

		// The lookup itself is the repository's: here it knows one line.
		$rOut = $this->child(
			self::PROBE,
			$this->lines(self::PROBE, '$rPath = base64_decode(', 'if ($rStreamID && $rUserInfo) {'),
			$rCases,
			'array_keys(json_decode((string) @file_get_contents(FLOOD_TMP_PATH . $rIP . "_user"), true)["attempts"] ?? [])',
			'define("FLOOD_TMP_PATH", ' . var_export($this->rDir, true) . ');'
				. '$rSettings = ["bruteforce_username_attempts" => 10, "bruteforce_frequency" => 300];'
				. 'class ProbeLines { public static function getStreamingUserInfo($rSettings, $rCached, $rBouquets, $rUserID, $rUsername, $rPassword) { return [$rUsername, $rPassword] === ["reseller", "secret"] ? ["id" => 1] : null; } }'
				. 'class_alias("ProbeLines", UserRepository::class);'
		);

		$this->assertSame(['no such line' => ['guess'], 'no such line, short link' => ['guess'], 'no such line, playlist link' => ['guess'], 'its own' => [], 'a token' => [], 'where auth ignores it' => []], $rOut);
	}

	/** The HMAC branch of auth.php, from the request's parameters to the key that signed them. */
	private function hmac(array $rRequests): array {
		$rCases = [];
		foreach ($rRequests as $rName => $rRequest) {
			$rCases[$rName] = ['rRequest' => $rRequest, 'rStreamID' => 1, 'rExtension' => 'ts', 'rIP' => '10.0.0.1', 'rDeny' => true];
		}
		$rSetup = '$rSettings = ["enable_cache" => 0, "live_streaming_pass" => "streaming-pass"];'
			. '$db = new class { public function query($rQuery) { return true; } public function get_rows() { return [["id" => 9, "key" => Encryption::encrypt("s3cret", "streaming-pass", OPENSSL_EXTRA)]]; } };';
		return $this->child(self::AUTH, $this->lines(self::AUTH, '$rIdentifier = (empty(', 'if ($rIsHMAC) {'), $rCases, '$rIsHMAC', $rSetup);
	}

	private function sign(string $rExpiry, string $rSecret = 's3cret'): string {
		return hash_hmac('sha256', '1##ts##' . $rExpiry . '####viewer-1##0', $rSecret);
	}

	public function testAnHmacLinkIsCheckedWithOrWithoutAnExpiry(): void {
		$rAhead = (string) (time() + 3600);
		$rPast = (string) (time() - 3600);

		$rOut = $this->hmac([
			'no expiry' => ['hmac' => $this->sign(''), 'identifier' => 'viewer-1'],
			'expiry ahead' => ['hmac' => $this->sign($rAhead), 'identifier' => 'viewer-1', 'expiry' => $rAhead],
			'expiry past' => ['hmac' => $this->sign($rPast), 'identifier' => 'viewer-1', 'expiry' => $rPast],
			'another key' => ['hmac' => $this->sign('', 'other-secret'), 'identifier' => 'viewer-1'],
			'expiry left out' => ['hmac' => $this->sign($rAhead), 'identifier' => 'viewer-1'],
		]);

		$this->assertSame(['no expiry' => 9, 'expiry ahead' => 9, 'expiry past' => 'refused TOKEN_EXPIRED', 'another key' => null, 'expiry left out' => null], $rOut);
	}

	public function testAnHmacParameterSentAsAListIsRefused(): void {
		$rLink = ['hmac' => $this->sign(''), 'identifier' => 'viewer-1'];

		$rOut = $this->hmac([
			'hmac' => ['hmac' => [$rLink['hmac']]] + $rLink,
			'identifier' => ['identifier' => ['viewer-1']] + $rLink,
			'ip' => ['ip' => ['10.0.0.1']] + $rLink,
			'expiry' => ['expiry' => ['']] + $rLink,
		]);

		$this->assertSame(array_fill_keys(['hmac', 'identifier', 'ip', 'expiry'], 'refused INVALID_CREDENTIALS'), $rOut);
	}

	public function testAMovieTokenNamesItsSourceTheSameForALineAndAnHmacLink(): void {
		$rSource = 'http://source.example/movie.mp4';
		$rVars = [
			'rStreamID' => 7, 'rExtension' => 'mp4', 'rType' => 'movie', 'rPID' => 1, 'rIdentifier' => 'viewer-1', 'rCountryCode' => '', 'rActivityStart' => 1700000000, 'rIsMag' => false, 'rUUID' => str_repeat('a', 32),
			'rRequest' => ['hmac' => 'signature'],
			'rUserInfo' => ['id' => 3, 'username' => 'u', 'password' => 'p', 'max_connections' => 1, 'pair_id' => null, 'con_isp_name' => null, 'is_restreamer' => 0],
			'rChannelInfo' => ['stream_id' => 7, 'bitrate' => 0, 'target_container' => 'mp4', 'redirect_id' => 1, 'pid' => 0, 'direct_proxy' => 1, 'stream_source' => json_encode([$rSource])],
		];

		$rOut = $this->child(
			self::AUTH,
			$this->lines(self::AUTH, 'if (!$rIsHMAC) {', 'if (isset($_GET[\'segment\'])) {', "case 'series':"),
			[
				'line' => ['rIsHMAC' => null] + $rVars,
				'hmac' => ['rIsHMAC' => 9] + $rVars,
				'hmac, not proxied' => ['rIsHMAC' => 9, 'rChannelInfo' => ['direct_proxy' => 0] + $rVars['rChannelInfo']] + $rVars,
			],
			'$rTokenData["channel_info"]'
		);

		$this->assertSame($rSource, $rOut['line']['proxy']);
		$this->assertSame($rOut['line'], $rOut['hmac']);
		$this->assertNull($rOut['hmac, not proxied']['proxy']);
	}

	public function testALineWithNoExpiringClipToBeShownGoesOnToItsStream(): void {
		$rSettings = ['show_expiring_video' => 1, 'show_connected_video' => 1, 'show_expired_video' => 1, 'show_banned_video' => 1, 'show_not_on_air_video' => 1];
		foreach (['expiring', 'connected', 'expired', 'banned', 'not_on_air'] as $rName) {
			$rSettings[$rName . '_video_path'] = '/video/' . $rName . '.ts';
		}
		$rRestreamer = ['is_restreamer' => 1, 'con_isp_name' => null];
		$rViewer = ['is_restreamer' => 0] + $rRestreamer;

		$rCases = ['no clip' => ['rOption' => 'show_expiring_video', 'rPath' => 'expiring_video_path', 'rUserInfo' => $rViewer, 'rSettings' => ['expiring_video_path' => ''] + $rSettings]];
		foreach (['expiring', 'connected', 'expired', 'banned', 'not_on_air'] as $rName) {
			$rCases[$rName] = ['rOption' => 'show_' . $rName . '_video', 'rPath' => $rName . '_video_path', 'rUserInfo' => $rRestreamer, 'rSettings' => $rSettings];
		}
		// A clip to show, on a server whose viewers go through its proxies, none of which is free.
		foreach (['expiring', 'not_on_air'] as $rName) {
			$rCases[$rName . ', no proxy'] = ['rUserInfo' => $rViewer] + $rCases[$rName];
		}

		$rOut = $this->child(
			self::AUTH,
			'$GLOBALS["rSettings"] = $rSettings; OffAirHandler::showVideoServer($rOption, $rPath, "ts", $rUserInfo, "10.0.0.1", "", null, 1);',
			$rCases,
			'"goes on"',
			'define("VIDEO_PATH", ' . var_export($this->rDir, true) . '); $rServers = [1 => ["enable_proxy" => 1, "server_type" => 0]];'
		);

		// The expiring clip is a notice; the others stand in for a stream the line may not have.
		$this->assertSame(['no clip' => 'goes on', 'expiring' => 'goes on', 'connected' => 'refused 404', 'expired' => 'refused EXPIRED', 'banned' => 'refused BANNED', 'not_on_air' => 'refused STREAM_OFFLINE', 'expiring, no proxy' => 'goes on', 'not_on_air, no proxy' => 'refused 404'], $rOut);
	}
}
