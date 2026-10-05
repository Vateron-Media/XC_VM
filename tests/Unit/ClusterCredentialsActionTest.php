<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterStripCredentialsCommand;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Phase 9's operator triggers: "Drop DB credentials" and "Pin core" on the
 * Cluster Nodes page, and `cluster:strip-credentials`. Only an active node in
 * mode 2 is asked to drop MAIN's credentials, the page and the CLI both ask
 * for confirmation, and every attempt is audited. The signed channel is not
 * set up here, so a command that passes the checks is "not queued" — and
 * never falls back to a `signals` row.
 */
final class ClusterCredentialsActionTest extends TestCase {
	private TestDb $rDb;

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 2, `flows` int NOT NULL DEFAULT 2, `root_ready` int NOT NULL DEFAULT 1, `gen` int NOT NULL DEFAULT 1, `install_id` varchar(64) DEFAULT NULL, `db_revoked_at` int DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->exec("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`) VALUES (7, '0f8fad5b-d9cb-469f-a165-70867728950e')");
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterMeta::set('panel_sign_pub', base64_encode(str_repeat("\x11", 32)));
		$this->rSettingsBefore = SettingsManager::getAll();
		SettingsManager::set(['cluster_api_enabled' => 1]);
	}

	protected function tearDown(): void {
		ClusterStripCredentialsCommand::useAsk(null);
		SettingsManager::set($this->rSettingsBefore);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function act(string $rAction): array {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0], 7 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'lb-7']];
		return ClusterAdmin::act(new FakeClusterCrypto(), ['cluster_action' => $rAction, 'server_id' => 7], $rServers, 1, [], 3);
	}

	private function audit(): array {
		$this->rDb->query('SELECT `event`, `actor` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): string => $rRow['event'] . ' ' . $rRow['actor'], $this->rDb->get_rows());
	}

	private function cli(array $rArgs): array {
		ob_start();
		$rCode = (new ClusterStripCredentialsCommand())->execute($rArgs);
		return [$rCode, (string) ob_get_clean()];
	}

	public function testThePageStripsOnlyAnActiveMode2Node(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 1');
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_strip_needs_mode2'], $this->act('strip_credentials'));
		$this->assertSame([], $this->audit());
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 2');
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_strip_not_queued'], $this->act('strip_credentials'));
		$this->assertSame(['node.strip_credentials admin:3'], $this->audit());
		try {
			$this->rDb->query('SELECT 1 FROM `signals`');
			$this->fail('no signals row: the table was never needed');
		} catch (\PDOException $rE) {
			$this->assertStringContainsStringIgnoringCase('signals', $rE->getMessage());
		}
	}

	/**
	 * The one step with no way back waits: for seven days in mode 2, counted
	 * from when the page moved the node there (the move itself no longer
	 * waits), and for a node that says it reads its streams on itself, since
	 * mode down is how that heals and it needs these credentials. A node MAIN
	 * holds no such record for (born in mode 2) is asked as before.
	 */
	public function testAStripWaitsForSevenDaysInModeTwoAndForLocalStreams(): void {
		(new \ReflectionProperty(\XcVm\Domain\Cluster\NodeAudit::class, 'db'))->setValue(null, null);
		ClusterMeta::set(ClusterAdmin::MODE2_AT . 7, (string) (ClusterClock::now() - 7 * 86400 + 60));
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_strip_too_soon'], $this->act('strip_credentials'));
		[$rCode, $rOut] = $this->cli(['7', '--yes']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('not been in mode 2 for seven days', $rOut);
		$this->assertSame([], $this->audit(), 'nothing was asked of the node');

		ClusterMeta::set(ClusterAdmin::MODE2_AT . 7, (string) (ClusterClock::now() - 7 * 86400));
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_strip_not_queued'], $this->act('strip_credentials'), 'seven days: asked (no signed channel here)');

		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `audit` text');
		$this->rDb->exec('UPDATE `cluster_nodes` SET `audit` = \'{"settings_misses":{},"streams_local":false}\'');
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_strip_not_local'], $this->act('strip_credentials'));
		[$rCode, $rOut] = $this->cli(['7', '--yes']);
		$this->assertStringContainsString('does not read its streams on itself', $rOut);
		$this->rDb->exec('UPDATE `cluster_nodes` SET `audit` = \'{"settings_misses":{},"streams_local":true}\'');
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_strip_not_queued'], $this->act('strip_credentials'));
		$this->assertSame(['node.strip_credentials admin:3', 'node.strip_credentials admin:3'], $this->audit());
	}

	public function testThePagePinsTheCoreOnlyForARootReadyNode(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `root_ready` = 0');
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_pin_core_no_root'], $this->act('pin_core'));
		$this->rDb->exec('UPDATE `cluster_nodes` SET `root_ready` = 1');
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_pin_core_not_queued'], $this->act('pin_core'));
	}

	public function testTheNodeListCarriesTheNewState(): void {
		$rNode = ClusterAdmin::nodes([7 => ['server_name' => 'lb-7']], 30)[0];
		$this->assertNull($rNode['db_revoked_at']);
		$this->assertFalse($rNode['core_pinned']);
		$this->assertArrayNotHasKey('node_sign_pub', $rNode);
		$this->rDb->exec('UPDATE `cluster_nodes` SET `db_revoked_at` = 1800000000');
		$this->assertSame(1800000000, ClusterAdmin::nodes([], 30)[0]['db_revoked_at']);
	}

	public function testTheCliConfirmsBeforeItSends(): void {
		[$rCode, $rOut] = $this->cli(['7']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('No terminal to confirm on: pass --yes.', $rOut);
		ClusterStripCredentialsCommand::useAsk(static fn(string $rPrompt): string => "8\n");
		[$rCode, $rOut] = $this->cli(['7']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Not confirmed: nothing sent.', $rOut);
		$this->assertSame([], $this->audit(), 'nothing sent, nothing audited');

		ClusterStripCredentialsCommand::useAsk(static fn(string $rPrompt): string => "7\n");
		[$rCode, $rOut] = $this->cli(['7']);
		$this->assertSame(1, $rCode, 'confirmed; no signed channel in this suite');
		$this->assertStringContainsString('could not be queued', $rOut);
		$this->assertSame(['node.strip_credentials cli'], $this->audit());
	}

	public function testTheCliRefusesWhatThePageRefuses(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 1');
		[$rCode, $rOut] = $this->cli(['7', '--yes']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Only a node in mode 2', $rOut);
		[$rCode, $rOut] = $this->cli(['99', '--yes']);
		$this->assertStringContainsString('no enrolled node', $rOut);
		[$rCode, $rOut] = $this->cli([]);
		$this->assertStringContainsString('Usage: cluster:strip-credentials', $rOut);
		SettingsManager::set(['cluster_api_enabled' => 0]);
		[$rCode, $rOut] = $this->cli(['7', '--yes']);
		$this->assertStringContainsString('The cluster API is off', $rOut);
	}

	public function testAnAlreadyRevokedNodeIsReported(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `db_revoked_at` = 1800000000');
		[$rCode, $rOut] = $this->cli(['7', '--yes']);
		$this->assertSame(0, $rCode);
		$this->assertStringContainsString('already gave up', $rOut);
		$this->assertSame(1800000000, DbCredentials::revokedAt(7));
	}

	public function testThePageHasTheButtonsTheConfirmationAndTheStrings(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/cluster_nodes.php');
		$this->assertStringContainsString('value="strip_credentials"', $rView);
		$this->assertStringContainsString('js-cluster-strip', $rView);
		$this->assertStringContainsString("cluster_strip_credentials_confirm", $rView, 'the page asks first');
		$this->assertStringContainsString('value="pin_core"', $rView);
		$rKeys = ['cluster_core_pinned_help', 'cluster_pin_core', 'cluster_pin_core_help', 'cluster_pin_core_queued', 'cluster_pin_core_no_root', 'cluster_pin_core_no_root_key', 'cluster_pin_core_not_queued',
			'cluster_strip_credentials', 'cluster_strip_credentials_help', 'cluster_strip_credentials_confirm', 'cluster_strip_queued', 'cluster_strip_needs_mode2', 'cluster_strip_not_active', 'cluster_strip_not_queued',
			'cluster_db_revoked', 'cluster_db_revoked_help', 'cluster_config_needs_install_id', 'cluster_config_no_extension', 'cluster_config_not_queued'];
		// en.ini only: the other languages are generated from it (make lang-translate),
		// and a key they miss falls back to English.
		$rIni = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');
		foreach ($rKeys as $rKey) {
			$this->assertMatchesRegularExpression('/^' . $rKey . ' = "[^"]+"$/m', $rIni, 'en.ini: ' . $rKey);
		}
		foreach (ClusterStripCredentialsCommand::MESSAGES as $rKey => $rText) {
			$this->assertMatchesRegularExpression('/^' . $rKey . ' = /m', (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini'), $rKey);
		}
	}
}
