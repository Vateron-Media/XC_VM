<?php

use PHPUnit\Framework\TestCase;

/**
 * The second web player plays a radio station through the panel's own play
 * address, as it plays a live channel: that is where the panel authorises the
 * line and counts its connection. The station list and the page name the
 * controller's play answer, the play answer names that address, and none of
 * them a station's source.
 *
 * The controller answers and exits, so each request runs in a child PHP
 * against its own database: one station the panel runs and one it passes
 * straight on to its source.
 */
final class AuditDecisionRadioPlayAddressTest extends TestCase {
	private const ADDRESS = 'http://my.tv:8080/viewer/secret/';
	private const SOURCE = 'provider.test';
	private const STATIONS = [
		['id' => 4, 'name' => 'Panel Station', 'logo' => '', 'category_id' => 1, 'direct' => false, 'url' => '/radio?stream=4'],
		['id' => 5, 'name' => 'Direct Station', 'logo' => '', 'category_id' => 1, 'direct' => true, 'url' => '/radio?stream=5'],
	];

	/** Answers the request, line and settings given as JSON in its one argument. */
	private const CHILD = <<<'PHP'
		namespace XcVm\Public\Controllers\PlayerV2 {
			// A child PHP keeps no header: the controller's go to the error stream as they are sent.
			function header(string $rHeader): void {
				fwrite(STDERR, 'header: ' . $rHeader . "\n");
				\header($rHeader);
			}
		}

		namespace {
			[$rBootstrap, $rDir, $rRequest, $rLine, $rSettings] = json_decode($argv[1], true);
			require $rBootstrap;
			error_reporting(E_ERROR | E_PARSE);
			$db = new TestDb();
			foreach (['streams', 'streams_servers', 'streams_categories'] as $rTable) {
				$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
			}
			$db->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`) VALUES (1, 'radio', 'Stations')");
			$db->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `category_id`, `direct_source`) VALUES (4, 4, 'Panel Station', '[1]', 0), (5, 4, 'Direct Station', '[1]', 1)");
			$db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = 4', json_encode(['http://provider.test/panel.mp3?key=upstream']));
			$db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = 5', json_encode(['http://provider.test/direct.mp3']));
			\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
			define('SERVER_ID', 1);
			define('CACHE_TMP_PATH', $rDir);
			$rServers = [1 => ['enable_proxy' => 0, 'server_protocol' => 'http', 'http_broadcast_port' => 8080, 'domain_name' => 'my.tv', 'server_ip' => '198.51.100.9', 'server_type' => 0, 'is_main' => 1]];
			$rSettings += ['keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'channel_number_type' => 'bouquet'];
			\XcVm\Core\Config\SettingsManager::set($rSettings);
			require_once MAIN_HOME . 'Infrastructure/Bootstrap/player_utility_functions.php';
			$rUserInfo = $rLine + ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'bouquet' => [1], 'live_ids' => [], 'vod_ids' => [], 'series_ids' => [], 'radio_ids' => [4, 5]];
			\XcVm\Core\Http\RequestManager::set($rRequest);
			$_SERVER['HTTP_X_SPA_REQUEST'] = '1'; // a page comes back as its own markup, without the layout around it
			register_shutdown_function(static function () {
				echo "\n" . json_encode(http_response_code());
			});
			(new \XcVm\Public\Controllers\PlayerV2\RadioController())->index();
		}
		PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-radio-address-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Ask the radio controller for $rRequest as a line allowed the outputs $rOutputs, on a panel with $rSettings.
	 *
	 * @return array{0: string, 1: int|false, 2: list<string>} What it printed, the response code it set and the headers it sent.
	 */
	private function ask(array $rRequest, array $rOutputs = [1, 2, 3], array $rSettings = []): array {
		$rArgument = json_encode([dirname(__DIR__) . '/bootstrap.php', $this->rDir, $rRequest, ['allowed_outputs' => $rOutputs], (object) $rSettings]);

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', self::CHILD, '--', $rArgument], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rCut = strrpos($rOut, "\n");
		$this->assertNotFalse($rCut, $rOut . $rErr);
		$this->assertStringNotContainsString('Fatal error', $rOut, $rErr);
		preg_match_all('/^header: (.*)$/m', $rErr, $rHeaders);

		return [substr($rOut, 0, $rCut), json_decode(substr($rOut, $rCut + 1)), $rHeaders[1]];
	}

	/**
	 * A station is listed with the play answer to ask and whether the panel
	 * passes it on to its source, which the page plays as plain audio and not
	 * as HLS; with nothing of its source, and nothing of the line's password.
	 */
	public function testTheListNamesThePlayAnswerAndNoSource(): void {
		[$rOut] = $this->ask(['ajax' => '1']);

		$this->assertSame(self::STATIONS, json_decode($rOut, true)['stations'] ?? null, $rOut);
		$this->assertStringNotContainsString(self::SOURCE, $rOut);
		$this->assertStringNotContainsString('secret', $rOut);
	}

	public function testThePageNamesThePlayAnswerAndNoSource(): void {
		[$rOut] = $this->ask([]);
		$rHtml = (string) (json_decode($rOut, true)['html'] ?? '');
		$this->assertSame(1, preg_match('/initialStations: (\[.*\]),\n/', $rHtml, $rList), $rOut);

		$this->assertSame(self::STATIONS, json_decode($rList[1], true));
		$this->assertStringNotContainsString(self::SOURCE, $rOut);
		$this->assertStringNotContainsString('secret', $rOut);
	}

	/** The play answer of every station is the panel's play address, a direct station's too: the panel answers for it there. */
	public function testThePlayAnswerIsThePanelsPlayAddress(): void {
		foreach ([4, 5] as $rStation) {
			[$rOut, $rStatus, $rHeaders] = $this->ask(['stream' => (string) $rStation]);

			$this->assertSame(302, $rStatus);
			$this->assertSame(['Location: ' . self::ADDRESS . $rStation . '.m3u8'], $rHeaders);
			$this->assertStringNotContainsString(self::SOURCE, $rOut);
		}
	}

	/** The stations play as HLS, like the live channels: a line without that output, or a panel that has it off, is not offered them. */
	public function testThePageNeedsTheHlsOutput(): void {
		foreach ([[[2, 3], []], [[1, 2, 3], ['disable_hls' => 1]]] as [$rOutputs, $rSettings]) {
			foreach ([['ajax' => '1'], [], ['stream' => '4']] as $rRequest) {
				[$rOut, $rStatus, $rHeaders] = $this->ask($rRequest, $rOutputs, $rSettings);

				$this->assertSame(302, $rStatus, $rOut);
				$this->assertSame(['Location: index'], $rHeaders);
				$this->assertStringNotContainsString('Station', $rOut);
			}
		}
	}
}
