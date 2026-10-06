<?php

use PHPUnit\Framework\TestCase;

/**
 * The second web player's radio script plays a station from the controller's
 * play answer, which sends the browser to the panel's own play address: as
 * HLS when the panel runs the station, as plain audio when the panel passes it
 * on to its source.
 *
 * The script runs in node against a stand-in page that keeps what the HLS
 * player and the audio element were given to play and what the bar says, and
 * that can make the station's play address fail, time pass and the audio play.
 */
final class AuditDecisionRadioScriptTest extends TestCase {
	private const ANSWER = '/radio?stream=4';
	private const STATION = ['id' => 4, 'name' => 'Panel Station', 'direct' => false, 'url' => self::ANSWER];

	/** The play address stops answering, and the time the script waits before it acts on that passes. */
	private const DROP = [['Stand', 'networkFails'], ['Stand', 'timePasses']];

	/** Loads the script named first with the storage and calls of the scenario named second. */
	private const DRIVER = <<<'JS'
		const [script, scenarioJson] = process.argv.slice(-2);
		const scenario = JSON.parse(scenarioJson);

		const element = () => ({
			innerHTML: '', textContent: '', value: '', className: '', style: {}, dataset: {},
			classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
			addEventListener() {}, getAttribute: () => null, setAttribute() {}, removeAttribute() {},
			querySelector: () => null, querySelectorAll: () => []
		});

		const heard = {};
		const audio = Object.assign(element(), {
			src: null, pause() {}, load() {}, play: () => Promise.resolve(),
			addEventListener(name, listener) { (heard[name] = heard[name] || []).push(listener); }
		});
		const status = element();
		const hls = [];
		let player = null;
		class Hls {
			static isSupported() { return true; }
			constructor() { this.handlers = {}; player = this; }
			loadSource(address) { hls.push(address); }
			attachMedia() {}
			on(name, handler) { this.handlers[name] = handler; }
			startLoad() {}
			recoverMediaError() {}
			destroy() { this.handlers = {}; }
		}
		Hls.Events = { MANIFEST_PARSED: 'hlsManifestParsed', FRAG_BUFFERED: 'hlsFragBuffered', ERROR: 'hlsError' };
		Hls.ErrorTypes = { NETWORK_ERROR: 'networkError', MEDIA_ERROR: 'mediaError' };

		const timers = new Map();
		let timer = 0;
		const setTimeout = (callback) => { timers.set(++timer, callback); return timer; };
		const clearTimeout = (id) => { timers.delete(id); };

		const document = {
			readyState: 'complete',
			documentElement: element(),
			body: Object.assign(element(), { contains: () => true }),
			getElementById: (id) => (id === 'radio-audio' ? audio : (id === 'radio-bar-status-text' ? status : null)),
			querySelector: () => null,
			querySelectorAll: () => [],
			addEventListener() {}
		};
		const stored = Object.assign({}, scenario.storage);
		const localStorage = {
			getItem: (key) => (key in stored ? stored[key] : null),
			setItem(key, value) { stored[key] = String(value); },
			removeItem(key) { delete stored[key]; }
		};
		const Stand = {
			mediaPlays() {
				const handler = player && player.handlers[Hls.Events.FRAG_BUFFERED];
				if (handler) handler();
				(heard.play || []).forEach((listener) => listener());
			},
			networkFails() {
				const handler = player && player.handlers[Hls.Events.ERROR];
				if (handler) handler(Hls.Events.ERROR, { fatal: true, type: Hls.ErrorTypes.NETWORK_ERROR });
			},
			timePasses() {
				const due = [...timers.values()];
				timers.clear();
				due.forEach((callback) => callback());
			}
		};
		const window = { document, localStorage, Stand, innerHeight: 800, addEventListener() {} };
		const sandbox = { window, document, localStorage, console, Hls, setTimeout, clearTimeout, navigator: {} };

		const vm = require('vm');
		vm.createContext(sandbox);
		vm.runInContext(require('fs').readFileSync(script, 'utf8'), sandbox);
		for (const [name, method, ...args] of scenario.calls) sandbox.window[name][method](...args);

		process.stdout.write(JSON.stringify({ hls, audio: audio.src, status: status.textContent }));
		JS;

	/**
	 * Run the radio script and return what it gave the HLS player and the audio element to play.
	 *
	 * @param list<list<mixed>>     $rCalls   [global, method, arguments...] to call once the script is loaded
	 * @param array<string, string> $rStorage what localStorage holds
	 * @return array{hls: list<string>, audio: ?string}
	 */
	private function play(array $rCalls, array $rStorage = []): array {
		return array_intersect_key($this->listen($rCalls, $rStorage), ['hls' => true, 'audio' => true]);
	}

	/**
	 * Run the radio script and return that and what the bar says of the station.
	 *
	 * @param list<list<mixed>>     $rCalls   [global, method, arguments...] to call once the script is loaded
	 * @param array<string, string> $rStorage what localStorage holds
	 * @return array{hls: list<string>, audio: ?string, status: string}
	 */
	private function listen(array $rCalls, array $rStorage = []): array {
		if (trim((string) shell_exec('command -v node')) === '') {
			$this->markTestSkipped('the player scripts run in node');
		}

		$rScenario = json_encode(['storage' => (object) $rStorage, 'calls' => $rCalls]);
		$rProc = proc_open(['node', '-e', self::DRIVER, '--', MAIN_HOME . 'Public/assets/player_v2/js/player-radio.js', $rScenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$rPlayed = json_decode((string) $rOut, true);
		$this->assertIsArray($rPlayed, $rOut . $rErr);

		return $rPlayed;
	}

	public function testAStationThePanelRunsPlaysAsHls(): void {
		$rStation = ['id' => 4, 'name' => 'Panel Station', 'direct' => false, 'url' => self::ANSWER];

		$this->assertSame(['hls' => [self::ANSWER], 'audio' => null], $this->play([['RadioApp', 'playStation', $rStation, false]]));
	}

	public function testAStationThePanelPassesOnPlaysAsPlainAudio(): void {
		$rStation = ['id' => 4, 'name' => 'Direct Station', 'direct' => true, 'url' => self::ANSWER];

		$this->assertSame(['hls' => [], 'audio' => self::ANSWER], $this->play([['RadioApp', 'playStation', $rStation, false]]));
	}

	/** A station the browser kept from before the play answer named the panel's address is played from it like any other. */
	public function testAStationKeptFromBeforePlaysAsHls(): void {
		$rStation = json_encode(['id' => 4, 'name' => 'Panel Station', 'container' => '', 'direct_source' => '', 'url' => self::ANSWER]);
		$rStorage = ['xc_player_v2_radio_current' => $rStation, 'xc_player_v2_radio_stations' => '[' . $rStation . ']'];

		$this->assertSame(['hls' => [self::ANSWER], 'audio' => null], $this->play([['RadioApp', 'togglePlayPause']], $rStorage));
	}

	/** How a station plays is what the list the page holds now says of it, not what the browser kept of it. */
	public function testAStationKeptFromBeforePlaysAsTheListNowSays(): void {
		$rKept = json_encode(['id' => 4, 'name' => 'Direct Station', 'container' => '', 'direct_source' => '', 'url' => self::ANSWER]);
		$rStorage = ['xc_player_v2_radio_current' => $rKept, 'xc_player_v2_radio_stations' => '[' . $rKept . ']'];
		$rList = [['id' => 4, 'name' => 'Direct Station', 'direct' => true, 'url' => self::ANSWER]];

		$this->assertSame(
			['hls' => [], 'audio' => self::ANSWER],
			$this->play([['RadioApp', 'start', ['baseUrl' => '/', 'initialStations' => $rList]], ['RadioApp', 'togglePlayPause']], $rStorage)
		);

		$rKept = json_encode(['id' => 4, 'name' => 'Panel Station', 'direct' => true, 'url' => self::ANSWER]);
		$rStorage = ['xc_player_v2_radio_current' => $rKept, 'xc_player_v2_radio_stations' => '[' . $rKept . ']'];

		$this->assertSame(
			['hls' => [self::ANSWER], 'audio' => null],
			$this->play([['RadioApp', 'start', ['baseUrl' => '/', 'initialStations' => [self::STATION]]], ['RadioApp', 'togglePlayPause']], $rStorage)
		);
	}

	/** A play address is only good for the connection it was given for: when it stops answering, the play answer is asked for a new one. */
	public function testAStationThatDroppedOutIsAskedForAgainFromThePlayAnswer(): void {
		$rPlayed = $this->listen([['RadioApp', 'playStation', self::STATION, false], ['Stand', 'mediaPlays'], ...self::DROP]);

		$this->assertSame([self::ANSWER, self::ANSWER], $rPlayed['hls']);
		$this->assertSame('Playing Live Stream', $rPlayed['status']);
	}

	public function testAStationThatStaysAwayIsGivenUpAndShownAsPaused(): void {
		$rPlayed = $this->listen([['RadioApp', 'playStation', self::STATION, false], ['Stand', 'mediaPlays'], ...self::DROP, ...self::DROP, ...self::DROP, ...self::DROP, ...self::DROP]);

		$this->assertSame([self::ANSWER, self::ANSWER, self::ANSWER, self::ANSWER], $rPlayed['hls']);
		$this->assertSame('Paused', $rPlayed['status']);
	}

	public function testAStationThatCameBackHasItsTriesAgain(): void {
		$rPlayed = $this->listen([['RadioApp', 'playStation', self::STATION, false], ['Stand', 'mediaPlays'], ...self::DROP, ...self::DROP, ...self::DROP, ['Stand', 'mediaPlays'], ...self::DROP]);

		$this->assertCount(5, $rPlayed['hls']);
		$this->assertSame('Playing Live Stream', $rPlayed['status']);
	}

	/** Only a station the listener is told is playing, and still wants, is asked for again. */
	public function testAListenerWhoIsNotListeningIsNotStartedAgain(): void {
		$rPlay = ['RadioApp', 'playStation', self::STATION, false];
		$rOther = ['id' => 9, 'name' => 'Other Station', 'direct' => false, 'url' => '/radio?stream=9'];

		$rNeverStarted = $this->listen([$rPlay, ...self::DROP]);
		$this->assertSame([self::ANSWER], $rNeverStarted['hls']);
		$this->assertSame('Paused', $rNeverStarted['status']);

		$rPaused = $this->listen([$rPlay, ['Stand', 'mediaPlays'], ['RadioApp', 'togglePlayPause'], ...self::DROP]);
		$this->assertSame([self::ANSWER], $rPaused['hls']);

		$rPausedInTheWait = $this->listen([$rPlay, ['Stand', 'mediaPlays'], ['Stand', 'networkFails'], ['RadioApp', 'togglePlayPause'], ['Stand', 'timePasses']]);
		$this->assertSame([self::ANSWER], $rPausedInTheWait['hls']);
		$this->assertSame('Paused', $rPausedInTheWait['status']);

		$this->assertSame([self::ANSWER], $this->listen([$rPlay, ['Stand', 'mediaPlays'], ['Stand', 'networkFails'], ['RadioApp', 'stopPlayer'], ['Stand', 'timePasses']])['hls']);
		$this->assertSame(
			[self::ANSWER, '/radio?stream=9'],
			$this->listen([$rPlay, ['Stand', 'mediaPlays'], ['Stand', 'networkFails'], ['RadioApp', 'playStation', $rOther, false], ['Stand', 'timePasses']])['hls']
		);
	}
}
