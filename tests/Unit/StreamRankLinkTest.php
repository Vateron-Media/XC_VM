<?php

use PHPUnit\Framework\TestCase;

/**
 * A stream's name on Stream Rank opens that stream: the link carries the
 * stream's id, not the id of its statistics row.
 */
final class StreamRankLinkTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
namespace XcVm\Core\Util {
	final class LayoutRenderer {
		public static function renderFooter(string $rScope): void {
		}
	}
}

namespace {
	final class StreamRankLanguage {
		public static function get(string $rKey): string {
			return $rKey;
		}
	}

	require %BOOTSTRAP%;

	$rUserInfo = ['id' => 9, 'member_group_id' => 5];
	$rPermissions = ['is_admin' => 1, 'advanced' => ['streams']];
	$db = new stdClass();
	$language = StreamRankLanguage::class;
	$rPeriod = 'today';
	$rRows = [['id' => 17847, 'stream_id' => 1, 'stream_display_name' => 'News', 'time' => 3600, 'connections' => 4, 'users' => 2, 'rank' => 1]];

	require MAIN_HOME . 'Public/Views/admin/stream_rank.php';
}
PHP;

	public function testTheNameLinksTheStream(): void {
		$rChild = sys_get_temp_dir() . '/xcvm-stream-rank-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
		try {
			exec(implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), '-d', 'display_errors=stderr', $rChild])) . ' 2>&1', $rOut, $rCode);
		} finally {
			@unlink($rChild);
		}
		$rPage = implode("\n", $rOut);

		$this->assertSame(0, $rCode, $rPage);
		$this->assertStringContainsString('href="stream_view?id=1"', $rPage);
		$this->assertStringNotContainsString('17847', $rPage);
	}
}
