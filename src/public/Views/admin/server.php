<?php if (!isset($__viewMode)): ?>
    <?php

    include 'session.php';
    include 'functions.php';

    if (!PageAuthorization::checkPermissions()) {
        AdminHelpers::goHome();
    }

    if (isset(RequestManager::getAll()['id']) && ($rServerArr = $allServers[RequestManager::getAll()['id']])) {
    } else {
        AdminHelpers::goHome();
    }

    $rWatchdog = !empty($rServerArr['watchdog_data']) ? json_decode($rServerArr['watchdog_data'], true) : [];
    $rServiceMax = (0 < intval($rWatchdog['cpu_cores']) ? $rWatchdog['cpu_cores'] : 16);

    if ($rServiceMax < 4) {
        $rServiceMax = 4;
    }

    $rInterfaces = !empty($rServerArr['interfaces']) ? json_decode($rServerArr['interfaces'], true) : [];
    $rCertificate = !empty($rServerArr['certbot_ssl']) ? json_decode($rServerArr['certbot_ssl'], true) : [];
    $rCertValid = false;

    if (isset($rCertificate['expiration'])) {
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

    $rFS = ServerRepository::getFreeSpace($rServerArr['id']);
    $rMounted = false;

    foreach ($rFS as $rMount) {
        if ($rMount['mount'] == rtrim(STREAMS_PATH, '/')) {
            $rMounted = true;
            break;
        }
    }
    $_TITLE = 'Edit Server';
    require_once __DIR__ . '/../layouts/admin.php';
    renderUnifiedLayoutHeader('admin'); ?>
<?php endif; ?>
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
                    <h4 class="page-title"><?= $language::get('permission_edit_server') ?></h4>
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
                                            <span class="d-none d-sm-inline"><?= $language::get('details') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#additional_ips" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-web"></i>
                                            <?php if (!$rServerArr['is_main']) {
                                                echo '<span class="d-none d-sm-inline">' . $language::get('domains_and_ips_span') . '</span>';
                                            } else {
                                                echo '<span class="d-none d-sm-inline">' . $language::get('domains') . '</span>';
                                            } ?>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('advanced') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#performance" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-flash mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('performance') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#ssl-certificate" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-certificate mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('ssl_certificate') ?></span>
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
                                                            title="<?= $language::get('this_ip_will_be_used_tooltip') ?>"
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
                                                        <i title="<?= $language::get('enter_a_private_ip_to_tooltip') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="private_ip"
                                                            name="private_ip"
                                                            value="<?php echo htmlspecialchars($rServerArr['private_ip'] ?? ''); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="total_clients">Max
                                                        Clients <i
                                                            title="<?= $language::get('maximum_number_of_simultaneous_connections_tooltip') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="total_clients" name="total_clients"
                                                            value="<?php echo htmlspecialchars($rServerArr['total_clients']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label"
                                                        for="timeshift_only">Timeshift Only <i
                                                            title="<?= $language::get('dont_allow_connections_to_this_tooltip') ?>"
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
                                                            title="<?= $language::get('utilise_this_server_for_connections_and_streams') ?>"
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
                                                            title="<?= $language::get('route_connections_through_allocated_proxies') ?>"
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="http_broadcast_ports">HTTP Ports <i
                                                            title="<?= $language::get('enter_one_or_more_port_numbers_between_80_and_65535') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="http_broadcast_ports[]" id="http_broadcast_ports"
                                                            class="form-control col-md-12 select2-multiple"
                                                            data-toggle="select2" multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>">
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
                                                            title="<?= $language::get('enter_one_or_more_port_numbers_between_80_and_65535') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="https_broadcast_ports[]"
                                                            id="https_broadcast_ports"
                                                            class="form-control col-md-12 select2-multiple"
                                                            data-toggle="select2" multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>">
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
                                                            title="<?= $language::get('enter_the_port_to_run_the_rtmp_server_on') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="rtmp_port" name="rtmp_port"
                                                            value="<?= htmlspecialchars($rServerArr['rtmp_port']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="disable_ramdisk">Disable
                                                        Ramdisk <i
                                                            title="<?= $language::get('if_you_have_a_fast_tooltip') ?>"
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
                                                            title="<?= $language::get('which_network_interface_to_use_for_statistics') ?>"
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
                                                            title="<?= $language::get('port_speed_to_consider_when_connecting_clients') ?>"
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
                                                            title="<?= $language::get('select_which_countries_should_be_prioritised_to_this_server') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="geoip_countries[]" id="geoip_countries"
                                                            class="form-control select2 select2-multiple"
                                                            data-toggle="select2" multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>">
                                                            <?php $rSelected = json_decode($rServerArr['geoip_countries'], true) ?? []; ?>
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
                                                            title="<?= $language::get('route_connections_to_the_nearest_tooltip') ?>"
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="tab-pane" id="performance">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="total_services">PHP
                                                        Services <i
                                                            title="<?= $language::get('how_many_phpfpm_daemons_to_tooltip') ?>"
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
                                                    <?php if ($rServerArr['is_main']): ?>
                                                        <label class="col-md-4 col-form-label" for="enable_gzip">GZIP
                                                            Compression <i
                                                                title="<?= $language::get('compressing_server_output_on_your_tooltip') ?>"
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-2">
                                                            <input name="enable_gzip" id="enable_gzip" type="checkbox"
                                                                <?php if ($rServerArr['enable_gzip'] == 1) echo 'checked '; ?>data-plugin="switchery"
                                                                class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="limit_requests">Rate
                                                        Limit - Per Second <i
                                                            title="<?= $language::get('limit_requests_per_second_this_tooltip') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="limit_requests" name="limit_requests"
                                                            value="<?php echo htmlspecialchars($rServerArr['limit_requests']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="limit_burst">Rate Limit
                                                        - Burst Queue <i
                                                            title="<?= $language::get('when_the_request_limit_is_tooltip') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="limit_burst" name="limit_burst"
                                                            value="<?php echo htmlspecialchars($rServerArr['limit_burst']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <?php if (!empty($rServerArr['governors']) && count(json_decode($rServerArr['governors'], true) ?: []) > 0):
                                                    $rCurrentGovernor = json_decode($rServerArr['governor'], true);
                                                    $rCurrentGovernor[3] = '* ' . $rCurrentGovernor[2] . ' - Freq: ' . round($rCurrentGovernor[0] / 1000000, 1) . 'GHz - ' . round($rCurrentGovernor[1] / 1000000, 1) . 'GHz'; ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="governor">CPU Governor
                                                            <i title="<?= $language::get('change_default_cpu_governor_for_tooltip') ?>"
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
                                                            title="<?= $language::get('write_a_custom_sysctlconf_to_tooltip') ?>"
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
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
                                                            title="<?= $language::get('allow_ssl_connections_to_this_tooltip') ?>"
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="enable_https" id="enable_https" type="checkbox"
                                                            <?php if ($rServerArr['enable_https'] == 1) echo 'checked'; ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <?php if (!$rCertValid): ?>
                                                    <?php $rErrorLog = ServerRepository::getSSLLog($rServerArr['id']); ?>
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
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
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
<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script id="scripts">
    var resizeObserver = new ResizeObserver(entries => $(window).scroll());
    $(document).ready(function() {
        resizeObserver.observe(document.body)
        $("form").attr('autocomplete', 'off');
        $(document).keypress(function(event) {
            if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
        });
        $.fn.dataTable.ext.errMode = 'none';
        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function(html) {
            var switchery = new Switchery(html, {
                'color': '#414d5f'
            });
            window.rSwitches[$(html).attr("id")] = switchery;
        });
        setTimeout(pingSession, 30000);
        <?php if (!$rMobile && $rSettings['header_stats']): ?>
            headerStats();
        <?php endif; ?>
        bindHref();
        refreshTooltips();
        $(window).scroll(function() {
            if ($(this).scrollTop() > 200) {
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeOut();
                }
                $('#scrollToTop').fadeIn();
            } else {
                $('#scrollToTop').fadeOut();
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeIn();
                } else {
                    $('#scrollToBottom').hide();
                }
            }
        });
        $("#scrollToTop").unbind("click");
        $('#scrollToTop').click(function() {
            $('html, body').animate({
                scrollTop: 0
            }, 800);
            return false;
        });
        $("#scrollToBottom").unbind("click");
        $('#scrollToBottom').click(function() {
            $('html, body').animate({
                scrollTop: $(document).height()
            }, 800);
            return false;
        });
        $(window).scroll();
        $(".nextb").unbind("click");
        $(".nextb").click(function() {
            var rPos = 0;
            var rActive = null;
            $(".nav .nav-item").each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        $(".prevb").unbind("click");
        $(".prevb").click(function() {
            var rPos = 0;
            var rActive = null;
            $($(".nav .nav-item").get().reverse()).each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        (function($) {
            $.fn.inputFilter = function(inputFilter) {
                return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
                    if (inputFilter(this.value)) {
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty("oldValue")) {
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    }
                });
            };
        }(jQuery));
        <?php if ($rSettings['js_navigate']): ?>
            $(".navigation-menu li").mouseenter(function() {
                $(this).find(".submenu").show();
            });
            delParam("status");
            $(window).on("popstate", function() {
                if (window.rRealURL) {
                    if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
                        navigate(window.location.href.split("/").reverse()[0]);
                    }
                }
            });
        <?php endif; ?>
        $(document).keydown(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = true;
            }
        });
        $(document).keyup(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = false;
            }
        });
        document.onselectstart = function() {
            if (window.rShiftHeld) {
                return false;
            }
        }
    });

    function MoveUp() {
        var rSelected = $('#domain_name option:selected');
        if (rSelected.length) {
            var rPrevious = rSelected.first().prev()[0];
            if ($(rPrevious).html() != '') {
                rSelected.first().prev().before(rSelected);
            }
        }
    }

    function MoveDown() {
        var rSelected = $('#domain_name option:selected');
        if (rSelected.length) {
            rSelected.last().next().after(rSelected);
        }
    }

    function setDefault() {
        $("#sysctl").val("# XC_VM\n\nnet.ipv4.tcp_congestion_control = bbr\nnet.core.default_qdisc = fq\nnet.ipv4.tcp_rmem = 8192 87380 134217728\nnet.ipv4.udp_rmem_min = 16384\nnet.core.rmem_default = 262144\nnet.core.rmem_max = 268435456\nnet.ipv4.tcp_wmem = 8192 65536 134217728\nnet.ipv4.udp_wmem_min = 16384\nnet.core.wmem_default = 262144\nnet.core.wmem_max = 268435456\nnet.core.somaxconn = 1000000\nnet.core.netdev_max_backlog = 250000\nnet.core.optmem_max = 65535\nnet.ipv4.tcp_max_tw_buckets = 1440000\nnet.ipv4.tcp_max_orphans = 16384\nnet.ipv4.ip_local_port_range = 2000 65000\nnet.ipv4.tcp_no_metrics_save = 1\nnet.ipv4.tcp_slow_start_after_idle = 0\nnet.ipv4.tcp_fin_timeout = 15\nnet.ipv4.tcp_keepalive_time = 300\nnet.ipv4.tcp_keepalive_probes = 5\nnet.ipv4.tcp_keepalive_intvl = 15\nfs.file-max=20970800\nfs.nr_open=20970800\nfs.aio-max-nr=20970800\nnet.ipv4.tcp_timestamps = 1\nnet.ipv4.tcp_window_scaling = 1\nnet.ipv4.tcp_mtu_probing = 1\nnet.ipv4.route.flush = 1\nnet.ipv6.route.flush = 1");
    }
    $(document).ready(function() {
        $('.select2').select2({
            width: '100%'
        })
        $("#http_broadcast_ports").select2({
            width: '100%',
            tags: true,
            createTag: function(params) {
                if (!$.isNumeric(params.term) || params.term < 80 || params.term > 65535) {
                    return null;
                }
                return {
                    id: params.term,
                    text: params.term
                }
            }
        });
        $("#https_broadcast_ports").select2({
            width: '100%',
            tags: true,
            createTag: function(params) {
                if (!$.isNumeric(params.term) || params.term < 80 || params.term > 65535) {
                    return null;
                }
                return {
                    id: params.term,
                    text: params.term
                }
            }
        });
        $("#isp_names").select2({
            width: '100%',
            tags: true
        });
        $("#add_ip").click(function() {
            if (($("#ip_field").val()) && ((isValidIP($("#ip_field").val())) || (isValidDomain($("#ip_field").val())))) {
                var o = new Option($("#ip_field").val(), $("#ip_field").val());
                $("#domain_name").append(o);
                $("#ip_field").val("");
            } else {
                $.toast("Please enter a valid IP address or domain name.");
            }
        });
        $("#remove_ip").click(function() {
            $('#domain_name option:selected').remove();
        });
        $("#total_clients").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#rtmp_port").inputFilter(function(value) {
            return /^\d*$/.test(value) && (value === "" || parseInt(value) <= 65535);
        });
        $("#network_guaranteed_speed").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#limit_requests").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#limit_burst").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#submit_server_ssl").click(function() {
            $("#regenerate_ssl").val(1);
            $("#submit_button").click();
        });
        $("form").submit(function(e) {
            e.preventDefault();
            $("#domain_name option").prop('selected', true);
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
    });
    <?php if (SettingsManager::getAll()['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>