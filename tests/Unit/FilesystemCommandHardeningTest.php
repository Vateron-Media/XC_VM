<?php

use PHPUnit\Framework\TestCase;
use XcVm\Ministra\PortalHandler;

final class FilesystemCommandHardeningTest extends TestCase {
	public function testInstallerRejectsTraversalAndLinks(): void {
		$rDriver = <<<'PY'
import importlib.machinery, importlib.util, io, json, os, stat, sys, tarfile, tempfile, zipfile
loader = importlib.machinery.SourceFileLoader("xc_install", sys.argv[1])
mod = importlib.util.module_from_spec(importlib.util.spec_from_loader("xc_install", loader))
loader.exec_module(mod)
root = tempfile.mkdtemp()
results = {}
traversal_tar = ""
for name, kind in (("tar-traversal", "traversal"), ("tar-symlink", "symlink"), ("tar-hardlink", "hardlink")):
    path = os.path.join(root, name + ".tar.gz")
    with tarfile.open(path, "w:gz") as archive:
        member = tarfile.TarInfo("../escape" if kind == "traversal" else "link")
        if kind == "symlink": member.type, member.linkname = tarfile.SYMTYPE, "/etc/passwd"
        elif kind == "hardlink": member.type, member.linkname = tarfile.LNKTYPE, "target"
        else: member.size = 0
        archive.addfile(member, io.BytesIO())
    results[name] = mod.is_valid_tar(path)
    if kind == "traversal": traversal_tar = path
for name, entry, mode in (("zip-traversal", "../escape", 0), ("zip-symlink", "link", stat.S_IFLNK | 0o777)):
    path = os.path.join(root, name + ".zip")
    with zipfile.ZipFile(path, "w") as archive:
        info = zipfile.ZipInfo(entry)
        info.create_system = 3
        info.external_attr = mode << 16
        archive.writestr(info, "/etc/passwd" if mode else "")
    results[name] = mod.is_valid_zip(path)
loader = importlib.machinery.SourceFileLoader("xc_update", sys.argv[2])
update = importlib.util.module_from_spec(importlib.util.spec_from_loader("xc_update", loader))
loader.exec_module(update)
try:
    update.extract_archive(traversal_tar, os.path.join(root, "update"))
    results["update-traversal"] = True
except RuntimeError:
    results["update-traversal"] = False
print(json.dumps(results))
PY;
		$rFile = tempnam(sys_get_temp_dir(), 'xcsec9-');
		file_put_contents($rFile, $rDriver);
		$rRoot = dirname(__DIR__, 2);
		exec('python3 -B ' . escapeshellarg($rFile) . ' ' . escapeshellarg($rRoot . '/install') . ' ' . escapeshellarg($rRoot . '/src/update'), $rOutput, $rCode);
		@unlink($rFile);
		$this->assertSame(0, $rCode, implode("\n", $rOutput));
		$this->assertSame([
			'tar-traversal' => false,
			'tar-symlink' => false,
			'tar-hardlink' => false,
			'zip-traversal' => false,
			'zip-symlink' => false,
			'update-traversal' => false,
		], json_decode(implode("\n", $rOutput), true));
	}

	public function testInstallerAndUpdaterUsePinnedSafePaths(): void {
		$rRoot = dirname(__DIR__, 2);
		$rInstall = (string) file_get_contents($rRoot . '/install');
		$rUpdate = (string) file_get_contents($rRoot . '/src/update');
		$this->assertStringNotContainsString('mariadb_repo_setup | bash', $rInstall);
		$this->assertStringNotContainsString('/var/lib/dpkg/lock-frontend', $rInstall);
		$this->assertStringContainsString('177F4010FE56CA3336300305F1656F24C74CD1D8', $rInstall);
		$this->assertStringContainsString('"redis-server"', $rInstall);
		$this->assertStringContainsString('"python3-guessit"', $rInstall);
		$this->assertStringContainsString('member.issym() or member.islnk()', $rUpdate);
		$this->assertStringNotContainsString('tar -zxf', $rUpdate);
	}

	public function testMalformedPortalCacheFailsClosed(): void {
		if (!function_exists('igbinary_unserialize')) {
			$this->markTestSkipped('igbinary is not installed');
		}
		$rFile = tempnam(sys_get_temp_dir(), 'xcsec9-cache-');
		file_put_contents($rFile, 'not igbinary');
		$rMethod = new ReflectionMethod(PortalHandler::class, 'cacheArray');
		$rMethod->setAccessible(true);
		$this->assertSame([], $rMethod->invoke(null, $rFile));
		file_put_contents($rFile, igbinary_serialize(['info' => ['id' => 7]]));
		$this->assertSame(['info' => ['id' => 7]], $rMethod->invoke(null, $rFile));
		@unlink($rFile);
	}

	public function testDbAndConfigValuesNeverEnterAShellCommand(): void {
		$rRoot = dirname(__DIR__, 2) . '/src/';
		$rSignals = (string) file_get_contents($rRoot . 'Cli/Commands/SignalsCommand.php');
		$rSender = (string) file_get_contents($rRoot . 'Streaming/Delivery/SignalSender.php');
		$this->assertStringNotContainsString('wget --timeout', $rSignals);
		$this->assertStringContainsString("FILTER_VALIDATE_URL", $rSignals);
		$this->assertStringNotContainsString('shell_exec($rFFMPEG_CPU', $rSender);
		$this->assertStringNotContainsString('passthru($rFFMPEG_CPU', $rSender);
		$this->assertStringContainsString('proc_open($rArgv', $rSender);
		$this->assertStringContainsString('realpath(STREAMS_PATH', $rSender);
	}

	public function testUnsupportedBundledRuntimesAreGone(): void {
		$rRoot = dirname(__DIR__, 2);
		foreach (['src/bin/guess', 'src/bin/redis/redis-server', 'src/Public/assets/admin/js/vendor.min.js',
			'src/Public/assets/player/js/bootstrap.bundle.min.js', 'src/Public/assets/player/js/jquery-3.5.1.min.js'] as $rRemoved) {
			$this->assertFileDoesNotExist($rRoot . '/' . $rRemoved);
		}
		$rSwal = (string) file_get_contents($rRoot . '/src/Public/assets/admin/vendor/libs/sweetalert2/sweetalert2.js');
		$this->assertStringContainsString('sweetalert2 v11.26.25', $rSwal);
	}
}
