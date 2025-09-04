<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php
CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
$rSettings = CoreUtilities::$rSettings;
$_TITLE = 'Cache & Redis Settings';
include 'header.php';
?>

<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                            echo '';
                                        } else {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <form action="#" method="POST">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title">Cache & Redis Settings</h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Cache & Redis settings successfully updated!
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-body">
                            <?php
                            $rColour = 'secondary'; // Default color
                            $rHeader = 'Poor'; // Default header
                            $rSize = 25; // Default size

                            $rMessage = "You're using neither Caching or Redis Connection Handler, the server will perform poorly compared to having either enabled."; // Default message

                            if (CoreUtilities::$rSettings['enable_cache'] || CoreUtilities::$rSettings['redis_handler']) {
                                $rHeader = 'Good';
                                $rColour = 'info';
                                $rMessage = "Redis Connection Handler is disabled on your service, if you have a lot of throughput you will see better performance with Redis enabled.<br/>If you maintain active connections of over 10,000 for example you should consider this. Below this amount you're unlikely to see any benefit.";
                                $rSize = 75;

                                if (!CoreUtilities::$rSettings['enable_cache']) {
                                    $rSize = 50;
                                    $rMessage = 'Caching is disabled on your service, this will impact performance significantly under load compared to having it enabled.';
                                }

                                if (CoreUtilities::$rSettings['enable_cache'] && CoreUtilities::$rSettings['redis_handler']) {
                                    $rSize = 100;
                                    $rColour = 'pink';
                                    $rHeader = 'Maximum';
                                    $rMessage = "You're using both Caching and Redis Connection Handler, your service is optimised for <strong>maximum performance</strong>!";
                                }
                            }
                            ?>
                            <h5 class="card-title"><?= $rHeader ?> Performance</h5>
                            <p><?= $rMessage ?></p>
                            <div class="progress mb-2">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-<?= $rColour ?>" role="progressbar" aria-valuenow="<?= $rSize ?>" aria-valuemin="0" aria-valuemax="100" style="width: <?= $rSize ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#cache" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-cached mr-1"></i>
                                            <span class="d-none d-sm-inline">XC_VM Caching System</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#connections" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-lan-connect mr-1"></i>
                                            <span class="d-none d-sm-inline">Redis Connection Handler</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="cache">
                                        <div class="row">
                                            <div class="col-12">
                                                <?php if ($rSettings['enable_cache']): ?>
                                                    <?php
                                                    $db->query("SELECT `time` FROM `crontab` WHERE `filename` = 'cache_engine.php';");
                                                    list($rMinute, $rHour, $rDayOfMonth, $rMonth, $rDayOfWeek) = explode(' ', $db->get_row()['time']);
                                                    $db->query('SELECT `id` FROM `lines`;');
                                                    $rLineCount = $db->result->rowCount();
                                                    $db->query('SELECT `id` FROM `streams`;');
                                                    $rStreamCount = $db->result->rowCount();
                                                    $db->query('SELECT `id` FROM `streams_series`;');
                                                    $rSeriesCount = $db->result->rowCount();
                                                    $rLineCountR = count(glob(LINES_TMP_PATH . 'line_i_*'));
                                                    $rStreamCountR = count(glob(STREAMS_TMP_PATH . 'stream_*'));
                                                    $rSeriesCountR = count(glob(SERIES_TMP_PATH . 'series_*')) - 2;
                                                    $rSeriesCountR = max($rSeriesCountR, 0);
                                                    $rFreeCache = 100 - intval(disk_free_space(MAIN_HOME . 'tmp') / disk_total_space(MAIN_HOME . 'tmp') * 100);
                                                    ?>

                                                    <?php if ($rFreeCache >= 90): ?>
                                                        <div class="alert alert-danger mb-4" role="alert">
                                                            Your cache tmpfs mount is <strong><?= $rFreeCache ?>% full</strong>! This can stop new lines and streams from caching and when the mount is completely full cache will not work correctly.<br /><br /><strong>You should increase the size of your tmpfs size in /etc/fstab and reboot.</strong>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!file_exists(CACHE_TMP_PATH . 'cache_complete')): ?>
                                                        <div class="alert alert-warning mb-4" role="alert">
                                                            Cache isn't complete yet! If you have a lot of streams and lines, the caching process can take a while to complete. For now, no users will be able to connect to the service.<br /><br />Player API and Playlist functionality will be limited until cache is complete. This is a requirement regardless of whether cache is enabled or not.
                                                        </div>
                                                    <?php endif; ?>

                                                    <h5 class="card-title">Cache Cron Execution</h5>
                                                    <p>Your last cron execution was at <strong><?= date($rSettings['datetime_format'], $rSettings['last_cache']) ?></strong>. If it takes longer to run a cron than the time between executions, you will have issues as the caching will be cut off before completion.<br /><br />The default is to run the cron every 5 minutes, but when your Streams and Lines tables grow larger it can take a fair amount of time to grab and cache this data. You can change the time to achieve a better balance between performance and data accuracy.<br /><br /><strong>Please ensure the cron format is correct, otherwise it won't run.</strong></p>
                                                    <div class="form-group row mb-4">
                                                        <table class="table table-striped table-borderless mb-0" id="datatable-cache">
                                                            <tbody>
                                                                <tr>
                                                                    <td class="text-center">Minute</td>
                                                                    <td style="width:250px;"><input type="text" class="form-control text-center" id="minute" name="minute" value="<?= $rMinute ?>"></td>
                                                                    <td class="text-center">Hour</td>
                                                                    <td style="width:250px;"><input type="text" class="form-control text-center" id="hour" name="hour" value="<?= $rHour ?>"></td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="text-center">Thread Count</td>
                                                                    <td><input type="text" class="form-control text-center" id="cache_thread_count" name="cache_thread_count" value="<?= intval($rSettings['cache_thread_count']) ?>"></td>
                                                                    <td class="text-center">Update Changes Only</td>
                                                                    <td>
                                                                        <input name="cache_changes" id="cache_changes" type="checkbox" <?= $rSettings['cache_changes'] == 1 ? 'checked' : '' ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                                    </td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="text-center">Streams</td>
                                                                    <td class="text-center">
                                                                        <button type="button" class="btn btn-info btn-xs waves-effect waves-light"><?= number_format($rStreamCountR) ?> / <?= number_format($rStreamCount) ?></button>
                                                                    </td>
                                                                    <td class="text-center">Lines</td>
                                                                    <td class="text-center">
                                                                        <button type="button" class="btn btn-info btn-xs waves-effect waves-light"><?= number_format($rLineCountR) ?> / <?= number_format($rLineCount) ?></button>
                                                                    </td>
                                                                </tr>
                                                                <tr>
                                                                    <td class="text-center">Series</td>
                                                                    <td class="text-center">
                                                                        <button type="button" class="btn btn-info btn-xs waves-effect waves-light"><?= number_format($rSeriesCountR) ?> / <?= number_format($rSeriesCount) ?></button>
                                                                    </td>
                                                                    <td class="text-center">Time Taken</td>
                                                                    <td class="text-center">
                                                                        <button type="button" class="btn btn-info btn-xs waves-effect waves-light"><?= CoreUtilities::secondsToTime($rSettings['last_cache_taken']) ?></button>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php else: ?>
                                                    <h5 class="card-title">Cache is Disabled</h5>
                                                    <p>You have chosen to disable Cache system. You can re-enable it by clicking the Enable Cache box below, however when doing so you would get best results restarting XC_VM on this server.</p>
                                                <?php endif; ?>

                                                <ul class="list-inline wizard mb-0" style="margin-top:30px;">
                                                    <?php if ($rSettings['enable_cache']): ?>
                                                        <li class="list-inline-item">
                                                            <button id="disable_cache" onClick="api('disable_cache')" class="btn btn-danger" type="button">Disable Cache</button>
                                                            <button id="regenerate_cache" onClick="api('regenerate_cache')" class="btn btn-info" type="button">Regenerate Cache</button>
                                                        </li>
                                                        <li class="list-inline-item float-right">
                                                            <input name="submit_settings" type="submit" class="btn btn-primary" value="Save Cron" />
                                                        </li>
                                                    <?php else: ?>
                                                        <li class="list-inline-item">
                                                            <button id="enable_cache" onClick="api('enable_cache')" class="btn btn-success" type="button">Enable Cache</button>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="connections">
                                        <div class="row">
                                            <div class="col-12">
                                                <h5 class="card-title">Redis Connection Handler</h5>
                                                <p>The handler will allow all connections from clients to load balancers to be verified and managed using Redis rather than through mysql connections.<br /><br /><strong>Disabling Redis handler will disconnect all of your active clients, enabling it however should move the live connections from MySQL to Redis without disconnects.</strong></p>
                                                <h5 class="card-title mt-4">Pros & Cons</h5>
                                                <p>Before deciding whether Redis Connection Handler is right for you, you should know a few things. Firstly, enabling Redis will significantly increase XC_VM's ability to handle connections as the previous bottleneck would be from MySQL not being able to handle the amount of incoming client requests. You'll also find that zap time will be quicker, CPU should be lower and things will generally run quite smoothly.<br /><br />The drawbacks from using Redis is that the live connection database is stored in memory, although a backup is periodically written, restarting XC_VM can result in connection losses. In addition to this, your ability to filter or search some content in the Admin or Reseller interface will be diminished. For example, with Redis on you can only sort Live Connections by Time Active ascending or descending and you cannot search the live connection list. You also lose the ability to sort by Active Connections in Lines or Content pages etc.<br /><br />The best way to decide if Redis is right for you is to try it for yourself.</p>

                                                <?php if ($rSettings['redis_handler']): ?>
                                                    <?php
                                                    try {
                                                        CoreUtilities::$redis = new Redis();
                                                        CoreUtilities::$redis->connect(CoreUtilities::$rServers[SERVER_ID]['server_ip'], 6379);
                                                        $rStatus = true;
                                                    } catch (Exception $e) {
                                                        $rStatus = false;
                                                    }

                                                    try {
                                                        CoreUtilities::$redis->auth(CoreUtilities::$rSettings['redis_password']);
                                                        $rAuth = true;
                                                    } catch (Exception $e) {
                                                        $rAuth = false;
                                                    }
                                                    ?>

                                                    <div class="form-group row mb-4 mt-4">
                                                        <table class="table table-striped table-borderless mb-0" id="datatable-redis">
                                                            <tbody>
                                                                <tr>
                                                                    <td class="text-center">Server Status</td>
                                                                    <td class="text-center">
                                                                        <?php if ($rStatus): ?>
                                                                            <button type="button" class="btn btn-success btn-xs waves-effect waves-light btn-fixed-xl">ONLINE</button>
                                                                        <?php else: ?>
                                                                            <button type="button" class="btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl">OFFLINE</button>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="text-center">Authentication</td>
                                                                    <td class="text-center">
                                                                        <?php if ($rAuth): ?>
                                                                            <button type="button" class="btn btn-success btn-xs waves-effect waves-light btn-fixed-xl">AUTHENTICATED</button>
                                                                        <?php else: ?>
                                                                            <button type="button" class="btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl">INVALID PASSWORD</button>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php else: ?>
                                                    <p><strong>You have chosen to disable Redis Connection Handler. Click the button below to re-enable it.</strong></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:30px;">
                                            <?php if ($rSettings['redis_handler']): ?>
                                                <li class="list-inline-item">
                                                    <button id="disable_handler" onClick="api('disable_handler')" class="btn btn-danger" type="button">Disable Handler</button>
                                                    <button id="clear_redis" onClick="api('clear_redis')" class="btn btn-info" type="button">Clear Database</button>
                                                </li>
                                            <?php else: ?>
                                                <li class="list-inline-item">
                                                    <button id="enable_handler" onClick="api('enable_handler')" class="btn btn-success" type="button">Enable Handler</button>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php';
		echo "\r\n" . '        function checkRegex(e) {' . "\r\n" . '            var rRegex = /^[0-9\\/*,-]+$/;' . "\r\n" . '            return rRegex.test(String.fromCharCode(e.which));' . "\r\n" . '        }' . "\r\n\r\n" . '        function api(rType, rConfirm=false) {' . "\r\n\t\t\t" . 'if ((rType == "clear_redis") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Clear",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to clear the Redis database? This will drop all connections.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n\t\t\t\t" . 'if (rType == "regenerate_cache") {' . "\r\n\t\t\t\t\t" . '$.toast("Regenerating cache in the background...");' . "\r\n\t\t\t\t\t" . '$("#regenerate_cache").attr("disabled", true);' . "\r\n\t\t\t\t" . '} else if (rType == "disable_cache") { ' . "\r\n\t\t\t\t\t" . '$.toast("Cache has been completely disabled!");' . "\r\n\t\t\t\t\t" . '$("#disable_cache").attr("disabled", true); $("#restart_cache").attr("disabled", true);' . "\r\n\t\t\t\t" . '} else if (rType == "enable_cache") {' . "\r\n\t\t\t\t\t" . '$.toast("Cache has been enabled!");' . "\r\n\t\t\t\t\t" . '$("#enable_cache").attr("disabled", true);' . "\r\n\t\t\t\t" . '} else if (rType == "disable_handler") { ' . "\r\n\t\t\t\t\t" . '$.toast("Handler has been completely disabled!");' . "\r\n\t\t\t\t\t" . '$("#disable_handler").attr("disabled", true);' . "\r\n\t\t\t\t" . '} else if (rType == "enable_handler") {' . "\r\n\t\t\t\t\t" . '$.toast("Handler has been enabled!");' . "\r\n\t\t\t\t\t" . '$("#enable_handler").attr("disabled", true);' . "\r\n\t\t\t\t" . '} else if (rType == "clear_redis") {' . "\r\n\t\t\t\t\t" . '$.toast("Redis database has been cleared!");' . "\r\n\t\t\t\t\t" . '$("#clear_redis").attr("disabled", true);' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$.getJSON("./api?action=" + rType, function(data) {' . "\r\n\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t" . 'window.location.reload();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$.toast("An error occured while processing your request.");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}).fail(function() {' . "\r\n\t\t\t\t\t" . '$.toast("An error occured while processing your request.");' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            $("#minute").keypress(function(e) { return checkRegex(e); });' . "\r\n" . '            $("#hour").keypress(function(e) { return checkRegex(e); });' . "\r\n" . '            $("#cache_thread_count").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>