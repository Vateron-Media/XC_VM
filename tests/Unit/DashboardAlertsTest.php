<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\DashboardAlerts;
use XcVm\Tests\Support\InstallSchema;

final class DashboardAlertsTest extends TestCase {
	public function testDefaultsEnableFourLatestAlertsAndTwentyHistoryEntries(): void {
		$this->assertSame(['latest_enabled' => true, 'latest_count' => 4, 'history_enabled' => true, 'history_count' => 20], DashboardAlerts::options([]));
	}

	public function testSwitchesAndCountsAreIndependentAndBounded(): void {
		$this->assertSame(['latest_enabled' => false, 'latest_count' => 1, 'history_enabled' => true, 'history_count' => 200], DashboardAlerts::options([
			'dashboard_alerts' => '0', 'dashboard_alerts_count' => -9,
			'dashboard_alert_history' => '1', 'dashboard_alert_history_count' => 9999,
		]));
		$this->assertSame(['latest_enabled' => true, 'latest_count' => 50, 'history_enabled' => false, 'history_count' => 20], DashboardAlerts::options([
			'dashboard_alerts' => '1', 'dashboard_alerts_count' => 100,
			'dashboard_alert_history' => '0', 'dashboard_alert_history_count' => 'invalid',
		]));
	}
	public function testFreshInstallPutsPreferencesOnSettingsAndNotSignals(): void {
		foreach (array_keys(DashboardAlerts::values([])) as $rKey) {
			$this->assertContains($rKey, InstallSchema::columns('settings'));
			$this->assertNotContains($rKey, InstallSchema::columns('signals'));
		}
	}

	public function testStaleFormSubmitsOnlyThePreferencesActuallyEdited(): void {
		$rOriginal = DashboardAlerts::values([]);
		$rForm = $rOriginal + ['submit_settings' => 1, 'dashboard_alerts_original' => json_encode($rOriginal)];
		$this->assertSame([], DashboardAlerts::submitted($rForm));
		$rForm['dashboard_alert_history_count'] = 30;
		$this->assertSame(['dashboard_alert_history_count' => 30], DashboardAlerts::submitted($rForm));
		unset($rForm['dashboard_alerts'], $rForm['dashboard_alert_history']);
		$this->assertEquals(['dashboard_alert_history_count' => 30, 'dashboard_alerts' => 0, 'dashboard_alert_history' => 0], DashboardAlerts::submitted($rForm));
		$rForm['dashboard_alerts_original'] = 'broken';
		$this->assertSame([], DashboardAlerts::submitted($rForm));
	}

	public function testMigrationIsRepeatableAndRollbackKeepsOtherSettings(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `settings` (`id` int PRIMARY KEY, `search_items` int DEFAULT 15);');
		$rDb->exec('INSERT INTO `settings` (`id`) VALUES (1);');
		$rUp = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/097_add_dashboard_alert_preferences.sql');
		$rDb->exec($rUp);
		$rDb->exec($rUp);
		$rDb->query('SELECT * FROM `settings`;');
		$rSettings = $rDb->get_row();
		$this->assertSame(['latest_enabled' => true, 'latest_count' => 4, 'history_enabled' => true, 'history_count' => 20], DashboardAlerts::options($rSettings));
		$rDb->exec('UPDATE `settings` SET `dashboard_alerts` = 0, `dashboard_alert_history_count` = 35;');
		$rDb->exec($rUp);
		$rDb->query('SELECT * FROM `settings`;');
		$this->assertSame(35, (int) $rDb->get_row()['dashboard_alert_history_count']);
		$rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/down/097_add_dashboard_alert_preferences.sql'));
		$rDb->query('SELECT * FROM `settings`;');
		$this->assertEquals(['id' => 1, 'search_items' => 15], $rDb->get_row());
	}
}
