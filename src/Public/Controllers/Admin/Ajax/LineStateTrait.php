<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;

/**
 * Shared line-state sub-actions for admin-ajax controllers that act on a line
 * (identified by its `user_id`): enable / disable / ban / unban / kill.
 *
 * Used by both {@see DeviceAjaxController} (mag/enigma devices act on their
 * owning line) and {@see UserAjaxController} (the line itself).
 *
 * @phpstan-require-extends BaseAjaxController
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
trait LineStateTrait {

    /**
     * @param array<string, mixed> $rExtra
     */
    abstract protected function ok(array $rExtra = array()): never;

    /**
     * @param array<string, mixed> $rExtra
     */
    abstract protected function fail(array $rExtra = array()): never;

    /**
     * Handle the enable/disable/ban/unban/kill sub-actions for a line and
     * terminate the request. An unhandled sub falls through to `{"result":false}`.
     *
     * @param mixed $rUserID line id the action targets
     */
    private function lineStateAction(string $rSub, $rUserID): never {
        global $db;

        if ($rSub == 'enable') {
            $db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rUserID);
            LineService::updateLineSignal($rUserID);
            $this->ok();
        }

        if ($rSub == 'disable') {
            $db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rUserID);
            LineService::updateLineSignal($rUserID);
            $this->ok();
        }

        if ($rSub == 'ban') {
            $db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserID);
            LineService::updateLineSignal($rUserID);
            $this->ok();
        }

        if ($rSub == 'unban') {
            $db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rUserID);
            LineService::updateLineSignal($rUserID);
            $this->ok();
        }

        if ($rSub == 'kill') {
            if (SettingsManager::get('redis_handler')) {
                foreach (ConnectionTracker::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
                    ConnectionTracker::closeConnection($rConnection);
                }
            } else {
                $db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rUserID);

                foreach ($db->get_rows() as $rRow) {
                    ConnectionTracker::closeConnection($rRow);
                }
            }

            $this->ok();
        }

        $this->fail();
    }
}
