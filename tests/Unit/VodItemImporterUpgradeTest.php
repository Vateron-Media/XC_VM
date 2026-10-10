<?php

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Domain\Vod\VodItemImportHalt;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use PHPUnit\Framework\TestCase;

/**
 * VodItemImporter::applyUpgrade() — always ends by throwing VodItemImportHalt (the
 * exit()-replacement introduced for testability), so every scenario is
 * asserted with expectException() around the outcome it produces first.
 */
final class VodItemImporterUpgradeTest extends TestCase {

    private TestDb $db;
    private array $tmpFiles = array();

    protected function setUp(): void {
        // First, before anything that can throw: tearDown() closes this buffer, never PHPUnit's.
        // The importer reports its progress on stdout for the CLI run: not this test's output.
        ob_start();
        $this->db = new TestDb();
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_source TEXT, target_container TEXT);');
        $this->db->exec('CREATE TABLE streams_servers (stream_id INTEGER, server_id INTEGER, bitrate INTEGER, current_source TEXT, to_analyze INTEGER, pid INTEGER, stream_started INTEGER, stream_info TEXT, compatible INTEGER, video_codec TEXT, audio_codec TEXT, resolution TEXT, stream_status INTEGER);');
        if (!defined('SERVER_ID')) {
            define('SERVER_ID', 1);
        }
        $this->db->exec(InstallSchema::table('queue'));
        VodItemImporter::setDb($this->db);
        DatabaseFactory::set($this->db);
    }

    protected function tearDown(): void {
        ob_end_clean();
        NodeRpc::useTransport(null);
        EventDispatcher::clear();
        DatabaseFactory::reset();
        foreach ($this->tmpFiles as $rPath) {
            @unlink($rPath);
        }
    }

    private function tmpFile(int $rSizeBytes): string {
        $rPath = WATCH_TMP_PATH . 'upgrade_test_' . uniqid() . '.mkv';
        file_put_contents($rPath, str_repeat('a', $rSizeBytes));
        $this->tmpFiles[] = $rPath;
        return $rPath;
    }

    private function threadData(array $rOverrides = array()): array {
        return array_merge(array(
            'auto_upgrade' => true,
            'auto_encode' => false,
        ), $rOverrides);
    }

    public function testDoesNothingWhenAutoUpgradeIsDisabled(): void {
        $rWriteCacheCalled = false;
        try {
            VodItemImporter::applyUpgrade(
                array('id' => 10, 'source' => 's:' . SERVER_ID . ':/old.mkv'),
                $this->threadData(array('auto_upgrade' => false)),
                '/new.mkv', array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie',
                function () use (&$rWriteCacheCalled) { $rWriteCacheCalled = true; }
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertFalse($rWriteCacheCalled);
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testDoesNothingWhenTheOldSourceIsFromADifferentServer(): void {
        // the old source sits on a server other than SERVER_ID.
        $this->expectException(VodItemImportHalt::class);

        VodItemImporter::applyUpgrade(
            array('id' => 10, 'source' => 's:' . (SERVER_ID + 1) . ':/old.mkv'),
            $this->threadData(), '/new.mkv', array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie',
            function () { $this->fail('cache writer must not run'); }
        );
    }

    public function testDoesNothingWhenTheExistingFileIsAlreadyAsLargeOrLarger(): void {
        $rOldFile = $this->tmpFile(2000);
        $rNewFile = $this->tmpFile(1000);

        try {
            VodItemImporter::applyUpgrade(
                array('id' => 10, 'source' => 's:' . SERVER_ID . ':' . $rOldFile),
                $this->threadData(), $rNewFile, array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie',
                function () { $this->fail('cache writer must not run'); }
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testUpgradesWhenTheNewFileIsLargerAndWritesTheCache(): void {
        $this->db->query("INSERT INTO streams (id, stream_source, target_container) VALUES (10, 'old', 'avi');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (10, ?);', SERVER_ID);
        $rOldFile = $this->tmpFile(1000);
        $rNewFile = $this->tmpFile(5000);
        $rCacheWriteReceived = null;

        try {
            VodItemImporter::applyUpgrade(
                array('id' => 10, 'source' => 's:' . SERVER_ID . ':' . $rOldFile),
                $this->threadData(), $rNewFile, array('stream_source' => 'new-source', 'target_container' => 'mkv'), 1, 'movie',
                function ($rUpgradeData) use (&$rCacheWriteReceived) { $rCacheWriteReceived = $rUpgradeData; }
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(array('id' => 10, 'source' => 's:' . SERVER_ID . ':' . $rOldFile), $rCacheWriteReceived);
        $this->db->query('SELECT `stream_source`, `target_container` FROM `streams` WHERE `id` = 10;');
        $rRow = $this->db->get_row();
        $this->assertSame('new-source', $rRow['stream_source']);
        $this->assertSame('mkv', $rRow['target_container']);
        $this->db->query('SELECT `bitrate`, `stream_status` FROM `streams_servers` WHERE `stream_id` = 10;');
        $rServerRow = $this->db->get_row();
        $this->assertNull($rServerRow['bitrate']);
        $this->assertSame('0', (string) $rServerRow['stream_status']);
    }

    public function testUpgradesWhenNoOldFileExistsAtAll(): void {
        // !file_exists($rActualPath) unconditionally counts as "better source".
        $this->db->query("INSERT INTO streams (id, stream_source, target_container) VALUES (10, 'old', 'avi');");
        $this->db->query('INSERT INTO streams_servers (stream_id, server_id) VALUES (10, ?);', SERVER_ID);
        $rNewFile = $this->tmpFile(10);

        $this->expectException(VodItemImportHalt::class);
        VodItemImporter::applyUpgrade(
            array('id' => 10, 'source' => 's:' . SERVER_ID . ':' . WATCH_TMP_PATH . 'does_not_exist.mkv'),
            $this->threadData(), $rNewFile, array('stream_source' => 'new-source', 'target_container' => 'mkv'), 1, 'movie',
            function () {}
        );
    }

    // ── another server's file (a load balancer's folder scanned from MAIN) ──

    /**
     * An import of server 2's file, upgraded or not by the sizes server 2 gives.
     *
     * @param array<string, int|null>|null $rSizes path => bytes on the node; null: it knows no sizes
     * @return array{statuses: list<int>, source: ?string, cached: ?string}
     */
    private function upgradeOnNode(?array $rSizes, string $rOldSource = 's:2:/lb/Show/old.mkv'): array {
        $rStatuses = array();
        EventDispatcher::listen(VodImportResultEvent::class, static function (VodImportResultEvent $rEvent) use (&$rStatuses): void {
            $rStatuses[] = $rEvent->status;
        });
        NodeRpc::useTransport(static function (string $rKind, array $rServers, array $rData) use ($rSizes): string {
            $rPath = urldecode($rData['dir']);
            if ($rSizes === null) {
                return (string) json_encode(array('files' => array($rPath => 1), 'next' => null));
            }
            return (string) json_encode(array('files' => (object) array(), 'sizes' => (object) (isset($rSizes[$rPath]) ? array($rPath => $rSizes[$rPath]) : array()), 'next' => null));
        });
        $this->db->exec("INSERT INTO streams (id, stream_source, target_container) VALUES (10, '[\"" . $rOldSource . "\"]', 'mkv')");
        // The episode as server 2 holds it, and a row of this server's that is not the file's.
        $this->db->exec('INSERT INTO streams_servers (stream_id, server_id, bitrate, pid, stream_status) VALUES (10, 2, 1234, 55, 0), (10, ' . SERVER_ID . ', 1234, 55, 0)');
        $rCached = null;
        try {
            VodItemImporter::applyUpgrade(
                array('id' => 10, 'source' => $rOldSource),
                $this->threadData(array('import' => true, 'servers' => array(2), 'auto_encode' => true)),
                's:2:/lb/Show/new.mkv', array('stream_source' => '["s:2:/lb/Show/new.mkv"]', 'target_container' => 'mkv'), 2, 'episode',
                function ($rUpgradeData, $rNewSource) use (&$rCached) { $rCached = $rNewSource; }
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }
        $this->db->query('SELECT stream_source FROM streams WHERE id = 10');
        $rSource = $this->db->get_col();
        $this->db->query('SELECT server_id, bitrate, pid FROM streams_servers WHERE stream_id = 10 ORDER BY server_id');
        $rRows = array();
        foreach ($this->db->get_rows() as $rRow) {
            $rRows[(int) $rRow['server_id']] = array($rRow['bitrate'] === null ? null : (int) $rRow['bitrate'], $rRow['pid'] === null ? null : (int) $rRow['pid']);
        }
        $this->db->query('SELECT type, server_id, stream_id FROM queue');
        $rQueued = array_map(static fn(array $rRow): string => $rRow['type'] . ' ' . $rRow['stream_id'] . ' on ' . $rRow['server_id'], $this->db->get_rows());
        return array('statuses' => $rStatuses, 'source' => $rSource, 'cached' => $rCached, 'rows' => $rRows, 'queued' => $rQueued);
    }

    public function testALoadBalancersBetterCopyIsUpgradedByTheSizesItGives(): void {
        $rOut = $this->upgradeOnNode(array('/lb/Show/old.mkv' => 700, '/lb/Show/new.mkv' => 900));

        $this->assertSame(array(VodImportResultEvent::STATUS_UPGRADED), $rOut['statuses']);
        $this->assertSame('["s:2:/lb/Show/new.mkv"]', $rOut['source']);
        $this->assertSame('s:2:/lb/Show/new.mkv', $rOut['cached'], 'the cache names the file on its own server');
        // The file's server starts over and encodes the new file: it was this
        // server's row and queue, so the node went on serving the old file.
        $this->assertSame(array(SERVER_ID => array(1234, 55), 2 => array(null, null)), $rOut['rows']);
        $this->assertSame(array('movie 10 on 2'), $rOut['queued']);
    }

    public function testALoadBalancersCopyThatIsGoneIsReplaced(): void {
        $rOut = $this->upgradeOnNode(array('/lb/Show/new.mkv' => 900));

        $this->assertSame(array(VodImportResultEvent::STATUS_UPGRADED), $rOut['statuses']);
    }

    public function testACopyThatIsNotBetterIsKeptAndSaidSo(): void {
        $rOut = $this->upgradeOnNode(array('/lb/Show/old.mkv' => 900, '/lb/Show/new.mkv' => 900));

        $this->assertSame(array(VodImportResultEvent::STATUS_DUPLICATE), $rOut['statuses'], 'the watch log gets a row: the file was skipped in silence on every scan');
        $this->assertSame('["s:2:/lb/Show/old.mkv"]', $rOut['source']);
        $this->assertNull($rOut['cached']);
        $this->assertSame(array(SERVER_ID => array(1234, 55), 2 => array(1234, 55)), $rOut['rows'], 'nothing starts over');
        $this->assertSame(array(), $rOut['queued']);
    }

    public function testANodeThatGivesNoSizesUpgradesNothing(): void {
        $rOut = $this->upgradeOnNode(null);

        $this->assertSame(array(VodImportResultEvent::STATUS_DUPLICATE), $rOut['statuses']);
        $this->assertSame('["s:2:/lb/Show/old.mkv"]', $rOut['source']);
    }

    public function testACopyOnAnotherServerOrAURLIsNotCompared(): void {
        foreach (array('s:3:/lb/Show/old.mkv', 'http://example.test/old.mkv') as $rOldSource) {
            $this->db->exec('DELETE FROM streams');
            $rOut = $this->upgradeOnNode(array('/lb/Show/old.mkv' => 1, '/lb/Show/new.mkv' => 900), $rOldSource);
            $this->assertSame(VodImportResultEvent::STATUS_DUPLICATE, end($rOut['statuses']), $rOldSource);
            $this->assertSame('["' . $rOldSource . '"]', $rOut['source'], $rOldSource);
        }
    }

    public function testThisServersCopyThatIsNotBetterIsSaidTooAndStillComparedOnItsDisk(): void {
        $rStatuses = array();
        EventDispatcher::listen(VodImportResultEvent::class, static function (VodImportResultEvent $rEvent) use (&$rStatuses): void {
            $rStatuses[] = $rEvent->status;
        });
        NodeRpc::useTransport(function (): string {
            $this->fail('this server\'s files are not asked of a node');
        });
        $rOld = $this->tmpFile(200);
        $rNew = $this->tmpFile(100);
        try {
            VodItemImporter::applyUpgrade(array('id' => 10, 'source' => 's:' . SERVER_ID . ':' . $rOld), $this->threadData(), $rNew, array('stream_source' => 'x', 'target_container' => 'mkv'), 1, 'movie', function () {});
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(array(VodImportResultEvent::STATUS_DUPLICATE), $rStatuses);
    }
}
