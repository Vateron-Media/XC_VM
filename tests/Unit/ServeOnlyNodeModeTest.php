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
		// A brand-new type-2 LB arms --serve-only from the mode persisted on the row.
		$this->assertStringContainsString("\$rServeOnlyTail = \$rServeOnly ? ['--serve-only'] : [];", $rService);
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

	public function testIsApiModeClassifierCoversBothFlavours(): void {
		// The one classifier the ad-hoc `=== 'api'` sites now route through.
		$this->assertTrue(ClusterSettings::isApiMode('api'));
		$this->assertTrue(ClusterSettings::isApiMode('serve_only'));
		$this->assertFalse(ClusterSettings::isApiMode('legacy'));
		$this->assertFalse(ClusterSettings::isApiMode(null));
		$this->assertFalse(ClusterSettings::isApiMode(''));
	}

	public function testSchemaAndMigrationHoldServeOnly(): void {
		// `serve_only` is 10 chars; a varchar(8) column could not store it.
		$rSchema = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		$this->assertMatchesRegularExpression('/`lb_new_node_mode` varchar\((1[0-9]|[2-9][0-9]|[1-9]\d{2,})\)/', $rSchema, 'the install schema holds >= 10 chars');
		$this->assertStringNotContainsString('`lb_new_node_mode` varchar(8)', $rSchema);
		$rMig = (string) @file_get_contents(MAIN_HOME . 'migrations/database/up/101_widen_lb_new_node_mode.sql');
		$this->assertStringContainsString('MODIFY COLUMN `lb_new_node_mode` varchar(16)', $rMig, 'a migration widens the existing column');
	}

	public function testClusterInitPreservesAChosenServeOnly(): void {
		// Enabling the cluster API must not rewrite a chosen serve_only to api.
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ClusterInitCommand.php');
		$this->assertStringContainsString('$rApi = ClusterSettings::isApiMode($rExisting) || (empty($rRow[\'redis_handler\']) && CredentialFreeConfig::supported());', $rCmd);
		$this->assertStringContainsString('$rNewMode = ClusterSettings::isApiMode($rExisting) ? $rExisting : ($rApi ? \'api\' : ($rExisting ?: \'legacy\'));', $rCmd);
	}

	public function testEnrolmentPutsServeOnlyInModeTwo(): void {
		// Both enrolment paths (install, approved code) go through begin(), which
		// must treat serve_only as mode 2, not only the literal 'api'.
		$rEnrol = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/EnrolmentService.php');
		$this->assertStringContainsString("\$rMode = ClusterSettings::isApiMode(ClusterSettings::enum('lb_new_node_mode', \$rSettings['lb_new_node_mode'] ?? null)) ? 2 : 1;", $rEnrol);
		$this->assertStringNotContainsString("=== 'api' ? 2 : 1", $rEnrol, 'the literal api-only mode-2 test is gone');
	}

	public function testNewInstallPersistsServeOnlyBeforeLaunch(): void {
		// The row carries the intended mode before the worker starts, so a retry
		// (which reads the row) and the worker both see serve-only.
		$rService = (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerService.php');
		$this->assertStringContainsString('$rServeOnly = intval($rData[\'type\']) === 2 && ClusterSettings::newNodesServeOnly(SettingsManager::getAll());', $rService);
		$this->assertStringContainsString('$rArray[\'serve_only\'] = $rServeOnly ? 1 : 0;', $rService);
		// The persist sits before the INSERT (so the row is set when the worker launches).
		// Anchor on strings unique to install(): the serve_only compute and the INSERT query.
		$this->assertLessThan(strpos($rService, 'INSERT INTO `servers`('), strpos($rService, '$rServeOnly = intval($rData[\'type\']) === 2 && ClusterSettings::newNodesServeOnly(SettingsManager::getAll());'));
	}

	public function testWorkerHonoursThePersistedMode(): void {
		// A global-mode change between queue and worker start cannot abort a
		// serve-only install: the worker reads the row's serve_only too.
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('$rApiMode = $rType == 2 && (LbInstallFlow::installsInApiMode(SettingsManager::getAll(), $rServerID) || !empty($rServers[$rServerID][\'serve_only\']));', $rCmd);
	}

	public function testReinstallKeepsPortsBeforeServeOnly(): void {
		// --serve-only is positional after the ports; the reinstall must not let it
		// become the first argument (parsed as port 0) when metadata has no ports.
		$rCtrl = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/Ajax/ServerAjaxController.php');
		$this->assertStringContainsString('if ($rTail === []) {', $rCtrl);
		$this->assertStringContainsString("\$rTail = [(string) intval(\$rServers[\$rServerID]['http_broadcast_port']), (string) intval(\$rServers[\$rServerID]['https_broadcast_port'])];", $rCtrl);
		// the fallback precedes the flag append
		$this->assertLessThan(strpos($rCtrl, "\$rTail[] = '--serve-only';"), strpos($rCtrl, 'if ($rTail === []) {'));
	}

	public function testDocsPublishServeOnly(): void {
		$rDocs = (string) file_get_contents(MAIN_HOME . '../docs/en/development/cluster-api.md');
		$this->assertStringContainsString('legacy \| api \| serve_only', $rDocs);
	}
}
