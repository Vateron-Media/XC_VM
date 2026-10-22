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
}
