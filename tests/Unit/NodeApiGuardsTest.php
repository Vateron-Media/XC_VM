<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Util\NetworkUtils;

/**
 * The guards on the node system API (`InternalApiController`): MAIN names the
 * paths `scandir`/`getFile` read and the URL `probe` hands to ffprobe, and
 * `get_pids` sends whole command lines back. Without these, any absolute path
 * on a node is listable, any readable file with an allowed extension is
 * servable, ffprobe follows loopback and cloud-metadata URLs, and provider
 * credentials reach MAIN's admin UI and `cluster_commands.result`.
 */
final class NodeApiGuardsTest extends TestCase {

	private string $rRoot;

	protected function setUp(): void {
		$rBase = sys_get_temp_dir() . '/xcvm_guard_' . bin2hex(random_bytes(6));
		@mkdir($rBase . '/root/sub', 0700, true);
		@mkdir($rBase . '/outside', 0700, true);
		touch($rBase . '/root/sub/movie.mp4');
		touch($rBase . '/outside/secret.log');
		$this->rRoot = $rBase;
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rRoot));
	}

	private function roots(): array {
		return [$this->rRoot . '/root'];
	}

	// ── paths ────────────────────────────────────────────────────────────────

	/** The file browser opens at "/": above the roots it gets the way down to them, inside one the node lists it. */
	public function testTheFileBrowserWalksDownToTheScanRoots(): void {
		$rRoots = ['/home/xc_vm/content', '/mnt', '/media'];
		$this->assertSame(['home', 'mnt', 'media'], ClusterSettings::dirsTowardRoots('/', $rRoots));
		$this->assertSame(['xc_vm'], ClusterSettings::dirsTowardRoots('/home/', $rRoots));
		$this->assertSame(['content'], ClusterSettings::dirsTowardRoots('/home/xc_vm', $rRoots));
		$this->assertNull(ClusterSettings::dirsTowardRoots('/home/xc_vm/content/', $rRoots), 'a root: the node lists it');
		$this->assertNull(ClusterSettings::dirsTowardRoots('/mnt/films/', $rRoots), 'inside a root: the node lists it');
		$this->assertNull(ClusterSettings::dirsTowardRoots('/etc/', $rRoots), 'outside every root: the node refuses it');
		$this->assertNull(ClusterSettings::dirsTowardRoots('/mn/', $rRoots), 'a name prefix is not a parent');
		$this->assertSame(['data'], ClusterSettings::dirsTowardRoots('/srv', ['/srv/data/a', '/srv/data/b']), 'one segment, once');
	}

	public function testAPathInsideARootIsAllowed(): void {
		$this->assertTrue(ClusterSettings::pathAllowed($this->rRoot . '/root/sub/movie.mp4', $this->roots()));
		$this->assertTrue(ClusterSettings::pathAllowed($this->rRoot . '/root', $this->roots()));
	}

	public function testAPathOutsideEveryRootIsRefused(): void {
		$this->assertFalse(ClusterSettings::pathAllowed($this->rRoot . '/outside/secret.log', $this->roots()));
		$this->assertFalse(ClusterSettings::pathAllowed('/etc/passwd', $this->roots()));
	}

	public function testTraversalAndSymlinksOutOfARootAreRefused(): void {
		$this->assertFalse(ClusterSettings::pathAllowed($this->rRoot . '/root/../outside/secret.log', $this->roots()));

		symlink($this->rRoot . '/outside/secret.log', $this->rRoot . '/root/escape.log');
		$this->assertFalse(ClusterSettings::pathAllowed($this->rRoot . '/root/escape.log', $this->roots()));
	}

	public function testAPathThatDoesNotExistIsRefused(): void {
		$this->assertFalse(ClusterSettings::pathAllowed($this->rRoot . '/root/sub/missing.mp4', $this->roots()));
	}

	public function testRootsComeFromTheSettingInEitherForm(): void {
		$rJson = json_encode($this->roots(), JSON_UNESCAPED_SLASHES);
		$rLines = implode("\n", $this->roots());

		$this->assertTrue(ClusterSettings::pathAllowed($this->rRoot . '/root/sub/movie.mp4', $rJson));
		$this->assertTrue(ClusterSettings::pathAllowed($this->rRoot . '/root/sub/movie.mp4', $rLines));
	}

	public function testAnEmptySettingFallsBackToTheDefaultRoots(): void {
		// The default roots do not cover the temp tree, so this must refuse.
		$this->assertFalse(ClusterSettings::pathAllowed($this->rRoot . '/root/sub/movie.mp4', ''));
		$this->assertSame(['/home/xc_vm/content', '/mnt', '/media'], ClusterSettings::DEFAULT_SCAN_ROOTS);
	}

	public function testAnExtraRootOpensOnlyThatTree(): void {
		// getFile passes MAIN_HOME this way for certbot logs, subtitles and modules.
		$rPath = $this->rRoot . '/outside/secret.log';

		$this->assertFalse(ClusterSettings::pathAllowed($rPath, $this->roots()));
		$this->assertTrue(ClusterSettings::pathAllowed($rPath, $this->roots(), [$this->rRoot . '/outside']));
	}

	// ── probe targets ────────────────────────────────────────────────────────

	public function testProbeRefusesLoopbackLinkLocalAndNonHttpSchemes(): void {
		foreach ([
			'http://127.0.0.1/probe/x',
			'http://127.9.9.9:8080/x.ts',
			'http://[::1]/x.ts',
			'http://169.254.169.254/latest/meta-data/',
			'http://0.0.0.0/x.ts',
			'http://localhost/probe/x',
			'file:///etc/passwd',
			'concat:/etc/passwd',
			'/home/xc_vm/config/config.enc',
			'',
		] as $rUrl) {
			$this->assertFalse(NetworkUtils::probeTargetAllowed($rUrl), $rUrl);
		}
	}

	public function testProbeAllowsPublicAndPrivateLanTargets(): void {
		// Parents and proxies sit on private ranges routinely, so those stay allowed.
		foreach ([
			'http://198.51.100.10:8080/probe/abc',
			'https://198.51.100.10/probe/abc',
			'http://10.0.0.5/live/1.ts',
			'http://192.168.1.20:25461/x.m3u8',
		] as $rUrl) {
			$this->assertTrue(NetworkUtils::probeTargetAllowed($rUrl), $rUrl);
		}
	}

	public function testLocalAddressCheckKnowsBothFamilies(): void {
		$this->assertTrue(NetworkUtils::isLocalOrLinkLocalIP('127.0.0.1'));
		$this->assertTrue(NetworkUtils::isLocalOrLinkLocalIP('::1'));
		$this->assertTrue(NetworkUtils::isLocalOrLinkLocalIP('fe80::1'));
		$this->assertTrue(NetworkUtils::isLocalOrLinkLocalIP('::ffff:127.0.0.1'));
		$this->assertTrue(NetworkUtils::isLocalOrLinkLocalIP('not-an-ip'));
		$this->assertFalse(NetworkUtils::isLocalOrLinkLocalIP('8.8.8.8'));
		$this->assertFalse(NetworkUtils::isLocalOrLinkLocalIP('2001:db8::1'));
	}

	public function testCidrMatchingIsAddressFamilyAware(): void {
		// ip2long() returned false for IPv6, so every IPv6 matched every IPv6 range.
		$this->assertTrue(NetworkUtils::ipInCIDR('fe80::1', 'fe80::/10'));
		$this->assertFalse(NetworkUtils::ipInCIDR('2001:db8::1', 'fe80::/10'));
		$this->assertFalse(NetworkUtils::ipInCIDR('2001:db8::1', '127.0.0.0/8'));
		$this->assertFalse(NetworkUtils::ipInCIDR('127.0.0.1', '::1/128'));
		$this->assertTrue(NetworkUtils::ipInCIDR('10.1.2.3', '10.0.0.0/8'));
		$this->assertFalse(NetworkUtils::ipInCIDR('11.1.2.3', '10.0.0.0/8'));
	}

	// ── process list ─────────────────────────────────────────────────────────

	public function testProcessListLosesCredentials(): void {
		$rLine = "xc_vm 123 0.5 1.0 900 800 ? Ssl 00:01 02:11 ffmpeg -i 'http://host:80/live/bob/s3cret/7.ts' "
			. "-headers 'X-Token: t' http://cdn/x?password=hunter2&token=abc";
		$rOut = Redactor::redact($rLine);

		$this->assertStringNotContainsString('s3cret', $rOut);
		$this->assertStringNotContainsString('hunter2', $rOut);
		$this->assertStringNotContainsString('=abc', $rOut);
		$this->assertStringContainsString('ffmpeg', $rOut);
		$this->assertStringContainsString('123', $rOut);
	}
}
