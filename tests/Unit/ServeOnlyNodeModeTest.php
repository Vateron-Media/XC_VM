<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;

/**
 * `lb_new_node_mode = serve_only` (one-binary LB, Milestone 1): a third new-node
 * mode. It is an API-mode flavour — credential-free, no DB — that also installs
 * the node PHP-free (server:install --serve-only): one Go binary, no http-nginx,
 * nginx_rtmp kept. So it carries the same two prerequisites as `api` (the cluster
 * API on, an extension that packs a credential-free config) and reads as API mode
 * everywhere newNodesInApiMode() is asked (no DB grant, credential-free config.enc).
 *
 * The global mode arms --serve-only for a BRAND-NEW type-2 LB (ServerService);
 * a reinstall/edit instead preserves the node's recorded serve_only, so a mode
 * change since its first install never silently flips a live node.
 */
final class ServeOnlyNodeModeTest extends TestCase {
	public function testServeOnlyIsAnApiModeFlavour(): void {
		// serve_only reads as API mode (credential-free) — same no-grant / no-DB path as api.
		$this->assertTrue(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'serve_only']));
		// …and is distinguishable from api for the --serve-only decision.
		$this->assertTrue(ClusterSettings::newNodesServeOnly(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'serve_only']));
		$this->assertFalse(ClusterSettings::newNodesServeOnly(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api']), 'api keeps PHP');
		$this->assertFalse(ClusterSettings::newNodesServeOnly(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy']));
		$this->assertFalse(ClusterSettings::newNodesServeOnly(['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'serve_only']), 'needs the cluster API, like api');
		$this->assertFalse(ClusterSettings::newNodesServeOnly(['lb_new_node_mode' => 'serve_only']));
	}

	public function testServeOnlyNeedsTheSamePrerequisitesAsApi(): void {
		$rMain = ['http_broadcast_port' => 80, 'https_broadcast_port' => 443];
		$rOn = ['cluster_api_enabled' => 1];
		$rAllowed = ['extension_ok' => true, 'credential_free_config' => true];

		// Accepted only with the cluster API on and a credential-free-config extension.
		$this->assertSame([['lb_new_node_mode' => 'serve_only'], []], ClusterSettings::normalize(['lb_new_node_mode' => 'serve_only'], $rMain, $rOn, $rAllowed));
		// No extension → refused, same error as api.
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_extension']]], ClusterSettings::normalize(['lb_new_node_mode' => 'serve_only'], $rMain, $rOn, ['credential_free_config' => false]));
		// Cluster API off → refused, same error as api.
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_api_off']]], ClusterSettings::normalize(['lb_new_node_mode' => 'serve_only'], $rMain, ['cluster_api_enabled' => 0], $rAllowed));
		// serve_only is an allowed enum value (not coerced back to the default).
		$this->assertSame('serve_only', ClusterSettings::enum('lb_new_node_mode', 'serve_only'));
	}

	public function testNewInstallArmsServeOnlyFromTheGlobalMode(): void {
		$rService = (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerService.php');
		// A brand-new type-2 LB takes --serve-only from the global new-node mode.
		$this->assertStringContainsString("\$rServeOnlyTail = (intval(\$rData['type']) === 2 && ClusterSettings::newNodesServeOnly(SettingsManager::getAll())) ? ['--serve-only'] : [];", $rService);
		// An edit/reinstall instead preserves the node's own recorded mode.
		$this->assertStringContainsString("\$rServeOnlyTail = (intval(\$rData['type']) === 2 && !empty(\$rServer['serve_only'])) ? ['--serve-only'] : [];", $rService);
		// The flag rides the type-2 tail (positional, after the ports; the command reads --serve-only from args 6+).
		$this->assertSame(2, substr_count($rService, "['80', '443', (string) intval(\$rUpdateSysctl), ...\$rServeOnlyTail]"), 'both the new-install and edit type-2 tails spread the flag');
	}

	public function testReinstallPreservesTheNodesServeOnlyMode(): void {
		$rCtrl = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/Ajax/ServerAjaxController.php');
		$this->assertStringContainsString("if (\$rType === 2 && !empty(\$rServers[\$rServerID]['serve_only'])) {", $rCtrl);
		$this->assertStringContainsString("\$rTail[] = '--serve-only';", $rCtrl);
	}

	public function testTheSettingsUiOffersServeOnly(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		$this->assertStringContainsString("['lb_new_node_mode', ['legacy', 'api', 'serve_only'],", $rView);
	}
}
