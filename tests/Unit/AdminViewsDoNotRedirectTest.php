<?php

use PHPUnit\Framework\TestCase;

/**
 * A view BaseAdminController::render() shows comes after the layout header:
 * the headers are sent by then, so a redirect from one is refused and the page
 * stops at an empty shell. Import & Review ran its import, and the redirect
 * that follows it, there.
 */
class AdminViewsDoNotRedirectTest extends TestCase {
	public function testNoViewShownInsideTheLayoutRedirects(): void {
		$rViews = [];
		foreach (glob(MAIN_HOME . 'Public/Controllers/Admin/*.php') as $rFile) {
			preg_match_all('/->render\(\s*\'([\w\/]+)\'/', (string) file_get_contents($rFile), $rMatches);
			$rViews = array_merge($rViews, $rMatches[1]);
		}
		$rViews = array_unique($rViews);

		$rRedirecting = [];
		foreach ($rViews as $rView) {
			$rFile = MAIN_HOME . 'Public/Views/admin/' . $rView . '.php';
			if (is_file($rFile) && preg_match('/\bheader\(\s*[\'"]Location/i', (string) file_get_contents($rFile))) {
				$rRedirecting[] = $rView;
			}
		}

		$this->assertGreaterThan(100, count($rViews));
		$this->assertSame([], $rRedirecting);
	}
}
