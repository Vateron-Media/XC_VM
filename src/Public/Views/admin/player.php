<?php

use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Localization\Translator;
use XcVm\Infrastructure\Bootstrap\AdminScopeBootstrap;

SessionManager::start('admin');
SessionManager::requireAuth();
global $db, $rSettings, $rMobile, $rServers, $rProxyServers, $rDetect,
    $rTimeout, $rProtocol, $allServers, $rPermissions, $allowedLangs,
    $rServerError, $allServersHealthy, $updateRequired, $rUserInfo,
    $_STATUS, $customScript, $language;
$language = Translator::class;
AdminScopeBootstrap::hydrateAdminContext();

if (RequestManager::has('id')) {
    if (PageAuthorization::checkPermissions()) {
        $rExpires = time() + 14400;
        $rTokenData = array('session_id' => session_id(), 'expires' => $rExpires, 'stream_id' => intval(RequestManager::get('id')), 'ip' => NetworkUtils::getUserIP());

        if (RequestManager::has('container')) {
            $rTokenData['container'] = RequestManager::get('container');
        }

        if (RequestManager::has('start')) {
            $rTokenData['start'] = RequestManager::get('start');
        }

        if (RequestManager::has('duration')) {
            $rTokenData['duration'] = RequestManager::get('duration');
        }

        $streamType = (in_array(RequestManager::get('type'), array('live', 'timeshift')) ? 'hls' : preg_replace('/[^A-Za-z0-9 ]/', '', $rTokenData['container']));

        if (in_array(RequestManager::get('type'), array('live', 'timeshift'))) {
            $db->query('SELECT `server_id`, `on_demand` FROM `streams_servers` WHERE ((`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0) OR (`streams_servers`.`on_demand` = 1)) AND `stream_id` = ?;', RequestManager::get('id'));
        } else {
            $db->query('SELECT `server_id`, `on_demand` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE (`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` <> 1) AND `stream_id` = ?;', RequestManager::get('id'));
        }

        $rServerID = null;

        foreach ($db->get_rows() as $rRow) {
            if ($rRow['server_id'] == SERVER_ID || !$rServerID) {
                $rServerID = $rRow['server_id'];
            }
        }

        if ($rServerID) {
            $rUIToken = ViewerKey::mint(json_encode($rTokenData), $rServers, (int) $rServerID, SettingsManager::getAll());

            // An on-demand stream starts on the browser's first request: the
            // uitoken path of admin/live runs the same start, so MAIN sends the
            // node no stream password.
            $rURL = $rProtocol . '://' . (($rServers[$rServerID]['domain_name'] ? explode(',', $rServers[$rServerID]['domain_name'])[0] : $rServers[$rServerID]['server_ip'])) . ':' . ((AdminHelpers::issecure() ? $rServers[$rServerID]['https_broadcast_port'] : $rServers[$rServerID]['http_broadcast_port'])) . '/admin/' . ((RequestManager::get('type') == 'live' ? 'live' : (RequestManager::get('type') == 'timeshift' ? 'timeshift' : 'vod'))) . '?uitoken=' . $rUIToken . ((RequestManager::get('type') == 'live' ? '&extension=.m3u8' : ''));

            // canPlayType() rejects made-up MIMEs like video/mkv, so unknown containers
            // are declared as video/mp4 — the browser sniffs the real container itself.
            $rMimeMap = array(
                'mp4'  => 'video/mp4',
                'm4v' => 'video/mp4',
                'mov' => 'video/mp4',
                'webm' => 'video/webm',
                'ogg' => 'video/ogg',
                'ogv' => 'video/ogg',
            );
            $rMime = ($streamType === 'hls')
                ? 'application/x-mpegURL'
                : ($rMimeMap[strtolower($streamType)] ?? 'video/mp4');

?>
            <html>

            <head>
                <script src="assets/js/vendor.min.js"></script>
                <link rel="stylesheet" href="assets/vendor/libs/videojs/video-js.min.css">
                <script src="assets/vendor/libs/videojs/video.min.js"></script>
                <style>
                    html,
                    body {
                        margin: 0;
                        padding: 0;
                        width: 100%;
                        height: 100%;
                        overflow: hidden;
                        background: #000;
                    }
                </style>
            </head>

            <body>
                <video id="now__playing__player" class="video-js vjs-big-play-centered" controls preload="auto"></video>
                <script>
                    $(document).ready(function() {
                        var rPlayer = videojs("now__playing__player", {
                            autoplay: true,
                            fill: true,
                            liveui: true,
                            controls: true
                        });
                        rPlayer.src({
                            src: "<?php echo $rURL; ?>",
                            type: "<?php echo $rMime; ?>"
                        });
                    });
                </script>
            </body>

            </html>
<?php
        } else {
            exit();
        }
    } else {
        AdminHelpers::goHome();
    }
} else {
    exit();
}
?>