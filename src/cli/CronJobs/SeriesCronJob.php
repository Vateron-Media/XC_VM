<?php

/**
 * SeriesCronJob — series cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class SeriesCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:series';
    }

    public function getDescription(): string {
        return 'Cron: update series playlists and scan bouquets';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        require INCLUDES_PATH . 'admin.php';

        $this->setProcessTitle('XC_VM[Series]');
        $this->acquireCronLock();

        $this->loadCron();

        @unlink($this->rIdentifier);

        return 0;
    }

    private function loadCron(): void {
        global $db;

        if (time() - SettingsManager::getAll()['cc_time'] < 3600) {
            return;
        }

        $db->query('UPDATE `settings` SET `cc_time` = ?;', time());
        $db->query('SELECT `id`, `stream_display_name`, `series_no`, `stream_source` FROM `streams` WHERE `type` = 3 AND `series_no` <> 0;');

        if ($db->num_rows() > 0) {
            foreach ($db->get_rows() as $rRow) {
                $rPlaylist = SeriesService::generatePlaylist(intval($rRow['series_no']));
                if ($rPlaylist['success']) {
                    $rSourceArray = json_decode($rRow['stream_source'], true);
                    $UpdateSeries = false;
                    foreach ($rPlaylist['sources'] as $rSource) {
                        if (!in_array($rSource, $rSourceArray)) {
                            $UpdateSeries = true;
                        }
                    }
                    if ($UpdateSeries) {
                        $db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode($rPlaylist['sources'], JSON_UNESCAPED_UNICODE), $rRow['id']);
                        echo 'Updated: ' . $rRow['stream_display_name'] . "\n";
                    }
                }
            }
        }

        BouquetService::scan();
    }
}
