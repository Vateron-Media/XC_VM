<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;

/** The first-run setup renders the real admin header before an admin user exists. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SetupHeaderTest extends TestCase {
	public function testSetupHeaderRendersWithUndefinedUserContext(): void {
		$this->assertFirstRunHeader(false);
	}

	public function testSetupHeaderRendersWithNullUserContext(): void {
		$this->assertFirstRunHeader(true);
	}

	private function assertFirstRunHeader(bool $rExplicitNull): void {
		$rSettings = ['server_name' => 'Fresh install', 'update_data' => '{}'];
		SettingsManager::set($rSettings);
		$GLOBALS['_SETUP'] = true;
		if ($rExplicitNull) {
			$rUserInfo = null;
		}

		ob_start();
		try {
			require MAIN_HOME . 'Public/Views/admin/header.php';
			$rHtml = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}

		$this->assertSame(['theme' => 'light', 'rtl' => false], $xmUiPrefs);
		$this->assertSame('light', $xmBsTheme);
		$this->assertStringContainsString('data-bs-theme="light"', $rHtml);
		$this->assertStringContainsString('window.XC_VM_UIPrefs = {"theme":"light","rtl":false};', $rHtml);
		$this->assertStringContainsString('Fresh install', $rHtml);
		$this->assertStringNotContainsString('id="navbar-collapse"', $rHtml);
	}
}
