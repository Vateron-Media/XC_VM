<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Public\Controllers\Admin\ReportIssueController;

/**
 * Report an Issue (ReportIssueController + Views/admin/report_issue.php): the
 * panel prefills GitHub's issue forms, so the field ids it sends must be the
 * ids those forms actually have — a form that renames a field would otherwise
 * drop the prefill silently. Security is never filed as a public issue.
 */
final class ReportIssueTest extends TestCase {
	/** Category => [template file, the field ids the page prefills on it]. */
	private const PREFILL = [
		'bug_report.yml' => ['version', 'build_type', 'environment', 'description'],
		'feature_request.yml' => ['description'],
		'documentation.yml' => ['description'],
		'other.yml' => ['version', 'issuedescription'],
	];

	private function view(): string {
		return (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/report_issue.php');
	}

	/** The `id:` values of an issue form. */
	private function formFieldIds(string $rFile): array {
		$rPath = dirname(__DIR__, 2) . '/.github/ISSUE_TEMPLATE/' . $rFile;
		$this->assertFileExists($rPath, $rFile);
		preg_match_all('/^\s+id:\s*(\S+)\s*$/m', (string) file_get_contents($rPath), $rM);
		return $rM[1];
	}

	public function testEveryPrefilledFieldIsOneTheFormHas(): void {
		foreach (self::PREFILL as $rFile => $rFields) {
			$rIds = $this->formFieldIds($rFile);
			foreach ($rFields as $rField) {
				$this->assertContains($rField, $rIds, "{$rFile} has no field '{$rField}' to prefill");
			}
		}
	}

	public function testTheViewSendsExactlyTheseTemplatesAndFields(): void {
		$rView = $this->view();
		foreach (self::PREFILL as $rFile => $rFields) {
			$this->assertStringContainsString($rFile, $rView, "view does not link {$rFile}");
		}
		// The description field ids both forms use, and the one other.yml uses.
		$this->assertStringContainsString("descField: 'issuedescription'", $rView);
		$this->assertStringContainsString("descField: 'description'", $rView);
	}

	public function testSecurityIsPrivateNotAPublicIssue(): void {
		$rView = $this->view();
		$this->assertStringContainsString('/security/advisories/new', $rView, 'security must route to a private advisory');
		$this->assertStringNotContainsString('security.yml', $rView, 'security must not be a filable category');
		$this->assertStringNotContainsString('incident.yml', $rView, 'incident is an internal template, not for the panel');
	}

	public function testEnvironmentReportsTheVersionAndBuild(): void {
		if (!defined('XC_VM_VERSION')) {
			define('XC_VM_VERSION', '9.9.9');
		}
		$rMethod = new ReflectionMethod(ReportIssueController::class, 'environment');
		$rMethod->setAccessible(true);
		try {
			NodeRole::useServers(static fn(): array => [SERVER_ID => ['is_main' => 1]]);
			$rMain = $rMethod->invoke(null);
			$this->assertSame(XC_VM_VERSION, $rMain['version']);
			$this->assertSame('MAIN (single server)', $rMain['build_type']);
			$this->assertStringContainsString('XC_VM: ' . XC_VM_VERSION, $rMain['environment']);
			$this->assertStringContainsString('PHP: ' . PHP_VERSION, $rMain['environment']);
			$this->assertStringContainsString('OS: ', $rMain['environment']);

			NodeRole::useServers(static fn(): array => [SERVER_ID => ['is_main' => 0]]);
			$this->assertSame('LoadBalancer (multi-server)', $rMethod->invoke(null)['build_type']);
		} finally {
			NodeRole::useServers(null);
		}
	}
}
