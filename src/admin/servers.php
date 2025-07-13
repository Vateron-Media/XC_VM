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
<?php include 'footer.php'; ?>