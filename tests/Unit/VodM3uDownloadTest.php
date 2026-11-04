<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Vod\VodDownload;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Domain\Vod\VodM3uImport;
use XcVm\Domain\Vod\VodHttp;
use XcVm\Domain\Vod\VodDownloadQuota;

final class VodM3uDownloadTest extends TestCase {
	private string $directory;
	private array $settings;
	private array $jobs = [];
	private $server;
	private string $base;
	private array $quota;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/xcvm-vod-download-' . bin2hex(random_bytes(5));
		mkdir($this->directory);
		$this->settings = SettingsManager::getAll();
		SettingsManager::set(['lb_scan_roots' => [$this->directory]]);
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$port = (int) substr(strrchr($address, ':'), 1);
		$this->base = 'http://vod.example:' . $port;
		$this->quota = VodDownloadQuota::fromForm([]);
		VodHttp::useResolver(static fn(): array => ['93.184.216.34']);
		VodHttp::useTransport(static function ($url, $options) use ($port) {
			$options[CURLOPT_RESOLVE] = ['vod.example:' . $port . ':127.0.0.1'];
			$curl = curl_init(); curl_setopt_array($curl, $options); $ok = curl_exec($curl); curl_close($curl); return $ok;
		});
		file_put_contents($this->directory . '/router.php', '<?php if ($_SERVER["REQUEST_URI"] === "/playlist.m3u") { header("Content-Type: application/x-mpegurl"); echo file_get_contents(__DIR__ . "/playlist.m3u"); exit; } if ($_SERVER["REQUEST_URI"] === "/hls") { header("Content-Type: audio/mpegurl"); echo "#EXTM3U\n#EXT-X-TARGETDURATION:10\nsegment.ts"; exit; } if ($_SERVER["REQUEST_URI"] === "/missing.mp4") { http_response_code(404); exit; } header("Content-Type: video/mp4"); echo str_repeat("vod-content", 4096);');
		$this->server = proc_open([PHP_BINARY, '-S', $address, $this->directory . '/router.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
		for ($i = 0; $i < 100; $i++) {
			$ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
			if ($ready) { fclose($ready); return; }
			usleep(10000);
		}
		$this->fail('Local HTTP fixture did not start');
	}

	protected function tearDown(): void {
		VodHttp::useResolver(null);
		VodHttp::useTransport(null);
		@unlink(WATCH_TMP_PATH . 'vod_budget_' . $this->quota['batch_id'] . '.json');
		ProcessRunner::useRunner(null);
		NodeRpc::useTransport(null);
		SettingsManager::set($this->settings);
		unset($_FILES['m3u_file']);
		if (is_resource($this->server)) { proc_terminate($this->server); proc_close($this->server); }
		foreach ($this->jobs as $id) {
			foreach (glob(WATCH_TMP_PATH . 'vod_download_' . $id . '.*') ?: [] as $file) { unlink($file); }
		}
		foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
		foreach (array_merge(glob($this->directory . '/.vod-download-*') ?: [], glob($this->directory . '/.*.vod-receipt.json') ?: []) as $file) { unlink($file); }
		rmdir($this->directory);
	}

	public function testPlaylistPreservesNamesAndSelectsEntries(): void {
		$content = "#EXTM3U\n#EXTINF:123 tvg-logo=\"logo\",Movie, Part 1\nhttps://provider.test/movie/1.mp4?token=a%2Bb\n#EXTINF:-1,Show S01E02\nhttps://provider.test/series/2.mkv\n#EXTINF:-1,Bad protocol\nfile:///etc/passwd\n#EXTINF:-1,Duplicate\nhttps://provider.test/movie/1.mp4?token=a%2Bb\n";
		$file = $this->directory . '/playlist.m3u';
		file_put_contents($file, $content);
		$_FILES['m3u_file'] = ['tmp_name' => $file, 'name' => 'playlist.m3u', 'error' => UPLOAD_ERR_OK];
		$all = VodM3uImport::entries(['target_container' => 'mkv']);
		$this->assertCount(2, $all);
		$this->assertSame('Movie, Part 1', $all[0]['title']);
		$this->assertSame('https://provider.test/movie/1.mp4?token=a%2Bb', $all[0]['url']);
		$this->assertSame([$all[1]], VodM3uImport::entries(['target_container' => 'mkv', 'm3u_selection' => json_encode([hash('sha256', $all[1]['url'])])]));
		$this->assertSame([], VodM3uImport::entries(['m3u_selection' => '[]']));
		file_put_contents($file, "#EXTM3U\n#EXTINF:-1,Show S01E02\nhttps://provider.test/series/2.mkv\n#EXTINF:-1,Other title\nhttps://provider.test/movie/3.mp4\n");
		$this->assertSame([$all[1]], VodM3uImport::entries(['target_container' => 'mkv', 'm3u_selection' => json_encode([hash('sha256', $all[1]['url'])])]), 'selection follows the source URL when playlist order changes');
	}

	public function testReadsPlaylistFromHttpUrl(): void {
		file_put_contents($this->directory . '/playlist.m3u', "#EXTM3U\n#EXTINF:-1,Remote Film\n" . $this->base . "/film.mp4?token=a%2Bb\n");
		$entries = VodM3uImport::entries(['m3u_url' => $this->base . '/playlist.m3u', 'target_container' => 'mkv']);
		$this->assertSame([['url' => $this->base . '/film.mp4?token=a%2Bb', 'title' => 'Remote Film', 'container' => 'mkv']], $entries);
	}

	public function testFailedUploadIsRejectedInsteadOfFallingThroughToSingleMovieCreation(): void {
		$_FILES['m3u_file'] = ['tmp_name' => '', 'name' => 'large.m3u', 'error' => UPLOAD_ERR_INI_SIZE];
		$this->assertTrue(VodM3uImport::hasPlaylist([]));
		$this->expectException(InvalidArgumentException::class);
		VodM3uImport::entries([]);
	}

	private function start(string $path = '/film.mp4', string $title = '../A Movie'): array {
		return VodDownload::request(['function' => 'start', 'directory' => $this->directory, 'url' => $this->base . $path, 'title' => $title, 'quota' => $this->quota], function ($id): void { $this->jobs[] = $id; });
	}

	public function testDownloadPublishesCompleteFileAndReturnsLocalSource(): void {
		$job = $this->start();
		$this->assertTrue($job['result']);
		$this->assertTrue(VodDownload::run($job['id']));
		$status = VodDownload::request(['function' => 'status', 'id' => $job['id']]);
		$this->assertSame('complete', $status['state']);
		$this->assertSame($this->directory, dirname($status['path']));
		$this->assertSame(str_repeat('vod-content', 4096), file_get_contents($status['path']));
		$this->assertFileDoesNotExist(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.job');
		$this->assertSame([], glob($this->directory . '/.vod-download-*'));
		NodeRpc::useTransport(static function ($kind, $servers, $request) use ($job): string {
			return json_encode($request['function'] === 'start' ? $job : VodDownload::request($request));
		});
		$this->assertSame('s:7:' . $status['path'], VodDownload::receive(['file' => $this->base . '/film.mp4', 'title' => '../A Movie', 'download' => ['server_id' => 7, 'directory' => $this->directory]]));
		$this->assertSame($job, $this->start(), 'A repeated import reuses a completed download');
	}

	public function testWorkerLaunchUsesArgumentListAndReportsFailure(): void {
		$commands = [];
		ProcessRunner::useRunner(static function ($argv) use (&$commands): int { $commands[] = $argv; return 127; });
		$url = $this->base . '/launch.mp4';
		$name = VodDownload::filename($url, 'Title');
		$id = hash('sha256', $this->directory . '/' . $name . "\0" . $url);
		$this->jobs[] = $id;
		$reply = VodDownload::request(['function' => 'start', 'directory' => $this->directory, 'url' => $url, 'title' => 'Title']);
		$this->assertFalse($reply['result']);
		$this->assertSame([[PHP_BIN, MAIN_HOME . 'console.php', 'vod_download', $id]], $commands);
		$this->assertSame('failed', VodDownload::request(['function' => 'status', 'id' => $id])['state']);
		$this->assertFileDoesNotExist(WATCH_TMP_PATH . 'vod_download_' . $id . '.job');
	}

	public function testHttpFailureLeavesNoPublishedOrPartialFile(): void {
		$job = $this->start('/missing.mp4');
		$this->assertFalse(VodDownload::run($job['id']));
		$this->assertSame('failed', VodDownload::request(['function' => 'status', 'id' => $job['id']])['state']);
		$this->assertFileDoesNotExist($this->directory . '/' . VodDownload::filename($this->base . '/missing.mp4', '../A Movie'));
		$this->assertSame([], glob($this->directory . '/.vod-download-*'));
	}

	public function testRefusesOverwriteAndPathsOutsideScanRoots(): void {
		$name = VodDownload::filename($this->base . '/film.mp4', '../A Movie');
		file_put_contents($this->directory . '/' . $name, 'existing');
		$this->assertFalse($this->start()['result']);
		$this->assertSame('existing', file_get_contents($this->directory . '/' . $name));
		$this->assertNull(VodDownload::directory(dirname($this->directory)));
		symlink(dirname($this->directory), $this->directory . '/outside');
		$this->assertNull(VodDownload::directory($this->directory . '/outside'));
		$this->assertFalse(VodDownload::request(['function' => 'status', 'id' => '../bad'])['result']);
	}

	public function testRelativePlaylistAndChangedPreview(): void {
		$entries = VodM3uImport::parse("#EXTM3U\n#EXTINF:-1,Relative\n../media/movie.mp4?x=1\n#EXTINF:-1,Root\n/root.mkv\n#EXTINF:-1,Missing URL\n", [], 'https://provider.test/lists/sub/list.m3u');
		$this->assertSame(['https://provider.test/lists/media/movie.mp4?x=1', 'https://provider.test/root.mkv'], array_column($entries, 'url'));
		$file = $this->directory . '/changed.m3u';
		file_put_contents($file, "#EXTM3U\n#EXTINF:-1,New title\nhttps://provider.test/new.mp4\n");
		$_FILES['m3u_file'] = ['tmp_name' => $file, 'name' => 'changed.m3u', 'error' => UPLOAD_ERR_OK];
		$this->expectExceptionMessage('vod_m3u_changed');
		VodM3uImport::entries(['m3u_fingerprint' => VodM3uImport::fingerprint($entries)]);
	}

	public function testTransientStatusFailuresAreRetried(): void {
		$polls = 0; $starts = 0;
		NodeRpc::useTransport(static function ($kind, $servers, $request) use (&$polls, &$starts): string {
			if ($request['function'] === 'start') { return ++$starts === 1 ? json_encode(['error' => 'RPC_TIMEOUT']) : json_encode(['result' => true, 'id' => str_repeat('e', 64)]); }
			return ++$polls <= 4 ? '{}' : json_encode(['result' => true, 'state' => 'complete', 'path' => '/media/movie.mp4']);
		});
		$source = VodDownload::receive(['file' => 'https://provider.test/movie.mp4', 'title' => 'Movie', 'download' => ['server_id' => 7, 'directory' => '/media']], static function (): void {});
		$this->assertSame('s:7:/media/movie.mp4', $source);
		$this->assertSame(5, $polls);
		$this->assertSame(2, $starts);
	}

	public function testManifestContentTypesAndSignaturesAreRejected(): void {
		$file = $this->directory . '/body'; file_put_contents($file, 'body');
		foreach (['audio/mpegurl', 'audio/x-mpegurl', 'application/mpegurl', 'application/x-mpegurl', 'application/vnd.apple.mpegurl', 'audio/vnd.apple.mpegurl; charset=utf-8'] as $type) {
			$this->assertFalse(VodDownload::mediaBody($type, $file), $type);
		}
		file_put_contents($file, "#EXTM3U\n#EXT-X-VERSION:3\n");
		$this->assertFalse(VodDownload::mediaBody('application/octet-stream', $file));
		$job = $this->start('/hls');
		$this->assertFalse(VodDownload::run($job['id']));
		$this->assertSame('failed', VodDownload::request(['function' => 'status', 'id' => $job['id']])['state']);
	}

	public function testFileAndSharedImportBudgetsAbortTransfers(): void {
		$this->quota['max_file_bytes'] = 1024;
		$job = $this->start('/large.mp4');
		$this->assertFalse(VodDownload::run($job['id']));
		$this->assertSame([], glob($this->directory . '/.vod-download-*'));
		$this->quota['max_file_bytes'] = 50000;
		$this->quota['max_import_bytes'] = 60000;
		$one = $this->start('/one.mp4'); $two = $this->start('/two.mp4');
		$this->assertTrue(VodDownload::run($one['id']));
		$this->assertFalse(VodDownload::run($two['id']));
		$this->assertFileDoesNotExist($this->directory . '/' . VodDownload::filename($this->base . '/two.mp4', '../A Movie'));
	}

	public function testCrashAfterPublicationRecoversAndMetadataExpires(): void {
		$job = $this->start(); $this->assertTrue(VodDownload::run($job['id']));
		$status = VodDownload::request(['function' => 'status', 'id' => $job['id']]);
		// Emulate a crash after link publication: the durable receipt exists, terminal status does not.
		file_put_contents(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.json', json_encode(['state' => 'downloading', 'path' => $status['path'], 'updated' => 1]));
		$this->assertSame('complete', VodDownload::request(['function' => 'status', 'id' => $job['id']])['state']);
		foreach (glob(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.*') as $file) { touch($file, time() - VodDownload::METADATA_TTL - 10); }
		VodDownload::cleanup(true);
		$this->assertSame([], glob(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.*'));
		$this->assertSame($job, $this->start(), 'A durable receipt also survives metadata expiry');
	}

	public function testCleanupPreservesActiveJobsAndTheirBudget(): void {
		$job = $this->start();
		$worker = fopen(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.worker', 'c');
		flock($worker, LOCK_EX);
		$status = WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.json';
		file_put_contents($status, json_encode(['state' => 'downloading', 'batch_id' => $this->quota['batch_id'], 'updated' => 1]));
		$budget = WATCH_TMP_PATH . 'vod_budget_' . $this->quota['batch_id'] . '.json';
		file_put_contents($budget, '{}');
		foreach (array_merge(glob(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.*'), [$budget]) as $file) { touch($file, time() - VodDownload::METADATA_TTL - 10); }
		try {
			VodDownload::cleanup(true);
			$this->assertFileExists($status);
			$this->assertFileExists($budget);
		} finally { flock($worker, LOCK_UN); fclose($worker); }
		VodDownload::cleanup(true);
		$this->assertFileDoesNotExist($status);
		$this->assertFileDoesNotExist($budget);
	}

	public function testWorkerThatExitsBeforeReportingCanBeRetried(): void {
		$job = $this->start();
		file_put_contents(WATCH_TMP_PATH . 'vod_download_' . $job['id'] . '.json', json_encode(['state' => 'queued', 'updated' => time() - 120]));
		$this->assertSame('failed', VodDownload::request(['function' => 'status', 'id' => $job['id']])['state']);
		$this->assertSame($job, $this->start());
	}

	public function testRejectsHlsAndKeepsDownloadTimeoutSeparateFromMetadataImports(): void {
		$this->assertFalse($this->start('/film.m3u8')['result']);
		$batch = $this->directory . '/batch.jsonl';
		file_put_contents($batch, json_encode(['file' => 'https://example.test/a.mp4', 'download' => ['server_id' => 7]]) . "\n" . json_encode(['file' => 'https://example.test/b.mp4']) . "\n");
		$commands = iterator_to_array(VodItemImporter::batchCommands($batch, 300));
		$this->assertStringStartsWith('/usr/bin/timeout ' . (VodDownload::TIMEOUT + 180) . ' ', $commands[0]);
		$this->assertStringStartsWith('/usr/bin/timeout 300 ', $commands[1]);
	}
}
