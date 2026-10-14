<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression locks for the reseller-domain fixes ported from the Parches module
 * (verified still-present in 2.6.7). A reseller must not be able to put an
 * arbitrary domain on the codes it generates, and its own download/EPG links
 * should carry its reseller_dns, not the server's domain.
 */
final class ParchesResellerDomainTest extends TestCase {

	/** 1.14.21 — a reseller's forged dns_base (not one of its reseller_dns values) is ignored. */
	public function testResellerDnsBaseConstrainedToOwnDomains(): void {
		$rSvc = (string) file_get_contents(MAIN_HOME . 'Domain/Line/ActiveCodeService.php');
		$this->assertStringContainsString('if (!$isAdmin && $dnsBase !== null) {', $rSvc, 'a reseller dns_base is validated, an admin may set any');
		$this->assertStringContainsString('in_array($dnsBase, $rResellerDomains, true)', $rSvc, 'the dns_base must be one of the reseller own reseller_dns values');
	}

	/** 1.14.19 — a reseller's download/EPG links are built from its reseller_dns, not the server domain. */
	public function testResellerDownloadLinksUseResellerDns(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/lines.php');
		$this->assertStringContainsString("\$rUserInfo['reseller_dns']", $rView, 'the download base considers the reseller dns');
		$this->assertStringContainsString('$rResellerHost', $rView, 'and swaps the host into the base URL');
	}
}
