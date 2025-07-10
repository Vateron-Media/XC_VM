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

                <?php } else {
                exit();
            } ?>