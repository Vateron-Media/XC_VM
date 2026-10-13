<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\DataPlaneTrust;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;

/**
 * The legacy LB `/api` is gone (Phase 10): lb_configs/nginx.conf has neither
 * location nor the api_legacy switch; MAIN's own `/api` stays. The LB build
 * must ship every file its nginx.conf includes: nginx refuses to start on a
 * missing one.
 */
#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
final class LbNginxApiLegacyTest extends TestCase {
	private string $rRoot;

	private ?string $rFlows = null;

	protected function setUp(): void {
		$this->rRoot = dirname(__DIR__, 2);
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeRole::useServers(null);
		DataPlane::useServers(null);
		DataPlaneTrust::useSources(null, null, null);
		if ($this->rFlows !== null) {
			@unlink($this->rFlows);
		}
	}

	/** lb_configs/nginx.conf, the nginx.conf of the LB archive. */
	private function lbConf(): string {
		return str_replace("\r\n", "\n", (string) file_get_contents($this->rRoot . '/lb_configs/nginx.conf'));
	}

	/** The body of `location = $rPath { … }`, its braces balanced. */
	private function location(string $rConf, string $rPath): string {
		$rStart = strpos($rConf, 'location = ' . $rPath . ' {');
		$this->assertNotFalse($rStart, 'no location = ' . $rPath);
		$rOpen = strpos($rConf, '{', $rStart);
		$rDepth = 0;
		for ($i = $rOpen, $n = strlen($rConf); $i < $n; $i++) {
			if ($rConf[$i] === '{') {
				$rDepth++;
			} elseif ($rConf[$i] === '}' && --$rDepth === 0) {
				return substr($rConf, $rOpen + 1, $i - $rOpen - 1);
			}
		}
		$this->fail('unbalanced location = ' . $rPath);
	}

	public function testTheLbConfigServesNoLegacyApi(): void {
		$rConf = $this->lbConf();
		$this->assertStringNotContainsString('api_legacy', $rConf);
		$this->assertStringNotContainsString('location = /api {', $rConf);
		$this->assertStringNotContainsString('location = /api.php {', $rConf);
		$this->assertStringNotContainsString('fastcgi_param XC_API internal;', $rConf);
	}

	/** MAIN's own `/api` is untouched: still served, no toggle. */
	public function testMainsOwnApiIsStillServed(): void {
		$rMain = str_replace("\r\n", "\n", (string) file_get_contents($this->rRoot . '/src/bin/nginx/conf/nginx.conf'));
		$this->assertStringNotContainsString('include api_legacy.conf;', $rMain);
		$this->assertStringNotContainsString('if ($api_legacy', $rMain);
		foreach (['/api', '/api.php'] as $rPath) {
			$rBody = $this->location($rMain, $rPath);
			$this->assertStringNotContainsString('return 404;', $rBody, $rPath);
			$this->assertStringContainsString('fastcgi_param XC_API internal;', $rBody, $rPath);
		}
	}

	/** The body of the first `location ~ $rRegex {…}`. */
	private function regexLocation(string $rConf, string $rRegex): string {
		$rStart = strpos($rConf, 'location ~ ' . $rRegex . ' {');
		$this->assertNotFalse($rStart, 'no location ~ ' . $rRegex);
		$rOpen = strpos($rConf, '{', $rStart);
		$rClose = strpos($rConf, "\n        }", $rOpen);
		return substr($rConf, $rOpen + 1, $rClose - $rOpen);
	}

	/**
	 * D16: a node in mode 2 reads no line from MAIN's database, so the viewer
	 * APIs (player_api, enigma2, xplugin, epg, playlist) answer 404 there, in
	 * both their forms; every other node serves them, as before.
	 */
	public function testTheViewerApisAreGuardedAndAnswer404OnEveryLoadBalancer(): void {
		$rConf = $this->lbConf();
		$this->assertMatchesRegularExpression('/^\s*include viewer_api\.conf;$/m', $rConf);
		foreach (['^/api/(player_api|enigma2|xplugin|epg|playlist)$', '^/(player_api|enigma2|xplugin|epg|playlist)(?:\.php)?$'] as $rRegex) {
			$rBody = $this->regexLocation($rConf, $rRegex);
			$this->assertMatchesRegularExpression('/^\s*if \(\$viewer_api = 0\) \{\s*return 404;\s*\}/', $rBody, $rRegex . ': the guard comes first');
			$this->assertStringContainsString('fastcgi_param XC_API $1;', $rBody, $rRegex);
		}
		$this->assertSame('set $viewer_api 0;', trim((string) file_get_contents($this->rRoot . '/src/bin/nginx/conf/viewer_api.conf')), 'the shipped default answers 404');

		$this->rFlows = (string) tempnam(sys_get_temp_dir(), 'flows');
		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 0]]);
		NodeRole::useMainBuild(false);
		try {
			// Every mode: the controllers need Domain/User, which the LB build strips.
			foreach ([[2, 'active', '0'], [2, 'quarantined', '0'], [1, 'active', '0'], [0, 'active', '0']] as [$rMode, $rState, $rOn]) {
				file_put_contents($this->rFlows, json_encode(['mode' => $rMode, 'flows' => NodeFlows::CONFIG, 'state' => $rState]));
				NodeFlows::usePath($this->rFlows, true);
				$this->assertSame('set $viewer_api ' . $rOn . ';', RootSignalsCronJob::viewerApiConf(), "mode $rMode, $rState");
			}
			NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 1]]);
			NodeFlows::usePath($this->rFlows, true);
			$this->assertNull(RootSignalsCronJob::viewerApiConf(), 'MAIN: nothing to write');
		} finally {
			NodeRole::useMainBuild(null);
		}
		$rMain = (string) file_get_contents($this->rRoot . '/src/bin/nginx/conf/nginx.conf');
		$this->assertStringNotContainsString('viewer_api', $rMain, 'MAIN serves its viewer APIs');
	}

	/**
	 * Every file lb_configs/nginx.conf includes by name (relative to
	 * bin/nginx/conf/, globs aside) is in the LB archive: tracked under src/,
	 * inside LB_DIRS and stripped by neither LB_DIRS_TO_REMOVE nor
	 * LB_FILES_TO_REMOVE.
	 */
	public function testTheLbBuildShipsEveryFileItsNginxIncludes(): void {
		if (!is_file($this->rRoot . '/Makefile') || !file_exists($this->rRoot . '/.git')) {
			$this->markTestSkipped('the LB lists are read from the Makefile of a git checkout');
		}
		$rDirs = $this->makeList('LB_DIRS');
		$rRmDirs = $this->makeList('LB_DIRS_TO_REMOVE');
		$rRmFiles = $this->makeList('LB_FILES_TO_REMOVE');

		preg_match_all('/^\s*include\s+([^\s;*]+);/m', $this->lbConf(), $rMatches);
		$this->assertContains('viewer_api.conf', $rMatches[1]);
		foreach (array_unique($rMatches[1]) as $rInclude) {
			$rPath = 'bin/nginx/conf/' . $rInclude;
			exec('git -C ' . escapeshellarg($this->rRoot) . ' ls-files --error-unmatch -- ' . escapeshellarg('src/' . $rPath) . ' 2>/dev/null', $rOut, $rCode);
			$this->assertSame(0, $rCode, $rPath . ' is not tracked');
			$this->assertContains(explode('/', $rPath)[0], $rDirs, $rPath);
			$this->assertNotContains($rPath, $rRmFiles, $rPath . ' is stripped from the LB archive');
			foreach ($rRmDirs as $rRmDir) {
				$this->assertStringStartsNotWith($rRmDir . '/', $rPath, $rPath . ' is stripped from the LB archive');
			}
		}
	}

	/** @return string[] */
	private function makeList(string $rName): array {
		exec('make -s -C ' . escapeshellarg($this->rRoot) . ' print-' . $rName . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		return preg_split('/\s+/', trim(implode(' ', $rOut)), -1, PREG_SPLIT_NO_EMPTY);
	}
}
