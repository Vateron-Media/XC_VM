<?php

/**
 * Cache & Redis settings (Bootstrap 5). A performance summary bar plus two tabs — the
 * caching system (cron schedule + thread count + live cache counters) and the Redis
 * connection handler (server/auth status). Cron settings save via post.php?action=cache;
 * enable/disable/regenerate/clear actions run through ./api?action=<action> and reload.
 * Reached full-page in the new-UI shell.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\TimeUtils;
use XcVm\Domain\Server\ServerRepository;

$rColour = 'secondary';
$rHeader = 'Poor';
$rSize = 25;
$rMessage = "You're using neither Caching nor the Redis Connection Handler; the server will perform poorly compared to having either enabled.";
if (SettingsManager::get('enable_cache') || SettingsManager::get('redis_handler')) {
    $rHeader = 'Good';
    $rColour = 'info';
    $rSize = 75;
    $rMessage = "Redis Connection Handler is disabled. With a lot of throughput you'll see better performance with Redis enabled — consider it above ~10,000 concurrent connections.";
    if (!SettingsManager::get('enable_cache')) {
        $rSize = 50;
        $rMessage = 'Caching is disabled; this significantly impacts performance under load.';
    }
    if (SettingsManager::get('enable_cache') && SettingsManager::get('redis_handler')) {
        $rSize = 100;
        $rColour = 'primary';
        $rHeader = 'Maximum';
        $rMessage = "You're using both Caching and the Redis Connection Handler — optimised for <strong>maximum performance</strong>!";
    }
}
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('cache_redis_settings'); ?></h4>
</div>

<form method="POST" id="cache-form">
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title"><?= $rHeader; ?> Performance</h5>
            <p class="text-body-secondary"><?= $rMessage; ?></p>
            <div class="progress" style="height:10px">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-<?= $rColour; ?>" role="progressbar" style="width:<?= $rSize; ?>%" aria-valuenow="<?= $rSize; ?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#cache" role="tab"><i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('xc_vm_caching_system'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#connections" role="tab"><i class="icon-base ti tabler-plug-connected me-1"></i><?= $language::get('redis_connection_handler'); ?></button></li>
            </ul>
            <div class="tab-content p-0">
                <!-- Caching system -->
                <div class="tab-pane fade show active" id="cache" role="tabpanel">
                    <?php if ($rSettings['enable_cache']): ?>
                        <?php
                        $db->query("SELECT `time` FROM `crontab` WHERE `filename` = 'cache_engine';");
                        [$rMinute, $rHour] = array_pad(explode(' ', (string) ($db->get_row()['time'] ?? '')), 2, '*');
                        $db->query('SELECT `id` FROM `lines`;');
                        $rLineCount = $db->result->rowCount();
                        $db->query('SELECT `id` FROM `streams`;');
                        $rStreamCount = $db->result->rowCount();
                        $db->query('SELECT `id` FROM `streams_series`;');
                        $rSeriesCount = $db->result->rowCount();
                        $rLineCountR = count(glob(LINES_TMP_PATH . 'line_i_*'));
                        $rStreamCountR = count(glob(STREAMS_TMP_PATH . 'stream_*'));
                        $rSeriesCountR = max(count(glob(SERIES_TMP_PATH . 'series_*')) - 2, 0);
                        $rFreeCache = 100 - (int) (disk_free_space(MAIN_HOME . 'tmp') / disk_total_space(MAIN_HOME . 'tmp') * 100);
                        ?>
                        <?php if ($rFreeCache >= 90): ?>
                            <div class="alert alert-danger" role="alert">Your cache tmpfs mount is <strong><?= $rFreeCache; ?>% full</strong>! This can stop new lines and streams from caching. <strong>Increase the tmpfs size in /etc/fstab and reboot.</strong></div>
                        <?php endif; ?>
                        <?php if (!file_exists(CACHE_TMP_PATH . 'cache_complete')): ?>
                            <div class="alert alert-warning" role="alert">Cache isn't complete yet. With many streams and lines the caching process can take a while; until it finishes, Player API and Playlist functionality is limited and users may be unable to connect.</div>
                        <?php endif; ?>
                        <h5 class="card-title"><?= $language::get('cache_cron_execution'); ?></h5>
                        <p class="text-body-secondary">Last cron execution: <strong><?= date($rSettings['datetime_format'], $rSettings['last_cache']); ?></strong>. The default is every 5 minutes; as your Streams and Lines tables grow, tune the schedule for a balance between performance and data accuracy. <strong>Ensure the cron format is correct, otherwise it won't run.</strong></p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><label class="form-label" for="minute">Minute</label><input type="text" class="form-control" id="minute" name="minute" value="<?= htmlspecialchars((string) $rMinute, ENT_QUOTES); ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="hour">Hour</label><input type="text" class="form-control" id="hour" name="hour" value="<?= htmlspecialchars((string) $rHour, ENT_QUOTES); ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="cache_thread_count">Thread Count</label><input type="text" class="form-control" id="cache_thread_count" name="cache_thread_count" value="<?= (int) $rSettings['cache_thread_count']; ?>"></div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cache_changes" name="cache_changes" value="1" <?= $rSettings['cache_changes'] == 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cache_changes">Update Changes Only</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <span class="badge bg-label-info">Streams: <?= number_format($rStreamCountR); ?> / <?= number_format($rStreamCount); ?></span>
                            <span class="badge bg-label-info">Lines: <?= number_format($rLineCountR); ?> / <?= number_format($rLineCount); ?></span>
                            <span class="badge bg-label-info">Series: <?= number_format($rSeriesCountR); ?> / <?= number_format($rSeriesCount); ?></span>
                            <span class="badge bg-label-secondary">Time Taken: <?= TimeUtils::secondsToTime($rSettings['last_cache_taken']); ?></span>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-danger js-api" data-action="disable_cache"><?= $language::get('disable_cache'); ?></button>
                                <button type="button" class="btn btn-info js-api" data-action="regenerate_cache"><?= $language::get('regenerate_cache'); ?></button>
                            </div>
                            <button type="submit" class="btn btn-primary">Save Cron</button>
                        </div>
                    <?php else: ?>
                        <h5 class="card-title"><?= $language::get('cache_is_disabled'); ?></h5>
                        <p class="text-body-secondary">Caching is disabled. Re-enable it below; for best results restart XC_VM on this server afterwards.</p>
                        <button type="button" class="btn btn-success js-api" data-action="enable_cache"><?= $language::get('enable_cache'); ?></button>
                    <?php endif; ?>
                </div>
                <!-- Redis connection handler -->
                <div class="tab-pane fade" id="connections" role="tabpanel">
                    <h5 class="card-title"><?= $language::get('redis_connection_handler'); ?></h5>
                    <p class="text-body-secondary">The handler verifies and manages all client→load-balancer connections through Redis instead of MySQL. <strong>Disabling it disconnects active clients; enabling it moves live connections from MySQL to Redis without disconnects.</strong></p>
                    <?php if ($rSettings['redis_handler']): ?>
                        <?php
                        $rStatus = $rAuth = false;
                        try {
                            $rTestRedis = new \Redis();
                            $rStatus = $rTestRedis->connect(ServerRepository::getAll()[SERVER_ID]['server_ip'], 6379);
                            $rAuth = $rTestRedis->auth(SettingsManager::get('redis_password'));
                        } catch (\Exception $e) {
                            // status/auth stay false
                        }
                        ?>
                        <div class="d-flex flex-wrap gap-3 my-3">
                            <span class="badge bg-label-secondary">Server Status: <span class="badge bg-<?= $rStatus ? 'success' : 'danger'; ?> ms-1"><?= $rStatus ? $language::get('online_btn') : $language::get('offline'); ?></span></span>
                            <span class="badge bg-label-secondary">Authentication: <span class="badge bg-<?= $rAuth ? 'success' : 'danger'; ?> ms-1"><?= $rAuth ? $language::get('authenticated') : $language::get('invalid_password'); ?></span></span>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-danger js-api" data-action="disable_handler"><?= $language::get('disable_handler'); ?></button>
                            <button type="button" class="btn btn-info js-api" data-action="clear_redis"><?= $language::get('clear_database'); ?></button>
                        </div>
                    <?php else: ?>
                        <p class="text-body-secondary"><strong>The Redis Connection Handler is disabled.</strong> Click below to re-enable it.</p>
                        <button type="button" class="btn btn-success js-api" data-action="enable_handler"><?= $language::get('enable_handler'); ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var toast = window.xcToast || function() {};

        // enable/disable/regenerate/clear actions.
        document.querySelectorAll('.js-api').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = this.getAttribute('data-action');
                var run = function() {
                    btn.disabled = true;
                    fetch('./api?action=' + encodeURIComponent(action), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.json(); })
                        .then(function(d) { if (d && d.result === true) { location.reload(); } else { btn.disabled = false; toast(errText, 'error'); } })
                        .catch(function() { btn.disabled = false; toast(errText, 'error'); });
                };
                if (action.indexOf('disable') === 0 || action === 'clear_redis' || action === 'regenerate_cache') {
                    (window.xcConfirm ? window.xcConfirm('Are you sure?') : Promise.resolve(confirm('Are you sure?'))).then(function(ok) { if (ok) { run(); } });
                } else { run(); }
            });
        });

        // Save cron settings.
        var form = document.getElementById('cache-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            var fd = new FormData(form);
            fd.append('submit_settings', '1');
            fetch('post.php?action=cache', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (btn) { btn.disabled = false; }
                    toast(d && d.result !== false ? 'Cache & Redis settings updated.' : errText, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
