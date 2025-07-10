<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

CoreUtilities::$rServers = CoreUtilities::getServers(true);

$_TITLE = 'Proxy Servers';
include 'header.php';
?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Proxy Servers</h4>
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
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Status</th>
                                    <th>Proxy Name</th>
                                    <th>Proxied Server</th>
                                    <th class="text-center">Proxy IP</th>
                                    <th class="text-center">Network</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">CPU %</th>
                                    <th class="text-center">MEM %</th>
                                    <th class="text-center">Ping</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (CoreUtilities::$rServers as $rServer) {
                                    if ($rServer['server_type'] == 1) {
                                        $rWatchDog = json_decode($rServer['watchdog_data'], true);
                                        $rWatchDog = is_array($rWatchDog) ? $rWatchDog : array('total_mem_used_percent' => 0, 'cpu' => 0);

                                        if (!CoreUtilities::$rServers[$rServer['id']]['server_online']) {
                                            $rWatchDog['cpu'] = 0;
                                            $rWatchDog['total_mem_used_percent'] = 0;
                                        } ?>
                                        <tr id="server-<?php echo $rServer['id']; ?>">
                                            <td class="text-center"><?php echo $rServer['id']; ?></td>
                                            <td class="text-center">
                                                <?php
                                                if (!$rServer['enabled']) {
                                                    echo '<i class="text-secondary fas fa-square tooltip" title="Disabled"></i>';
                                                } else {
                                                    if ($rServer['server_online']) {
                                                        echo '<i class="text-success fas fa-square tooltip" title="Online"></i>';
                                                    } else {
                                                        $rLastCheck = $rServer['last_check_ago'] > 0 ? date($rSettings['datetime_format'], $rServer['last_check_ago']) : 'Never';
                                                        switch ($rServer['status']) {
                                                            case 3:
                                                                echo '<i class="text-info fas fa-square tooltip" title="Installing..."></i>';
                                                                break;
                                                            case 4:
                                                                echo '<i class="text-warning fas fa-square tooltip" title="Installation Failed!"></i>';
                                                                break;
                                                            case 5:
                                                                echo '<i class="text-info fas fa-square tooltip" title="Updating..."></i>';
                                                                break;
                                                            default:
                                                                echo '<i class="text-danger fas fa-square tooltip" title="Last Ping: ' . $rLastCheck . '"></i>';
                                                        }
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td><a href="server_view?id=<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name'];
                                                                                                        echo !empty($rServer['domain_name']) ? '<br/><small>' . explode(',', $rServer['domain_name'])[0] . '</small>' : ''; ?></a></td>
                                            <td>
                                                <a href="server_view?id=<?php echo $rServer['parent_id'][0]; ?>"><?php echo $rServers[$rServer['parent_id'][0]]['server_name']; ?></a>
                                                <?php if (count($rServer['parent_id']) > 1) { ?>
                                                    &nbsp; <button title="View All Servers" onClick="viewServers(<?php echo intval($rServer['id']); ?>);" type='button' class='tooltip-left btn btn-info btn-xs waves-effect waves-light'>+ <?php echo count($rServer['parent_id']) - 1; ?></button>
                                                <?php } ?>
                                            </td>
                                            <td class="text-center"><a onClick="whois('<?php echo $rServer['server_ip']; ?>');" href="javascript: void(0);"><?php echo $rServer['server_ip']; ?></a></td>
                                            <td class="text-center">
                                                <?php
                                                $rClients = getLiveConnections($rServer['id'], true);
                                                if (hasPermissions('adv', 'live_connections')) {
                                                    $rClients = '<a href="./live_connections?server=' . $rServer['id'] . '"><button type="button" class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border">' . number_format($rClients, 0) . '</button></a>';
                                                } else {
                                                    $rClients = '<button type="button" class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border">' . number_format($rClients, 0) . '</button>';
                                                }
                                                echo $rClients;
                                                ?>
                                                <br /><small>of <?php echo number_format($rServer['total_clients'], 0); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?php echo number_format(($rServer['server_online'] ? $rServer['ping'] : 0), 0); ?> ms</button>
                                            </td>
                                            <td class="text-center">
                                                <?php if (hasPermissions('adv', 'edit_server')) {
                                                    include 'server_actions.php'; // Assuming 'server_actions.php' is the file where you manage server actions
                                                } else {
                                                    echo '--';
                                                } ?>
                                            </td>
                                        </tr>
                                <?php }
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