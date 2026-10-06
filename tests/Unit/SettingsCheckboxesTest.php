<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Domain\Server\SettingsService;
use XcVm\Tests\Support\InstallSchema;

/**
 * An unchecked box posts nothing, so a full settings save stores 0 only for the
 * boxes SettingsService::checkboxes() names. `lb_lease_fence` was missing from
 * that list: the switch could be turned on from the page and never off. Every
 * name in that list is a box of the page, and every setting the page offers
 * has a reader: eleven fields once changed nothing at all.
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

	/** The settings page's boxes, the cluster switches included. */
	private static function boxes(): array {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		preg_match_all('/<input[^>]*name="([a-z0-9_]+)"[^>]*type="checkbox"|<input[^>]*type="checkbox"[^>]*name="([a-z0-9_]+)"/', $rView, $rStatic);
		preg_match_all("/\\['([a-z0-9_]+)', 'switch'/", $rView, $rCluster);
		return array_values(array_unique(array_filter(array_merge($rStatic[1], $rStatic[2], $rCluster[1]))));
	}

	public function testEveryNameAFullSaveTurnsOffIsABoxOfThePage(): void {
		$this->assertSame([], array_values(array_diff(SettingsService::checkboxes(), self::boxes())));
	}

	/** A setting the page offers whose reader is gone, kept on purpose: field => why. */
	private const READERLESS = [
		'reseller_ssl_domain' => 'its reader was lost in 2.5.0 (the reseller playlist link); to be restored, not removed',
	];

	public function testEverySettingThePageOffersIsReadSomewhere(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		preg_match_all('/name="([a-z0-9_]+)"/', $rView, $rNames);
		$rFields = array_intersect(array_unique($rNames[1]), InstallSchema::columns('settings'));
		$this->assertGreaterThan(100, count($rFields), 'the settings fields of the page');

		$rSource = '';
		$rFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAIN_HOME, FilesystemIterator::SKIP_DOTS));
		foreach ($rFiles as $rFile) {
			$rPath = substr($rFile->getPathname(), strlen(MAIN_HOME));
			if (preg_match('/\\.(php|js|py|sh)$/', $rPath) && !preg_match('#^(vendor|migrations|bin|Core/Localization/lang)/#', $rPath) && !in_array($rPath, ['Public/Views/admin/settings.php', 'Domain/Server/SettingsService.php'], true)) {
				$rSource .= file_get_contents($rFile->getPathname()) . "\n";
			}
		}

		$rUnread = array_values(array_filter($rFields, static fn(string $rField): bool => !isset(self::READERLESS[$rField]) && !preg_match('/\\b' . $rField . '\\b/', $rSource)));
		$this->assertSame([], $rUnread, 'settings the page offers that nothing reads');
	}
}
