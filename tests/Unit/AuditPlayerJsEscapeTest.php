<?php

use PHPUnit\Framework\TestCase;

/**
 * The web player draws its grids in the browser, from what the server sends
 * and, on the home page, from what an earlier visit left in the browser. A
 * name, a title, a rating or an image address is there the text of one node or
 * the value of one attribute: it never adds markup of its own.
 *
 * Each script runs in node against a stand-in page that keeps the HTML the
 * script gives its elements; that HTML is then read the way a browser reads it.
 */
final class AuditPlayerJsEscapeTest extends TestCase {
	private const NAME = 'A "quoted" <b data-injected="1">name</b> & \'more\'';
	private const IMAGE = 'http://img.test/p.jpg?a=\'x\'&b="><b data-injected="1">';

	/** Loads the script named first with the page, storage and calls of the scenario named second. */
	private const DRIVER = <<<'JS'
		const [script, scenarioJson] = process.argv.slice(-2);
		const scenario = JSON.parse(scenarioJson);

		const element = () => ({
			innerHTML: '', textContent: '', value: '', style: {}, dataset: {},
			classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
			addEventListener() {}, getAttribute: () => null, setAttribute() {}, removeAttribute() {},
			querySelector: () => null, querySelectorAll: () => [], scrollIntoView() {}
		});

		const elements = {};
		for (const id of scenario.elements) elements[id] = element();

		const document = {
			readyState: 'complete',
			documentElement: element(),
			body: Object.assign(element(), { contains: () => true }),
			getElementById: (id) => elements[id] || null,
			querySelector: () => null,
			querySelectorAll: () => [],
			addEventListener() {}
		};
		const localStorage = {
			getItem: (key) => (key in scenario.storage ? scenario.storage[key] : null),
			setItem() {},
			removeItem() {}
		};
		const window = { document, localStorage, innerHeight: 800, addEventListener() {} };
		const sandbox = {
			window, document, localStorage, console,
			fetch: () => new Promise(() => {}),
			setTimeout() {}, clearTimeout() {}, setInterval() {}, clearInterval() {}
		};

		const vm = require('vm');
		vm.createContext(sandbox);
		vm.runInContext(require('fs').readFileSync(script, 'utf8'), sandbox);
		for (const [name, method, ...args] of scenario.calls) sandbox.window[name][method](...args);

		const html = {};
		for (const id of scenario.elements) html[id] = elements[id].innerHTML;
		process.stdout.write(JSON.stringify(html));
		JS;

	/**
	 * Run a player script and return the HTML it gave each element of $rElements.
	 *
	 * @param list<string>          $rElements ids of the elements the page has
	 * @param list<list<mixed>>     $rCalls    [global, method, arguments...] to call once the script is loaded
	 * @param array<string, string> $rStorage  what localStorage holds
	 * @return array<string, string>
	 */
	private function render(string $rScript, array $rElements, array $rCalls, array $rStorage = []): array {
		if (trim((string) shell_exec('command -v node')) === '') {
			$this->markTestSkipped('the player scripts run in node');
		}

		$rScenario = json_encode(['elements' => $rElements, 'storage' => (object) $rStorage, 'calls' => $rCalls]);
		$rProc = proc_open(['node', '-e', self::DRIVER, '--', MAIN_HOME . 'Public/assets/player_v2/js/' . $rScript, $rScenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$rHtml = json_decode((string) $rOut, true);
		$this->assertIsArray($rHtml, $rOut . $rErr);

		return $rHtml;
	}

	/** $rHtml holds the name as $rNames texts or attribute values, the address as the source of $rImages images, and nothing they brought. */
	private function assertValuesArriveAsData(string $rHtml, int $rNames, int $rImages): void {
		$rDom = new DOMDocument();
		@$rDom->loadHTML('<?xml encoding="UTF-8"><div>' . $rHtml . '</div>');
		$rPage = new DOMXPath($rDom);

		$this->assertSame(0, $rPage->query('//*[@data-injected]')->length, 'a value added an element to the page');

		$rSeen = ['name' => 0, 'image' => 0];
		foreach ($rPage->query('//text() | //@*') as $rNode) {
			$rSeen['name'] += (int) (trim($rNode->nodeValue) === self::NAME);
			$rSeen['image'] += (int) ($rNode->nodeName === 'src' && $rNode->nodeValue === self::IMAGE);
		}
		$this->assertSame(['name' => $rNames, 'image' => $rImages], $rSeen, 'a value did not arrive whole');
	}

	public function testLiveChannels(): void {
		$rChannel = ['id' => 5, 'name' => self::NAME, 'logo' => self::IMAGE, 'url' => 'http://media.test/live/u/p/5.m3u8'];
		$rHtml = $this->render('player-live.js', ['live-channels-container', 'live-player-channel-list'], [
			['LiveApp', 'start', ['baseUrl' => '/play/', 'initialChannels' => [$rChannel]]],
			['LiveApp', 'playChannel', $rChannel],
		]);

		// The card: the logo's alternative text, the heading and its tooltip. The player's list: the name.
		$this->assertValuesArriveAsData($rHtml['live-channels-container'], 3, 1);
		$this->assertValuesArriveAsData($rHtml['live-player-channel-list'], 1, 1);
	}

	/** The year and the rating come from the same answer as the title. */
	public function testMovies(): void {
		$rMovie = ['id' => 5, 'title' => self::NAME, 'cover' => self::IMAGE, 'year' => self::NAME, 'rating' => self::NAME];
		$rHtml = $this->render('player-movies.js', ['movies-container'], [
			['MoviesApp', 'start', ['baseUrl' => '/play/', 'initialMovies' => [$rMovie]]],
		]);

		$this->assertValuesArriveAsData($rHtml['movies-container'], 5, 1);
	}

	public function testSeries(): void {
		$rSeries = ['id' => 3, 'title' => self::NAME, 'cover' => self::IMAGE, 'year' => self::NAME, 'rating' => self::NAME];
		$rHtml = $this->render('player-series.js', ['series-container'], [
			['SeriesApp', 'start', ['baseUrl' => '/play/', 'initialSeries' => [$rSeries]]],
		]);

		$this->assertValuesArriveAsData($rHtml['series-container'], 5, 1);
	}

	/** The station list beside the radio player is drawn once a station is playing. */
	public function testRadioStations(): void {
		$rStation = ['id' => 7, 'name' => self::NAME, 'logo' => self::IMAGE];
		$rHtml = $this->render('player-radio.js', ['radio-container', 'radio-mini-zap-list'], [
			['RadioApp', 'start', ['baseUrl' => '/play/', 'initialStations' => [$rStation]]],
		], ['xc_player_v2_radio_current' => json_encode($rStation)]);

		$this->assertValuesArriveAsData($rHtml['radio-container'], 3, 1);
		$this->assertValuesArriveAsData($rHtml['radio-mini-zap-list'], 1, 0);
	}

	/** A page without stations of its own draws those an earlier visit left in the browser: there the id is a stored value too. */
	public function testRadioStationsKeptByTheBrowser(): void {
		$rStation = ['id' => self::NAME, 'name' => self::NAME, 'logo' => self::IMAGE];
		$rHtml = $this->render('player-radio.js', ['radio-container', 'radio-mini-zap-list'], [
			['RadioApp', 'start', ['baseUrl' => '/play/', 'initialStations' => []]],
		], ['xc_player_v2_radio_stations' => json_encode([$rStation]), 'xc_player_v2_radio_current' => json_encode($rStation)]);

		// Beside the names: the id on the card, its two links and its two buttons, and on the entry of the list.
		$this->assertValuesArriveAsData($rHtml['radio-container'], 3 + 5, 1);
		$this->assertValuesArriveAsData($rHtml['radio-mini-zap-list'], 1 + 1, 0);
	}

	/** The "continue watching" shelf is drawn from the browser's storage alone. */
	public function testHomeShelf(): void {
		$rHtml = $this->render('player-home.js', ['continue-watching-shelf-wrap', 'continue-watching-container'], [], [
			'xc_player_v2_continue_movies' => json_encode([['id' => 5, 'title' => self::NAME, 'backdrop' => self::IMAGE]]),
			'xc_player_v2_continue_series' => json_encode([['seriesId' => self::NAME, 'streamId' => self::NAME, 'seasonNum' => self::NAME, 'episodeNum' => self::NAME, 'title' => self::NAME, 'cover' => self::IMAGE]]),
		]);

		// Per card: the picture's alternative text, the heading and its tooltip.
		$this->assertValuesArriveAsData($rHtml['continue-watching-container'], 6, 2);
		$this->assertStringContainsString('type=series', $rHtml['continue-watching-container'], 'the stored episode was not drawn as an episode');
	}

	/** The database layer hands the < and > of a stored name over as &lt; and &gt; (Database::clean_row()): a grid shows them as the characters they stand for. */
	public function testANameFromThePanelsCatalogueKeepsItsAngleBrackets(): void {
		$rSent = 'Sky Sports &lt;HD&gt; & News &gt; Live';
		$rStation = ['id' => 7, 'name' => $rSent, 'logo' => ''];
		$rRadio = $this->render('player-radio.js', ['radio-container', 'radio-mini-zap-list'], [
			['RadioApp', 'start', ['baseUrl' => '/play/', 'initialStations' => [$rStation]]],
		], ['xc_player_v2_radio_current' => json_encode($rStation)]);
		$rGrids = [
			'live' => $this->render('player-live.js', ['live-channels-container'], [['LiveApp', 'start', ['baseUrl' => '/play/', 'initialChannels' => [['id' => 5, 'name' => $rSent, 'logo' => '']]]]])['live-channels-container'],
			'movies' => $this->render('player-movies.js', ['movies-container'], [['MoviesApp', 'start', ['baseUrl' => '/play/', 'initialMovies' => [['id' => 5, 'title' => $rSent, 'cover' => '']]]]])['movies-container'],
			'series' => $this->render('player-series.js', ['series-container'], [['SeriesApp', 'start', ['baseUrl' => '/play/', 'initialSeries' => [['id' => 3, 'title' => $rSent, 'cover' => '']]]]])['series-container'],
			'radio' => $rRadio['radio-container'],
			'home' => $this->render('player-home.js', ['continue-watching-shelf-wrap', 'continue-watching-container'], [], ['xc_player_v2_continue_movies' => json_encode([['id' => 5, 'title' => $rSent]])])['continue-watching-container'],
		];
		foreach ($rGrids as $rGrid => $rHtml) {
			$rDom = new DOMDocument();
			@$rDom->loadHTML('<?xml encoding="UTF-8"><div>' . $rHtml . '</div>');
			$rPage = new DOMXPath($rDom);
			$this->assertSame('Sky Sports <HD> & News > Live', trim($rPage->evaluate('string(//h6)')), $rGrid);
			$this->assertSame('Sky Sports <HD> & News > Live', $rPage->evaluate('string(//h6/@title)'), $rGrid);
		}

		// The list beside the radio player names the station too.
		$rDom = new DOMDocument();
		@$rDom->loadHTML('<?xml encoding="UTF-8"><div>' . $rRadio['radio-mini-zap-list'] . '</div>');
		$this->assertSame('Sky Sports <HD> & News > Live', (new DOMXPath($rDom))->evaluate('string(//span[contains(@class, "text-truncate")])'), 'radio list');
	}

	/** The same two entities in a name from another server are still text: they never open an element or an attribute. */
	public function testAnAngleBracketEntityNeverAddsMarkup(): void {
		$rSent = '&lt;b data-injected="1"&gt;x&lt;/b&gt;" data-injected="1';
		$rHtml = $this->render('player-live.js', ['live-channels-container'], [
			['LiveApp', 'start', ['baseUrl' => '/play/', 'initialChannels' => [['id' => 5, 'name' => $rSent, 'logo' => 'http://img.test/n.png']]]],
		])['live-channels-container'];

		$rDom = new DOMDocument();
		@$rDom->loadHTML('<?xml encoding="UTF-8"><div>' . $rHtml . '</div>');
		$rPage = new DOMXPath($rDom);
		$this->assertSame(0, $rPage->query('//*[@data-injected]')->length, 'a value added an element to the page');
		$this->assertSame('<b data-injected="1">x</b>" data-injected="1', $rPage->evaluate('string(//h6/@title)'));
		$this->assertSame('<b data-injected="1">x</b>" data-injected="1', trim($rPage->evaluate('string(//h6)')));
	}

	/** A name without markup is drawn as it always was. */
	public function testAPlainNameIsUnchanged(): void {
		$rHtml = $this->render('player-live.js', ['live-channels-container'], [
			['LiveApp', 'start', ['baseUrl' => '/play/', 'initialChannels' => [['id' => 5, 'name' => 'News 24', 'logo' => 'http://img.test/n.png']]]],
		]);

		$this->assertStringContainsString('<img src="http://img.test/n.png" alt="News 24" class="channel-logo-img"', $rHtml['live-channels-container']);
		$this->assertStringContainsString('title="News 24">News 24</h6>', $rHtml['live-channels-container']);
	}
}
