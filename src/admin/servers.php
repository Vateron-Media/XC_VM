<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

CoreUtilities::$rServers = CoreUtilities::getServers(true);
$_TITLE = 'Servers';
include 'header.php';
?>

<div class="wrapper"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Servers</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">Order</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Proxied</th>
                                    <th>Server Name</th>
                                    <th class="text-center">Server IP</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Network</th>
                                    <th class="text-center">CPU %</th>
                                    <th class="text-center">MEM %</th>
                                    <th class="text-center">Ping</th>
                                    <th class="text-center">Version</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (CoreUtilities::$rServers as $rServer) {
                                    if ($rServer['server_type'] == 0) {
                                        $rWatchDog = json_decode($rServer['watchdog_data'], true) ?: array('total_mem_used_percent' => '0', 'cpu' => '0');
                                        if (!CoreUtilities::$rServers[$rServer['id']]['server_online']) {
                                            $rWatchDog['cpu'] = 0;
                                            $rWatchDog['total_mem_used_percent'] = 0;
                                        }
                                ?>
                                        <tr id="server-<?php echo $rServer['id']; ?>">
                                            <td class="text-center">
                                                <a data-id="<?php echo $rServer['id']; ?>"
                                                    href="server_view?id=<?php echo $rServer['id']; ?>">
                                                    <?php echo ($rServer['order'] ?: $rServer['id']); ?>
                                                </a>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                if (!$rServer['enabled']) {
                                                    echo '<i class="text-secondary fas fa-square tooltip" title="Disabled"></i>';
                                                } else {
                                                    if ($rServer['server_online']) {
                                                        echo '<i class="text-success fas fa-square tooltip" title="Online"></i>';
                                                    } else {
                                                        echo '<i class="text-danger fas fa-square tooltip" title="Offline"></i>';
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo $rServer['enable_proxy'] ? '<i class="text-success fas fa-square"></i>' : '<i class="text-secondary fas fa-square"></i>'; ?>
                                            </td>
                                            <td>
                                                <a href="server_view?id=<?php echo $rServer['id']; ?>">
                                                    <?php echo $rServer['server_name']; ?>
                                                    <?php if (!empty($rServer['domain_name'])) { ?>
                                                        <br /><small><?php echo explode(',', $rServer['domain_name'])[0]; ?></small>
                                                    <?php } ?>
                                                </a>
                                            </td>
                                            <td class="text-center">
                                                <a onClick="whois('<?php echo $rServer['server_ip']; ?>');"
                                                    href="javascript: void(0);"><?php echo $rServer['server_ip']; ?></a>
                                                <?php if (!empty($rServer['private_ip'])) { ?>
                                                    <br /><small style="font-size: 8pt;">private: <a
                                                            onClick="whois('<?php echo $rServer['private_ip']; ?>');"
                                                            href="javascript: void(0);"><?php echo $rServer['private_ip']; ?></a></small>
                                                <?php } ?>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                $rClients = CoreUtilities::$rSettings['redis_handler'] ? $rServer['connections'] : getLiveConnections($rServer['id']);
                                                echo hasPermissions('adv', 'live_connections') ? '<a href="./live_connections?server=' . $rServer['id'] . '"><button type="button" class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border">' . number_format($rClients, 0) . '</button></a>'
                                                    : '<button type="button" class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border">' . number_format($rClients, 0) . '</button>';
                                                ?>
                                                <br /><small>of
                                                    <?php echo number_format($rServer['total_clients'], 0); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                    class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border">
                                                    <span
                                                        id="header_streams_up"><?php echo number_format($rWatchDog['bytes_sent'] / 125000, 0); ?></span>
                                                    <i class="mdi mdi-arrow-up-thick"></i> &nbsp;
                                                    <span
                                                        id="header_streams_down"><?php echo number_format($rWatchDog['bytes_received'] / 125000, 0); ?></span>
                                                    <i class="mdi mdi-arrow-down-thick"></i>
                                                </button>
                                                <br /><small><?php echo number_format($rServer['network_guaranteed_speed'], 0); ?>
                                                    Mbps</small>
                                            </td>
                                            <td class="text-center">
                                                <div class="progress">
                                                    <div class="progress-bar" role="progressbar"
                                                        style="width: <?php echo $rWatchDog['cpu']; ?>%;"
                                                        aria-valuenow="<?php echo $rWatchDog['cpu']; ?>" aria-valuemin="0"
                                                        aria-valuemax="100"><?php echo $rWatchDog['cpu']; ?>%</div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="progress">
                                                    <div class="progress-bar" role="progressbar"
                                                        style="width: <?php echo $rWatchDog['total_mem_used_percent']; ?>%;"
                                                        aria-valuenow="<?php echo $rWatchDog['total_mem_used_percent']; ?>"
                                                        aria-valuemin="0" aria-valuemax="100">
                                                        <?php echo $rWatchDog['total_mem_used_percent']; ?>%</div>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                    class="btn btn-light btn-xs waves-effect waves-light"><?php echo number_format(($rServer['server_online'] ? $rServer['ping'] : 0), 0); ?>
                                                    ms</button>
                                            </td>
                                            <td class="text-center">
                                                <button type="button"
                                                    class="btn <?php echo ($rServer['xc_vm_version'] != $rServers[SERVER_ID]['xc_vm_version']) ? 'btn-warning' : 'btn-light'; ?> btn-xs waves-effect waves-light">
                                                    <?php echo $rServer['xc_vm_version'] ? $rServer['xc_vm_version'] : 'N/A'; ?>
                                                </button>
                                            </td>
                                            <td class="text-center">
                                                <?php if (hasPermissions('adv', 'edit_server')): ?>
                                                    <?php if (CoreUtilities::$rSettings['group_buttons']): ?>
                                                        <div class="btn-group dropdown">
                                                            <a href="javascript: void(0);"
                                                                class="table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm"
                                                                data-toggle="dropdown" aria-expanded="false"><i
                                                                    class="mdi mdi-menu"></i></a>
                                                            <div class="dropdown-menu dropdown-menu-right">
                                                                <a class="dropdown-item btn-reboot-server" href="javascript:void(0);"
                                                                    data-id="<?php echo $rServer['id']; ?>">Server Tools</a>
                                                                <a class="dropdown-item" href="javascript:void(0);"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'restart');">Restart
                                                                    Live Streams</a>
                                                                <a class="dropdown-item" href="javascript:void(0);"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'start');">Start All
                                                                    Streams</a>
                                                                <a class="dropdown-item" href="javascript:void(0);"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'stop');">Stop All
                                                                    Streams</a>
                                                                <a class="dropdown-item" href="javascript:void(0);"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'kill');">Kill
                                                                    Connections</a>
                                                                <a class="dropdown-item"
                                                                    href="./server?id=<?php echo $rServer['id']; ?>">Edit Server</a>
                                                                <?php if ($rServer['enable_proxy']): ?>
                                                                    <a class="dropdown-item" href="javascript:void(0);"
                                                                        onClick="api(<?php echo $rServer['id']; ?>, 'disable_proxy');">Disable
                                                                        Proxy</a>
                                                                <?php else: ?>
                                                                    <a class="dropdown-item" href="javascript:void(0);"
                                                                        onClick="api(<?php echo $rServer['id']; ?>, 'enable_proxy');">Enable
                                                                        Proxy</a>
                                                                <?php endif; ?>
                                                                <?php if ($rServer['is_main'] == 0): ?>
                                                                    <?php if ($rServer['enabled']): ?>
                                                                        <a class="dropdown-item" href="javascript:void(0);"
                                                                            onClick="api(<?php echo $rServer['id']; ?>, 'disable');">Disable
                                                                            Server</a>
                                                                    <?php else: ?>
                                                                        <a class="dropdown-item" href="javascript:void(0);"
                                                                            onClick="api(<?php echo $rServer['id']; ?>, 'enable');">Enable
                                                                            Server</a>
                                                                    <?php endif; ?>
                                                                    <a class="dropdown-item" href="javascript:void(0);"
                                                                        onClick="api(<?php echo $rServer['id']; ?>, 'delete');">Delete
                                                                        Server</a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="btn-group">
                                                            <button type="button" title="Server Tools"
                                                                class="btn btn-light waves-effect waves-light btn-xs btn-reboot-server tooltip"
                                                                data-id="<?php echo $rServer['id']; ?>"><i
                                                                    class="mdi mdi-creation"></i></button>
                                                            <button type="button" title="Restart Live Streams"
                                                                class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                onClick="api(<?php echo $rServer['id']; ?>, 'restart');"><i
                                                                    class="mdi mdi-refresh"></i></button>
                                                            <button type="button" title="Start All Streams"
                                                                class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                onClick="api(<?php echo $rServer['id']; ?>, 'start');"><i
                                                                    class="mdi mdi-play"></i></button>
                                                            <button type="button" title="Stop All Streams"
                                                                class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                onClick="api(<?php echo $rServer['id']; ?>, 'stop');"><i
                                                                    class="mdi mdi-stop"></i></button>
                                                            <button type="button" title="Kill All Connections"
                                                                class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                onClick="api(<?php echo $rServer['id']; ?>, 'kill');"><i
                                                                    class="fas fa-hammer"></i></button>
                                                            <a href="./server?id=<?php echo $rServer['id']; ?>"><button type="button"
                                                                    title="Edit Server"
                                                                    class="btn btn-light waves-effect waves-light btn-xs tooltip"><i
                                                                        class="mdi mdi-pencil-outline"></i></button></a>
                                                            <?php if ($rServer['enable_proxy']): ?>
                                                                <button type="button" title="Disable Proxy"
                                                                    class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'disable_proxy');"><i
                                                                        class="mdi mdi-shield-off-outline"></i></button>
                                                            <?php else: ?>
                                                                <button type="button" title="Enable Proxy"
                                                                    class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'enable_proxy');"><i
                                                                        class="mdi mdi-shield-check-outline"></i></button>
                                                            <?php endif; ?>
                                                            <?php if ($rServer['is_main'] == 0): ?>
                                                                <?php if ($rServer['enabled']): ?>
                                                                    <button type="button" title="Disable Server"
                                                                        class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                        onClick="api(<?php echo $rServer['id']; ?>, 'disable');"><i
                                                                            class="mdi mdi-close-network-outline"></i></button>
                                                                <?php else: ?>
                                                                    <button type="button" title="Enable Server"
                                                                        class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                        onClick="api(<?php echo $rServer['id']; ?>, 'enable');"><i
                                                                            class="mdi mdi-access-point-network"></i></button>
                                                                <?php endif; ?>
                                                                <button type="button" title="Delete Server"
                                                                    class="btn btn-light waves-effect waves-light btn-xs tooltip"
                                                                    onClick="api(<?php echo $rServer['id']; ?>, 'delete');"><i
                                                                        class="mdi mdi-close"></i></button>
                                                            <?php else: ?>
                                                                <button disabled type="button"
                                                                    class="btn btn-light waves-effect waves-light btn-xs"><i
                                                                        class="mdi mdi-access-point-network"></i></button>
                                                                <button disabled type="button"
                                                                    class="btn btn-light waves-effect waves-light btn-xs"><i
                                                                        class="mdi mdi-close"></i></button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    --
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                <?php
                                    }
                                } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php';
		echo '        var rSelected = [];' . "\r\n" . '        ' . "\r\n" . '        function updateAll(rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Update",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to update all running servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        updateAll(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else {' . "\r\n" . '                $.getJSON("./api?action=update_all_servers", function(data) {' . "\r\n" . '                    $.toast("Servers are being updated in the background...");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . 'function restartServices(rConfirm=false) {' . "\r\n\t\t\t" . 'if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Update",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to restart services on all running servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        restartServices(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else {' . "\r\n" . '                $.getJSON("./api?action=restart_all_services", function(data) {' . "\r\n" . '                    $.toast("Services will be restarted shortly...");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function updateBinaries(rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Update",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to update binaries on all running servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        updateBinaries(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else {' . "\r\n" . '                $.getJSON("./api?action=update_all_binaries", function(data) {' . "\r\n" . '                    $.toast("Binaries are being updated in the background...");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((window.rSelected) && (window.rSelected.length > 0)) {' . "\r\n" . '                $.toast("Individual actions disabled in multi-select mode.");' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . "                    content: \"Are you sure you want to delete this server and it's accompanying streams?\"," . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "kill") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections to this server?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "restart") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Restart",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to restart all running streams on this server?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "start") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Start",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to start all streams on this server? This will start EVERYTHING.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "stop") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Stop",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to stop all streams on this server?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "disable") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable this server?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "disable_proxy") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable all proxies on this server? All traffic will be routed through the original IP address.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "enable_proxy") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Enable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to enable all proxies on this server? All traffic will be routed through the proxy servers.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "update") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Update",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to update this server? It will go offline until the update is completed.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=server&sub=" + rType + "&server_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("Server successfully deleted.");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("All server connections have been killed.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("All streams on this server have been restarted.");' . "\r\n" . '                        } else if (rType == "start") {' . "\r\n" . '                            $.toast("All streams on this server have been started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("All streams on this server have been stopped.");' . "\r\n" . '                        } else if (rType == "update") {' . "\r\n" . '                            $.toast("Updating server...");' . "\r\n" . '                        } else if (rType == "disable") {' . "\r\n" . '                            reloadPage();' . "\r\n" . '                        } else if (rType == "enable") {' . "\r\n" . '                            reloadPage();' . "\r\n" . '                        } else if (rType == "disable_proxy") {' . "\r\n" . '                            reloadPage();' . "\r\n" . '                        } else if (rType == "enable_proxy") {' . "\r\n" . '                            reloadPage();' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function multiAPI(rType, rConfirm=false) {' . "\r\n" . '            if (rType == "clear") {' . "\r\n" . '                if ("#header_stats") {' . "\r\n" . '                    $("#header_stats").show();' . "\r\n" . '                }' . "\r\n" . '                window.rSelected = [];' . "\r\n" . '                $(".multiselect").hide();' . "\r\n" . "                \$(\"#datatable tr\").removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if (rType == "tools") {' . "\r\n" . '                $(".bs-server-modal-center").data("id", "[" + window.rSelected.join(",") + "]");' . "\r\n" . '                $(".bs-server-modal-center").modal("show");' . "\r\n" . '                $("#reinstall_server").prop("disabled", true);' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "start") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Start",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to start all streams on these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "stop") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Stop",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to stop all streams on these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "restart") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Restart",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to restart all streams on these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "enable_proxy") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Enable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to enable Proxy on these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "disable_proxy") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable Proxy on these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "enable") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Enable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to enable these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "disable") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable these servers?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=multi&type=server&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "restart") {' . "\r\n" . '                            $.toast("Streams have been restarted.");' . "\r\n" . '                        } else if (rType == "start") {' . "\r\n" . '                            $.toast("Streams have been started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Streams have been stopped.");' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Servers have been deleted.");' . "\r\n" . '                        } else if (rType == "enable_proxy") {' . "\r\n" . '                            $.toast("Proxy has been enabled for selected servers.");' . "\r\n" . '                        } else if (rType == "disable_proxy") {' . "\r\n" . '                            $.toast("Proxy has been disabled for selected servers.");' . "\r\n" . '                        } else if (rType == "enable") {' . "\r\n" . '                            $.toast("Servers have been enabled.");' . "\r\n" . '                        } else if (rType == "disable") {' . "\r\n" . '                            $.toast("Servers have been disabled.");' . "\r\n" . '                        }' . "\r\n" . '                        reloadPage();' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                multiAPI("clear");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function bindServers() {' . "\r\n" . '            $("#reinstall_server").unbind();' . "\r\n" . '            $("#reinstall_server").click(function() {' . "\r\n" . "                navigate('./server_install?id=' + \$(\".bs-server-modal-center\").data(\"id\"));" . "\r\n" . '            });' . "\r\n" . '            $("#restart_services_ssh").unbind();' . "\r\n" . '            $("#restart_services_ssh").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").modal("hide");' . "\r\n" . '                $.getJSON("./api?action=restart_services&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("XC_VM will be restarted shortly.");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                    $(".bs-server-modal-center").data("id", "");' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            $("#reboot_server_ssh").unbind();' . "\r\n" . '            $("#reboot_server_ssh").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").modal("hide");' . "\r\n" . '                $.getJSON("./api?action=reboot_server&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("Server will be rebooted shortly.");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                    $(".bs-server-modal-center").data("id", "");' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            $("#update_binaries").unbind();' . "\r\n" . '            $("#update_binaries").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").modal("hide");' . "\r\n" . '                $.getJSON("./api?action=update_binaries&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("Binaries are updating in the background...");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                    $(".bs-server-modal-center").data("id", "");' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            $("#update_server").unbind();' . "\r\n" . '            $("#update_server").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").modal("hide");' . "\r\n" . '                $.getJSON("./api?action=server&sub=update&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("Server is updating in the background...");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                    $(".bs-server-modal-center").data("id", "");' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            $(".btn-reboot-server").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").data("id", $(this).data("id"));' . "\r\n" . '                $(".bs-server-modal-center").modal("show");' . "\r\n" . '                ';

		echo '                $("#reinstall_server").prop("disabled", false);' . "\r\n" . '                ';

		echo '            });' . "\r\n" . '            $("#update_server").prop("disabled", false);' . "\r\n" . '            $("#update_binaries").prop("disabled", false);' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindServers();' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    ';

		if (!hasPermissions('adv', 'edit_server')) {
		} else {
			echo '                    // Multi Actions' . "\r\n" . '                    multiAPI("clear");' . "\r\n" . '                    $("#datatable tr").click(function() {' . "\r\n" . '                        if (window.rShiftHeld) {' . "\r\n" . "                            if (\$(this).hasClass('selectedfilter')) {" . "\r\n" . "                                \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                                window.rSelected.splice($.inArray($($(this).find("td:eq(0)").html()).data("id"), window.rSelected), 1);' . "\r\n" . '                            } else {            ' . "\r\n" . "                                \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                                window.rSelected.push($($(this).find("td:eq(0)").html()).data("id"));' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#multi_servers_selected").html(window.rSelected.length + " servers");' . "\r\n" . '                        if (window.rSelected.length > 0) {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").hide();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_servers").show();' . "\r\n" . '                        } else {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").show();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_servers").hide();' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>