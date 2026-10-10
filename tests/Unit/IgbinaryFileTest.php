<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\IgbinaryFile;

/**
 * IgbinaryFile::read — a cache file the crons wrote reads as its array; one
 * that is missing, empty, corrupt or not an array reads as null.
 */
final class IgbinaryFileTest extends TestCase {
	private string $dir;

	protected function setUp(): void {
		if (!function_exists('igbinary_serialize')) {
			$this->markTestSkipped('igbinary is not installed');
		}
		$this->dir = sys_get_temp_dir() . '/xcvm-igb-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	protected function tearDown(): void {
		if (isset($this->dir)) {
			exec('rm -rf ' . escapeshellarg($this->dir));
		}
	}

	public function testAnArrayFileReadsAsItsArray(): void {
		file_put_contents($this->dir . '/stream_7', igbinary_serialize(['info' => ['id' => 7]]));
		$this->assertSame(['info' => ['id' => 7]], IgbinaryFile::read($this->dir . '/stream_7'));
	}

	public function testAMissingFileReadsAsNull(): void {
		$this->assertNull(IgbinaryFile::read($this->dir . '/stream_8'));
	}

	public function testAnEmptyOrCorruptFileReadsAsNull(): void {
		file_put_contents($this->dir . '/empty', '');
		file_put_contents($this->dir . '/corrupt', 'not igbinary');
		$this->assertNull(IgbinaryFile::read($this->dir . '/empty'));
		$this->assertNull(IgbinaryFile::read($this->dir . '/corrupt'));
	}

	public function testANonArrayValueReadsAsNull(): void {
		file_put_contents($this->dir . '/scalar', igbinary_serialize('text'));
		$this->assertNull(IgbinaryFile::read($this->dir . '/scalar'));
	}

	public function testADirectoryReadsAsNull(): void {
		$this->assertNull(IgbinaryFile::read($this->dir));
	}
}
