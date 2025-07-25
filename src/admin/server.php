<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id']) && ($rServerArr = $rServers[CoreUtilities::$rRequest['id']])) {
} else {
    goHome();
}

$rInterfaces = array();
$rWatchdog = json_decode($rServerArr['watchdog_data'], true);
$rServiceMax = (0 < intval($rWatchdog['cpu_cores']) ? $rWatchdog['cpu_cores'] : 16);

if ($rServiceMax < 4) {
    $rServiceMax = 4;
}

$rInterfaces = json_decode($rServerArr['interfaces'], true);
$rCertificate = json_decode($rServerArr['certbot_ssl'], true);
$rCertValid = false;

if ($rCertificate['expiration']) {
    $rHasCert = true;

    if (time() < $rCertificate['expiration']) {
        $rCertValid = true;
    }

    $rExpiration = date($rSettings['datetime_format'], $rCertificate['expiration']);
} else {
    $rHasCert = false;
    $rExpiration = 'No Certificate Installed';
}

if (count($rInterfaces) == 0) {
    $rInterfaces = array('eth0');
}

$rFS = getFreeSpace($rServerArr['id']);
$rMounted = false;

foreach ($rFS as $rMount) {
    if ($rMount['mount'] == rtrim(STREAMS_PATH, '/')) {
        $rMounted = true;
        break;
    }
}
$_TITLE = 'Edit Server';
include 'header.php'; ?>
<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Edit Server</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <input type="hidden" name="edit" value="<?php echo $rServerArr['id']; ?>" />
                            <input type="hidden" id="regenerate_ssl" name="regenerate_ssl" value="0" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#server-details" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#additional_ips" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-web"></i>
                                            <?php if (!$rServerArr['is_main']) {
                                                echo '<span class="d-none d-sm-inline">Domains & IP\'s</span>';
                                            } else {
                                                echo '<span class="d-none d-sm-inline">Domains</span>';
                                            } ?>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Advanced</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#performance" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-flash mr-1"></i>
                                            <span class="d-none d-sm-inline">Performance</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#ssl-certificate" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-certificate mr-1"></i>
                                            <span class="d-none d-sm-inline">SSL Certificate</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="server-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_name">Server
                                                        Name</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="server_name"
                                                            name="server_name"
                                                            value="<?php echo htmlspecialchars($rServerArr['server_name']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_ip">Server IP <i
                                                            title="This IP will be used for internal connections as well as broadcast if no domains are allocated."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="server_ip"
                                                            name="server_ip"
                                                            value="<?php echo htmlspecialchars($rServerArr['server_ip']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="private_ip">Private IP
                                                        <i title="Enter a private IP to route internal traffic between load balancers through the internal network."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="private_ip"
                                                            name="private_ip"
                                                            value="<?php echo htmlspecialchars($rServerArr['private_ip']); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="total_clients">Max
                                                        Clients <i
                                                            title="Maximum number of simultaneous connections to allow on this server."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="total_clients" name="total_clients"
                                                            value="<?php echo htmlspecialchars($rServerArr['total_clients']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label"
                                                        for="timeshift_only">Timeshift Only <i
                                                            title="Don't allow connections to this server unless they are for timeshift."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="timeshift_only" id="timeshift_only" type="checkbox"
                                                            <?php if ($rServerArr['timeshift_only'] == 1) {
                                                                echo 'checked';
                                                            } ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="enabled">Enabled <i
                                                            title="Utilise this server for connections and streams."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input <?php if ($rServerArr['is_main']) {
                                                                    echo 'readonly';
                                                                } ?>
                                                            name="enabled" id="enabled" type="checkbox"
                                                            <?php if ($rServerArr['enabled'] == 1) {
                                                                echo 'checked';
                                                            } ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="enable_proxy">Proxied <i
                                                            title="Route connections through allocated proxies."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_proxy" id="enable_proxy" type="checkbox"
                                                            <?php if ($rServerArr['enable_proxy'] == 1) {
                                                                echo 'checked';
                                                            } ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="additional_ips">
                                        <div class="row">
                                            <div class="col-12">
                                                <?php if (!$rServerArr['is_main']) : ?>
                                                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                                                        <button type="button" class="close" data-dismiss="alert"
                                                            aria-label="Close">
                                                            <span aria-hidden="true">&times;</span>
                                                        </button>
                                                        By default, clients will be directed to the Server IP on the Details
                                                        tab. You can add IP's or Domain Names here to force clients to be
                                                        directed to those instead. If random IP / domain is selected, each
                                                        client will be directed to a random entry in the list, otherwise the
                                                        first entry in the list will be used to serve content.
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="ip_field"><?php echo !$rServerArr['is_main'] ? "Domains & IP's" : "Domain Names"; ?></label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="ip_field" class="form-control" value="">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" id="add_ip"
                                                                class="btn btn-primary waves-effect waves-light"><i
                                                                    class="mdi mdi-plus"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="domain_name">&nbsp;</label>
                                                    <div class="col-md-8">
                                                        <select id="domain_name" name="domain_name[]" size=6
                                                            class="form-control" multiple="multiple">
                                                            <?php foreach (explode(',', $rServerArr['domain_name']) as $rIP) : ?>
                                                                <?php if (strlen($rIP) > 0) : ?>
                                                                    <option value="<?php echo $rIP; ?>"><?php echo $rIP; ?>
                                                                    </option>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <?php if (!$rServerArr['is_main']) : ?>
                                                        <label class="col-md-4 col-form-label" for="random_ip">Serve Random
                                                            IP / Domain</label>
                                                        <div class="col-md-2">
                                                            <input name="random_ip" id="random_ip" type="checkbox"
                                                                <?php if ($rServerArr['random_ip'] == 1) echo 'checked'; ?>
                                                                data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd" />
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="<?php echo !$rServerArr['is_main'] ? 'col-md-6' : 'col-md-8'; ?>"
                                                        align="right">
                                                        <ul class="list-inline wizard mb-0">
                                                            <li class="list-inline-item">
                                                                <a href="javascript: void(0);" onClick="MoveUp()"
                                                                    class="btn btn-secondary"><i
                                                                        class="mdi mdi-chevron-up"></i></a>
                                                                <a href="javascript: void(0);" onClick="MoveDown()"
                                                                    class="btn btn-secondary"><i
                                                                        class="mdi mdi-chevron-down"></i></a>
                                                                <a href="javascript: void(0)" id="remove_ip"
                                                                    class="btn btn-danger waves-effect waves-light"><i
                                                                        class="mdi mdi-close"></i></a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="http_broadcast_ports">HTTP Ports <i
                                                            title="Enter one or more port numbers between 80 and 65535."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="http_broadcast_ports[]" id="http_broadcast_ports"
                                                            class="form-control col-md-12 select2-multiple"
                                                            data-toggle="select2" multiple="multiple"
                                                            data-placeholder="Choose...">
                                                            <?php if (is_numeric($rServerArr['http_broadcast_port']) && $rServerArr['http_broadcast_port'] >= 80 && $rServerArr['http_broadcast_port'] <= 65535): ?>
                                                                <option selected
                                                                    value="<?= $rServerArr['http_broadcast_port']; ?>">
                                                                    <?= $rServerArr['http_broadcast_port']; ?></option>
                                                            <?php endif; ?>
                                                            <?php foreach (explode(',', $rServerArr['http_ports_add']) as $rPort): ?>
                                                                <?php if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535): ?>
                                                                    <option selected value="<?= $rPort; ?>"><?= $rPort; ?>
                                                                    </option>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="https_broadcast_ports">HTTPS Ports <i
                                                            title="Enter one or more port numbers between 80 and 65535."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="https_broadcast_ports[]"
                                                            id="https_broadcast_ports"
                                                            class="form-control col-md-12 select2-multiple"
                                                            data-toggle="select2" multiple="multiple"
                                                            data-placeholder="Choose...">
                                                            <?php if (is_numeric($rServerArr['https_broadcast_port']) && $rServerArr['https_broadcast_port'] >= 80 && $rServerArr['https_broadcast_port'] <= 65535): ?>
                                                                <option selected
                                                                    value="<?= $rServerArr['https_broadcast_port']; ?>">
                                                                    <?= $rServerArr['https_broadcast_port']; ?></option>
                                                            <?php endif; ?>
                                                            <?php foreach (explode(',', $rServerArr['https_ports_add']) as $rPort): ?>
                                                                <?php if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535): ?>
                                                                    <option selected value="<?= $rPort; ?>"><?= $rPort; ?>
                                                                    </option>
                                                                <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="rtmp_port">RTMP Port <i
                                                            title="Enter the port to run the RTMP server on."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="rtmp_port" name="rtmp_port"
                                                            value="<?= htmlspecialchars($rServerArr['rtmp_port']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="disable_ramdisk">Disable
                                                        Ramdisk <i
                                                            title="If you have a fast NVMe SSD, you can disable ramdisk to allow streams to be run and output from your disk. Faster than you'd think, but you could hit a IO bottleneck depending on your connections. This setting will take a minute or so to update as it requires root access."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="disable_ramdisk" id="disable_ramdisk"
                                                            type="checkbox" <?php if (!$rMounted) echo 'checked'; ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="network_interface">Network Interface <i
                                                            title="Which network interface to use for statistics."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <select name="network_interface" id="network_interface"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array_merge(['auto'], json_decode($rServerArr['interfaces'], true) ?: []) as $rInterface): ?>

                                                                <option
                                                                    <?= $rServerArr['network_interface'] == $rInterface ? 'selected' : ''; ?>
                                                                    value="<?= $rInterface; ?>"><?= $rInterface; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md-4 col-form-label"
                                                        for="network_guaranteed_speed">Network Speed - Mbps <i
                                                            title="Port speed to consider when connecting clients."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="network_guaranteed_speed"
                                                            name="network_guaranteed_speed"
                                                            value="<?= htmlspecialchars($rServerArr['network_guaranteed_speed']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="geoip_type">GeoIP
                                                        Priority</label>
                                                    <div class="col-md-8">
                                                        <select name="geoip_type" id="geoip_type"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach (['high_priority' => 'High Priority', 'low_priority' => 'Low Priority', 'strict' => 'Strict'] as $rType => $rText): ?>
                                                                <option
                                                                    <?= $rServerArr['geoip_type'] == $rType ? 'selected' : ''; ?>
                                                                    value="<?= $rType; ?>"><?= $rText; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="geoip_countries">GeoIP
                                                        Countries <i
                                                            title="Select which countries should be prioritised to this server."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="geoip_countries[]" id="geoip_countries"
                                                            class="form-control select2 select2-multiple"
                                                            data-toggle="select2" multiple="multiple"
                                                            data-placeholder="Choose...">
                                                            <?php $rSelected = json_decode($rServerArr['geoip_countries'], true); ?>
                                                            <?php foreach ($rCountries as $rCountry): ?>
                                                                <option
                                                                    <?= in_array($rCountry['id'], $rSelected) ? 'selected' : ''; ?>
                                                                    value="<?= $rCountry['id']; ?>">
                                                                    <?= $rCountry['name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="enable_geoip">GeoIP Load
                                                        Balancing <i
                                                            title="Route connections to the nearest server based on the location of the client."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_geoip" id="enable_geoip" type="checkbox"
                                                            <?= $rServerArr['enable_geoip'] == 1 ? 'checked' : ''; ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <!-- Additional configurations and options can be added in a similar manner -->
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="performance">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="php_version">PHP Version
                                                        <i title="Which version of PHP 7 to use."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <select name="php_version_num" id="php_version"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array(array('72', 'PHP 7.2.34'), array('74', 'PHP 7.4.10')) as $phpVersionSymbolicLink): ?>
                                                                <option
                                                                    <?php if ($rServerArr['php_version'] == $phpVersionSymbolicLink[0]) echo 'selected '; ?>value="<?php echo $phpVersionSymbolicLink[0]; ?>">
                                                                    <?php echo $phpVersionSymbolicLink[1]; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="total_services">PHP
                                                        Services <i
                                                            title="How many PHP-FPM daemons to run on this server. You can use up to a maximum of one per core."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <select name="total_services" id="total_services"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach (range(1, $rServiceMax) as $rInt): ?>
                                                                <option
                                                                    <?php if ($rServerArr['total_services'] == $rInt || $rInt == 4) echo 'selected '; ?>value="<?php echo $rInt; ?>">
                                                                    <?php echo $rInt; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="limit_requests">Rate
                                                        Limit - Per Second <i
                                                            title="Limit requests per second. This can be enabled if your server can't keep up with the incoming requests. Set to 0 to disable."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="limit_requests" name="limit_requests"
                                                            value="<?php echo htmlspecialchars($rServerArr['limit_requests']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="limit_burst">Rate Limit
                                                        - Burst Queue <i
                                                            title="When the request limit is reached, excess requests will be dropped by default. You can push these requests into a queue which will be fulfilled in order rather than concurrently. This will help ease the flow of traffic and make sure service isn't disrupted by the rate limiting."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="limit_burst" name="limit_burst"
                                                            value="<?php echo htmlspecialchars($rServerArr['limit_burst']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <?php if ($rServerArr['is_main']): ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="enable_gzip">GZIP
                                                            Compression <i
                                                                title="Compressing server output on your main server will reduce network output significantly, but will increase CPU usage. If you have CPU to spare but your network usage is high, you should enable this."
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-2">
                                                            <input name="enable_gzip" id="enable_gzip" type="checkbox"
                                                                <?php if ($rServerArr['enable_gzip'] == 1) echo 'checked '; ?>data-plugin="switchery"
                                                                class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (count(json_decode($rServerArr['governors'], true)) > 0):
                                                    $rCurrentGovernor = json_decode($rServerArr['governor'], true);
                                                    $rCurrentGovernor[3] = '* ' . $rCurrentGovernor[2] . ' - Freq: ' . round($rCurrentGovernor[0] / 1000000, 1) . 'GHz - ' . round($rCurrentGovernor[1] / 1000000, 1) . 'GHz'; ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="governor">CPU Governor
                                                            <i title="Change default CPU governor for all cores. Default for Ubuntu is ondemand, with performance governor giving the best theoretical results. This may take a minute or so to change."
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-8">
                                                            <select name="governor" id="governor"
                                                                class="form-control select2" data-toggle="select2">
                                                                <option selected
                                                                    value="<?php echo $rCurrentGovernor[2]; ?>">
                                                                    <?php echo $rCurrentGovernor[3]; ?>
                                                                </option>
                                                                <?php foreach (json_decode($rServerArr['governors'], true) as $rGovernor): ?>
                                                                    <?php if ($rGovernor != $rCurrentGovernor[2]): ?>
                                                                        <option value="<?php echo $rGovernor; ?>">
                                                                            <?php echo $rGovernor; ?>
                                                                        </option>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="sysctl">Custom
                                                        Sysctl.conf <i
                                                            title="Write a custom sysctl.conf to the server. You can break your server by inputting incorrect values here, this is for advanced usage only. The Default template is provided for restorative and informative purposes."
                                                            class="tooltip text-secondary far fa-circle"></i><br /><br /><input
                                                            onClick="setDefault();" type="button"
                                                            class="btn btn-light btn-xs" value="Default" /></label>
                                                    <div class="col-md-8">
                                                        <textarea class="form-control" id="sysctl" name="sysctl"
                                                            rows="16"><?php echo $rServerArr['sysctl']; ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="ssl-certificate">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="expiration_date">Expiration Date</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="expiration_date"
                                                            value="<?php echo $rExpiration; ?>" readonly>
                                                    </div>
                                                </div>
                                                <?php if ($rCertValid): ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="cert_serial">Certificate
                                                            Serial</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="cert_serial"
                                                                value="<?php echo $rCertificate['serial']; ?>" readonly>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="cert_subject">Certificate Subject</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="cert_subject"
                                                                value="<?php echo $rCertificate['subject']; ?>" readonly>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="enable_https">Enable
                                                        HTTPS <i
                                                            title="Allow SSL connections to this server. Ensure the certificate is valid for the domains beforehand. Reseller DNS would probably fail under these circumstances as they would not have a valid certificate."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_https" id="enable_https" type="checkbox"
                                                            <?php if ($rServerArr['enable_https'] == 1) echo 'checked'; ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <?php if (!$rCertValid): ?>
                                                    <?php $rErrorLog = getSSLLog($rServerArr['id']); ?>
                                                    <?php if ($rErrorLog): ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-4 col-form-label" for="error_log">Error
                                                                Log</label>
                                                            <div class="col-md-8">
                                                                <textarea style="width: 100%;" rows="10" id="error_log"
                                                                    class="form-control"
                                                                    readonly><?php echo implode("\n", $rErrorLog['output']); ?></textarea>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="alert alert-info mb-4" role="alert">
                                                        You can use Certbot to automatically generate a valid SSL
                                                        certificate for your server by clicking the Generate Certificate
                                                        button below. This will instruct XC_VM to attempt to generate
                                                        certificates for each of the domain names listed in the Domains
                                                        section.<br /><br /><strong>Please save your changes before clicking
                                                            the Generate button to ensure the correct domains are
                                                            used.</strong>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <?php if (!$rCertValid && count(explode(',', $rServerArr['domain_name'])) > 0): ?>
                                                    <input id="submit_server_ssl" type="button" class="btn btn-info"
                                                        value="Generate SSL">
                                                <?php elseif ($rCertValid): ?>
                                                    <input id="submit_server_ssl" type="button" class="btn btn-info"
                                                        value="Force Update SSL">
                                                <?php endif; ?>
                                                <input name="submit_server" id="submit_button" type="submit"
                                                    class="btn btn-primary" value="Save">
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>