<?php

/**
 * TmpCronJob — tmp cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class TmpCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:tmp';
    }

    public function getDescription(): string {
        return 'Cron: cleanup temporary files and stale playlists';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        global $db;
        $db->close_mysql();

        $this->setProcessTitle('XC_VM[TMP]');
        $this->acquireCronLock();

        $rTmpPaths = array(
            TMP_PATH, CRONS_TMP_PATH, DIVERGENCE_TMP_PATH,
            FLOOD_TMP_PATH, MINISTRA_TMP_PATH, SIGNALS_TMP_PATH, LOGS_TMP_PATH
        );

        foreach ($rTmpPaths as $rTmpPath) {
            if (!is_dir($rTmpPath)) {
                @mkdir($rTmpPath, 0775, true);
                continue;
            }
            foreach (scandir($rTmpPath) as $rFile) {
                $fullPath = $rTmpPath . '/' . $rFile;
                if ($rFile === '.' || $rFile === '..') {
                    continue;
                }
                if (is_file($fullPath) && time() - filemtime($fullPath) >= 600 && stripos($rFile, 'ministra_') === false) {
                    unlink($fullPath);
                }
            }
        }

        foreach (scandir(PLAYLIST_PATH) as $rFile) {
            $fullPath = rtrim(PLAYLIST_PATH, '/') . '/' . $rFile;
            if ($rFile === '.' || $rFile === '..') {
                continue;
            }
            if (is_file($fullPath)) {
                if (SettingsManager::getAll()['cache_playlists'] <= time() - filemtime($fullPath)) {
                    unlink($fullPath);
                }
            }
        }

        clearstatcache();
        @unlink($this->rIdentifier);

        return 0;
    }
}
