<?php

/**
 * Общий shutdown handler для streaming endpoints (live, vod, timeshift).
 *
 * Заменяет дублированные function shutdown() в трёх файлах.
 * auth.php и rtmp.php используют собственный (другую логику — BruteforceGuard).
 *
 * Использование:
 *   register_shutdown_function([ShutdownHandler::class, 'handle'], 'live');
 *
 * @package XC_VM_Streaming_Lifecycle
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ShutdownHandler {

    /**
     * @param string $rContext  'live' | 'vod' | 'timeshift'
     */
    public static function handle($rContext = 'live') {
        global $rCloseCon, $rTokenData, $rPID, $rChannelInfo, $rStreamID, $rServers, $db;
        $rSettings = CacheReader::get('settings');

        if ($rCloseCon) {
            if ($rSettings['redis_handler']) {
                if (!RedisManager::isConnected()) {
                    RedisManager::ensureConnected();
                }

                $rConnection = ConnectionTracker::getConnection($rTokenData['uuid']);

                if ($rConnection && $rConnection['pid'] == $rPID) {
                    $rChanges = array('hls_last_read' => time() - intval($rServers[SERVER_ID]['time_offset']));
                    ConnectionTracker::updateConnection($rConnection, $rChanges, 'close');
                }
            } else {
                if (!is_object($db)) {
                    DatabaseFactory::connect();
                }

                $db->query(
                    'UPDATE `lines_live` SET `hls_end` = 1, `hls_last_read` = ? WHERE `uuid` = ? AND `pid` = ?;',
                    time() - intval($rServers[SERVER_ID]['time_offset']),
                    $rTokenData['uuid'],
                    $rPID
                );
            }

            // Только live: очистка tmp-файлов соединения
            if ($rContext === 'live') {
                @unlink(CONS_TMP_PATH . $rTokenData['uuid']);
                @unlink(CONS_TMP_PATH . $rStreamID . '/' . $rTokenData['uuid']);
            }
        }

        // Только live: on-demand instant off
        if ($rContext === 'live' && $rSettings['on_demand_instant_off'] && $rChannelInfo['on_demand'] == 1) {
            ConnectionTracker::removeFromQueue($rStreamID, $rPID);
        }

        // Закрытие ресурсов
        if (!$rSettings['redis_handler'] && is_object($db)) {
            DatabaseFactory::close();
        } elseif ($rSettings['redis_handler'] && RedisManager::isConnected()) {
            RedisManager::closeInstance();
        }
    }
}
