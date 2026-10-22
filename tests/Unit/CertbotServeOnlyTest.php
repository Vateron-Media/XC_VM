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
		$this->assertStringContainsString("is_file(CONFIG_PATH . 'cluster/serve_only') || ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] == 80", $rSrc, 'the --webroot arm is taken for a serve-only node (or http port 80)');
		$this->assertStringContainsString('--webroot -w', $rSrc, 'the webroot authenticator is used');
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
		$this->assertFileDoesNotExist($rBase . 'nginx/conf/server.crt.tmp', 'the staged cert temp is renamed into place, not left behind');
		$this->assertFileDoesNotExist($rBase . 'nginx/conf/server.key.tmp', 'the staged key temp is renamed into place, not left behind');

		file_put_contents($rBase . 'nginx/conf/server.crt', 'KEEP');
		CertbotCommand::syncFrontCert(true, $rBase . 'absent.pem', $rKey, $rBase);
		$this->assertSame('KEEP', file_get_contents($rBase . 'nginx/conf/server.crt'), 'a missing live cert leaves server.crt as it was');

		// Wired at BOTH ssl.conf write sites (the fresh-issue path and the
		// existing-cert path), so a serve-only renew always refreshes the front's cert.
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/CertbotCommand.php');
		$this->assertSame(2, substr_count($rSrc, "self::syncFrontCert(is_file(CONFIG_PATH . 'cluster/serve_only'), \$rCertificate, \$rPrivateKey, BIN_PATH)"), 'the cert sync runs at both ssl.conf write sites');
	}
}
