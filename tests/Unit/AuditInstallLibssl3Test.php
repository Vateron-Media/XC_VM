<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerInstallCommand;

/**
 * The OpenSSL 3 package an Ubuntu 20 install adds, in both installers
 * (server:install over SSH, and the `install` script on MAIN). dpkg runs a
 * package's scripts as root, so it is handed the download only when that is
 * the pinned build (its SHA-256, as Ubuntu's signed index lists it), from a
 * directory no other user can write to, and the install log says the library
 * is there only when it is.
 */
final class AuditInstallLibssl3Test extends TestCase {
	/** libssl3 3.0.2-0ubuntu1 amd64, as jammy's main/binary-amd64/Packages lists it. */
	private const SHA256 = '11a83260542e05aebbbafce9164d594287d2584be80972e08708528fe06f80d3';

	/** @var list<string> */
	private array $rRan = [];

	private string $rDir = '';

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-libssl3-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run the SSH flow's step against a node of release $rVersion whose download
	 * hashes to $rSum, whose mktemp gives $rTmp and which answers $rAfter to the
	 * install command. Returns what the install log says.
	 */
	private function overSsh(string $rVersion, string $rSum, string $rTmp = '/tmp/xcvm.aB3dE6gH9k', string $rAfter = "SSL_OK\n"): string {
		$this->rRan = [];
		$rNode = function ($rConn, string $rCommand) use ($rSum, $rTmp, $rAfter): array {
			$this->rRan[] = $rCommand;
			return ['output' => match (true) {
				str_starts_with($rCommand, 'mktemp') => $rTmp . "\n",
				str_starts_with($rCommand, 'sha256sum') => $rSum === '' ? '' : $rSum . '  ' . $rTmp . "/libssl3.deb\n",
				str_contains($rCommand, 'dpkg') => $rAfter,
				default => '',
			}, 'error' => ''];
		};
		$rStep = new ReflectionMethod(ServerInstallCommand::class, 'installUbuntu20Compatibility');
		$rStep->setAccessible(true);
		ob_start();
		try {
			$rStep->invoke(new ServerInstallCommand(), null, $rNode, $rVersion);
		} finally {
			$rSaid = (string) ob_get_clean();
		}
		return $rSaid;
	}

	/** @return list<string> The commands that hand a package to dpkg. */
	private function dpkgRuns(): array {
		return array_values(array_filter($this->rRan, static fn(string $rCommand): bool => str_contains($rCommand, 'dpkg')));
	}

	public function testOverSshOnlyThePinnedPackageIsHandedToDpkg(): void {
		// Another file under the package's name, and a download that failed.
		foreach ([str_repeat('ab', 32), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', ''] as $rSum) {
			$rSaid = $this->overSsh('20.04', $rSum);
			$this->assertSame([], $this->dpkgRuns(), 'dpkg is not given a file whose SHA-256 is ' . var_export($rSum, true));
			$this->assertStringNotContainsString('installed successfully', $rSaid);
			$this->assertStringContainsString('libssl3 is not installed', $rSaid);
			$this->assertContains('rm -rf /tmp/xcvm.aB3dE6gH9k', $this->rRan, 'and the download is removed');
		}
	}

	public function testOverSshThePinnedPackageIsInstalledFromAPrivateDirectory(): void {
		$rSaid = $this->overSsh('20.04', self::SHA256);
		$this->assertSame('mktemp -d /tmp/xcvm.XXXXXXXXXX', $this->rRan[0]);
		$this->assertStringStartsWith('wget -O /tmp/xcvm.aB3dE6gH9k/libssl3.deb ', $this->rRan[1]);
		$this->assertStringEndsWith('/libssl3_3.0.2-0ubuntu1_amd64.deb', $this->rRan[1]);
		$this->assertSame('sha256sum /tmp/xcvm.aB3dE6gH9k/libssl3.deb', $this->rRan[2], 'checked before dpkg sees it');
		$this->assertCount(1, $this->dpkgRuns());
		$this->assertSame($this->rRan[3], $this->dpkgRuns()[0]);
		$this->assertStringStartsWith('sudo dpkg -i /tmp/xcvm.aB3dE6gH9k/libssl3.deb ', $this->rRan[3]);
		$this->assertStringContainsString('rm -rf /tmp/xcvm.aB3dE6gH9k', $this->rRan[3], 'and is removed with its directory');
		$this->assertStringContainsString('libssl3 installed successfully', $rSaid);
	}

	public function testOverSshNothingIsFetchedWithoutAPrivateDirectory(): void {
		foreach (['', '/tmp', '/tmp/xcvm.aB3dE6gH9k; id', 'mktemp: failed to create directory'] as $rBad) {
			$rSaid = $this->overSsh('20.04', self::SHA256, $rBad);
			$this->assertSame(['mktemp -d /tmp/xcvm.XXXXXXXXXX'], $this->rRan, var_export($rBad, true));
			$this->assertStringNotContainsString('installed successfully', $rSaid);
		}
	}

	public function testOverSshTheLibraryIsSaidInstalledOnlyWhenItIsThere(): void {
		// dpkg's exit status says nothing here: the package asks for a newer libc6
		// than Ubuntu 20 has, so dpkg unpacks the library and still reports an error.
		$rSaid = $this->overSsh('20.04', self::SHA256, '/tmp/xcvm.aB3dE6gH9k', '');
		$this->assertCount(1, $this->dpkgRuns());
		$this->assertStringContainsString('test -e /usr/lib/x86_64-linux-gnu/libssl.so.3 && echo SSL_OK', $this->dpkgRuns()[0]);
		$this->assertStringNotContainsString('installed successfully', $rSaid);
		$this->assertStringContainsString('libssl3 is not installed', $rSaid);
	}

	public function testOverSshOtherReleasesAreLeftAlone(): void {
		foreach (['22.04', '24.04', '12', '13'] as $rVersion) {
			$this->assertSame('', $this->overSsh($rVersion, self::SHA256));
			$this->assertSame([], $this->rRan, $rVersion);
		}
	}

	/**
	 * Run the `install` script's step in a child Python, with its shell
	 * commands recorded instead of run: the download writes $rBody, which is
	 * the pinned build when $rPinned, and the library is there afterwards when
	 * $rLibrary.
	 *
	 * @return array{pin: ?string, ran: list<string>, said: list<string>, dpkg: list<array{file: string, dir_mode: string}>, left: list<string>}
	 */
	private function onMain(string $rBody, bool $rPinned, bool $rLibrary = true): array {
		if (trim((string) shell_exec('command -v python3')) === '') {
			$this->markTestSkipped('the installer is a Python 3 script');
		}
		$rLib = $this->rDir . '/libssl.so.3';
		$rLibrary ? touch($rLib) : @unlink($rLib);
		$rDriver = $this->rDir . '/driver.py';
		file_put_contents($rDriver, <<<'PY'
			import hashlib, importlib.machinery, importlib.util, json, os, re, sys

			script, body, pinned, library = sys.argv[1], sys.argv[2].encode(), sys.argv[3] == "1", sys.argv[4]
			loader = importlib.machinery.SourceFileLoader("xc_install", script)
			mod = importlib.util.module_from_spec(importlib.util.spec_from_loader("xc_install", loader))
			loader.exec_module(mod)

			out = {"pin": getattr(mod, "_OPENSSL3_DEB_SHA256", None), "ran": [], "said": [], "dpkg": []}
			wrote = []
			if pinned:
			    mod._OPENSSL3_DEB_SHA256 = hashlib.sha256(body).hexdigest()
			mod._OPENSSL3_LIB = library

			def run_command(cmd, shell=True, capture_output=False):
			    out["ran"].append(cmd)
			    got = re.search(r'wget\s+-qO\s+"?([^"\s]+)"?', cmd)
			    if got:
			        wrote.append(got.group(1))
			        with open(got.group(1), "wb") as f:
			            f.write(body)
			    for deb in re.findall(r'dpkg\s+(?:--force-depends\s+)?-i\s+"?([^"\s]+)"?', cmd):
			        out["dpkg"].append({"file": deb, "dir_mode": oct(os.stat(os.path.dirname(deb)).st_mode & 0o777)})
			    return 0, None, None

			mod.run_command = run_command
			mod.printc = lambda text, *a, **k: out["said"].append(text)
			try:
			    mod._install_openssl3_compat("ubuntu", "20")
			finally:
			    out["left"] = [p for p in wrote if os.path.exists(p)]
			    for p in out["left"]:
			        os.remove(p)
			print(json.dumps(out))
			PY);
		$rCmd = 'TMPDIR=' . escapeshellarg($this->rDir) . ' python3 -B ' . escapeshellarg($rDriver) . ' ' . escapeshellarg(dirname(MAIN_HOME) . '/install')
			. ' ' . escapeshellarg($rBody) . ' ' . ($rPinned ? '1' : '0') . ' ' . escapeshellarg($rLib) . ' 2>&1';
		exec($rCmd, $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$rResult = json_decode((string) end($rOut), true);
		$this->assertIsArray($rResult, implode("\n", $rOut));
		return $rResult;
	}

	public function testOnMainOnlyThePinnedPackageIsHandedToDpkg(): void {
		$rResult = $this->onMain('another file under the package name', false);
		$this->assertSame([], $rResult['dpkg'], 'dpkg is not given a file that is not the pinned build');
		$this->assertSame([], $rResult['left'], 'and the download is removed');
		$this->assertStringNotContainsString('completed', implode("\n", $rResult['said']));
		$this->assertStringContainsString('not installed', implode("\n", $rResult['said']));
	}

	public function testOnMainThePinnedPackageIsInstalledFromAPrivateDirectory(): void {
		$rResult = $this->onMain('the pinned build', true);
		$this->assertNotSame([], $rResult['dpkg']);
		foreach ($rResult['dpkg'] as $rRun) {
			$this->assertStringStartsWith($this->rDir . '/', $rRun['file'], 'in a directory of its own, not at a fixed path');
			$this->assertSame('0o700', $rRun['dir_mode'], 'that no other user can write to');
		}
		$this->assertSame([], $rResult['left'], 'and is removed with its directory');
		$this->assertStringContainsString('completed', implode("\n", $rResult['said']));
	}

	public function testOnMainTheLibraryIsSaidInstalledOnlyWhenItIsThere(): void {
		$rResult = $this->onMain('the pinned build', true, false);
		$this->assertNotSame([], $rResult['dpkg']);
		$this->assertStringNotContainsString('completed', implode("\n", $rResult['said']));
		$this->assertStringContainsString('not installed', implode("\n", $rResult['said']));
	}

	public function testBothInstallersPinTheBuildUbuntuPublished(): void {
		$rOverSsh = (new ReflectionClass(ServerInstallCommand::class))->getConstant('LIBSSL3_SHA256');
		$this->assertSame(self::SHA256, $rOverSsh);
		$this->assertSame(self::SHA256, $this->onMain('', false)['pin']);
	}
}
