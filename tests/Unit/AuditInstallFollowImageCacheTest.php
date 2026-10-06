<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\CleanupCronJob;

/**
 * The resized images the panels cache (ImageResizeService, under
 * IMAGES_PATH . 'admin/' and 'player/') do not stay for good: cron:cleanup
 * removes a cached file past its age, and the oldest ones of a directory
 * that holds more than its share. Nothing else under IMAGES_PATH is the
 * cron's to remove: the icons and logos an operator uploaded or an import
 * downloaded live there too.
 */
final class AuditInstallFollowImageCacheTest extends TestCase {
	private const NOW = 1800000000;

	private const DAY = 86400;

	private string $rImages;

	protected function setUp(): void {
		$this->rImages = sys_get_temp_dir() . '/xcvm-images-' . bin2hex(random_bytes(6)) . '/';
		foreach (['admin', 'player', 'enigma2', 'covers'] as $rDir) {
			mkdir($this->rImages . $rDir, 0700, true);
		}
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rImages));
	}

	/** A file under the images directory, last written $rDays days ago. */
	private function image(string $rName, float $rDays, int $rBytes = 16): string {
		$rPath = $this->rImages . $rName;
		file_put_contents($rPath, str_repeat('x', $rBytes));
		touch($rPath, self::NOW - (int) ($rDays * self::DAY));
		return $rPath;
	}

	/** A name the resizer gives a cached PNG. */
	private static function png(string $rUrl, int $rW = 96, int $rH = 96): string {
		return md5($rUrl) . '_' . $rW . '_' . $rH . '.png';
	}

	public function testACachedImagePastItsAgeIsRemovedFromBothCaches(): void {
		$rOld = [
			$this->image('admin/' . self::png('a'), 31),
			$this->image('player/' . self::png('b', 267, 400), 45),
			$this->image('player/' . md5('c') . '.webp', 400),
		];
		$rFresh = [
			$this->image('admin/' . self::png('d'), 29),
			$this->image('player/' . self::png('e', 1920, 1920), 0),
			$this->image('player/' . md5('f') . '.webp', 7),
		];
		$this->assertSame(3, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW));
		foreach ($rOld as $rPath) {
			$this->assertFileDoesNotExist($rPath);
		}
		foreach ($rFresh as $rPath) {
			$this->assertFileExists($rPath, 'browsers keep theirs for a week: a file of this age is still asked for');
		}
	}

	public function testNothingButTheResizersOwnFilesIsRemoved(): void {
		$rKept = [
			// The panel's own images, however old and whatever their names.
			$this->image(md5('logo') . '.png', 900),
			$this->image(self::png('named like a cached file'), 900),
			$this->image(md5('poster') . '.webp', 900),
			$this->image('channel.jpg', 900),
			$this->image('index.html', 900),
			$this->image('enigma2/device_screen_1700000000_abc.jpg', 900),
			$this->image('enigma2/' . self::png('x'), 900),
			$this->image('covers/' . self::png('y'), 900),
			$this->image('covers/' . md5('z') . '.webp', 900),
			// In the cache directories: what the resizer did not write stays.
			$this->image('admin/index.html', 900),
			$this->image('admin/logo.png', 900),
			$this->image('admin/' . md5('no size') . '.png', 900),
			$this->image('admin/' . md5('jpeg') . '_96_96.jpg', 900),
			$this->image('player/' . strtoupper(md5('upper')) . '_96_96.png', 900),
			$this->image('player/x' . self::png('prefixed'), 900),
			$this->image('player/' . self::png('suffixed') . '.bak', 900),
			$this->image('admin/' . self::png('line end') . "\n", 900),
			$this->image('player/' . md5('line end') . ".webp\n", 900),
		];
		// A directory is never a cached file, and no directory is entered.
		mkdir($this->rImages . 'player/' . self::png('dir'));
		$rKept[] = $this->image('player/' . self::png('dir') . '/' . self::png('inside'), 900);
		mkdir($this->rImages . 'admin/sub');
		$rKept[] = $this->image('admin/sub/' . self::png('nested'), 900);

		$rGone = $this->image('admin/' . self::png('cached'), 900);
		$this->assertSame(1, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, 0), 'even with nothing allowed to stay');
		$this->assertFileDoesNotExist($rGone);
		foreach ($rKept as $rPath) {
			$this->assertFileExists($rPath);
		}
	}

	public function testADirectoryOverItsShareLosesItsOldestFilesFirst(): void {
		$rPlayer = [];
		foreach ([4, 2, 3, 1] as $rDays) {
			$rPlayer[$rDays] = $this->image('player/' . self::png('p' . $rDays), $rDays, 1000);
		}
		// Each directory has its own share: these two fit in theirs.
		$rAdmin = [$this->image('admin/' . self::png('a1'), 20, 1000), $this->image('admin/' . md5('a2') . '.webp', 10, 1000)];
		// What is not the resizer's does not count towards it.
		$rOther = $this->image('player/index.html', 900, 5000);

		$this->assertSame(2, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, 2500));
		$this->assertFileDoesNotExist($rPlayer[4]);
		$this->assertFileDoesNotExist($rPlayer[3]);
		$this->assertFileExists($rPlayer[2]);
		$this->assertFileExists($rPlayer[1]);
		foreach ([...$rAdmin, $rOther] as $rPath) {
			$this->assertFileExists($rPath);
		}
		$this->assertSame(0, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, 2500), 'within its share: nothing more goes');
	}

	/**
	 * Small files never reach a directory's bytes: it holds no more than its
	 * number of files either. One with more than twice that many loses the
	 * oldest while it is read, and what stays is still the newest.
	 */
	public function testADirectoryOverItsNumberOfFilesLosesItsOldestFilesFirst(): void {
		$rPlayer = [];
		foreach ([7, 2, 10, 5, 1, 8, 3, 9, 4, 6] as $rDays) {
			$rPlayer[$rDays] = $this->image('player/' . self::png('p' . $rDays), $rDays);
		}
		// Each directory has its own share: these two fit in theirs.
		$rAdmin = [$this->image('admin/' . self::png('a1'), 20), $this->image('admin/' . md5('a2') . '.webp', 10)];
		// What is not the resizer's does not count towards it.
		$rOther = [$this->image('player/index.html', 900), $this->image('player/logo.png', 900)];

		$this->assertSame(7, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, PHP_INT_MAX, 3));
		foreach ($rPlayer as $rDays => $rPath) {
			$this->assertSame($rDays <= 3, file_exists($rPath), 'the file written ' . $rDays . ' days ago');
		}
		foreach ([...$rAdmin, ...$rOther] as $rPath) {
			$this->assertFileExists($rPath);
		}
		$this->assertSame(0, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, PHP_INT_MAX, 3), 'within its share: nothing more goes');

		// Whichever of the two shares is the smaller one decides.
		$this->assertSame(1, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, 40, 3));
		$this->assertFileDoesNotExist($rPlayer[3]);
		$this->assertSame(2, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW, 40, 1));
		$this->assertFileDoesNotExist($rPlayer[2]);
		$this->assertFileExists($rPlayer[1]);
		$this->assertFileDoesNotExist($rAdmin[0]);
		$this->assertFileExists($rAdmin[1]);
	}

	public function testAMissingCacheDirectoryIsNothingToPrune(): void {
		rmdir($this->rImages . 'player');
		$rOld = $this->image('admin/' . self::png('a'), 31);
		$this->assertSame(1, CleanupCronJob::pruneImageCaches($this->rImages, self::NOW));
		$this->assertFileDoesNotExist($rOld);
		$this->assertSame(0, CleanupCronJob::pruneImageCaches($this->rImages . 'none/', self::NOW));
	}

	/** The cron prunes MAIN's caches, where the panels that fill them run, after everything it did before. */
	public function testTheCronPrunesTheCachesUnderTheImagesPath(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/CleanupCronJob.php');
		$this->assertSame(1, preg_match('/if \(defined\(\'IMAGES_PATH\'\)\) \{\s*self::pruneImageCaches\(IMAGES_PATH\);\s*\}/', $rSource, $rMatch, PREG_OFFSET_CAPTURE), 'a pass without the constant prunes nothing');
		$this->assertGreaterThan(strpos($rSource, 'if (!NodeRole::isMain()) {'), $rMatch[0][1]);
		$this->assertGreaterThan(strrpos($rSource, 'self::prune($db,'), $rMatch[0][1]);
		$this->assertSame(1, substr_count($rSource, 'self::pruneImageCaches('), 'once, and no other path');
	}
}
