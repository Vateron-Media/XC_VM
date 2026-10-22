<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\CertbotCommand;

/**
 * Slice 3 (certbot-webroot for serve-only): a serve-only LB issues/renews its
 * Let's Encrypt cert through certbot's WEBROOT authenticator — the Go front
 * already serves /.well-known/acme-challenge/ from certbot's -w directory — and
 * the renewed cert is copied to the files the front reads (server.crt/server.key)
 * so the front's mtime-watch CertReloader picks it up. The certbot-CLI branch and
 * the wiring are checked by source assertion (exec()-driven, node-only — the
 * convention ServeOnlyInstallTest/ApiModeInstallTest use); the cert-copy helper
 * has a behavioral temp-dir test.
 *
 * @covers \XcVm\Cli\Commands\CertbotCommand
 */
final class CertbotServeOnlyTest extends TestCase {

	public function testServeOnlyUsesWebrootAuthenticator(): void {
		// A serve-only node must issue certbot with --webroot; --standalone would
		// bind its own :80 and collide with the Go front that owns the node ports.
		// The webroot arm is taken for a serve-only node OR when the http port is 80.
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/CertbotCommand.php');
		// Serve-only is detected from the marker, guarded on CONFIG_PATH so the command-build
		// path does not fatal in a unit context without the full CLI bootstrap.
		$this->assertStringContainsString("\$rServeOnly = defined('CONFIG_PATH') && is_file(CONFIG_PATH . 'cluster/serve_only');", $rSrc, 'serve-only is read from the marker, guarded on CONFIG_PATH');
		// Pin --webroot to the serve-only / port-80 TRUE arm and --standalone to the else,
		// so a swapped branch (standalone in the true arm) cannot pass on the text alone.
		$this->assertMatchesRegularExpression(
			"/if \\(\\\$rServeOnly \\|\\| ServerRepository::getAll\\(\\)\\[SERVER_ID\\]\\['http_broadcast_port'\\] == 80\\) \\{[^}]*--webroot -w/",
			$rSrc,
			'the serve-only / port-80 branch selects --webroot'
		);
		$this->assertMatchesRegularExpression("/\\} else \\{[^}]*--standalone/", $rSrc, 'the other branch keeps --standalone');
	}

	public function testServeOnlyCutoverQueuesACertbotSyncForTheFront(): void {
		// A node converted to serve-only while it already holds a valid cert would serve the
		// installer's self-signed server.crt until the cert is next due (the daily cron sends
		// no certbot_generate while it is valid). The cutover queues one certbot_generate so
		// the node's existing-cert path runs syncFrontCert within the minute; skipped when the
		// node has only an IP (no http-01 name).
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertMatchesRegularExpression(
			"/if \\(\\\$rServeOnly\\) \\{.*NodeActions::certbot\\(\\\$rServerID, \\\$rCertDomains, \\\$db\\)/s",
			$rCmd,
			'the serve-only cutover queues a certbot_generate so an existing cert reaches the front'
		);
		$this->assertMatchesRegularExpression(
			"/if \\(\\\$rCertDomains !== \\[\\]\\) \\{\\s*NodeActions::certbot/",
			$rCmd,
			'the certbot dispatch is skipped when the node has no domain name (IP-only)'
		);
	}

	public function testSyncFrontCertCopiesForServeOnlyOnly(): void {
		// On a serve-only node the Go front reads server.crt/server.key, so a renewed
		// certbot cert must be copied there (its CertReloader hot-reloads on mtime).
		// A mode-1 node is never touched; a missing live cert never clobbers the files.
		$rBase = sys_get_temp_dir() . '/certsync_' . bin2hex(random_bytes(4)) . '/';
		mkdir($rBase . 'nginx/conf', 0775, true);
		$rCert = $rBase . 'full.pem';
		$rKey = $rBase . 'priv.pem';
		file_put_contents($rCert, 'CERT');
		file_put_contents($rKey, 'KEY');

		CertbotCommand::syncFrontCert(false, $rCert, $rKey, $rBase);
		$this->assertFileDoesNotExist($rBase . 'nginx/conf/server.crt', 'a non-serve-only node never has server.crt touched');

		CertbotCommand::syncFrontCert(true, $rCert, $rKey, $rBase);
		$this->assertSame('CERT', file_get_contents($rBase . 'nginx/conf/server.crt'), 'serve-only: server.crt becomes the certbot fullchain');
		$this->assertSame('KEY', file_get_contents($rBase . 'nginx/conf/server.key'), 'serve-only: server.key becomes the certbot privkey');
		$this->assertSame('0600', substr(sprintf('%o', fileperms($rBase . 'nginx/conf/server.key')), -4), 'the private key is written owner-only (0600), not world-readable');
		$rLeft = array_filter(scandir($rBase . 'nginx/conf/') ?: [], static fn(string $rF): bool => str_starts_with($rF, '.cert_'));
		$this->assertCount(0, $rLeft, 'no staged temp file is left behind (renamed into place)');

		file_put_contents($rBase . 'nginx/conf/server.crt', 'KEEP');
		CertbotCommand::syncFrontCert(true, $rBase . 'absent.pem', $rKey, $rBase);
		$this->assertSame('KEEP', file_get_contents($rBase . 'nginx/conf/server.crt'), 'a missing live cert leaves server.crt as it was');

		// Wired at BOTH ssl.conf write sites (the fresh-issue path and the
		// existing-cert path), so a serve-only renew always refreshes the front's cert.
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/CertbotCommand.php');
		$this->assertSame(2, substr_count($rSrc, 'self::syncFrontCert($rServeOnly, $rCertificate, $rPrivateKey, BIN_PATH)'), 'the cert sync runs at both ssl.conf write sites');
	}
}
