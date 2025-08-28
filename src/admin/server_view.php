<?php
include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id'])) {
    if (isset($rServers[CoreUtilities::$rRequest['id']])) {
        $rServer = $rServers[CoreUtilities::$rRequest['id']];
    } else {
        if (isset($rProxyServers[CoreUtilities::$rRequest['id']])) {
            $rServer = $rProxyServers[CoreUtilities::$rRequest['id']];
        } else {
            exit();
        }
    }

    $rWatchdog = json_decode($rServer['watchdog_data'], true);
    $rServer['gpu_info'] = json_decode($rServer['gpu_info'], true);
    $rStats = array('cpu' => array(), 'memory' => array(), 'io' => array(), 'input' => array(), 'output' => array(), 'dates' => array(null, null));

    foreach (getWatchdog($rServer['id']) as $rData) {
        if ($rStats['dates'][0] && $rData['time'] * 1000 > $rStats['dates'][0]) {
        } else {
            $rStats['dates'][0] = $rData['time'] * 1000;
        }

        if ($rStats['dates'][1] && $rStats['dates'][1] > $rData['time'] * 1000) {
        } else {
            $rStats['dates'][1] = $rData['time'] * 1000;
        }

        $rStats['cpu'][] = array($rData['time'] * 1000, floatval(rtrim($rData['cpu'], '%')));
        $rStats['memory'][] = array($rData['time'] * 1000, floatval(rtrim($rData['total_mem_used_percent'], '%')));
        $rStats['io'][] = array($rData['time'] * 1000, floatval(json_decode($rData['iostat_info'], true)['avg-cpu']['iowait']));
        $rStats['input'][] = array($rData['time'] * 1000, round($rData['bytes_received'] / 125000, 0));
        $rStats['output'][] = array($rData['time'] * 1000, round($rData['bytes_sent'] / 125000, 0));
    }
    $rCertificate = json_decode($rServer['certbot_ssl'], true);
    $rCertValid = false;

    if ($rCertificate['expiration']) {
        $rHasCert = true;

        if (time() >= $rCertificate['expiration']) {
        } else {
            $rCertValid = true;
        }

        $rExpiration = date($rSettings['datetime_format'], $rCertificate['expiration']);
    } else {
        $rHasCert = false;
        $rExpiration = 'No Certificate Installed';
    }

    if ($rServer['server_type'] == 0) {
        $_TITLE = 'View Server';
    } else {
        $_TITLE = 'View Proxy';
    }

    include 'header.php'; ?>
    <div class="wrapper boxed-layout-ext"
        <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        } else {
            echo ' style="display: none;"';
        } ?>>
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title">
                            <?php echo $rServer['server_name']; ?>
                            <small style="margin-left: 5px;"><?php echo $rServer['server_ip']; ?></small>
                        </h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <?php if (isset($_STATUS) && $_STATUS == STATUS_CERTBOT) { ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Certbot will run in the background and attempt to generate certificates for your server
                            automatically, check back here shortly for a progress update. Once a certificate is installed, you
                            can turn SSL on for this server.
                        </div>
                    <?php } else if (isset($_STATUS) && $_STATUS == STATUS_CERTBOT_INVALID) { ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                            No valid domains were selected for Certbot automation. Please try again.
                        </div>
                    <?php } ?>
                    <div class="row text-center">
                        <div class="<?php echo $rServer['server_type'] == 0 ? 'col-md-3' : 'col-md-6'; ?>">
                            <?php if (hasPermissions('adv', 'live_connections')) { ?>
                                <a href="./live_connections?server_id=<?php echo $rServer['id']; ?>">
                                <?php } ?>
                                <div
                                    class="card cta-box <?php echo $rSettings['dark_mode'] ? '' : 'bg-purple'; ?> text-white">
                                    <div class="card-body active-connections">
                                        <div class="media align-items-center">
                                            <div class="col-3">
                                                <div class="avatar-sm bg-light">
                                                    <i
                                                        class="fe-zap avatar-title font-22 <?php echo $rSettings['dark_mode'] ? 'text-white' : 'text-purple'; ?>"></i>
                                                </div>
                                            </div>
                                            <div class="col-9">
                                                <div class="text-right">
                                                    <h3 class="text-white my-1"><span data-plugin="counterup" class="entry"
                                                            id="open_connections">0</span></h3>
                                                    <p class="text-white mb-1 text-truncate">Connections</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (hasPermissions('adv', 'live_connections')) { ?>
                                </a>
                            <?php } ?>
                        </div>
                        <div class="<?php echo $rServer['server_type'] == 0 ? 'col-md-3' : 'col-md-6'; ?>">
                            <?php if (hasPermissions('adv', 'live_connections')) { ?>
                                <a href="./live_connections?server_id=<?php echo $rServer['id']; ?>">
                                <?php } ?>
                                <div
                                    class="card cta-box <?php echo $rSettings['dark_mode'] ? '' : 'bg-success'; ?> text-white">
                                    <div class="card-body active-connections">
                                        <div class="media align-items-center">
                                            <div class="col-3">
                                                <div class="avatar-sm bg-light">
                                                    <i
                                                        class="fe-users avatar-title font-22 <?php echo $rSettings['dark_mode'] ? 'text-white' : 'text-success'; ?>"></i>
                                                </div>
                                            </div>
                                            <div class="col-9">
                                                <div class="text-right">
                                                    <h3 class="text-white my-1"><span data-plugin="counterup" class="entry"
                                                            id="online_users">0</span></h3>
                                                    <p class="text-white mb-1 text-truncate">Users</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (hasPermissions('adv', 'live_connections')) { ?>
                                </a>
                            <?php } ?>
                        </div>
                        <?php if ($rServer['server_type'] == 0) { ?>
                            <div class="col-md-3">
                                <?php if (hasPermissions('adv', 'streams')) { ?>
                                    <a href="./streams?filter=1&server=<?php echo $rServer['id']; ?>">
                                    <?php } ?>
                                    <div
                                        class="card cta-box <?php echo $rSettings['dark_mode'] ? '' : 'bg-pink'; ?> text-white">
                                        <div class="card-body active-connections">
                                            <div class="media align-items-center">
                                                <div class="col-3">
                                                    <div class="avatar-sm bg-light">
                                                        <i
                                                            class="fe-play avatar-title font-22 <?php echo $rSettings['dark_mode'] ? 'text-white' : 'text-pink'; ?>"></i>
                                                    </div>
                                                </div>
                                                <div class="col-9">
                                                    <div class="text-right">
                                                        <h3 class="text-white my-1"><span data-plugin="counterup" class="entry"
                                                                id="total_running_streams">0</span></h3>
                                                        <p class="text-white mb-1 text-truncate">Streams</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (hasPermissions('adv', 'streams')) { ?>
                                    </a>
                                <?php } ?>
                            </div>
                            <div class="col-md-3">
                                <?php if (hasPermissions('adv', 'streams')) { ?>
                                    <a href="./streams?filter=2&server=<?php echo $rServer['id']; ?>">
                                    <?php } ?>
                                    <div
                                        class="card cta-box <?php echo $rSettings['dark_mode'] ? '' : 'bg-info'; ?> text-white">
                                        <div class="card-body active-connections">
                                            <div class="media align-items-center">
                                                <div class="col-3">
                                                    <div class="avatar-sm bg-light">
                                                        <i
                                                            class="fe-pause avatar-title font-22 <?php echo $rSettings['dark_mode'] ? 'text-white' : 'text-info'; ?>"></i>
                                                    </div>
                                                </div>
                                                <div class="col-9">
                                                    <div class="text-right">
                                                        <h3 class="text-white my-1"><span data-plugin="counterup" class="entry"
                                                                id="offline_streams">0</span></h3>
                                                        <p class="text-white mb-1 text-truncate">Down</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (hasPermissions('adv', 'streams')) { ?>
                                    </a>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                    <div class="card-box">
                        <div class="col-md-12 align-self-center">
                            <?php if ($rServer['server_online']) { ?>
                                <h5 class="mb-1 mt-0">CPU Usage<small class="text-muted ml-2">of
                                        <?php echo $rWatchdog['cpu_cores']; ?> Cores</small></h5>
                                <div class="progress-w-percent" id="watchdog_cpu">
                                    <span class="progress-value font-weight-bold">0%</span>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0"
                                            aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <h5 class="mb-1 mt-0">Memory Usage<small class="text-muted ml-2">of
                                        <?php echo round($rWatchdog['total_mem'] / 1024 / 1024, 0); ?> GB</small></h5>
                                <div class="progress-w-percent" id="watchdog_mem">
                                    <span class="progress-value font-weight-bold">0%</span>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0"
                                            aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <?php if ($rServer['server_type'] == 0) { ?>
                                    <h5 class="mb-1 mt-0">Disk Usage<small class="text-muted ml-2"> of
                                            <?php echo (1099511627776 < $rWatchdog['total_disk_space'] ? number_format($rWatchdog['total_disk_space'] / 1024 / 1024 / 1024 / 1024, 0) . ' TB' : number_format($rWatchdog['total_disk_space'] / 1024 / 1024 / 1024, 0) . ' GB'); ?></small>
                                    </h5>
                                    <div class="progress-w-percent" id="watchdog_disk">
                                        <span class="progress-value font-weight-bold">0%</span>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0"
                                                aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                    <h5 class="mb-1 mt-0">IO Wait<small class="text-muted ml-2" id="watchdog_idle"> 0% Idle</small>
                                    </h5>
                                    <div class="progress-w-percent" id="watchdog_io">
                                        <span class="progress-value font-weight-bold">0%</span>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0"
                                                aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                <?php } ?>
                                <h5 class="mb-1 mt-0">Network Input<small class="text-muted ml-2">of
                                        <?php echo number_format($rServer['network_guaranteed_speed'], 0); ?> Mbps</small></h5>
                                <div class="progress-w-percent" id="watchdog_input">
                                    <span class="progress-value font-weight-bold">0 Mbps</span>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar
" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <h5 class="mb-1 mt-0">Network Output<small class="text-muted ml-2">of
                                        <?php echo number_format($rServer['network_guaranteed_speed'], 0); ?> Mbps</small></h5>
                                <div class="progress-w-percent" id="watchdog_output">
                                    <span class="progress-value font-weight-bold">0 Mbps</span>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0"
                                            aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            <?php } else { ?>
                                <div class="text-center" style="padding-top: 15px;">
                                    <i class="fe-alert-triangle avatar-title font-24 text-danger"></i><br />
                                    <h4 class="header-title text-danger">Server Offline</h4>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <?php if (is_array($rServer['gpu_info'])) {
                        $rGPUID = 0;
                        foreach ($rServer['gpu_info']['gpus'] as $rGPU) {
                            $rMemUsage = number_format(intval(explode(' ', $rGPU['memory_usage']['used'])[0]) / intval(explode(' ', $rGPU['memory_usage']['total'])[0]) * 100, 0); ?>
                            <div class="card-box">
                                <div class="col-md-12 align-self-center">
                                    <h5 class="mb-1 mt-0">GPU<?php echo $rGPUID; ?> Usage<small class="text-muted ml-2">
                                            <?php echo $rGPU['name']; ?></small></h5>
                                    <div class="progress-w-percent">
                                        <span
                                            class="progress-value font-weight-bold"><?php echo intval(explode(' ', $rGPU['utilisation']['gpu_util'])[0]); ?>%
                                        </span>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar" role="progressbar"
                                                style="width: <?php echo intval(explode(' ', $rGPU['utilisation']['gpu_util'])[0]); ?>%;"
                                                aria-valuenow="<?php echo intval(explode(' ', $rGPU['utilisation']['gpu_util'])[0]); ?>"
                                                aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                    <h5 class="mb-1 mt-0">GPU<?php echo $rGPUID; ?> Memory Usage<small class="text-muted ml-2">
                                            <?php echo number_format(explode(' ', $rGPU['memory_usage']['used'])[0], 0); ?>MB /
                                            <?php echo number_format(explode(' ', $rGPU['memory_usage']['total'])[0], 0); ?>MB</small>
                                    </h5>
                                    <div class="progress-w-percent">
                                        <span class="progress-value font-weight-bold"><?php echo $rMemUsage; ?>% </span>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $rMemUsage; ?>%;"
                                                aria-valuenow="<?php echo $rMemUsage; ?>" aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                    </div>
                                    <h5 class="mb-1 mt-0">GPU<?php echo $rGPUID; ?> Encoder Usage</h5>
                                    <div class="progress-w-percent">
                                        <span
                                            class="progress-value font-weight-bold"><?php echo intval(explode(' ', $rGPU['utilisation']['encoder_util'])[0]); ?>%
                                        </span>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar" role="progressbar"
                                                style="width: <?php echo intval(explode(' ', $rGPU['utilisation']['encoder_util'])[0]); ?>%;"
                                                aria-valuenow="<?php echo intval(explode(' ', $rGPU['utilisation']['encoder_util'])[0]); ?>"
                                                aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                    <h5 class="mb-1 mt-0">GPU<?php echo $rGPUID; ?> Decoder Usage</h5>
                                    <div class="progress-w-percent">
                                        <span
                                            class="progress-value font-weight-bold"><?php echo intval(explode(' ', $rGPU['utilisation']['decoder_util'])[0]); ?>%
                                        </span>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar" role="progressbar"
                                                style="width: <?php echo intval(explode(' ', $rGPU['utilisation']['decoder_util'])[0]); ?>%;"
                                                aria-valuenow="<?php echo intval(explode(' ', $rGPU['utilisation']['decoder_util'])[0]); ?>"
                                                aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php $rGPUID++;
                        }
                    }
                    if ($rHasCert) { ?>
                        <div class="card-box">
                            <div class="col-md-12 align-self-center">
                                <div class="form-group row mb-4">
                                    <label class="col-md-4 col-form-label" for="expiration_date">Certificate Expiration
                                        Date</label>
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="expiration_date"
                                            value="<?php echo $rExpiration; ?>" readonly>
                                    </div>
                                </div>
                                <?php if ($rCertValid) { ?>
                                    <div class="form-group row mb-4">
                                        <label class="col-md-4 col-form-label" for="cert_serial">Certificate Serial</label>
                                        <div class="col-md-8">
                                            <input type="text" class="form-control" id="cert_serial"
                                                value="<?php echo $rCertificate['serial']; ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label class="col-md-4 col-form-label" for="cert_subject">Certificate Subject</label>
                                        <div class="col-md-8">
                                            <input type="text" class="form-control" id="cert_subject"
                                                value="<?php echo $rCertificate['subject']; ?>" readonly>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                    <!-- Additional UI elements can be added here -->
                    <?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		if (in_array($rServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4)) || in_array($rProxyServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4))) {
			echo '        function getInstallStatus() {' . "\r\n" . '            $.getJSON("./api?action=install_status&server_id=';
			echo intval(CoreUtilities::$rRequest['id']);
			echo '", function(data) {' . "\r\n" . '                if (data.result === true) {' . "\r\n" . '                    $("#server_install").html(data.data);' . "\r\n" . '                    if (data.status == 3) {' . "\r\n" . '                        setTimeout(getInstallStatus, 1000);' . "\r\n" . '                    } else if (data.status == 1) {' . "\r\n" . "                        setTimeout(navigate, 3000, './server_view?id=";
			echo intval(CoreUtilities::$rRequest['id']);
			echo "');" . "\r\n" . '                    }' . "\r\n" . '                } else {' . "\r\n" . '                    $("#server_install").html("No status available...");' . "\r\n" . '                }' . "\r\n" . '                if ($("#server_install").length) {' . "\r\n" . '                   $("#server_install").scrollTop($("#server_install")[0].scrollHeight - $("#server_install").height());' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        ';
		}

		echo '        function viewLiveConnections(rStreamID, rServerID=-1) {' . "\r\n" . '            $("#datatable-live").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips(false);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n" . '                        d.server_id = rServerID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,7,8,9,10,11]},' . "\r\n" . '                    {"visible": false, "targets": [0,3,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-live-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function getBarColour(rInt) {' . "\r\n" . '            if (rInt >= 75) {' . "\r\n" . '                return "bg-danger";' . "\r\n" . '            } else if (rInt >= 50) {' . "\r\n" . '                return "bg-warning";' . "\r\n" . '            } else {' . "\r\n" . '                return "bg-success";' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function getStats(auto=true) {' . "\r\n" . '            var rStart = Date.now();' . "\r\n" . '            rURL = "./api?action=server_view&server_id=';
		echo intval(CoreUtilities::$rRequest['id']);
		echo '";' . "\r\n" . '            $.getJSON(rURL, function(data) {' . "\r\n" . '                $("#open_connections").html(data.data.open_connections);' . "\r\n" . '                $("#total_running_streams").html(data.data.total_running_streams);' . "\r\n" . '                $("#online_users").html(data.data.online_users);' . "\r\n" . '                $("#offline_streams").html(data.data.offline_streams);' . "\r\n" . '                if (data.data.watchdog) {' . "\r\n" . '                    // CPU' . "\r\n" . '                    $("#watchdog_cpu span").html($.number(data.data.watchdog.cpu, 2) + "%");' . "\r\n" . '                    $("#watchdog_cpu .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(data.data.watchdog.cpu));' . "\r\n" . '                    $("#watchdog_cpu .progress-bar").css("width", $.number(data.data.watchdog.cpu, 0) + "%");' . "\r\n" . '                    $("#watchdog_cpu .progress-bar").data("aria-valuenow", $.number(data.data.watchdog.cpu, 0));' . "\r\n" . '                    // Memory' . "\r\n" . '                    $("#watchdog_mem span").html($.number(data.data.watchdog.total_mem_used_percent, 2) + "%");' . "\r\n" . '                    $("#watchdog_mem .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(data.data.watchdog.total_mem_used_percent));' . "\r\n" . '                    $("#watchdog_mem .progress-bar").css("width", $.number(data.data.watchdog.total_mem_used_percent, 0) + "%");' . "\r\n" . '                    $("#watchdog_mem .progress-bar").data("aria-valuenow", $.number(data.data.watchdog.total_mem_used_percent, 0));' . "\r\n" . '                    // Disk Space' . "\r\n" . '                    rDiskUsage = (data.data.watchdog.total_disk_space - data.data.watchdog.free_disk_space) / data.data.watchdog.total_disk_space * 100;' . "\r\n" . '                    $("#watchdog_disk span").html($.number(rDiskUsage, 2) + "%");' . "\r\n" . '                    $("#watchdog_disk .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(rDiskUsage));' . "\r\n" . '                    $("#watchdog_disk .progress-bar").css("width", $.number(rDiskUsage, 0) + "%");' . "\r\n" . '                    $("#watchdog_disk .progress-bar").data("aria-valuenow", $.number(rDiskUsage, 0));' . "\r\n" . '                    // IO Usage' . "\r\n" . '                    if (data.data.watchdog.iostat_info) {' . "\r\n" . '                        $("#watchdog_io span").html($.number(data.data.watchdog.iostat_info["avg-cpu"].iowait, 2) + "%");' . "\r\n" . '                        $("#watchdog_io .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(data.data.watchdog.iostat_info["avg-cpu"].iowait));' . "\r\n" . '                        $("#watchdog_io .progress-bar").css("width", $.number(data.data.watchdog.iostat_info["avg-cpu"].iowait, 0) + "%");' . "\r\n" . '                        $("#watchdog_io .progress-bar").data("aria-valuenow", $.number(data.data.watchdog.iostat_info["avg-cpu"].iowait, 0));' . "\r\n" . '                        $("#watchdog_idle").html(" " + $.number(data.data.watchdog.iostat_info["avg-cpu"].idle, 0) + "% Idle");' . "\r\n" . '                    }' . "\r\n" . '                    // Network Input' . "\r\n" . '                    rUsage = $.number(((data.data.watchdog.bytes_received / 125000) / data.netspeed) * 100, 0);' . "\r\n" . '                    $("#watchdog_input span").html($.number(data.data.watchdog.bytes_received / 125000, 0) + " Mbps");' . "\r\n" . '                    $("#watchdog_input .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(rUsage));' . "\r\n" . '                    $("#watchdog_input .progress-bar").css("width", $.number(rUsage, 0) + "%");' . "\r\n" . '                    $("#watchdog_input .progress-bar").data("aria-valuenow", $.number(rUsage, 0));' . "\r\n" . '                    // Network Output' . "\r\n" . '                    rUsage = $.number(((data.data.watchdog.bytes_sent / 125000) / data.netspeed) * 100, 0);' . "\r\n" . '                    $("#watchdog_output span").html($.number(data.data.watchdog.bytes_sent / 125000, 0) + " Mbps");' . "\r\n" . '                    $("#watchdog_output .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(rUsage));' . "\r\n" . '                    $("#watchdog_output .progress-bar").css("width", $.number(rUsage, 0) + "%");' . "\r\n" . '                    $("#watchdog_output .progress-bar").data("aria-valuenow", $.number(rUsage, 0));' . "\r\n" . '                }' . "\r\n" . '                if (auto) {' . "\r\n" . '                    if (Date.now() - rStart < 1000) {' . "\r\n" . '                        setTimeout(getStats, 1000 - (Date.now() - rStart));' . "\r\n" . '                    } else {' . "\r\n" . '                        getStats();' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            }).fail(function() {' . "\r\n" . '                if (auto) {' . "\r\n" . '                    setTimeout(getStats, 1000);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function getFPMStatus(rServerID) {' . "\r\n" . '            $.getJSON("./api?action=fpm_status&server_id=" + rServerID, function(data) {' . "\r\n" . '                if (data.result) {' . "\r\n" . "                    new jBox('Modal', {" . "\r\n" . "                        attach: '#fpmModal'," . "\r\n" . "                        title: 'PHP-FPM Status'," . "\r\n" . '                        content: data.data' . "\r\n" . '                    }).open();' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function api(rID, rServerID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rServerID == "kill") && (!rConfirm)) {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '                rServerID = -1;' . "\r\n" . '                rType = "kill";' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=stream&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Stream successfully started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Stream successfully stopped.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Stream successfully restarted.");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                            if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                                $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                            }' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable_streams").DataTable().ajax.reload( null, false );' . "\r\n" . '                        $("#datatable_connections").DataTable().ajax.reload( null, false );' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . '            ';

		if (!(in_array($rServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4)) || in_array($rProxyServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4)))) {
		} else {
			echo '            getInstallStatus();' . "\r\n" . '            ';
		}

		echo '            getStats();' . "\r\n\t\t\t" . '$("#datatable_streams").DataTable({' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "streams";' . "\r\n\t\t\t\t\t\t" . 'd.server = ';
		echo CoreUtilities::$rRequest['id'];
		echo ';' . "\r\n" . '                        d.filter = 1;' . "\r\n" . '                        d.simple = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,4,5,6]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [1,3,7,8,9]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips(false);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable_connections").DataTable({' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n\t\t\t\t\t\t" . 'd.server_id = ';
		echo CoreUtilities::$rRequest['id'];
		echo ';' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [7,8,9,11]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,4,5,6,10]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\r\n" . '                    bindHref(); refreshTooltips(false);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . 'rDates = ';
		echo json_encode($rStats['dates']);
		echo ';' . "\r\n\t\t\t" . 'rCPUOptions = {' . "\r\n\t\t\t\t" . 'chart: {' . "\r\n\t\t\t\t\t" . 'height: 380,' . "\r\n\t\t\t\t\t" . 'type: "area",' . "\r\n\t\t\t\t\t" . 'stacked: false,' . "\r\n\t\t\t\t\t" . 'zoom: {' . "\r\n\t\t\t\t\t\t" . "type: 'x'," . "\r\n\t\t\t\t\t\t" . 'enabled: true,' . "\r\n\t\t\t\t\t\t" . 'autoScaleYaxis: true' . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'events: {' . "\r\n\t\t\t\t\t\t" . 'beforeZoom: function(ctx) {' . "\r\n\t\t\t\t\t\t\t" . 'ctx.w.config.xaxis.range = undefined' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '},' . "\r\n" . '                    animations: {' . "\r\n" . '                        enabled: false' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'colors: ["#5089de", "#56c2d6", "#51b089"],' . "\r\n\t\t\t\t" . 'dataLabels: {' . "\r\n\t\t\t\t\t" . 'enabled: false' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'stroke: {' . "\r\n\t\t\t\t\t" . 'width: [2],' . "\r\n\t\t\t\t\t" . 'curve: "smooth"' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'series: [{' . "\r\n\t\t\t\t\t" . 'name: "CPU Usage",' . "\r\n\t\t\t\t\t" . 'data: ';
		echo json_encode($rStats['cpu']);
		echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . '{' . "\r\n\t\t\t\t\t" . 'name: "Memory Usage",' . "\r\n\t\t\t\t\t" . 'data: ';
		echo json_encode($rStats['memory']);
		echo "\t\t\t\t" . '},' . "\r\n" . '                {' . "\r\n\t\t\t\t\t" . 'name: "IO Usage",' . "\r\n\t\t\t\t\t" . 'data: ';
		echo json_encode($rStats['io']);
		echo "\t\t\t\t" . '}],' . "\r\n\t\t\t\t" . 'fill: {' . "\r\n\t\t\t\t\t" . 'type: "gradient", ' . "\r\n\t\t\t\t\t" . 'gradient: {' . "\r\n\t\t\t\t\t\t" . 'opacityFrom: .6,' . "\r\n\t\t\t\t\t\t" . 'opacityTo: .8' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'xaxis: {' . "\r\n\t\t\t\t\t" . 'type: "datetime",' . "\r\n\t\t\t\t\t" . 'min: rDates[0],' . "\r\n\t\t\t\t\t" . 'max: rDates[1],' . "\r\n\t\t\t\t\t" . 'range: 3600000,' . "\r\n" . '                    labels: {' . "\r\n" . '                        formatter: function(value, timestamp, opts) {' . "\r\n" . '                            var d = new Date(timestamp);' . "\r\n" . '                            return ("0"+d.getHours()).slice(-2) + ":" + ("0"+d.getMinutes()).slice(-2);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'tooltip: {' . "\r\n\t\t\t\t" . '  y: {' . "\r\n\t\t\t\t\t" . 'formatter: function(value, { series, seriesIndex, dataPointIndex, w }) {' . "\r\n\t\t\t\t\t" . '  return value + "%";' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  }' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '};' . "\r\n\t\t\t" . '(rCPUChart = new ApexCharts(document.querySelector("#cpu_chart"), rCPUOptions)).render();' . "\r\n\t\t\t" . 'rNetworkOptions = {' . "\r\n\t\t\t\t" . 'chart: {' . "\r\n\t\t\t\t\t" . 'height: 380,' . "\r\n\t\t\t\t\t" . 'type: "area",' . "\r\n\t\t\t\t\t" . 'stacked: false,' . "\r\n\t\t\t\t\t" . 'zoom: {' . "\r\n\t\t\t\t\t\t" . "type: 'x'," . "\r\n\t\t\t\t\t\t" . 'enabled: true,' . "\r\n\t\t\t\t\t\t" . 'autoScaleYaxis: true' . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'events: {' . "\r\n\t\t\t\t\t\t" . 'beforeZoom: function(ctx) {' . "\r\n\t\t\t\t\t\t\t" . 'ctx.w.config.xaxis.range = undefined' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '},' . "\r\n" . '                    animations: {' . "\r\n" . '                        enabled: false' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'colors: ["#03a9f4", "#81d4fa"],' . "\r\n\t\t\t\t" . 'dataLabels: {' . "\r\n\t\t\t\t\t" . 'enabled: false' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'stroke: {' . "\r\n\t\t\t\t\t" . 'width: [2],' . "\r\n\t\t\t\t\t" . 'curve: "smooth"' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'series: [{' . "\r\n\t\t\t\t\t" . 'name: "Input",' . "\r\n\t\t\t\t\t" . 'data: ';
		echo json_encode($rStats['input']);
		echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . '{' . "\r\n\t\t\t\t\t" . 'name: "Output",' . "\r\n\t\t\t\t\t" . 'data: ';
		echo json_encode($rStats['output']);
		echo "\t\t\t\t" . '}],' . "\r\n\t\t\t\t" . 'fill: {' . "\r\n\t\t\t\t\t" . 'type: "gradient", ' . "\r\n\t\t\t\t\t" . 'gradient: {' . "\r\n\t\t\t\t\t\t" . 'opacityFrom: .6,' . "\r\n\t\t\t\t\t\t" . 'opacityTo: .8' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'xaxis: {' . "\r\n\t\t\t\t\t" . 'type: "datetime",' . "\r\n\t\t\t\t\t" . 'min: rDates[0],' . "\r\n\t\t\t\t\t" . 'max: rDates[1],' . "\r\n\t\t\t\t\t" . 'range: 3600000,' . "\r\n" . '                    labels: {' . "\r\n" . '                        formatter: function(value, timestamp, opts) {' . "\r\n" . '                            var d = new Date(timestamp);' . "\r\n" . '                            return ("0"+d.getHours()).slice(-2) + ":" + ("0"+d.getMinutes()).slice(-2);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'tooltip: {' . "\r\n\t\t\t\t" . '  y: {' . "\r\n\t\t\t\t\t" . 'formatter: function(value, { series, seriesIndex, dataPointIndex, w }) {' . "\r\n\t\t\t\t\t" . '  return value + " Mbps";' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  }' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '};' . "\r\n\t\t\t" . '(rNetworkChart = new ApexCharts(document.querySelector("#network_chart"), rNetworkOptions)).render();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>

                <?php } else {
                exit();
            } ?>