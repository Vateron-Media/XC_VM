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

	public function testPanelLogSubmissionIsEnabledByDefaultAndCanBeDisabled(): void {
		SettingsManager::set([]);
		$this->assertTrue(SettingsManager::getBool('diagnostics_panel_logs', true));
		SettingsManager::set(['diagnostics_panel_logs' => 0]);
		$this->assertFalse(DiagnosticsService::submitPanelLogs());
	}

	public function testFreshInstallUpgradeAndUiEnableDocumentedDiagnostics(): void {
		$rSchema = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		$rMigration = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/106_opt_in_panel_diagnostics.sql');
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		$rEnglish = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');
		$rRussian = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/ru.ini');
		$this->assertStringContainsString("`diagnostics_panel_logs` tinyint(1) NOT NULL DEFAULT '1'", $rSchema);
		$this->assertStringContainsString("DEFAULT '1'", $rMigration);
		$this->assertContains('diagnostics_panel_logs', \XcVm\Domain\Server\SettingsService::checkboxes());
		$this->assertStringContainsString('DiagnosticsService::PANEL_LOG_ENDPOINT', $rView);
		$this->assertStringContainsString("\$language::get('diagnostics_panel_logs_help'", $rView);
		foreach (['log_message', 'log_extra', 'retention period', 'stops future uploads', 'does not delete data already sent'] as $rDisclosure) {
			$this->assertStringContainsString($rDisclosure, $rEnglish);
		}
		$this->assertStringContainsString('Включено по умолчанию', $rRussian);
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

	public function testRedactorConsumesCompleteQuotedSecrets(): void {
		$rOut = Redactor::redact('password="correct horse battery staple" {"api_key":"quoted secret value"} Authorization: "Bearer header secret"');
		foreach (['correct', 'horse', 'battery', 'staple', 'quoted secret value', 'header secret'] as $rSecret) {
			$this->assertStringNotContainsString($rSecret, $rOut);
		}
		$this->assertSame($rOut, Redactor::redact($rOut));
	}

	private function assertStoredLineIsRedacted(string $rFile): void {
		$rStored = (string) base64_decode(trim((string) file_get_contents($rFile)));
		$this->assertStringNotContainsString('storage-secret', $rStored);
		$this->assertStringNotContainsString('trace-secret', $rStored);
		$this->assertStringContainsString(Redactor::MASK, $rStored);
	}
}
