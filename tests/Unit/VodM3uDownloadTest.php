<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Vod\VodDownload;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Domain\Vod\VodM3uImport;

final class VodM3uDownloadTest extends TestCase {
    private string $directory;
    private array $settings;
    private array $jobs = [];
    private $server;
    private string $base;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/xcvm-vod-download-' . bin2hex(random_bytes(5));
        mkdir($this->directory);
        $this->settings = SettingsManager::getAll();
        SettingsManager::set(['lb_scan_roots' => [$this->directory]]);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->base = 'http://' . $address;
        file_put_contents($this->directory . '/router.php', '<?php if ($_SERVER["REQUEST_URI"] === "/playlist.m3u") { header("Content-Type: application/x-mpegurl"); echo file_get_contents(__DIR__ . "/playlist.m3u"); exit; } if ($_SERVER["REQUEST_URI"] === "/missing.mp4") { http_response_code(404); exit; } header("Content-Type: video/mp4"); echo str_repeat("vod-content", 4096);');
        $this->server = proc_open([PHP_BINARY, '-S', $address, $this->directory . '/router.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100; $i++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
            if ($ready) { fclose($ready); return; }
            usleep(10000);
        }
        $this->fail('Local HTTP fixture did not start');
    }

    protected function tearDown(): void {
        NodeRpc::useTransport(null);
        SettingsManager::set($this->settings);
        unset($_FILES['m3u_file']);
        if (is_resource($this->server)) { proc_terminate($this->server); proc_close($this->server); }
        foreach ($this->jobs as $id) {
            foreach (glob(WATCH_TMP_PATH . 'vod_download_' . $id . '.*') ?: [] as $file) { unlink($file); }
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) { unlink($file); }
        foreach (glob($this->directory . '/.vod-download-*') ?: [] as $file) { unlink($file); }
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
        return VodDownload::request(['function' => 'start', 'directory' => $this->directory, 'url' => $this->base . $path, 'title' => $title], function ($id): void { $this->jobs[] = $id; });
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

    public function testRejectsHlsAndKeepsDownloadTimeoutSeparateFromMetadataImports(): void {
        $this->assertFalse($this->start('/film.m3u8')['result']);
        $batch = $this->directory . '/batch.jsonl';
        file_put_contents($batch, json_encode(['file' => 'https://example.test/a.mp4', 'download' => ['server_id' => 7]]) . "\n" . json_encode(['file' => 'https://example.test/b.mp4']) . "\n");
        $commands = iterator_to_array(VodItemImporter::batchCommands($batch, 300));
        $this->assertStringStartsWith('/usr/bin/timeout ' . (VodDownload::TIMEOUT + 180) . ' ', $commands[0]);
        $this->assertStringStartsWith('/usr/bin/timeout 300 ', $commands[1]);
    }
}
