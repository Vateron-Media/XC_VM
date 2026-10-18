<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Domain\Server\InstallCredentials;

/**
 * Phase A (MAIN side): a load balancer installed with `server:install
 * --serve-only` is marked serve-only — its `servers.serve_only` is set, its
 * config.enc carries a `serve_only` marker (so the node's run.sh threads the
 * agent/fanout flags), and its held streams' versions are bumped so the next
 * delta re-emits their directives with a transcode Spec (B1/B2).
 *
 * The node side (skip nginx/php-fpm, launch xc_node) and the end-to-end run
 * need a real LB and are not covered here; the SSH-driven execute() flow is
 * checked by source assertion, as ApiModeInstallTest checks its threading.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 */
final class ServeOnlyInstallTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	public function testConfigPackCarriesServeOnlyMarkerOnlyWhenServeOnly(): void {
		$rServers = [1 => ['server_ip' => '10.0.0.1'], 5 => []];

		$rOn = LbInstallFlow::configPackParams($rServers, 5, true);
		$this->assertSame(1, $rOn['serve_only'] ?? null, 'a serve-only install packs serve_only=1');
		$this->assertSame(1, $rOn['is_lb'] ?? null, 'it is still a load balancer');

		$rOff = LbInstallFlow::configPackParams($rServers, 5, false);
		$this->assertArrayNotHasKey('serve_only', $rOff, 'a legacy LB config is byte-identical (no marker)');
	}

	public function testServeOnlyFlagStaysPositionalForInArrayMatch(): void {
		// `--serve-only` is a valueless flag: splitOptions lifts only --name=value
		// into the options map, so the flag stays in the positional list and the
		// command matches it there with in_array() — the same convention
		// cluster:reenrol uses for --all/--dry-run/--pending. Routing valueless
		// flags into $rOptions instead broke cluster:reenrol's unknown-option check.
		$rArgs = ['2', '2', '22', '-', '-', '80', '443', '0', '--serve-only', '--cred-file=/x'];
		[$rPositional, $rOptions] = InstallCredentials::splitOptions($rArgs);

		$this->assertContains('--serve-only', $rPositional, 'the valueless flag stays positional for an in_array() match');
		$this->assertArrayNotHasKey('serve-only', $rOptions, 'it is not routed into the options map');
		$this->assertSame('/x', $rOptions['cred-file'] ?? null, '--name=value options still parse into the map');
		$this->assertTrue(in_array('--serve-only', $rPositional, true), 'so the command arms serve_only via in_array');
	}

	public function testServerInstallCommandWiresServeOnly(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString("\$rServeOnly = \$rType == 2 && in_array('--serve-only', \$rArgs, true);", $rSrc, 'the --serve-only flag is matched from the positional args for an LB');
		$this->assertStringContainsString('`serve_only` = ?', $rSrc, 'the final success UPDATE sets serve_only');
		$this->assertStringContainsString('StreamVersions::bump(StreamRecords::held($rServerID', $rSrc, 'B2: a serve_only install re-stamps its held streams');
		$this->assertMatchesRegularExpression('/try \{\s*StreamVersions::bump/', $rSrc, 'B2 is fault-tolerant: the non-essential re-stamp is wrapped so a DB hiccup does not fail the install');
		$this->assertStringContainsString('$db, $rApiMode, $rServeOnly)', $rSrc, 'serve_only threads into provisionConfig');
	}

	public function testProvisionConfigThreadsServeOnlyToConfigPack(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('self::configPackParams($rServers, $rServerID, $rServeOnly)', $rSrc, 'provisionConfig passes serve_only to configPackParams');
	}

	public function testProvisionConfigWritesTheServeOnlyMarker(): void {
		// The node's run.sh is shell and the serve path has no PHP to decrypt
		// config.enc, so a serve-only install writes a plaintext marker whose
		// content is the server id, under config/cluster/.
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString("self::sudoWrite((string) \$rServerID, CONFIG_PATH . 'cluster/serve_only')", $rSrc);
		// Downgrade safety: a non-serve-only (re)install must REMOVE a stale marker,
		// so the off-path undoes the on-path (config/ survives a re-install).
		$this->assertStringContainsString("sudo rm -f ' . CONFIG_PATH . 'cluster/serve_only'", $rSrc, 'a non-serve-only install clears the marker');
	}

	public function testServeOnlyNginxListenerIsOwnedByRootSignalsNotTheInstall(): void {
		// The :443->:8080 demote is NOT written at install: an unconditional
		// install-time demote caused an outage on a -front-less binary and was
		// reverted by the node's port-reconcile cron within ~60s. configureRuntime
		// only sets the loopback real-IP trust and records the front's listen
		// address; RootSignalsCronJob owns the serve-only https listener — it demotes
		// to the loopback backend only once the fanout is -front-capable (else keeps
		// the public ssl port, so no outage) and never reconciles the demote to ssl.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('bool $rServeOnly = false', $rLb, 'configureRuntime takes the serve_only flag');
		$this->assertStringNotContainsString("sudoWrite('listen 8080", $rLb, 'the demote is NOT written at install — the cron owns it');
		$this->assertStringContainsString('set_real_ip_from 127.0.0.1;', $rLb, 'configureRuntime sets the loopback real-IP trust');
		$this->assertStringContainsString("cluster/front_addr'", $rLb, 'configureRuntime records the front listen address (https_broadcast_port)');

		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("is_file(CONFIG_PATH . 'cluster/serve_only')", $rCron, 'the cron is serve_only-aware, strictly gated on the marker');
		$this->assertStringContainsString('grep -q -- -front', $rCron, 'the demote is gated on the fanout being -front-capable (no outage otherwise)');
		$this->assertStringContainsString("'backend' => true", $rCron, 'serve_only enqueues the loopback-backend https listener');
		$this->assertStringContainsString("'listen 127.0.0.1:8080;'", $rCron, 'the backend listener is loopback plaintext :8080');
		$this->assertStringContainsString("cluster/front_addr'", $rCron, 'the cron keeps the front listen address current for run.sh');

		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('LbInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rServerID, $rServeOnly)', $rCmd, 'the install threads serve_only into configureRuntime');
	}

	public function testRunShLaunchersThreadServeOnlyFlagsOnTheMarker(): void {
		$rFanout = (string) file_get_contents(MAIN_HOME . 'bin/xc_fanout/run.sh');
		$this->assertStringContainsString('config/cluster/serve_only', $rFanout, 'fanout run.sh gates the front on the marker');
		$this->assertStringContainsString('-front', $rFanout, 'fanout run.sh launches the Go front for a serve-only node');
		$this->assertStringContainsString('-front-nginx', $rFanout, 'the front strangler proxies non-serve requests to nginx');

		$this->assertStringContainsString("grep -q -- '-front'", $rFanout, 'the front is gated on the fanout binary knowing -front');
		$this->assertStringContainsString('config/cluster/front_addr', $rFanout, 'the front listens on the node https_broadcast_port (front_addr), not a hardcoded :443');

		$rAgent = (string) file_get_contents(MAIN_HOME . 'bin/xc_agent/run.sh');
		$this->assertStringContainsString('config/cluster/serve_only', $rAgent, 'agent run.sh gates seeding on the marker');
		$this->assertStringContainsString('-seed-fanout', $rAgent, 'agent run.sh seeds the fanout for a serve-only node');
		$this->assertStringContainsString("grep -q -- '-seed-fanout'", $rAgent, 'seeding is gated on the agent binary knowing -seed-fanout, so an older agent still enrols');
		// A transcode directive's portable Spec needs the node's resolver paths; the
		// daemon defaults ("ffmpeg"/"ffprobe") are not on a node's PATH.
		foreach (['-ffmpeg ', '-ffmpeg-gpu ', '-ffprobe ', '-streams '] as $rFlag) {
			$this->assertStringContainsString($rFlag, $rAgent, "agent seeding passes {$rFlag}for transcode-directive path resolution");
		}
	}
}
