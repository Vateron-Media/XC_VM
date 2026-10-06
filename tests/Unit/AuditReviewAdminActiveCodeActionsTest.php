<?php

use PHPUnit\Framework\TestCase;

/**
 * The activation-code list and the batch manager open for a group that may
 * list the lines. Enabling, disabling, extending, resetting and deleting codes
 * ask for more: the right to edit a line, or to mass edit lines. A page offers
 * those actions only to a group that may run them; viewing a code and
 * exporting a batch stay with whoever may open the page. Group 1 and a group
 * that lists no permissions keep every action.
 *
 * A view is a template: it is rendered in a child PHP, without the layout
 * around it, and the actions are read from what it printed.
 */
final class AuditReviewAdminActiveCodeActionsTest extends TestCase {
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
	final class AuditReviewAdminLanguage {
		public static function get(string $rKey): string {
			return $rKey;
		}
	}

	require %BOOTSTRAP%;

	$rIn = json_decode($argv[1], true);
	$rUserInfo = ['id' => 9, 'member_group_id' => $rIn['group']];
	$rPermissions = ['is_admin' => 1, 'advanced' => $rIn['advanced']];
	$db = new stdClass();

	$language = AuditReviewAdminLanguage::class;
	$batches = [['batch_name' => 'B1', 'package_name' => 'P', 'creator_name' => 'admin', 'total_codes' => 1, 'stock_count' => 1, 'active_count' => 0, 'created_at' => 0]];
	$resellers = $rPackages = [];

	require MAIN_HOME . 'Public/Views/admin/' . $rIn['view'] . '.php';
}
PHP;

	private string $rChild;

	protected function setUp(): void {
		$this->rChild = sys_get_temp_dir() . '/xcvm-code-actions-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($this->rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		@unlink($this->rChild);
	}

	/**
	 * What a view prints for an administrator.
	 *
	 * @param array<string> $rAdvanced the permissions the administrator's group lists
	 */
	private function rendered(string $rView, array $rAdvanced, int $rGroup): string {
		$rIn = ['view' => $rView, 'advanced' => array_values($rAdvanced), 'group' => $rGroup];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rView);
		$this->assertStringContainsString('</html>', $rOut, $rView . ' was rendered to its end');

		return $rOut;
	}

	/** @return array<string, array{list<string>, int, bool}> the group's list, the group, whether it may change a code */
	public static function adminGroups(): array {
		return [
			'lists the lines only' => [['users'], 5, false],
			'may edit a line' => [['users', 'edit_user'], 5, true],
			'may mass edit lines' => [['users', 'mass_edit_lines'], 5, true],
			'group 1' => [['ticket'], 1, true],
			'lists no permissions' => [[], 5, true],
		];
	}

	/**
	 * @dataProvider adminGroups
	 * @param list<string> $rAdvanced
	 */
	public function testTheCodeListOffersItsActionsOnlyToAGroupThatMayRunThem(array $rAdvanced, int $rGroup, bool $rMayEdit): void {
		$rList = $this->rendered('active_codes', $rAdvanced, $rGroup);

		$this->assertStringContainsString('const canEdit = ' . ($rMayEdit ? 'true' : 'false') . ';', $rList);
		$this->assertSame($rMayEdit, str_contains($rList, 'id="mass-action-bar"'), 'the mass bar');
		$this->assertSame($rMayEdit, str_contains($rList, 'id="select-all"'), 'the select-all box');

		// The rows are drawn in the browser: the two cells that hold a control
		// which changes a code ask canEdit first.
		$this->assertMatchesRegularExpression('/function renderCheckbox\([^)]*\) \{\s*if \(!canEdit\) return \'\';/', $rList);
		$this->assertMatchesRegularExpression('/btn-view-code[^\n]*\n\s*\(!canEdit \? \'\' :\s*\n[^\n]*btn-toggle-code[^\n]*\n[^\n]*btn-reset-code-device[^\n]*\n[^\n]*btn-delete-code[^\n]*\) \+/', $rList);

		// The columns stay where the table endpoint orders them.
		$this->assertSame(12, preg_match_all('/<th[ >]/', $rList));
	}

	/**
	 * @dataProvider adminGroups
	 * @param list<string> $rAdvanced
	 */
	public function testTheBatchManagerOffersItsActionsOnlyToAGroupThatMayRunThem(array $rAdvanced, int $rGroup, bool $rMayEdit): void {
		$rBatches = $this->rendered('active_codes_batch', $rAdvanced, $rGroup);

		// Enable, disable and delete carry the batch name.
		$this->assertSame($rMayEdit ? 3 : 0, substr_count($rBatches, 'data-batch="'));
		// The export asks for what opens the page.
		$this->assertStringContainsString('action=active_codes_export_txt', $rBatches);
	}
}
