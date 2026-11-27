<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Domain\Vod\VodImportLog;

final class VodImportLogTest extends TestCase {
	public function testImportLogPageIsOwnedAndRoutedByCore(): void {
		$this->assertFileExists(MAIN_HOME . 'Public/Controllers/Admin/VodImportLogsController.php');
		$this->assertFileExists(MAIN_HOME . 'Public/Controllers/Admin/Ajax/VodImportAjaxController.php');
		$this->assertFileExists(MAIN_HOME . 'Public/Views/admin/vod_import_logs.php');
		$this->assertStringContainsString("\$router->get('vod_import_logs', [VodImportLogsController::class, 'index']);", (string) file_get_contents(MAIN_HOME . 'Public/routes/admin.php'));
		$this->assertStringContainsString("\$router->api('vod_import_retry', [VodImportAjaxController::class, 'retry']);", (string) file_get_contents(MAIN_HOME . 'Public/routes/admin.php'));
		$this->assertStringContainsString('case "vod_import_logs":', (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/TableController.php'));
		$this->assertStringContainsString("->url('vod_import_logs')", (string) file_get_contents(MAIN_HOME . 'Core/Module/CoreNavbarProvider.php'));
		$this->assertStringContainsString('data-vod-retry', (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/vod_import_logs.php'));
	}

	public function testQueuedManualImportsRedirectToTheImportLog(): void {
		$this->assertSame(2, substr_count((string) file_get_contents(MAIN_HOME . 'Public/Views/admin/post.php'), "'location' => 'vod_import_logs'"));
		foreach (['movie.php', 'serie.php'] as $rView) {
			$this->assertStringContainsString("window.top.location.href = dt.location || 'vod_import_logs'", (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rView));
		}
	}

	public function testCoreOwnsAndStoresTheLatestResultWithoutWatchfolder(): void {
		$db = new TestDb();
		$db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/109_add_vod_import_logs.sql'));
		$db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/110_add_vod_import_log_payload.sql'));
		VodImportLog::setDb($db);

		VodImportLog::record(new VodImportResultEvent(1, 1, "s:2:/media/Tom & Jerry's.mkv", VodImportResultEvent::STATUS_NO_MATCH));
		VodImportLog::record(new VodImportResultEvent(1, 1, "s:2:/media/Tom & Jerry's.mkv", VodImportResultEvent::STATUS_IMPORTED, 42, 'Tom & Jerry'));

		$rRows = $db->pdo->query('SELECT `type`, `server_id`, `filename`, `title`, `status`, `stream_id` FROM `vod_import_logs`')->fetchAll(PDO::FETCH_ASSOC);
		$this->assertSame([[
			'type' => 1,
			'server_id' => 2,
			'filename' => "/media/Tom & Jerry's.mkv",
			'title' => 'Tom & Jerry',
			'status' => VodImportResultEvent::STATUS_IMPORTED,
			'stream_id' => 42,
		]], $rRows);
		$this->assertStringContainsString('VodImportLog::record($rEvent, $rThreadData)', (string) file_get_contents(MAIN_HOME . 'Domain/Vod/VodItemImporter.php'));
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `vod_import_logs`', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'));
		$this->assertStringContainsString('`payload` longtext', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'));
	}

	public function testNoMatchCanBeRetriedWithAnEditedTitleAndItsOriginalSettings(): void {
		$db = new TestDb();
		$db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/109_add_vod_import_logs.sql'));
		$db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/110_add_vod_import_log_payload.sql'));
		VodImportLog::setDb($db);

		$rPayload = [
			'import' => true,
			'type' => 'series',
			'file' => 'https://media.example/anne-s01e01.mp4',
			'title' => 'Mother - Anne - أمي - S01E01',
			'category_id' => [12],
			'servers' => [1, 2],
		];
		VodImportLog::record(new VodImportResultEvent(2, 1, $rPayload['file'], VodImportResultEvent::STATUS_NO_MATCH, 0, $rPayload['title']), $rPayload);

		$rBatch = null;
		$this->assertFalse(VodImportLog::retry(1, 'Wrong media permission', 1));
		$this->assertTrue(VodImportLog::retry(1, 'Mother - Anne (2016) - أمي - S01E01', 2, static function (string $rFile) use (&$rBatch): void {
			$rBatch = $rFile;
		}));
		$this->assertNotNull($rBatch);
		$rRetry = json_decode(trim((string) file_get_contents($rBatch)), true);
		@unlink($rBatch);

		$this->assertSame('Mother - Anne (2016) - أمي - S01E01', $rRetry['title']);
		$this->assertSame([12], $rRetry['category_id']);
		$this->assertSame([1, 2], $rRetry['servers']);
		$this->assertSame(VodImportResultEvent::STATUS_QUEUED, (int) $db->pdo->query('SELECT `status` FROM `vod_import_logs` WHERE `id` = 1')->fetchColumn());
	}

	public function testRetryDoesNotOverwriteAResultFromAFastWorker(): void {
		$db = new TestDb();
		$db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/109_add_vod_import_logs.sql'));
		$db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/110_add_vod_import_log_payload.sql'));
		VodImportLog::setDb($db);
		$rPayload = ['import' => true, 'type' => 'movie', 'file' => 'https://media.example/movie.mp4', 'title' => 'Movie'];
		VodImportLog::record(new VodImportResultEvent(1, 1, $rPayload['file'], VodImportResultEvent::STATUS_NO_MATCH, 0, $rPayload['title']), $rPayload);

		$this->assertTrue(VodImportLog::retry(1, 'Movie (2024)', 1, static function () use ($db): void {
			$db->query('UPDATE `vod_import_logs` SET `status` = ? WHERE `id` = 1;', VodImportResultEvent::STATUS_IMPORTED);
		}));
		$this->assertSame(VodImportResultEvent::STATUS_IMPORTED, (int) $db->pdo->query('SELECT `status` FROM `vod_import_logs` WHERE `id` = 1')->fetchColumn());
	}
}
