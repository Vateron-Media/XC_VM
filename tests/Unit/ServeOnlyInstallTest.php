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
		// configureRuntime() writes the serve-only front config under CONFIG_PATH.
		if (!defined('CONFIG_PATH')) {
			define('CONFIG_PATH', MAIN_HOME . 'config/');
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
		// The service is restarted AFTER the marker is written, so the node boots
		// sole-front (service skips nginx + php-fpm) instead of staying dual-stacked
		// from the pre-marker boot (SO_REUSEPORT split brain). Guards finding A.
		$this->assertMatchesRegularExpression("/tee ' \\. CONFIG_PATH \\. 'cluster\\/serve_only[^}]*sudo systemctl restart xc_vm/s", $rCmd, 'a completed serve-only install restarts the service after writing the marker');

		// provisionConfig no longer writes the plaintext marker (so a mid-install
		// failure cannot leave it); it only packs the PHP-side serve_only into config.enc.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringNotContainsString("sudoWrite((string) \$rServerID, CONFIG_PATH . 'cluster/serve_only')", $rLb, 'the marker is not written during provisionConfig');
	}

	public function testServeOnlyNginxListenerIsOwnedByRootSignalsNotTheInstall(): void {
		// The :443->:8080 demote is NOT written at install: an unconditional
		// install-time demote caused an outage on a -front-less binary. configureRuntime
		// only sets the loopback real-IP trust and records the front's listen address.
		// The capability-gated runtime demote in RootSignalsCronJob is now retired too
		// (the rethink dropped the nginx fallback; front_capable is never written): a
		// serve-only node keeps its public https ports, which the Go front binds.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('bool $rServeOnly = false', $rLb, 'configureRuntime takes the serve_only flag');
		$this->assertStringNotContainsString("sudoWrite('listen 8080", $rLb, 'the demote is NOT written at install — the cron owns it');
		$this->assertStringContainsString('set_real_ip_from 127.0.0.1;', $rLb, 'configureRuntime sets the loopback real-IP trust');
		$this->assertStringContainsString("sudo -u xc_vm tee ' . CONFIG_PATH . 'cluster/front_addr", $rLb, 'configureRuntime records the front listen address (https_broadcast_port) as xc_vm, not a root write');

		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("is_file(CONFIG_PATH . 'cluster/serve_only')", $rCron, 'the cron is still serve_only-aware (the php-fpm watchdog excludes serve-only)');
		// The capability-gated :8080 demote is retired: the rethink committed serve-only
		// to the single Go binary with no nginx fallback, so front_capable is never
		// written and the demote was dead code. A serve-only node keeps its public https
		// ports (the Go front binds them from ports/https.conf), like a normal LB.
		$this->assertStringNotContainsString('front_capable', $rCron, 'the dead front_capable-gated demote is retired');
		$this->assertStringNotContainsString("'backend' => true", $rCron, 'no loopback-backend https listener is enqueued');
		$this->assertStringNotContainsString("'listen 127.0.0.1:8080;'", $rCron, 'no :8080 demote — a serve-only node keeps its public ports');
		// The php-fpm crash watchdog is skipped on a serve-only node (php-fpm is absent
		// by design; nginx_rtmp's master + 0 php pids would otherwise restart-loop xc_vm).
		$this->assertStringContainsString("SettingsManager::get('restart_php_fpm') && !is_file(CONFIG_PATH . 'cluster/serve_only')", $rCron, 'the restart_php_fpm watchdog excludes serve-only nodes');
		// Root-boundary: the root cron must not execute the xc_vm-owned fanout binary
		// nor write through the xc_vm-owned config/cluster path. It only stat-checks
		// the capability file run.sh records; no shell_exec in the serve-only block.
		$this->assertStringNotContainsString("shell_exec('sudo -u xc_vm ' . escapeshellarg(MAIN_HOME . 'bin/xc_fanout/xc_fanout')", $rCron, 'the cron does NOT execute the xc_vm-owned fanout binary');
		$this->assertStringNotContainsString("NginxPortsFile::write(CONFIG_PATH . 'cluster/front_addr'", $rCron, 'the cron does NOT write front_addr through a root symlink-following path');
		$this->assertStringNotContainsString("sudo -u xc_vm tee ' . escapeshellarg(CONFIG_PATH . 'cluster/front_addr')", $rCron, 'the cron does NOT write front_addr (the install owns it)');

		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('LbInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rServerID, $rServeOnly)', $rCmd, 'the install threads serve_only into configureRuntime');
	}

	public function testConfigureRuntimeGatesHttpNginxAndPhpFpmForServeOnly(): void {
		// The cutover: a serve-only node serves HTTP entirely from the Go front, so
		// configureRuntime provisions no HTTP-nginx config and no php-fpm pools for it
		// (the service script then starts neither). nginx_rtmp's live.conf and the Go
		// front config (front_addr / loopback real-IP) are kept. A mode-1 node is
		// untouched: the `!$rServeOnly` gate means every one of those calls still runs.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');

		// The HTTP-nginx confs (custom.conf + realip_* + limit*) are gated off for serve-only.
		$this->assertMatchesRegularExpression(
			"/if \\(!\\\$rServeOnly\\) \\{\\s*call_user_func\\(\\\$rSendFileSSH, \\\$rConn, MAIN_HOME \\. 'bin\\/nginx\\/conf\\/custom\\.conf'/",
			$rLb,
			'the HTTP-nginx confs are wrapped in if (!$rServeOnly)'
		);
		// The php-fpm pools (rm bin/php/etc + PhpFpmPools::files loop) are gated off for serve-only.
		$this->assertMatchesRegularExpression(
			"/if \\(!\\\$rServeOnly\\) \\{\\s*call_user_func\\(\\\$rRunSSH, \\\$rConn, 'sudo rm ' \\. MAIN_HOME \\. 'bin\\/php\\/etc/",
			$rLb,
			'the php-fpm pool writes are wrapped in if (!$rServeOnly)'
		);
		// nginx_rtmp's auth-notify config is kept for every LB (serve-only included).
		$this->assertStringContainsString("bin/nginx_rtmp/conf/live.conf", $rLb, 'nginx_rtmp live.conf is still written');
	}

	public function testConfigureRuntimeServeOnlyEmitsNoHttpNginxOrPhpFpm(): void {
		// Behavioral: exercise configureRuntime with fake SSH callables and assert the
		// commands/files it ACTUALLY emits for a serve-only node — so a limit write or
		// the PhpFpmPools loop moved OUTSIDE the !$rServeOnly guard is caught (the
		// source assertion above only matches the first command in each gate). For a
		// serve-only node the fixture-needing parts (template read, TMP_PATH writes)
		// are gated off, so this runs clean.
		$rRun = [];
		$rSend = [];
		$rRunSSH = function ($rConn, string $rCmd) use (&$rRun) { $rRun[] = $rCmd; return ['output' => '4']; };
		$rSendFileSSH = function ($rConn, string $rSrc, string $rDst, bool $rWarn = false) use (&$rSend) { $rSend[] = $rDst; return true; };
		$rServers = [1 => ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443]];

		LbInstallFlow::configureRuntime(null, $rSendFileSSH, $rRunSSH, $rServers, 1, true);
		$rAll = implode("\n", array_merge($rRun, $rSend));

		// The inert HTTP-nginx + php-fpm configs a migrated (pre-cutover) node still
		// has on disk are torn down (one idempotent `rm -f`); a fresh node never had
		// them, so rm -f is a no-op there.
		$rRm = implode("\n", array_filter($rRun, static fn(string $rC): bool => str_contains($rC, 'rm -f')));
		foreach (['bin/nginx/conf/custom.conf', 'bin/nginx/conf/limit.conf', 'bin/nginx/conf/limit_queue.conf', 'bin/nginx/conf/balance.conf', 'bin/php/etc/*.conf'] as $rPath) {
			$this->assertStringContainsString($rPath, $rRm, "serve-only teardown removes {$rPath}");
		}
		// Nothing HTTP-nginx or php-fpm is PROVISIONED for a serve-only node: no config
		// is sent, and the limit*/pool writes (distinct from the teardown rm) never run.
		$this->assertEmpty(array_filter($rSend, static fn(string $rD): bool => str_contains($rD, 'custom.conf') || str_contains($rD, 'bin/php/etc')), 'no HTTP-nginx/php-fpm config is provisioned (sent) for serve-only');
		$rWrites = implode("\n", array_filter($rRun, static fn(string $rC): bool => !str_contains($rC, 'rm -f')));
		$this->assertStringNotContainsString('limit.conf', $rWrites, 'no HTTP-nginx limit.conf is written for serve-only (only removed)');
		$this->assertStringNotContainsString('bin/php/etc', $rWrites, 'no php-fpm pool config is written for serve-only (only removed)');
		// ...but the RTMP auth-notify, the Go-front listen address, and the realip
		// CIDRs the front reads ARE emitted.
		$this->assertStringContainsString('nginx_rtmp/conf/live.conf', $rAll, 'nginx_rtmp live.conf is kept');
		$this->assertStringContainsString('cluster/front_addr', $rAll, 'the Go front listen address is recorded');
		$this->assertNotEmpty(array_filter($rSend, static fn(string $rD): bool => str_contains($rD, 'realip_')), 'the realip CIDRs are shipped (the front reads them)');
	}

	public function testServeOnlyRequiresApiMode(): void {
		// A serve-only node is credential-free (no DB, no php-fpm), so --serve-only
		// must install in API mode; the gate refuses it on a mode-1 (credentialed)
		// node, whose nginx + php-fpm the provisioning gate would otherwise strip.
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertMatchesRegularExpression('/if \(\$rServeOnly && !\$rApiMode\) \{/', $rCmd, '--serve-only is refused when the install is not API-mode');
		$this->assertStringContainsString('--serve-only requires an API-mode', $rCmd, 'the refusal explains a serve-only node must be credential-free');
	}

	public function testRunShLaunchersThreadServeOnlyFlagsOnTheMarker(): void {
		$rFanout = (string) file_get_contents(MAIN_HOME . 'bin/xc_fanout/run.sh');
		$this->assertStringContainsString('config/cluster/serve_only', $rFanout, 'fanout run.sh gates the front on the marker');
		$this->assertStringContainsString("grep -q -- '-front-agent-sock'", $rFanout, 'the sole front is gated on the binary being the full single-binary front (-front-agent-sock implies -front)');
		$this->assertStringContainsString('-front-nginx ""', $rFanout, 'the sole front has no nginx backend (the Go binary is the whole HTTP surface)');
		$this->assertStringContainsString('-front-agent-sock "$SCRIPT/config/cluster/agent.sock"', $rFanout, 'the front routes /xfile, /stream/rtmp and /admin/* to the agent socket');
		$this->assertStringContainsString('config/cluster/front_addr', $rFanout, 'the front is enabled with the node https_broadcast_port (front_addr), not a hardcoded :443');
		$this->assertStringContainsString('"${FRONT[@]}"', $rFanout, 'the fanout daemon invocation actually expands the FRONT flag array (not just declares it)');
		// The Phase-A strangler machinery is retired: no nginx loopback backend, no capability/sole markers.
		$this->assertStringNotContainsString('127.0.0.1:8080', $rFanout, 'no strangler nginx backend — the Go binary serves everything');
		$this->assertStringNotContainsString('front_capable', $rFanout, 'the front_capable marker is retired (service gates on the serve_only marker alone)');
		$this->assertStringNotContainsString('front_sole', $rFanout, 'the front_sole marker is retired');

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

	public function testDowngradeOutOfServeOnlyRestoresHttpNginxAndPhpFpm(): void {
		// Clearing serve-only (a non-serve-only re-provision) restores the PHP stack
		// with no dedicated recovery code: the install removes the marker, so the next
		// `service` boot (no marker) starts nginx + php-fpm, and configureRuntime's
		// !$rServeOnly branch has already re-written their configs. The serve-only
		// config teardown must stay INSIDE the serve-only branch, so a mode-1 or
		// downgraded node never strips its own HTTP stack.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertMatchesRegularExpression(
			"/if \\(\\\$rServeOnly\\) \\{.*sudo rm -f ' \\. MAIN_HOME \\. 'bin\\/nginx\\/conf\\/custom\\.conf/s",
			$rLb,
			'the serve-only config teardown is gated inside if ($rServeOnly) — a mode-1 node never tears down its nginx/php-fpm'
		);
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString("sudo -u xc_vm rm -f ' . CONFIG_PATH . 'cluster/serve_only'", $rCmd, 'a non-serve-only install removes the marker (downgrade-out), so service boot restores nginx + php-fpm');
	}
}
