<?php

use PHPUnit\Framework\TestCase;

/**
 * The admin panel resizes an image through its `resize` route alone
 * (AdminResizeController, ImageResizeService), which every page asks for by
 * that name. The script that did the work by itself before the route existed
 * sits in a directory the web server runs scripts from, and an update copies
 * the new files over the installed ones without removing any: so the script
 * stays in the tree and holds no statement, and a panel that is updated gets
 * that copy in place of the one it has.
 */
final class AuditAdminMisc2ResizeScriptTest extends TestCase {
	private const SCRIPT = 'Public/Views/admin/resize.php';

	public function testTheScriptHoldsNoStatement(): void {
		$rSource = is_file(MAIN_HOME . self::SCRIPT) ? (string) file_get_contents(MAIN_HOME . self::SCRIPT) : '';

		$rCode = [];
		foreach (token_get_all($rSource) as $rToken) {
			if (!is_array($rToken) || !in_array($rToken[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
				$rCode[] = is_array($rToken) ? $rToken[1] : $rToken;
			}
		}

		$this->assertSame([], $rCode);
	}

	public function testThePanelResizesThroughItsRoute(): void {
		$this->assertStringContainsString("\$router->get('resize', [AdminResizeController::class, 'index']);", (string) file_get_contents(MAIN_HOME . 'Public/routes/admin.php'));
		$this->assertStringContainsString('ImageResizeService::serve(', (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/AdminResizeController.php'));

		$rByName = [];
		foreach (glob(MAIN_HOME . 'Public/Views/admin/*.php') ?: [] as $rFile) {
			if (str_contains((string) file_get_contents($rFile), 'resize.php')) {
				$rByName[] = basename($rFile);
			}
		}
		$this->assertSame([], $rByName, 'no page asks for the script by its file name');
	}
}
