<?php

use PHPUnit\Framework\TestCase;

/**
 * A station marked Direct Source is passed on to its source by the stream
 * endpoint: the live case of auth.php answers a play address of the line
 * (`<username>/<password>/<id>.<ext>`, the form player_api apps, playlists,
 * MAG and Enigma2 ask for) with a redirect to the station's first source and
 * ends there, before a token is made, so no connection is recorded. Every
 * client keeps that answer; the second web player's play answer leads to the
 * same request (AuditDecisionRadioPlayAddressTest).
 *
 * auth.php is procedural, so the live case's lines are read from the script
 * and run in a child PHP, from the stream cache and from the database.
 */
final class AuditDecisionRadioDirectSourceTest extends TestCase {
	private const AUTH = 'Public/stream/auth.php';
	private const SOURCE = 'http://provider.test/direct station.mp3';

	/** Runs the live case, with auth.php's imports, for station 5 cached or not, asked with the extension in its one argument. */
	private const CHILD = <<<'PHP'
		namespace XcVm\Streaming\Delivery {
			// A child PHP keeps no header: the redirector's go to the error stream as they are sent.
			function header(string $rHeader): void {
				fwrite(STDERR, 'header: ' . $rHeader . "\n");
			}
		}

		namespace {
			/*USES*/
			[$rBootstrap, $rDir, $rCached, $rExtension] = json_decode($argv[1], true);
			require $rBootstrap;
			define('SERVER_ID', 1);
			define('CACHE_TMP_PATH', $rDir);
			define('STREAMS_TMP_PATH', $rDir);
			$rStation = ['id' => 5, 'type' => 4, 'direct_source' => 1, 'direct_proxy' => 0, 'stream_source' => json_encode(['http://provider.test/direct station.mp3', 'http://backup.test/b.mp3']), 'target_container' => 'mp3'];
			if ($rCached) {
				file_put_contents(STREAMS_TMP_PATH . 'stream_5', igbinary_serialize(\XcVm\Domain\Stream\StreamCacheBuilder::entry($rStation, [1], [])));
			} else {
				$db = new TestDb();
				foreach (['streams', 'streams_types', 'streams_servers'] as $rTable) {
					$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
				}
				$db->query('INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `direct_source`, `direct_proxy`, `stream_source`, `target_container`) VALUES (?, ?, ?, ?, ?, ?, ?)', 5, 4, 'Direct Station', 1, 0, $rStation['stream_source'], 'mp3');
				\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
			}
			$rSettings = ['enable_cache' => $rCached ? 1 : 0, 'restreamer_bypass_proxy' => 0, 'ondemand_balance_equal' => 0];
			$rServers = [1 => ['server_online' => 1, 'server_type' => 0, 'timeshift_only' => 0, 'enable_proxy' => 0, 'total_clients' => 1000, 'enable_geoip' => 0, 'enable_isp' => 0]];
			$rUserInfo = ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'force_server_id' => 0, 'is_restreamer' => 0, 'con_isp_name' => '', 'bouquet' => [1]];
			$rStreamID = 5;
			$rCountryCode = '';
			/*LINES*/
			echo 'went on';
		}
		PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-radio-direct-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The first statements of the live case of auth.php: the server it is sent to, up to the answer for that server. */
	private function liveCase(): string {
		$rSource = (string) file_get_contents(MAIN_HOME . self::AUTH);
		$rStart = strpos($rSource, '$rChannelInfo = StreamRedirector::redirectStream(', (int) strpos($rSource, "case 'live':"));
		$this->assertNotFalse($rStart, self::AUTH . ' has no live case');
		$rEnd = strpos($rSource, 'if (is_array($rChannelInfo)) {', $rStart);
		$this->assertNotFalse($rEnd, self::AUTH . ' has no live case');
		return substr($rSource, $rStart, $rEnd - $rStart);
	}

	/**
	 * Run the live case for station 5 asked with $rExtension.
	 *
	 * @return array{0: string, 1: list<string>} What it printed after the case and the headers it sent.
	 */
	private function ask(bool $rCached, string $rExtension): array {
		preg_match_all('/^use [^;]+;$/m', (string) file_get_contents(MAIN_HOME . self::AUTH), $rUses);
		$rCode = strtr(self::CHILD, ['/*USES*/' => implode("\n", $rUses[0]), '/*LINES*/' => $this->liveCase()]);
		$rArgument = json_encode([dirname(__DIR__) . '/bootstrap.php', $this->rDir, $rCached, $rExtension]);

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $rCode, '--', $rArgument], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		preg_match_all('/^header: (.*)$/m', $rErr, $rHeaders);
		$this->assertSame('', trim(preg_replace('/^header: .*$/m', '', $rErr)), $rErr);

		return [$rOut, $rHeaders[1]];
	}

	/** The live case sends a Direct Source station to its first source and ends: no token, no connection, for HLS and TS alike. */
	public function testADirectSourceStationIsSentToItsSource(): void {
		foreach ([true, false] as $rCached) {
			foreach (['m3u8', 'ts'] as $rExtension) {
				[$rOut, $rHeaders] = $this->ask($rCached, $rExtension);

				$this->assertSame(['Location: ' . str_replace(' ', '%20', self::SOURCE)], $rHeaders, ($rCached ? 'cached ' : '') . $rExtension);
				$this->assertSame('', $rOut, ($rCached ? 'cached ' : '') . $rExtension);
			}
		}
	}
}
