<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Logging\Logger;

final class DiagnosticsPrivacyTest extends TestCase {
	private array $rSettings;
	private string $rDir;

	protected function setUp(): void {
		$this->rSettings = SettingsManager::getAll();
		$this->rDir = sys_get_temp_dir() . '/xcvm-diagnostics-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		SettingsManager::set($this->rSettings);
		FileLogger::setLogFile(null);
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
		@rmdir($this->rDir);
	}

	public function testPanelLogSubmissionIsOffUnlessExplicitlyEnabled(): void {
		SettingsManager::set([]);
		$this->assertFalse(DiagnosticsService::submitPanelLogs());
		SettingsManager::set(['diagnostics_panel_logs' => 0]);
		$this->assertFalse(DiagnosticsService::submitPanelLogs());
	}

	public function testFreshInstallUpgradeAndUiRequireInformedOptIn(): void {
		$rSchema = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		$rMigration = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/106_opt_in_panel_diagnostics.sql');
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		$this->assertStringContainsString("`diagnostics_panel_logs` tinyint(1) NOT NULL DEFAULT '0'", $rSchema);
		$this->assertStringContainsString("DEFAULT '0'", $rMigration);
		$this->assertContains('diagnostics_panel_logs', \XcVm\Domain\Server\SettingsService::checkboxes());
		$this->assertStringContainsString('DiagnosticsService::PANEL_LOG_ENDPOINT', $rView);
		foreach (['log_message', 'log_extra', 'retention period', 'stops future uploads', 'does not delete data already sent'] as $rDisclosure) {
			$this->assertStringContainsString($rDisclosure, $rView);
		}
	}

	public function testTransmittedPayloadHasOnlyDocumentedRedactedFields(): void {
		$rPayload = DiagnosticsService::panelLogPayload([[
			'id' => 9,
			'type' => 'EXCEPTION',
			'log_message' => 'Authorization: Bearer token-value',
			'log_extra' => 'GET https://alice:hunter2@example.test/x?api_key=live-key',
			'line' => 42,
			'date' => 1700000000,
			'version' => '2.7.4',
			'ignored' => 'do-not-send',
		]]);

		$this->assertSame(['type', 'log_message', 'log_extra', 'line', 'date', 'version'], array_keys($rPayload['errors'][0]));
		$this->assertStringNotContainsString('token-value', json_encode($rPayload));
		$this->assertStringNotContainsString('hunter2', json_encode($rPayload));
		$this->assertStringNotContainsString('live-key', json_encode($rPayload));
		$this->assertStringNotContainsString('do-not-send', json_encode($rPayload));
	}

	public function testPanelLoggersRedactBeforeWriting(): void {
		$rFile = $this->rDir . 'file.log';
		FileLogger::setLogFile($rFile);
		FileLogger::log('pdo', 'password=storage-secret', 'https://u:p@example.test/?token=trace-secret');
		$this->assertStoredLineIsRedacted($rFile);

		$rLoggerFile = $this->rDir . 'logger.log';
		(new ReflectionProperty(Logger::class, 'logFile'))->setValue(null, $rLoggerFile);
		Logger::log('EXCEPTION', 'api_key=storage-secret', 'Authorization: Basic trace-secret');
		$this->assertStoredLineIsRedacted($rLoggerFile);
	}

	public function testRedactorCoversCredentialsHeadersAndUrls(): void {
		$rOut = Redactor::redact('secret prefix=abcdefghij Authorization: Bearer abc.def https://u:p@host/?password=p');
		foreach (['abcdefghij', 'abc.def', 'u:p', 'password=p'] as $rSecret) {
			$this->assertStringNotContainsString($rSecret, $rOut);
		}
	}

	private function assertStoredLineIsRedacted(string $rFile): void {
		$rStored = (string) base64_decode(trim((string) file_get_contents($rFile)));
		$this->assertStringNotContainsString('storage-secret', $rStored);
		$this->assertStringNotContainsString('trace-secret', $rStored);
		$this->assertStringContainsString(Redactor::MASK, $rStored);
	}
}
