<?php

use PHPUnit\Framework\TestCase;

/**
 * The `install` script downloads, checks and unpacks the runtime bundle in a
 * directory of root's own (mode 0700, made for that run), so the file it
 * checked is the file it unpacks, and it leaves nothing of it behind.
 */
final class AuditModInstallBundleTempDirTest extends TestCase {
	private string $rDir = '';

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-bundle-tmp-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run the script's bundle step in a child Python with GitHub answered
	 * here: hashes.md5 holds $rSums (`%s` standing for the download's own
	 * MD5). The archive is "unpacked" into an empty directory, so nothing
	 * reaches the panel's tree and no command runs.
	 *
	 * @return array<string, mixed>
	 */
	private function bundleStep(string $rSums): array {
		if (trim((string) shell_exec('command -v python3')) === '') {
			$this->markTestSkipped('the installer is a Python 3 script');
		}
		$rDriver = $this->rDir . '/driver.py';
		file_put_contents($rDriver, <<<'PY'
			import importlib.machinery, importlib.util, io, json, os, stat, sys

			script, sums = sys.argv[1], sys.argv[2]
			loader = importlib.machinery.SourceFileLoader("xc_install", script)
			mod = importlib.util.module_from_spec(importlib.util.spec_from_loader("xc_install", loader))
			loader.exec_module(mod)

			out = {"said": [], "extract": None}
			state = {"md5": ""}

			class Reply(io.BytesIO):
			    def __enter__(self):
			        return self

			    def __exit__(self, *a):
			        return False

			def urlopen(req, timeout=None):
			    url = req if isinstance(req, str) else req.full_url
			    if url.endswith("/hashes.md5"):
			        return Reply(sums.replace("%s", state["md5"]).encode())
			    return Reply(json.dumps({"tag_name": "9.9.9"}).encode())

			def urlretrieve(url, filename):
			    where = os.stat(os.path.dirname(filename))
			    out["download"] = filename
			    out["dir_mode"] = stat.S_IMODE(where.st_mode)
			    out["dir_uid"] = where.st_uid
			    with open(filename, "wb") as f:
			        f.write(b"a runtime bundle")
			    state["md5"] = mod.compute_md5(filename)

			class Tar:
			    def __enter__(self):
			        return self

			    def __exit__(self, *a):
			        return False

			    def getmembers(self):
			        return []

			    def extractall(self, path=None, members=None):
			        out["extract"] = path
			        os.makedirs(path)

			def tar_open(name, mode="r"):
			    out["unpacked"] = name
			    return Tar()

			def no_command(*a, **k):
			    raise RuntimeError("no command runs in a test")

			mod.urllib.request.urlopen = urlopen
			mod.urllib.request.urlretrieve = urlretrieve
			mod.tarfile.open = tar_open
			mod.run_command = no_command
			mod.subprocess.run = no_command
			mod.printc = lambda text, *a, **k: out["said"].append(text)

			out["returned"] = mod.install_distribution_binaries("ubuntu", "22.04")
			out["uid"] = os.geteuid()
			out["left"] = [p for p in (out.get("download"), out["extract"]) if p and os.path.lexists(p)]
			out["dir_left"] = os.path.lexists(os.path.dirname(out["download"]))
			print(json.dumps(out))
			PY);
		$rCmd = 'cd ' . escapeshellarg($this->rDir) . ' && python3 -B ' . escapeshellarg($rDriver) . ' ' . escapeshellarg(dirname(MAIN_HOME) . '/install')
			. ' ' . escapeshellarg($rSums) . ' 2>&1';
		exec($rCmd, $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$rResult = json_decode((string) end($rOut), true);
		$this->assertIsArray($rResult, implode("\n", $rOut));
		return $rResult;
	}

	public function testTheBundleIsCheckedAndUnpackedInADirectoryOfItsOwn(): void {
		$rResult = $this->bundleStep("%s  ubuntu_22.tar.gz\n");
		$rSaid   = implode(' | ', $rResult['said']);
		$rOwn    = dirname($rResult['download']);

		$this->assertSame(0700, $rResult['dir_mode'], 'no other user can put a file where the bundle is downloaded');
		$this->assertSame($rResult['uid'], $rResult['dir_uid']);
		$this->assertSame($rResult['download'], $rResult['unpacked'] ?? null, 'the file that was checked is the file unpacked: ' . $rSaid);
		$this->assertStringStartsWith($rOwn . '/', (string) $rResult['extract'], 'and it is unpacked there too');
		$this->assertNotSame($rResult['download'], $rResult['extract']);

		$this->assertSame([], $rResult['left'], $rSaid);
		$this->assertFalse($rResult['dir_left'], 'the directory goes when the step ends');
	}

	public function testNothingIsLeftOfABundleThatFailsItsCheck(): void {
		$rResult = $this->bundleStep(str_repeat('0', 32) . "  ubuntu_22.tar.gz\n");

		$this->assertFalse($rResult['returned']);
		$this->assertNull($rResult['extract'], 'it is not unpacked');
		$this->assertSame(0700, $rResult['dir_mode']);
		$this->assertSame([], $rResult['left']);
		$this->assertFalse($rResult['dir_left'], 'the directory goes when the step ends');
	}
}
