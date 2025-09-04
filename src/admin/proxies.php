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
<?php include 'footer.php';
		echo '        var rSelected = [];' . "\r\n" . '        ' . "\r\n" . '        function viewServers(rProxyID) {' . "\r\n" . '            $(".bs-proxies-modal-center #datatable-sources").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n" . '                responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "parent_servers";' . "\r\n\t\t\t\t\t\t" . 'd.proxy_id = rProxyID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                order: [[ 0, "asc" ]],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-proxies-modal-center").data("id", rProxyID);' . "\r\n" . '            $(".bs-proxies-modal-center").modal("show");' . "\r\n" . '            $(".bs-proxies-modal-center #datatable-sources").css("width", "100%");' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((window.rSelected) && (window.rSelected.length > 0)) {' . "\r\n" . '                $.toast("Individual actions disabled in multi-select mode.");' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this proxy server?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "kill") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections to this proxy?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "disable") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable this proxy?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "update") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Update",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to update this proxy? It will go offline until the update is completed.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=proxy&sub=" + rType + "&server_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("Proxy successfully deleted.");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("All proxy connections have been killed.");' . "\r\n" . '                        } else if (rType == "update") {' . "\r\n" . '                            $.toast("Updating proxy server...");' . "\r\n" . '                        } else if (rType == "disable") {' . "\r\n" . '                            reloadPage();' . "\r\n" . '                        } else if (rType == "enable") {' . "\r\n" . '                            reloadPage();' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function multiAPI(rType, rConfirm=false) {' . "\r\n" . '            if (rType == "clear") {' . "\r\n" . '                if ("#header_stats") {' . "\r\n" . '                    $("#header_stats").show();' . "\r\n" . '                }' . "\r\n" . '                window.rSelected = [];' . "\r\n" . '                $(".multiselect").hide();' . "\r\n" . "                \$(\"#datatable tr\").removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if (rType == "tools") {' . "\r\n" . '                $(".bs-server-modal-center").data("id", "[" + window.rSelected.join(",") + "]");' . "\r\n" . '                $(".bs-server-modal-center").modal("show");' . "\r\n" . '                $("#reinstall_server").prop("disabled", true);' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete these proxies?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "enable") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Enable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to enable these proxies?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "disable") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable these proxies?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=multi&type=proxy&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Proxies have been deleted.");' . "\r\n" . '                        } else if (rType == "enable") {' . "\r\n" . '                            $.toast("Proxies have been enabled.");' . "\r\n" . '                        } else if (rType == "disable") {' . "\r\n" . '                            $.toast("Proxies have been disabled.");' . "\r\n" . '                        }' . "\r\n" . '                        reloadPage();' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                multiAPI("clear");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function bindServers() {' . "\r\n" . '            $("#reinstall_server").unbind();' . "\r\n" . '            $("#reinstall_server").click(function() {' . "\r\n" . "                navigate('./server_install?id=' + \$(\".bs-server-modal-center\").data(\"id\") + \"&proxy=1\");" . "\r\n" . '            });' . "\r\n" . '            $("#restart_services_ssh").unbind();' . "\r\n" . '            $("#restart_services_ssh").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").modal("hide");' . "\r\n" . '                $.getJSON("./api?action=restart_services&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("XC_VM will be restarted shortly.");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                    $(".bs-server-modal-center").data("id", "");' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            $("#reboot_server_ssh").unbind();' . "\r\n" . '            $("#reboot_server_ssh").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").modal("hide");' . "\r\n" . '                $.getJSON("./api?action=reboot_server&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("Server will be rebooted shortly.");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                    $(".bs-server-modal-center").data("id", "");' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . '            $(".btn-reboot-server").click(function() {' . "\r\n" . '                $(".bs-server-modal-center").data("id", $(this).data("id"));' . "\r\n" . '                $(".bs-server-modal-center").modal("show");' . "\r\n" . '            });' . "\r\n" . '            $("#update_server").prop("disabled", true);' . "\r\n" . '            $("#update_binaries").prop("disabled", true);' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindServers();' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    ';

		if (!hasPermissions('adv', 'edit_server')) {
		} else {
			echo '                    // Multi Actions' . "\r\n" . '                    multiAPI("clear");' . "\r\n" . '                    $("#datatable tr").click(function() {' . "\r\n" . '                        if (window.rShiftHeld) {' . "\r\n" . "                            if (\$(this).hasClass('selectedfilter')) {" . "\r\n" . "                                \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                                window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                            } else {            ' . "\r\n" . "                                \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                                window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#multi_proxies_selected").html(window.rSelected.length + " proxies");' . "\r\n" . '                        if (window.rSelected.length > 0) {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").hide();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_proxies").show();' . "\r\n" . '                        } else {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").show();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_proxies").hide();' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>