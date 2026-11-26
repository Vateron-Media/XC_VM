<?php

use PHPUnit\Framework\TestCase;

final class PlayerV2MainScriptTest extends TestCase {
	private const DRIVER = <<<'JS'
		const element = () => ({
			classList: { add() {}, remove() {}, contains: () => false },
			style: { setProperty() {} },
			addEventListener() {}, querySelector: () => null, querySelectorAll: () => [],
			getAttribute: () => null, setAttribute() {}
		});
		const documentElement = Object.assign(element(), { clientWidth: 1200 });
		const document = {
			documentElement, body: element(),
			addEventListener() {}, getElementById: () => null, getElementsByClassName: () => [],
			querySelector: () => null, querySelectorAll: () => []
		};
		const Helpers = new Proxy({
			isRtl: () => false, isDarkStyle: () => false, isSmallScreen: () => false,
			getPreferredTheme: () => 'light', getStoredTheme: () => null
		}, { get: (target, key) => target[key] || (() => {}) });
		const localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
		const window = {
			document, Helpers, localStorage, innerWidth: 1200, scrollY: 0,
			templateCustomizer: { settings: { defaultMenuCollapsed: false, semiDark: true } },
			matchMedia: () => ({ matches: false, addEventListener() {} }), addEventListener() {}
		};
		const sandbox = {
			window, document, Helpers, localStorage, console,
			navigator: { userAgent: 'test', language: 'en' }, location: { host: 'example.test' },
			config: { enableMenuLocalStorage: false }, templateName: 'player', assetsPath: '/',
			bootstrap: { Tooltip: function () {} }, HTMLAudioElement: function () {},
			setTimeout() {}, clearTimeout() {}, setInterval() {}, clearInterval() {}
		};
		const vm = require('vm');
		vm.createContext(sandbox);
		vm.runInContext(require('fs').readFileSync(process.argv.at(-1), 'utf8'), sandbox);
		JS;

	public function testLoginPageRunsMainScriptWithoutALayoutMenu(): void {
		if (trim((string) shell_exec('command -v node')) === '') {
			$this->markTestSkipped('the player scripts run in node');
		}

		$rProc = proc_open(['node', '-e', self::DRIVER, '--', MAIN_HOME . 'Public/assets/player_v2/js/main.js'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);

		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
	}
}
