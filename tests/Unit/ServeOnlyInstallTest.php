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
		$this->assertStringContainsString("\$rServeOnly = \$rType == 2 && in_array('--serve-only', array_slice(\$rArgs, 5), true);", $rSrc, 'the --serve-only flag is matched from the positional TAIL (not the cred slots) for an LB');
		$this->assertStringContainsString('`serve_only` = ?', $rSrc, 'the final success UPDATE sets serve_only');
		$this->assertStringContainsString('StreamVersions::bump(StreamRecords::held($rServerID', $rSrc, 'B2: a serve_only install re-stamps its held streams');
		$this->assertMatchesRegularExpression('/try \{\s*StreamVersions::bump/', $rSrc, 'B2 is fault-tolerant: the non-essential re-stamp is wrapped so a DB hiccup does not fail the install');
		$this->assertStringContainsString('$db, $rApiMode, $rServeOnly)', $rSrc, 'serve_only threads into provisionConfig');
	}

	public function testProvisionConfigThreadsServeOnlyToConfigPack(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('self::configPackParams($rServers, $rServerID, $rServeOnly)', $rSrc, 'provisionConfig passes serve_only to configPackParams');
	}

	public function testServeOnlyMarkerIsManagedOnCompletedInstall(): void {
		// The node's run.sh is shell and the serve path has no PHP to decrypt
		// config.enc, so a serve-only install writes a plaintext marker (its content
		// is the server id) under config/cluster/. It is written by the success path
		// — NOT by provisionConfig, so a failure after provisioning never leaves it —
		// and removed on a non-serve-only (downgrade) install, since config/ survives
		// a re-install. The off-path undoes the on-path, keeping the node's runtime
		// mode consistent with the DB serve_only committed alongside it.
		// Written AS xc_vm into its own config/cluster (never a root write/chown
		// through that xc_vm-owned path, which could follow a planted symlink and
		// escalate).
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertMatchesRegularExpression("/if \\(\\\$rType == 2\\) \\{.*sudo -u xc_vm tee ' \\. CONFIG_PATH \\. 'cluster\\/serve_only/s", $rCmd, 'a completed serve-only install writes the marker as xc_vm');
		$this->assertStringContainsString("sudo -u xc_vm rm -f ' . CONFIG_PATH . 'cluster/serve_only'", $rCmd, 'a non-serve-only install clears a stale marker as xc_vm');
		$this->assertStringNotContainsString("sudo chown xc_vm:xc_vm ' . CONFIG_PATH . 'cluster/serve_only'", $rCmd, 'no root chown through the xc_vm-owned marker path (symlink privesc)');

		// provisionConfig no longer writes the plaintext marker (so a mid-install
		// failure cannot leave it); it only packs the PHP-side serve_only into config.enc.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringNotContainsString("sudoWrite((string) \$rServerID, CONFIG_PATH . 'cluster/serve_only')", $rLb, 'the marker is not written during provisionConfig');
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
		$this->assertStringContainsString("sudo -u xc_vm tee ' . CONFIG_PATH . 'cluster/front_addr", $rLb, 'configureRuntime records the front listen address (https_broadcast_port) as xc_vm, not a root write');

		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("is_file(CONFIG_PATH . 'cluster/serve_only')", $rCron, 'the cron is serve_only-aware, strictly gated on the marker');
		$this->assertStringContainsString('grep -q -- -front', $rCron, 'the demote is gated on the fanout being -front-capable (no outage otherwise)');
		$this->assertStringContainsString("'backend' => true", $rCron, 'serve_only enqueues the loopback-backend https listener');
		$this->assertStringContainsString("'listen 127.0.0.1:8080;'", $rCron, 'the backend listener is loopback plaintext :8080');
		$this->assertStringContainsString('cluster/front_addr', $rCron, 'the cron keeps the front listen address current for run.sh');
		// Root-boundary: the cron must probe the xc_vm-owned fanout binary and write
		// the xc_vm-owned front_addr AS xc_vm, never as root (exec = RCE, write =
		// symlink clobber).
		$this->assertStringContainsString("shell_exec('sudo -u xc_vm ' . escapeshellarg(MAIN_HOME . 'bin/xc_fanout/xc_fanout')", $rCron, 'the -front capability probe runs as xc_vm, not root');
		$this->assertStringContainsString("| sudo -u xc_vm tee ' . escapeshellarg(CONFIG_PATH . 'cluster/front_addr')", $rCron, 'the cron writes front_addr as xc_vm, not a root write that follows a symlink');
		$this->assertStringNotContainsString("NginxPortsFile::write(CONFIG_PATH . 'cluster/front_addr'", $rCron, 'front_addr is not written through a root symlink-following path');

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
		$this->assertStringContainsString('"${FRONT[@]}"', $rFanout, 'the fanout daemon invocation actually expands the FRONT flag array (not just declares it)');

		$rAgent = (string) file_get_contents(MAIN_HOME . 'bin/xc_agent/run.sh');
		$this->assertStringContainsString('config/cluster/serve_only', $rAgent, 'agent run.sh gates seeding on the marker');
		$this->assertStringContainsString('-seed-fanout', $rAgent, 'agent run.sh seeds the fanout for a serve-only node');
		$this->assertStringContainsString("grep -q -- '-seed-fanout'", $rAgent, 'seeding is gated on the agent binary knowing -seed-fanout, so an older agent still enrols');
		// A transcode directive's portable Spec needs the node's resolver paths; the
		// daemon defaults ("ffmpeg"/"ffprobe") are not on a node's PATH.
		foreach (['-ffmpeg ', '-ffmpeg-gpu ', '-ffprobe ', '-streams '] as $rFlag) {
			$this->assertStringContainsString($rFlag, $rAgent, "agent seeding passes {$rFlag}for transcode-directive path resolution");
		}
		// {streams}/{logs} must resolve to the app's path constants (STREAMS_PATH =
		// content/streams/ — the ramdisk nginx serves; LOGS_TMP_PATH = tmp/logs/),
		// not the daemon's bare defaults, or transcode output lands off the served path.
		$this->assertStringContainsString('-streams "$SCRIPT/content/streams/"', $rAgent, 'serve streams resolve to STREAMS_PATH (content/streams/)');
		$this->assertStringContainsString('-logs "$SCRIPT/tmp/logs/"', $rAgent, 'logs resolve to LOGS_TMP_PATH (tmp/logs/)');
		$this->assertStringContainsString('"${SEED[@]}"', $rAgent, 'the agent run invocation actually expands the SEED flag array (not just declares it)');
	}
}
