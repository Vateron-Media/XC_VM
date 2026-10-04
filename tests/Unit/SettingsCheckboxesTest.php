<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Domain\Server\SettingsService;

/**
 * An unchecked box posts nothing, so a full settings save stores 0 only for the
 * boxes SettingsService::checkboxes() names. `lb_lease_fence` was missing from
 * that list: the switch could be turned on from the page and never off.
 */
final class SettingsCheckboxesTest extends TestCase {
	public function testEveryCheckboxOnTheSettingsPageIsOneAFullSaveTurnsOff(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		preg_match_all('/<input[^>]*name="([a-z0-9_]+)"[^>]*type="checkbox"|<input[^>]*type="checkbox"[^>]*name="([a-z0-9_]+)"/', $rView, $rStatic);
		preg_match_all("/\\['([a-z0-9_]+)', 'switch'/", $rView, $rCluster);
		$rBoxes = array_filter(array_merge($rStatic[1], $rStatic[2], $rCluster[1]));
		$this->assertContains('lb_lease_fence', $rBoxes);

		// responsive_tables is stored inverted, in disable_table_responsive (edit()).
		$rMissing = array_diff($rBoxes, SettingsService::checkboxes(), ['responsive_tables']);
		$this->assertSame([], array_values(array_unique($rMissing)));
	}

	public function testTheClusterSwitchesAreItsOnOffSettings(): void {
		$this->assertSame(['cluster_api_enabled', 'lb_lease_fence', 'lb_digest_nonce_required', 'cluster_kill_on_line_disable', 'cluster_db_allowlist'], ClusterSettings::switches());
	}
}
