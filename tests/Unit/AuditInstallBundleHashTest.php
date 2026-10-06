<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * What root unpacks into the panel's tree comes with a checksum from its
 * release, and nothing is unpacked without one: the runtime bundle (PHP,
 * nginx) in the updater, in a load balancer's install and in the `install`
 * script, and the panel archive that script downloads. A release whose
 * hashes.md5 cannot be fetched, or has no line for the asset, installs
 * nothing, and the running binaries are kept.
 */
final class AuditInstallBundleHashTest extends TestCase {
	private const ROOT = MAIN_HOME;

	private string $rDir = '';

	public static function setUpBeforeClass(): void {
		foreach (['GIT_OWNER' => 'Vateron-Media', 'GIT_REPO_BIN' => 'XC_VM_Binaries'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-bundle-hash-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run update_binaries.sh for release 01102026 on an "Ubuntu 22.04" whose
	 * curl gives a small bundle and, for hashes.md5, $rSums (null: a 404;
	 * `%s` stands for the bundle's own MD5). systemctl and sudo only log.
	 *
	 * @return array{0: int, 1: string} The script's exit status and output.
	 */
	private function update(?string $rSums): array {
		if (posix_geteuid() !== 0) {
			$this->markTestSkipped('the updater runs as root');
		}
		$rFake = $this->rDir . '/fake';
		foreach (['nginx/sbin/nginx', 'nginx_rtmp/sbin/nginx_rtmp', 'php/bin/php', 'network.py'] as $rFile) {
			@mkdir(dirname($this->rDir . '/bundle/' . $rFile), 0755, true);
			file_put_contents($this->rDir . '/bundle/' . $rFile, "#!/bin/sh\n");
		}
		@mkdir($rFake, 0755, true);
		@mkdir($this->rDir . '/target', 0755, true);
		exec('tar -czf ' . escapeshellarg($rFake . '/bundle.tar.gz') . ' -C ' . escapeshellarg($this->rDir . '/bundle') . ' .');
		@unlink($rFake . '/hashes.md5');
		if ($rSums !== null) {
			file_put_contents($rFake . '/hashes.md5', str_replace('%s', (string) md5_file($rFake . '/bundle.tar.gz'), $rSums));
		}
		$rScripts = [
			'systemctl' => 'echo "$@" >> ' . escapeshellarg($this->rDir . '/systemctl.log'),
			'sudo' => 'exit 0',
			'lsb_release' => 'case "$1" in -is) echo Ubuntu ;; -rs) echo 22.04 ;; esac',
			'curl' => 'out=""; url=""
while [ $# -gt 0 ]; do
	case "$1" in
		-o) out="$2"; shift 2 ;;
		--connect-timeout|--max-time|-H) shift 2 ;;
		-*) shift ;;
		*) url="$1"; shift ;;
	esac
done
case "$url" in
	*/hashes.md5)
		[ -f ' . escapeshellarg($rFake . '/hashes.md5') . ' ] || { echo "curl: (22) The requested URL returned error: 404" >&2; exit 22; }
		cat ' . escapeshellarg($rFake . '/hashes.md5') . ' ;;
	*) cp ' . escapeshellarg($rFake . '/bundle.tar.gz') . ' "$out" ;;
esac',
		];
		foreach ($rScripts as $rName => $rBody) {
			file_put_contents($rFake . '/' . $rName, "#!/bin/sh\n" . $rBody . "\n");
			chmod($rFake . '/' . $rName, 0755);
		}
		$rCmd = 'PATH=' . escapeshellarg($rFake . ':' . getenv('PATH')) . ' bash ' . escapeshellarg(self::ROOT . 'bin/install/update_binaries.sh')
			. ' Vateron-Media XC_VM_Binaries ' . escapeshellarg($this->rDir . '/target') . ' 01102026 2>&1';
		exec($rCmd, $rOut, $rCode);
		return [$rCode, implode("\n", $rOut)];
	}

	public function testTheUpdaterInstallsNoBundleItCouldNotVerify(): void {
		$rCases = [
			'hashes.md5 could not be fetched' => null,
			'hashes.md5 lists other assets only' => "%s  debian_12.tar.gz\n%s  ubuntu_24.tar.gz\n",
			'hashes.md5 is not a list of checksums' => "<html>Not Found</html>\n",
		];
		foreach ($rCases as $rCase => $rSums) {
			[$rCode, $rText] = $this->update($rSums);
			$this->assertNotSame(0, $rCode, $rCase . ': ' . $rText);
			$this->assertStringContainsString('hashes.md5', $rText, $rCase);
			$this->assertFileDoesNotExist($this->rDir . '/target/php', $rCase . ': nothing is installed');
			$this->assertFileDoesNotExist($this->rDir . '/target/bin_version.json', $rCase);
			$this->assertFileDoesNotExist($this->rDir . '/systemctl.log', $rCase . ': the service was neither stopped nor started: ' . $rText);
		}
	}

	public function testTheUpdaterInstallsABundleItsReleaseVouchesFor(): void {
		[$rCode, $rText] = $this->update("0123  debian_12.tar.gz\n%s  ubuntu_22.tar.gz\n");
		$this->assertSame(0, $rCode, $rText);
		$this->assertStringContainsString('MD5 verification passed for ubuntu_22.tar.gz', $rText);
		$this->assertFileExists($this->rDir . '/target/php/bin/php');
		$this->assertSame('01102026', json_decode((string) file_get_contents($this->rDir . '/target/bin_version.json'), true)['release']);
		$this->assertSame("stop xc_vm\nstart xc_vm\n", file_get_contents($this->rDir . '/systemctl.log'));
	}

	/**
	 * Run the hash check of a load balancer's bundle install (the step of
	 * LbInstallFlow::installDistributionBinaries from the hash file's URL to
	 * the unpacking) against a node whose curl of hashes.md5 prints $rSums and
	 * whose md5sum of the bundle is $rMd5. The rest of the method resolves the
	 * release over the network, so the step is taken from its source.
	 *
	 * @return array{0: bool, 1: list<string>} Whether the bundle goes on to be unpacked, and the commands run.
	 */
	private function onNode(string $rSums, string $rMd5): array {
		$rMethod = new ReflectionMethod(LbInstallFlow::class, 'installDistributionBinaries');
		$rSource = implode('', array_slice((array) file((string) $rMethod->getFileName()), $rMethod->getStartLine() - 1, $rMethod->getEndLine() - $rMethod->getStartLine() + 1));
		$this->assertSame(1, preg_match('/^\t\t\$rHashURL = .*?(?=^\t\techo "Extracting distribution binaries\\\\n";)/ms', $rSource, $rStep), 'the hash check');
		// Evaluated: lines of this repository's own LbInstallFlow.php, nothing from outside it.
		$rCheck = eval('return static function ($rConn, callable $rRunSSH, string $rTag, string $rBinaryName, string $rDir, string $rTar): bool {' . "\n" . $rStep[0] . "\n\t\t" . 'return true;' . "\n" . '};');
		$rRan = [];
		$rNode = static function ($rConn, string $rCommand) use (&$rRan, $rSums, $rMd5): array {
			$rRan[] = $rCommand;
			return ['output' => match (true) {
				str_starts_with($rCommand, 'curl') => $rSums,
				str_starts_with($rCommand, 'md5sum') => $rMd5 . "  /tmp/xcvm.aB3dE6gH9k/xc_vm_bin.tar.gz\n",
				default => '',
			}, 'error' => ''];
		};
		ob_start();
		try {
			return [$rCheck(null, $rNode, '01102026', 'ubuntu_22.tar.gz', '/tmp/xcvm.aB3dE6gH9k', '/tmp/xcvm.aB3dE6gH9k/xc_vm_bin.tar.gz'), $rRan];
		} finally {
			ob_end_clean();
		}
	}

	public function testALoadBalancerInstallUnpacksNoBundleItCouldNotVerify(): void {
		$rMd5 = str_repeat('ab', 16);
		$rCases = [
			'hashes.md5 could not be fetched' => '',
			'hashes.md5 lists other assets only' => $rMd5 . "  debian_12.tar.gz\n" . $rMd5 . "  ubuntu_24.tar.gz\n",
			'hashes.md5 is not a list of checksums' => "Not Found\n",
		];
		foreach ($rCases as $rCase => $rSums) {
			[$rUnpacks, $rRan] = $this->onNode($rSums, $rMd5);
			$this->assertFalse($rUnpacks, $rCase);
			$this->assertSame('rm -rf /tmp/xcvm.aB3dE6gH9k', end($rRan), $rCase . ': the download is removed');
		}
	}

	public function testALoadBalancerInstallUnpacksABundleItsReleaseVouchesFor(): void {
		$rMd5 = str_repeat('ab', 16);
		[$rUnpacks, $rRan] = $this->onNode("0123  debian_12.tar.gz\n" . $rMd5 . "  ubuntu_22.tar.gz\n", $rMd5);
		$this->assertTrue($rUnpacks);
		$this->assertNotContains('rm -rf /tmp/xcvm.aB3dE6gH9k', $rRan);

		[$rUnpacks] = $this->onNode(str_repeat('cd', 16) . "  ubuntu_22.tar.gz\n", $rMd5);
		$this->assertFalse($rUnpacks, 'a bundle that is not the one its release lists');
	}

	/**
	 * Run one of the `install` script's downloads in a child Python, in a
	 * directory of its own, with GitHub answered by the case: `api` (does the
	 * releases API answer), `sums` (what hashes.md5 holds, `%s` standing for
	 * the download's own MD5; null: a 404). Nothing is unpacked and no command
	 * runs.
	 *
	 * @param array{fn: string, api?: bool, sums: ?string} $rCase
	 * @return array<string, mixed>
	 */
	private function onMain(array $rCase): array {
		if (trim((string) shell_exec('command -v python3')) === '') {
			$this->markTestSkipped('the installer is a Python 3 script');
		}
		$rDriver = $this->rDir . '/driver.py';
		file_put_contents($rDriver, <<<'PY'
			import importlib.machinery, importlib.util, io, json, os, sys, zipfile

			script, case = sys.argv[1], json.loads(sys.argv[2])
			loader = importlib.machinery.SourceFileLoader("xc_install", script)
			mod = importlib.util.module_from_spec(importlib.util.spec_from_loader("xc_install", loader))
			loader.exec_module(mod)

			out = {"urls": [], "said": [], "unpacked": False}
			state = {"md5": ""}
			bundle = "/tmp/ubuntu_22.tar.gz"

			class Reply(io.BytesIO):
			    def __enter__(self):
			        return self

			    def __exit__(self, *a):
			        return False

			def urlopen(req, timeout=None):
			    url = req if isinstance(req, str) else req.full_url
			    out["urls"].append(url)
			    if url.endswith("/hashes.md5"):
			        if case["sums"] is None:
			            raise OSError("HTTP Error 404: Not Found")
			        return Reply(case["sums"].replace("%s", state["md5"]).encode())
			    if not case.get("api", True):
			        raise OSError("HTTP Error 403: rate limit exceeded")
			    return Reply(json.dumps({"tag_name": "9.9.9"}).encode())

			def urlretrieve(url, filename):
			    out["urls"].append(url)
			    if filename.endswith(".zip"):
			        with zipfile.ZipFile(filename, "w") as z:
			            z.writestr("install", "#!/usr/bin/python3\n")
			    else:
			        with open(filename, "wb") as f:
			            f.write(b"a runtime bundle")
			    state["md5"] = mod.compute_md5(filename)

			def unpack(*a, **k):
			    out["unpacked"] = True
			    raise RuntimeError("nothing is unpacked in a test")

			def no_command(*a, **k):
			    raise RuntimeError("no command runs in a test")

			mod.urllib.request.urlopen = urlopen
			mod.urllib.request.urlretrieve = urlretrieve
			mod.tarfile.open = unpack
			mod.run_command = no_command
			mod.subprocess.run = no_command
			mod.printc = lambda text, *a, **k: out["said"].append(text)

			if case["fn"] == "bundle":
			    if os.path.exists(bundle):
			        print(json.dumps({"skip": bundle + " exists"}))
			        sys.exit(0)
			    try:
			        out["returned"] = mod.install_distribution_binaries("ubuntu", "22.04")
			    finally:
			        out["kept"] = os.path.exists(bundle)
			        if out["kept"]:
			            os.remove(bundle)
			else:
			    out["returned"] = mod.download_xc_vm()
			    out["kept"] = os.path.exists("XC_VM.zip")
			print(json.dumps(out))
			PY);
		$rCmd = 'cd ' . escapeshellarg($this->rDir) . ' && python3 -B ' . escapeshellarg($rDriver) . ' ' . escapeshellarg(dirname(self::ROOT) . '/install')
			. ' ' . escapeshellarg((string) json_encode($rCase)) . ' 2>&1';
		exec($rCmd, $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$rResult = json_decode((string) end($rOut), true);
		$this->assertIsArray($rResult, implode("\n", $rOut));
		if (isset($rResult['skip'])) {
			$this->markTestSkipped($rResult['skip']);
		}
		@unlink($this->rDir . '/XC_VM.zip');
		return $rResult;
	}

	public function testTheInstallerUnpacksNoBundleItCouldNotVerify(): void {
		$rCases = [
			'hashes.md5 could not be fetched' => null,
			'hashes.md5 lists other assets only' => "%s  debian_12.tar.gz\n",
		];
		foreach ($rCases as $rCase => $rSums) {
			$rResult = $this->onMain(['fn' => 'bundle', 'sums' => $rSums]);
			$this->assertFalse($rResult['unpacked'], $rCase . ': ' . implode(' | ', $rResult['said']));
			$this->assertFalse($rResult['returned'], $rCase);
			$this->assertFalse($rResult['kept'], $rCase . ': the download is removed');
		}

		// A line as md5sum may write it, which the updater and a node's install read too.
		foreach (["%s  ubuntu_22.tar.gz\n", "%s *ubuntu_22.tar.gz\n", "%s  ./ubuntu_22.tar.gz\n"] as $rSums) {
			$rResult = $this->onMain(['fn' => 'bundle', 'sums' => $rSums]);
			$this->assertTrue($rResult['unpacked'], 'a bundle its release vouches for goes on (' . trim($rSums) . '): ' . implode(' | ', $rResult['said']));
		}
	}

	public function testTheInstallerKeepsNoPanelArchiveItCouldNotVerify(): void {
		$rCases = [
			'hashes.md5 could not be fetched' => null,
			'hashes.md5 has no line for it' => "%s xc_vm.tar.gz\n%s loadbalancer.tar.gz\n",
			'it is not the archive the release lists' => str_repeat('0', 32) . " XC_VM.zip\n",
		];
		// By the release the API names, and by "latest" when the API does not answer.
		foreach ([true, false] as $rApi) {
			foreach ($rCases as $rCase => $rSums) {
				$rResult = $this->onMain(['fn' => 'archive', 'api' => $rApi, 'sums' => $rSums]);
				$rWhere = $rCase . ($rApi ? '' : ' (no API)') . ': ' . implode(' | ', $rResult['said']);
				$this->assertFalse($rResult['returned'], $rWhere);
				$this->assertFalse($rResult['kept'], $rWhere . ': it is not left for the next run to take as a local archive');
			}
		}
	}

	public function testTheInstallerTakesAPanelArchiveItsReleaseVouchesFor(): void {
		$rResult = $this->onMain(['fn' => 'archive', 'api' => true, 'sums' => "%s xc_vm.tar.gz\n%s XC_VM.zip\n"]);
		$this->assertTrue($rResult['returned'], implode(' | ', $rResult['said']));
		$this->assertTrue($rResult['kept']);
		$this->assertContains('https://github.com/Vateron-Media/XC_VM/releases/download/9.9.9/hashes.md5', $rResult['urls']);

		$rResult = $this->onMain(['fn' => 'archive', 'api' => false, 'sums' => "%s xc_vm.tar.gz\n%s XC_VM.zip\n"]);
		$this->assertTrue($rResult['returned'], implode(' | ', $rResult['said']));
		$this->assertTrue($rResult['kept']);
		$this->assertContains('https://github.com/Vateron-Media/XC_VM/releases/latest/download/XC_VM.zip', $rResult['urls']);
		$this->assertContains('https://github.com/Vateron-Media/XC_VM/releases/latest/download/hashes.md5', $rResult['urls'], 'the latest release\'s own checksums');
	}
}
