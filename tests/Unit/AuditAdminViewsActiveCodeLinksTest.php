<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Reference\PermissionReference;

/**
 * The four activation-code pages link to each other, and each has a rule of
 * its own: generating codes, the list and the batch manager, and the mass edit
 * ask for three different permissions. A page offers an administrator whose
 * group lists advanced permissions only the pages that group may open, and
 * after a mass edit sends it to one of them. Group 1 and a group that lists
 * none keep every link.
 *
 * A view is a template: it is rendered in a child PHP, without the layout
 * around it, and the pages it leads to are read from what it printed.
 */
final class AuditAdminViewsActiveCodeLinksTest extends TestCase {
	/** The activation-code pages, each with the permission its rule asks for (PageAuthorization). */
	private const PAGES = [
		'active_code' => 'add_user',
		'active_codes' => 'users',
		'active_codes_batch' => 'users',
		'active_codes_mass' => 'mass_edit_lines',
	];

	private const CHILD = <<<'PHP'
<?php
namespace XcVm\Core\Util {
	/** The view closes the layout itself: there is none around it here. */
	final class LayoutRenderer {
		public static function renderFooter(string $rScope): void {
		}
	}
}

namespace {
	/** A view's `$language`: every text is its key. */
	final class AuditAdminViewsLanguage {
		public static function get(string $rKey): string {
			return $rKey;
		}
	}

	require %BOOTSTRAP%;

	$rIn = json_decode($argv[1], true);
	$rUserInfo = ['id' => 9, 'member_group_id' => $rIn['group']];
	$rPermissions = ['is_admin' => 1, 'advanced' => $rIn['advanced']];
	$db = new stdClass();

	$language = AuditAdminViewsLanguage::class;
	$batches = [['batch_name' => 'B1', 'package_name' => 'P', 'creator_name' => 'admin', 'total_codes' => 1, 'stock_count' => 1, 'active_count' => 0, 'created_at' => 0]];
	$resellers = $rResellers = $rPackages = $rBouquets = [];

	require MAIN_HOME . 'Public/Views/admin/' . $rIn['view'] . '.php';
}
PHP;

	private string $rChild;

	protected function setUp(): void {
		$this->rChild = sys_get_temp_dir() . '/xcvm-code-links-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($this->rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		@unlink($this->rChild);
	}

	/**
	 * The activation-code pages a view leads an administrator to, by a link or
	 * by sending the browser there, the page itself left out.
	 *
	 * @param array<string> $rAdvanced the permissions the administrator's group lists
	 * @return list<string>
	 */
	private function leadsTo(string $rView, array $rAdvanced, int $rGroup = 5): array {
		$rIn = ['view' => $rView, 'advanced' => array_values($rAdvanced), 'group' => $rGroup];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rView);
		$this->assertStringContainsString('</html>', $rOut, $rView . ' was rendered to its end');

		preg_match_all('/(?:\bhref="|\blocation\.href = [\'"])(active_codes?(?:_[a-z]+)?)\b/', $rOut, $rLinks);
		$rPages = array_values(array_unique(array_diff($rLinks[1], [$rView])));
		sort($rPages);
		return $rPages;
	}

	/** @return list<string> the other activation-code pages, in the order leadsTo() gives them */
	private function others(string $rView): array {
		$rPages = array_values(array_diff(array_keys(self::PAGES), [$rView]));
		sort($rPages);
		return $rPages;
	}

	public function testAPageOffersOnlyThePagesTheGroupMayOpen(): void {
		$rRefused = [];
		foreach (self::PAGES as $rView => $rKey) {
			// The group holds what opens this page, and nothing else.
			foreach ($this->leadsTo($rView, [$rKey]) as $rPage) {
				if (self::PAGES[$rPage] !== $rKey) {
					$rRefused[] = $rView . ' leads to ' . $rPage . ' without ' . self::PAGES[$rPage];
				}
			}
		}
		$this->assertSame([], $rRefused);
	}

	public function testAPageOffersEveryPageTheGroupMayOpen(): void {
		// What each page leads to when nothing is held back.
		$rLeads = [
			'active_code' => ['active_codes', 'active_codes_batch'],
			'active_codes' => $this->others('active_codes'),
			'active_codes_batch' => ['active_code', 'active_codes'],
			'active_codes_mass' => ['active_codes'],
		];
		foreach ($rLeads as $rView => $rPages) {
			$this->assertSame($rPages, $this->leadsTo($rView, PermissionReference::keys()), $rView . ' with every permission');
			$this->assertSame($rPages, $this->leadsTo($rView, ['ticket'], 1), $rView . ' for group 1');
			$this->assertSame($rPages, $this->leadsTo($rView, []), $rView . ' for a group that lists no permissions');

			// One permission more than the page's own opens the pages it guards.
			foreach (array_diff(array_unique(self::PAGES), [self::PAGES[$rView]]) as $rKey) {
				$rOpened = array_values(array_filter($rPages, static fn(string $rPage): bool => in_array(self::PAGES[$rPage], [$rKey, self::PAGES[$rView]], true)));
				$this->assertSame($rOpened, $this->leadsTo($rView, [self::PAGES[$rView], $rKey]), $rView . ' with ' . $rKey);
			}
		}
	}

	public function testAMassEditEndsOnAPageTheGroupMayOpen(): void {
		// The mass edit sends the browser on when it is done: to the list, or back here.
		$this->assertSame([], $this->leadsTo('active_codes_mass', ['mass_edit_lines']));
		$this->assertSame(['active_codes'], $this->leadsTo('active_codes_mass', ['mass_edit_lines', 'users']));
	}
}
