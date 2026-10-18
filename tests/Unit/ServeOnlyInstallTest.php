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
	}

	public function testConfigureRuntimeDemotesNginxForServeOnly(): void {
		// Task 4: a serve-only node's Go front owns :443 (TLS), so nginx is
		// demoted to a plaintext backend on :8080 (the front's -front-nginx
		// target) and loses its :443 ssl listener; it trusts the front on
		// loopback so X-Forwarded-For still resolves the viewer IP.
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('function configureRuntime(', $rSrc);
		$this->assertStringContainsString('bool $rServeOnly = false', $rSrc, 'configureRuntime takes the serve_only flag');
		$this->assertStringContainsString("self::sudoWrite('listen 8080 reuseport;'", $rSrc, 'nginx is demoted to the :8080 plaintext backend');
		$this->assertStringContainsString("ports/https.conf'", $rSrc, 'the demote rewrites the :443 ssl listen');
		$this->assertStringContainsString('set_real_ip_from 127.0.0.1;', $rSrc, 'nginx trusts the front on loopback for the viewer IP');

		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('LbInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rServerID, $rServeOnly)', $rCmd, 'the install threads serve_only into configureRuntime');
	}

	public function testRunShLaunchersThreadServeOnlyFlagsOnTheMarker(): void {
		$rFanout = (string) file_get_contents(MAIN_HOME . 'bin/xc_fanout/run.sh');
		$this->assertStringContainsString('config/cluster/serve_only', $rFanout, 'fanout run.sh gates the front on the marker');
		$this->assertStringContainsString('-front', $rFanout, 'fanout run.sh launches the Go front for a serve-only node');
		$this->assertStringContainsString('-front-nginx', $rFanout, 'the front strangler proxies non-serve requests to nginx');

		$this->assertStringContainsString("grep -q -- '-front'", $rFanout, 'the front is gated on the fanout binary knowing -front');

		$rAgent = (string) file_get_contents(MAIN_HOME . 'bin/xc_agent/run.sh');
		$this->assertStringContainsString('config/cluster/serve_only', $rAgent, 'agent run.sh gates seeding on the marker');
		$this->assertStringContainsString('-seed-fanout', $rAgent, 'agent run.sh seeds the fanout for a serve-only node');
		$this->assertStringContainsString("grep -q -- '-seed-fanout'", $rAgent, 'seeding is gated on the agent binary knowing -seed-fanout, so an older agent still enrols');
	}
}
