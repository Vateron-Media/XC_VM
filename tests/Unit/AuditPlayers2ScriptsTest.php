<?php

use PHPUnit\Framework\TestCase;

/**
 * The web player's pages are drawn in the browser. The home page's "continue
 * watching" shelf reads an episode's progress as the cinema player stores it,
 * a click on a favourite star is handled once however often the home page was
 * opened, and a series card shows the number of seasons the server sent.
 *
 * Each script runs in node against a stand-in page that keeps the HTML the
 * script gives its elements, what it stores, and the listeners it adds to the
 * document.
 */
final class AuditPlayers2ScriptsTest extends TestCase {
	/** Loads the script named first with the page, storage and calls of the scenario named second, then clicks the star it names. */
	private const DRIVER = <<<'JS'
		const [script, scenarioJson] = process.argv.slice(-2);
		const scenario = JSON.parse(scenarioJson);

		const element = () => ({
			innerHTML: '', textContent: '', value: '', className: '', style: {}, dataset: {},
			classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
			addEventListener() {}, getAttribute: () => null, setAttribute() {}, removeAttribute() {},
			querySelector: () => null, querySelectorAll: () => [], scrollIntoView() {}
		});

		const elements = {};
		for (const id of scenario.elements) elements[id] = element();

		// As a browser keeps them: one entry per type and function.
		const listeners = [];
		const document = {
			readyState: 'complete',
			documentElement: element(),
			body: Object.assign(element(), { contains: () => true }),
			getElementById: (id) => elements[id] || null,
			querySelector: () => null,
			querySelectorAll: () => [],
			addEventListener(type, listener) {
				if (!listeners.some(([t, l]) => t === type && l === listener)) listeners.push([type, listener]);
			}
		};
		const stored = Object.assign({}, scenario.storage);
		const localStorage = {
			getItem: (key) => (key in stored ? stored[key] : null),
			setItem(key, value) { stored[key] = String(value); },
			removeItem(key) { delete stored[key]; }
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

		let icon = null;
		if (scenario.star) {
			icon = { className: 'icon-base bx bx-star' };
			const button = Object.assign(element(), { getAttribute: (name) => scenario.star[name] || null, querySelector: () => icon });
			let halted = false;
			const click = { target: { closest: () => button }, preventDefault() {}, stopPropagation() {}, stopImmediatePropagation() { halted = true; } };
			for (const [type, listener] of listeners) {
				if (type === 'click' && !halted) listener(click);
			}
		}

		const html = {};
		for (const id of scenario.elements) html[id] = elements[id].innerHTML;
		process.stdout.write(JSON.stringify({ html, stored, icon: icon && icon.className }));
		JS;

	/**
	 * Run a player script and return the HTML it gave each element of $rElements, what the browser stores afterwards and the star's icon.
	 *
	 * @param list<string>          $rElements ids of the elements the page has
	 * @param list<list<mixed>>     $rCalls    [global, method, arguments...] to call once the script is loaded
	 * @param array<string, string> $rStorage  what localStorage holds
	 * @param array<string, string> $rStar     the attributes of a favourite star to click once the calls are made
	 * @return array{html: array<string, string>, stored: array<string, string>, icon: ?string}
	 */
	private function script(string $rScript, array $rElements, array $rCalls = [], array $rStorage = [], array $rStar = []): array {
		if (trim((string) shell_exec('command -v node')) === '') {
			$this->markTestSkipped('the player scripts run in node');
		}

		$rScenario = json_encode(['elements' => $rElements, 'storage' => (object) $rStorage, 'calls' => $rCalls, 'star' => $rStar ?: null]);
		$rProc = proc_open(['node', '-e', self::DRIVER, '--', MAIN_HOME . 'Public/assets/player_v2/js/' . $rScript, $rScenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$rResult = json_decode((string) $rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);

		return $rResult;
	}

	private function page(string $rHtml): DOMXPath {
		$rDom = new DOMDocument();
		@$rDom->loadHTML('<?xml encoding="UTF-8"><div>' . $rHtml . '</div>');

		return new DOMXPath($rDom);
	}

	/** What player-cinema.js keeps for a series once an episode has played for a few seconds. */
	private const WATCHED = ['seriesId' => 12, 'streamId' => 345, 'seasonNum' => 2, 'episodeNum' => 7, 'time' => 600, 'progress' => 42, 'timestamp' => 1790000000000];

	public function testTheShelfResumesAnEpisodeWhereTheCinemaPlayerLeftIt(): void {
		$rHtml = $this->script('player-home.js', ['continue-watching-shelf-wrap', 'continue-watching-container'], [], [
			'xc_player_v2_continue_series' => json_encode([self::WATCHED]),
		])['html']['continue-watching-container'];
		$rPage = $this->page($rHtml);

		$this->assertSame('player?type=series&id=345&series_id=12&s=2&e=7', $rPage->evaluate('string(//a/@href)'), 'the card does not resume the episode');
		$this->assertSame('S2E7', $rPage->evaluate('string(//span[1])'));
		$this->assertSame('42', $rPage->evaluate('string(//@data-progress-percent)'));
	}

	/** An episode only just started is kept with a progress of 0: its bar is the shortest one, and the default bar is for an item kept without a progress. */
	public function testTheShelfShowsAnEpisodeJustStartedAsJustStarted(): void {
		$rStarted = ['time' => 8, 'progress' => 0] + self::WATCHED;
		$rWithout = array_diff_key(['seriesId' => 13] + self::WATCHED, ['progress' => 0]);
		$rHtml = $this->script('player-home.js', ['continue-watching-shelf-wrap', 'continue-watching-container'], [], [
			'xc_player_v2_continue_series' => json_encode([$rStarted, $rWithout]),
		])['html']['continue-watching-container'];

		$rBars = [];
		foreach ($this->page($rHtml)->query('//@data-progress-percent') as $rBar) {
			$rBars[] = $rBar->value;
		}
		$this->assertSame(['5', '25'], $rBars);
	}

	/** The card names the series and shows its picture when the stored item carries them. */
	public function testTheShelfShowsTheTitleAndCoverKeptWithAnEpisode(): void {
		$rHtml = $this->script('player-home.js', ['continue-watching-shelf-wrap', 'continue-watching-container'], [], [
			'xc_player_v2_continue_series' => json_encode([self::WATCHED + ['title' => 'A Show', 'cover' => 'http://img.test/show.jpg']]),
		])['html']['continue-watching-container'];
		$rPage = $this->page($rHtml);

		$this->assertSame('A Show', $rPage->evaluate('string(//h6)'));
		$this->assertSame('http://img.test/show.jpg', $rPage->evaluate('string(//img/@src)'));
		$this->assertSame('S2E7', $rPage->evaluate('string(//span[1])'));
	}

	/** The stored values are data on the card: none of them adds markup of its own. */
	public function testAStoredEpisodeAddsNoMarkup(): void {
		$rMarkup = '"><b data-injected="1">x</b>';
		$rHtml = $this->script('player-home.js', ['continue-watching-shelf-wrap', 'continue-watching-container'], [], [
			'xc_player_v2_continue_series' => json_encode([['seriesId' => $rMarkup, 'streamId' => $rMarkup, 'seasonNum' => $rMarkup, 'episodeNum' => $rMarkup, 'progress' => $rMarkup]]),
		])['html']['continue-watching-container'];

		$this->assertSame(0, $this->page($rHtml)->query('//*[@data-injected]')->length);
	}

	/** player-spa.js calls PlayerHome.init() on every return to the home page; the document it listens on stays the same. */
	public function testAFavouriteStarIsToggledOncePerClick(): void {
		$rStar = ['data-fav-toggle-type' => 'movies', 'data-fav-id' => '5'];

		foreach ([[], [['PlayerHome', 'init']], [['PlayerHome', 'init'], ['PlayerHome', 'init']]] as $rVisits) {
			$rEnd = $this->script('player-home.js', [], $rVisits, [], $rStar);

			$this->assertSame('[5]', $rEnd['stored']['xc_player_v2_favs_movies'] ?? null, 'after ' . count($rVisits) . ' return(s) to the home page the click did not add the favourite');
			$this->assertStringContainsString('bxs-star', (string) $rEnd['icon']);
		}

		$rEnd = $this->script('player-home.js', [], [['PlayerHome', 'init']], ['xc_player_v2_favs_movies' => '[5,8]'], $rStar);
		$this->assertSame('[8]', $rEnd['stored']['xc_player_v2_favs_movies'], 'a click on a favourite did not remove it');
	}

	/** The layout loads the home script on every page; a second copy on the home page would run it, and bind its listeners, twice. */
	public function testTheHomeScriptIsLoadedByTheLayoutAlone(): void {
		$rTag = 'js/player-home.js"></script>';

		$this->assertSame(1, substr_count((string) file_get_contents(MAIN_HOME . 'Public/Views/layouts/player_v2/footer.php'), $rTag));
		$this->assertSame(0, substr_count((string) file_get_contents(MAIN_HOME . 'Public/Views/player_v2/index.php'), $rTag), 'the home page loads the script a second time');
	}

	public function testASeriesCardShowsTheNumberOfSeasonsTheServerSent(): void {
		$rHtml = $this->script('player-series.js', ['series-container'], [
			['SeriesApp', 'start', ['baseUrl' => '/play/', 'initialSeries' => [
				['id' => 3, 'title' => 'A Show', 'cover' => '', 'year' => 2019, 'rating' => 'N/A', 'seasons_count' => 4],
				['id' => 4, 'title' => 'A Short Show', 'cover' => '', 'year' => 2020, 'rating' => 'N/A', 'seasons_count' => 1],
			]]],
		])['html']['series-container'];

		$rBadges = [];
		foreach ($this->page($rHtml)->query('//span[contains(@class, "rounded-pill")]') as $rBadge) {
			$rBadges[] = trim($rBadge->textContent);
		}
		$this->assertSame(['4 Seasons', '1 Season'], $rBadges);
	}
}
