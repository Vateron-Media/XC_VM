<?php

/**
 * Quick tools (Bootstrap 5). Seven tabs of one-shot maintenance actions (streams / lines /
 * MAG / Enigma / logs / general / ASN). Each action is a Run button that, after a
 * confirmation, POSTs its action name to post.php?action=quick_tools (the handler checks
 * isset($_POST[<action>])). Reached full-page in the new-UI shell.
 */

$rTabs = [
    'streams' => ['tabler-player-play', 'streams', [
        ['restart_all_streams', 'restart_all_streams'],
        ['restart_online_streams', 'restart_online_streams'],
        ['start_offline_streams', 'start_offline_streams'],
        ['stop_online_streams', 'stop_online_streams'],
        ['stop_down_streams', 'stop_down_streams'],
        ['restart_down_streams', 'restart_down_streams'],
        ['symlink_all_movies', 'symlink_all_movies'],
        ['symlink_all_episodes', 'symlink_all_episodes'],
        ['recreate_channels', 'recreate_channels'],
        ['delete_duplicate_vod', 'delete_duplicates'],
        ['replace_movie_years', 'replace_movie_years'],
        ['replace_series_years', 'replace_series_years'],
        ['check_web_player_compatibility', 'check_compatibility'],
        ['re_scan_all_vod', 'rescan_vod'],
        ['add_tmdb_id_to_movies', 'add_tmdb_ids'],
        ['restore_lost_images', 'restore_images'],
    ]],
    'lines' => ['tabler-users', 'lines', [
        ['remove_expired_lines', 'remove_expired'],
        ['remove_trial_lines', 'remove_trial'],
        ['remove_expired_trial_lines', 'remove_expired_trial'],
        ['remove_null_lines', 'remove_null_lines'],
        ['enable_isp_lock', 'enable_isp'],
        ['disable_isp_lock', 'disable_isp'],
        ['flush_isp_lock', 'flush_isp'],
    ]],
    'mag' => ['tabler-device-desktop', 'mag', [
        ['remove_expired_devices', 'remove_expired_mag'],
        ['remove_trial_devices', 'remove_trial_mag'],
        ['remove_expired_trial_devices', 'remove_expired_trial_mag'],
        ['flush_isp_lock', 'flush_isp_mag'],
        ['enable_isp_lock', 'enable_isp_mag'],
        ['disable_isp_lock', 'disable_isp_mag'],
        ['enable_mag_lock', 'enable_mag_lock'],
        ['disable_mag_lock', 'disable_mag_lock'],
        ['flush_mag_lock', 'clear_mag_lock'],
        ['purge_unlinked_lines', 'purge_unlinked_lines_mag'],
        ['update_movie_ratings', 'update_ratings'],
    ]],
    'enigma' => ['tabler-device-tv', 'enigma', [
        ['remove_expired_devices', 'remove_expired_e2'],
        ['remove_trial_devices', 'remove_trial_e2'],
        ['remove_expired_trial_devices', 'remove_expired_trial_e2'],
        ['flush_isp_lock', 'flush_isp_e2'],
        ['enable_isp_lock', 'enable_isp_e2'],
        ['disable_isp_lock', 'disable_isp_e2'],
        ['purge_unlinked_lines', 'purge_unlinked_lines_e2'],
    ]],
    'logs' => ['tabler-clipboard-text', 'logs', [
        ['clear_activity_logs', 'clear_activity_logs'],
        ['clear_client_logs', 'clear_client_logs'],
        ['clear_credit_logs', 'clear_credit_logs'],
        ['clear_login_flood', 'clear_login_flood'],
        ['clear_login_logs', 'clear_login_logs'],
        ['clear_mag_events', 'clear_mag_events'],
        ['clear_panel_logs', 'clear_panel_logs'],
        ['clear_stream_errors', 'clear_stream_errors'],
        ['clear_stream_logs', 'clear_stream_logs'],
        ['clear_user_logs', 'clear_user_logs'],
        ['clear_watch_logs', 'clear_watch_logs'],
    ]],
    'general' => ['tabler-tool', 'general', [
        ['block_trial_lines', 'block_trial_lines'],
        ['unblock_trial_lines', 'unblock_trial_lines'],
        ['reauthorise_mysql_on_servers', 'reauthorise_mysql'],
        ['flush_blocked_ips', 'flush_blocked_ips'],
        ['flush_blocked_isps', 'flush_blocked_isps'],
        ['flush_blocked_uas', 'flush_blocked_uas'],
        ['flush_country_lock', 'flush_country_lock'],
        ['force_epg_update', 'force_epg_update'],
        ['clean_up_streams_table', 'cleanup_streams'],
        ['force_movies_tmdb_refresh', 'force_update_movies'],
        ['force_series_tmdb_refresh', 'force_update_series'],
        ['force_episodes_tmdb_refresh', 'force_update_episodes'],
    ]],
    'asns' => ['tabler-server', 'asns', [
        ['block_all_isps', 'block_all_isps'],
        ['unblock_all_isps', 'unblock_all_isps'],
        ['block_all_servers', 'block_all_servers'],
        ['unblock_all_servers', 'unblock_all_servers'],
        ['block_all_education', 'block_all_education'],
        ['unblock_all_education', 'unblock_all_education'],
        ['block_all_businesses', 'block_all_businesses'],
        ['unblock_all_businesses', 'unblock_all_businesses'],
        ['flush_blocked_asns', 'flush_blocked_asns'],
    ]],
];
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('quick_tools'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
            <?php $rFirst = true;
            foreach ($rTabs as $rId => $rTab): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link <?= $rFirst ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#qt-<?= $rId; ?>" role="tab"><i class="icon-base ti <?= $rTab[0]; ?> me-1"></i><?= $language::get($rTab[1]); ?></button>
                </li>
            <?php $rFirst = false;
            endforeach; ?>
        </ul>
        <div class="tab-content p-0">
            <?php $rFirst = true;
            foreach ($rTabs as $rId => $rTab): ?>
                <div class="tab-pane fade <?= $rFirst ? 'show active' : ''; ?>" id="qt-<?= $rId; ?>" role="tabpanel">
                    <div class="row g-3">
                        <?php foreach ($rTab[2] as $rAction): ?>
                            <div class="col-md-6">
                                <div class="border rounded p-3 d-flex justify-content-between align-items-center gap-3">
                                    <span><?= $language::get($rAction[0]); ?></span>
                                    <button type="button" class="btn btn-sm btn-label-info flex-shrink-0 js-run" data-action="<?= htmlspecialchars($rAction[1], ENT_QUOTES); ?>">Run</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php $rFirst = false;
            endforeach; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var toast = window.xcToast || function() {};
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-run');
            if (!btn) {
                return;
            }
            var action = btn.getAttribute('data-action');
            (window.xcConfirm ? window.xcConfirm("Run this tool? This can't be undone.") : Promise.resolve(confirm('Run this tool?'))).then(function(ok) {
                if (!ok) {
                    return;
                }
                btn.disabled = true;
                var original = btn.innerHTML;
                btn.innerHTML = '<i class="icon-base ti tabler-loader"></i>';
                var fd = new FormData();
                fd.append(action, 'Run');
                fetch('post.php?action=quick_tools', {
                        method: 'POST',
                        body: fd,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(txt) {
                        var d;
                        try {
                            d = JSON.parse(txt);
                        } catch (err) {
                            d = {
                                result: true
                            };
                        }
                        btn.disabled = false;
                        btn.innerHTML = original;
                        toast(d && d.result !== false ? 'Task started.' : errText, d && d.result !== false ? 'success' : 'error');
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.innerHTML = original;
                        toast(errText, 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>