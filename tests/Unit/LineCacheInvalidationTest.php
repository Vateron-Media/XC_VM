<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Line\LineService;

final class LineCacheInvalidationTest extends TestCase {
	public function testBulkInvalidationRemovesOnlyLineRows(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-line-cache-' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir);
		try {
			foreach (['line_i_12', 'line_i_34', 'line_c_lookup', 'line_t_token', 'other'] as $rFile) {
				file_put_contents($rDir . $rFile, 'data');
			}
			LineService::invalidateCachedLines($rDir);
			$this->assertSame(['line_c_lookup', 'line_t_token', 'other'], array_values(array_diff(scandir($rDir), ['.', '..'])));
		} finally {
			foreach (array_diff(scandir($rDir), ['.', '..']) as $rFile) {
				unlink($rDir . $rFile);
			}
			rmdir($rDir);
		}
	}
}
