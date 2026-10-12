<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FfmpegBuildsCommand;

/**
 * FfmpegBuildsCommand: the release's asset name for this distribution, and a build
 * put in place only once it runs here, the installed one kept otherwise.
 */
final class FfmpegBuildsCommandTest extends TestCase {
	private string $rBase;

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/ffmpeg_bin_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . '7.1', 0755, true);
		file_put_contents($this->rBase . '7.1/ffmpeg', 'the installed build');
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	public function testTheAssetIsNamedForThisDistribution(): void {
		$this->assertSame('ubuntu_22', FfmpegBuildsCommand::distro('ubuntu', '22.04'));
		$this->assertSame('ubuntu_20', FfmpegBuildsCommand::distro('ubuntu', '20.04'));
		$this->assertSame('debian_12', FfmpegBuildsCommand::distro('debian', '12'));
		$this->assertSame('debian_13', FfmpegBuildsCommand::distro('debian', '13'));
		$this->assertNull(FfmpegBuildsCommand::distro('rocky', '9.4'), 'not supported: no builds');
		$this->assertNull(FfmpegBuildsCommand::distro('ubuntu', '18.04'));
		$this->assertNull(FfmpegBuildsCommand::distro('arch', ''));
		$this->assertSame(['4.4', '7.1', '8.1'], FfmpegBuildsCommand::LABELS);
	}

	public function testABuildIsPlacedOnlyOnceItRunsHere(): void {
		$rOk = "#!/bin/sh\necho ffmpeg version 7.1.5\n";
		$this->assertNull((new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "FFmpeg : 7.1.5\n", 'extra' => 'x']), '7.1'));
		$this->assertSame($rOk, file_get_contents($this->rBase . '7.1/ffmpeg'));
		$this->assertFileExists($this->rBase . '7.1/BUILD_INFO');
		$this->assertFileDoesNotExist($this->rBase . '7.1/extra', 'only the three names a build has');
		$this->assertSame(0755, fileperms($this->rBase . '7.1/ffprobe') & 0777);
		$this->assertSame(['7.1'], $this->entries(), 'nothing left aside');

		// One that does not start here (another glibc): the installed one stays.
		$rWhy = (new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => "#!/bin/sh\nexit 127\n", 'BUILD_INFO' => "FFmpeg : 7.1.6\n"]), '7.1');
		$this->assertSame('ffprobe does not run on this node', $rWhy);
		$this->assertSame($rOk, file_get_contents($this->rBase . '7.1/ffmpeg'));
		$rWhy = (new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk]), '7.1');
		$this->assertSame('the archive does not hold ffmpeg, ffprobe and BUILD_INFO', $rWhy);
		$this->assertSame(['7.1'], $this->entries());

		// A label the node has no build of yet.
		$this->assertNull((new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "FFmpeg   : 8.1\n"]), '8.1'));
		$this->assertSame(['7.1', '8.1'], $this->entries());
	}

	/** A build is filed only under the minor version it really is: never again a 4.4.5 as 4.0. */
	public function testABuildOfAnotherVersionIsRefused(): void {
		$rOk = "#!/bin/sh\necho ffmpeg\n";
		$rWhy = (new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "FFmpeg   : 4.4.5\nLabel    : 4.0\n"]), '4.0');
		$this->assertSame('its BUILD_INFO is not an FFmpeg 4.0 build', $rWhy);
		$this->assertSame(['7.1'], $this->entries());
		$this->assertNull((new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "FFmpeg   : 4.4.5\n"]), '4.4'));
		$this->assertNull((new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "FFmpeg : 4.4-dev\n"]), '4.4'), 'a suffix after the version is fine');
		$this->assertSame('its BUILD_INFO is not an FFmpeg 4.4 build', (new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "FFmpeg : 4.45\n"]), '4.4'));
	}

	/** XUI's 4.0 moves to 4.0-xui; a second run changes nothing. */
	public function testXuisBuildGetsItsOwnName(): void {
		$this->dir('4.0', false);
		$this->layout();
		$this->assertFileExists($this->rBase . '4.0-xui/ffmpeg');
		$this->layout();
		$this->assertSame(['4.0-xui', '7.1'], $this->entries(), 'no link, nothing left aside');
	}

	/** Every other folder stays as it is: our old 4.0 and 8.0, an admin's own build, backups, and XUI's when 4.0-xui is taken. */
	public function testNoOtherFolderIsTouched(): void {
		$this->dir('4.0', true);
		$this->dir('8.0', false);
		$this->dir('7.0', true);
		$this->dir('4.3.backup.20260318', false);
		$this->dir('4.4', true);
		$this->dir('8.1', true);
		$this->layout();
		$this->assertSame(['4.0', '4.3.backup.20260318', '4.4', '7.0', '7.1', '8.0', '8.1'], $this->entries());
		$this->assertFalse(is_link($this->rBase . '4.0'));

		exec('rm -rf ' . escapeshellarg($this->rBase . '4.0'));
		$this->dir('4.0', false);
		$this->dir('4.0-xui', false);
		$this->layout();
		$this->assertFileExists($this->rBase . '4.0/ffmpeg', '4.0-xui taken: XUI\'s 4.0 stays');
	}

	/** A fresh leftover of a place() run is not swept: that run may still need it. */
	public function testAFreshLeftoverIsKept(): void {
		mkdir($this->rBase . '.7.1.old.0000');
		$this->layout();
		$this->assertDirectoryExists($this->rBase . '.7.1.old.0000');
	}

	private function dir(string $rName, bool $rOurs): void {
		mkdir($this->rBase . $rName, 0755, true);
		file_put_contents($this->rBase . $rName . '/ffmpeg', $rName);
		if ($rOurs) {
			file_put_contents($this->rBase . $rName . '/BUILD_INFO', "FFmpeg : {$rName}.5\n");
		}
	}

	private function layout(): string {
		$rLayout = new ReflectionMethod(FfmpegBuildsCommand::class, 'layout');
		$rLayout->setAccessible(true);
		ob_start();
		$rLayout->invoke(new FfmpegBuildsCommand($this->rBase));
		return (string) ob_get_clean();
	}

	/** @param array<string, string> $rFiles */
	private function archive(array $rFiles): string {
		$rDir = $this->rBase . '../src_' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir);
		foreach ($rFiles as $rName => $rBody) {
			file_put_contents($rDir . $rName, $rBody);
		}
		$rArchive = $this->rBase . '.test_' . bin2hex(random_bytes(4)) . '.tar.gz';
		exec('tar -czf ' . escapeshellarg($rArchive) . ' -C ' . escapeshellarg($rDir) . ' ' . implode(' ', array_map('escapeshellarg', array_keys($rFiles))) . ' && rm -rf ' . escapeshellarg($rDir));
		return $rArchive;
	}

	/** @return list<string> what ffmpeg_bin/ holds besides the test's archives */
	private function entries(): array {
		$rAll = array_values(array_filter(scandir($this->rBase), static fn(string $rN): bool => !in_array($rN, ['.', '..'], true) && !str_starts_with($rN, '.test_')));
		sort($rAll);
		return $rAll;
	}
}
