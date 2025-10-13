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

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo 'style="display: none;"' ?>>
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
                                <?php foreach (CoreUtilities::$rServers as $rServer): ?>
                                    <?php if ($rServer['server_type'] == 0): ?>
                                        <?php
                                        $rWatchDog = json_decode($rServer['watchdog_data'], true);
                                        if (!is_array($rWatchDog)) {
                                            $rWatchDog = array('total_mem_used_percent' => '0', 'cpu' => '0');
                                        }
                                        if (!CoreUtilities::$rServers[$rServer['id']]['server_online']) {
                                            $rWatchDog['cpu'] = 0;
                                            $rWatchDog['total_mem_used_percent'] = 0;
                                        }
                                        ?>
                                        <tr id="server-<?= $rServer['id'] ?>">
                                            <td class="text-center">
                                                <a data-id="<?= $rServer['id'] ?>" href="server_view?id=<?= $rServer['id'] ?>">
                                                    <?= ($rServer['order'] ?: $rServer['id']) ?>
                                                </a>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!$rServer['enabled']): ?>
                                                    <i class="text-secondary fas fa-square tooltip" title="Disabled"></i>
                                                <?php elseif ($rServer['server_online']): ?>
                                                    <?php if ($rServer['xc_vm_version'] && ($rServer['xc_vm_version'] != $rServers[SERVER_ID]['xc_vm_version'])): ?>
                                                        <a href="javascript: void(0);" onClick="api(<?= intval($rServer['id']) ?>, 'update');">
                                                            <i class="text-success mdi mdi-download tooltip" style="font-size:14pt;" title="An update is available! v<?= $rServers[SERVER_ID]['xc_vm_version'] ?>"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <i class="text-success fas fa-square tooltip" title="Online"></i>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php
                                                    $rPing = $rServer['last_check_ago'] > 0 ? date($F2d4d8f7981ac574['datetime_format'], $rServer['last_check_ago']) : 'Never';
                                                    ?>
                                                    <?php if ($rServer['status'] == 3): ?>
                                                        <i class="text-info fas fa-square tooltip" title="Installing..."></i>
                                                    <?php elseif ($rServer['status'] == 4): ?>
                                                        <i class="text-warning fas fa-square tooltip" title="Installation Failed!"></i>
                                                    <?php elseif ($rServer['status'] == 5): ?>
                                                        <i class="text-info fas fa-square tooltip" title="Updating..."></i>
                                                    <?php elseif (!$rServer['remote_status']): ?>
                                                        <i class="text-danger fas fa-square tooltip" title="Can't connect on <?= htmlentities($rServer['server_ip']) ?>:<?= intval($rServer['http_broadcast_port']) ?><br/>Last Ping: <?= $rPing ?>"></i>
                                                    <?php else: ?>
                                                        <i class="text-danger fas fa-square tooltip" title="Last Ping: <?= $rPing ?>"></i>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($rServer['enable_proxy']): ?>
                                                    <i class="text-success fas fa-square"></i>
                                                <?php else: ?>
                                                    <i class="text-secondary fas fa-square"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="server_view?id=<?= $rServer['id'] ?>">
                                                    <?= $rServer['server_name'] ?>
                                                    <?php if (!empty($rServer['domain_name'])): ?>
                                                        <br /><small><?= explode(',', $rServer['domain_name'])[0] ?></small>
                                                    <?php endif; ?>
                                                </a>
                                            </td>
                                            <td class="text-center">
                                                <a onClick="whois('<?= $rServer['server_ip'] ?>');" href="javascript: void(0);">
                                                    <?= $rServer['server_ip'] ?>
                                                </a>
                                                <?php if (strlen($rServer['private_ip']) > 0): ?>
                                                    <br /><small style="font-size: 8pt;">private:
                                                        <a onClick="whois('<?= $rServer['private_ip'] ?>');" href="javascript: void(0);">
                                                            <?= $rServer['private_ip'] ?>
                                                        </a>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                if (CoreUtilities::$rSettings['redis_handler']) {
                                                    $rClients = $rServer['connections'];
                                                } else {
                                                    $rClients = getLiveConnections($rServer['id']);
                                                }
                                                if (hasPermissions('adv', 'live_connections')) {
                                                    $rClients = '<a href="./live_connections?server=' . $rServer['id'] . "\"><button type='button' class='btn btn-dark bg-animate btn-xs waves-effect waves-light no-border'>" . number_format($rClients, 0) . '</button></a>';
                                                } else {
                                                    $rClients = "<button type='button' class='btn btn-dark bg-animate btn-xs waves-effect waves-light no-border'>" . number_format($rClients, 0) . '</button>';
                                                }
                                                echo $rClients;
                                                ?>
                                                <br /><small>of <?= number_format($rServer['total_clients'], 0) ?></small>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border">
                                                    <span id="header_streams_up"><?= number_format($rWatchDog['bytes_sent'] / 125000, 0) ?></span>
                                                    <i class="mdi mdi-arrow-up-thick"></i> &nbsp;
                                                    <span id="header_streams_down"><?= number_format($rWatchDog['bytes_received'] / 125000, 0) ?></span>
                                                    <i class="mdi mdi-arrow-down-thick"></i>
                                                </button>
                                                <br /><small><?= number_format($rServer['network_guaranteed_speed'], 0) ?> Mbps</small>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                if (intval($rWatchDog['cpu']) <= 34) {
                                                    $rStatColor = '#23b397';
                                                } elseif (intval($rWatchDog['cpu']) <= 67) {
                                                    $rStatColor = '#f8cc6b';
                                                } else {
                                                    $rStatColor = '#f0643b';
                                                }
                                                ?>
                                                <input data-plugin="knob" data-width="48" data-height="48"
                                                    data-bgColor="<?= $rUserInfo['theme'] == 1 ? '#7e8e9d' : '#ebeff2' ?>"
                                                    data-fgColor="<?= $rStatColor ?>"
                                                    data-readOnly=true
                                                    value="<?= intval($rWatchDog['cpu']) ?>" />
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                if (intval($rWatchDog['total_mem_used_percent']) <= 34) {
                                                    $rStatColor = '#23b397';
                                                } elseif (intval($rWatchDog['total_mem_used_percent']) <= 67) {
                                                    $rStatColor = '#f8cc6b';
                                                } else {
                                                    $rStatColor = '#f0643b';
                                                }
                                                ?>
                                                <input data-plugin="knob" data-width="48" data-height="48"
                                                    data-bgColor="<?= $rUserInfo['theme'] == 1 ? '#7e8e9d' : '#ebeff2' ?>"
                                                    data-fgColor="<?= $rStatColor ?>"
                                                    data-readOnly=true
                                                    value="<?= intval($rWatchDog['total_mem_used_percent']) ?>" />
                                            </td>
                                            <td class="text-center">
                                                <button type='button' class='btn btn-light btn-xs waves-effect waves-light'>
                                                    <?= number_format(($rServer['server_online'] ? $rServer['ping'] : 0), 0) ?> ms
                                                </button>
                                            </td>
                                            <td class="text-center">
                                                <button type='button' class='btn <?= ($rServer['xc_vm_version'] != $rServers[SERVER_ID]['xc_vm_version']) ? 'btn-warning' : 'btn-light' ?> btn-xs waves-effect waves-light'>
                                                    <?php if ($rServer['xc_vm_version']): ?>
                                                        <?= $rServer['xc_vm_version'] ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </button>
                                            </td>
                                            <td class="text-center">
                                                <?php if (hasPermissions('adv', 'edit_server')): ?>
                                                    <?php if (CoreUtilities::$rSettings['group_buttons']): ?>
                                                        <div class="btn-group dropdown">
                                                            <a href="javascript: void(0);" class="table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm" data-toggle="dropdown" aria-expanded="false">
                                                                <i class="mdi mdi-menu"></i>
                                                            </a>
                                                            <div class="dropdown-menu dropdown-menu-right">
                                                                <a class="dropdown-item btn-reboot-server" href="javascript:void(0);" data-id="<?= $rServer['id'] ?>">Server Tools</a>
                                                                <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'restart');">Restart Live Streams</a>
                                                                <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'start');">Start All Streams</a>
                                                                <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'stop');">Stop All Streams</a>
                                                                <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'kill');">Kill Connections</a>
                                                                <a class="dropdown-item" href="./server?id=<?= $rServer['id'] ?>">Edit Server</a>
                                                                <?php if ($rServer['enable_proxy']): ?>
                                                                    <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'disable_proxy');">Disable Proxy</a>
                                                                <?php else: ?>
                                                                    <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'enable_proxy');">Enable Proxy</a>
                                                                <?php endif; ?>
                                                                <?php if ($rServer['is_main'] == 0): ?>
                                                                    <?php if ($rServer['enabled']): ?>
                                                                        <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'disable');">Disable Server</a>
                                                                    <?php else: ?>
                                                                        <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'enable');">Enable Server</a>
                                                                    <?php endif; ?>
                                                                    <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= $rServer['id'] ?>, 'delete');">Delete Server</a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="btn-group">
                                                            <button type="button" title="Server Tools" class="btn btn-light waves-effect waves-light btn-xs btn-reboot-server tooltip" data-id="<?= $rServer['id'] ?>">
                                                                <i class="mdi mdi-creation"></i>
                                                            </button>
                                                            <button type="button" title="Restart Live Streams" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'restart');">
                                                                <i class="mdi mdi-refresh"></i>
                                                            </button>
                                                            <button type="button" title="Start All Streams" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'start');">
                                                                <i class="mdi mdi-play"></i>
                                                            </button>
                                                            <button type="button" title="Stop All Streams" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'stop');">
                                                                <i class="mdi mdi-stop"></i>
                                                            </button>
                                                            <button type="button" title="Kill All Connections" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'kill');">
                                                                <i class="fas fa-hammer"></i>
                                                            </button>
                                                            <a href="./server?id=<?= $rServer['id'] ?>">
                                                                <button type="button" title="Edit Server" class="btn btn-light waves-effect waves-light btn-xs tooltip">
                                                                    <i class="mdi mdi-pencil-outline"></i>
                                                                </button>
                                                            </a>
                                                            <?php if ($rServer['enable_proxy']): ?>
                                                                <button type="button" title="Disable Proxy" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'disable_proxy');">
                                                                    <i class="mdi mdi-shield-off-outline"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" title="Enable Proxy" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'enable_proxy');">
                                                                    <i class="mdi mdi-shield-check-outline"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($rServer['is_main'] == 0): ?>
                                                                <?php if ($rServer['enabled']): ?>
                                                                    <button type="button" title="Disable Server" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'disable');">
                                                                        <i class="mdi mdi-close-network-outline"></i>
                                                                    </button>
                                                                <?php else: ?>
                                                                    <button type="button" title="Enable Server" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'enable');">
                                                                        <i class="mdi mdi-access-point-network"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <button type="button" title="Delete Server" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?= $rServer['id'] ?>, 'delete');">
                                                                    <i class="mdi mdi-close"></i>
                                                                </button>
                                                            <?php else: ?>
                                                                <button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs">
                                                                    <i class="mdi mdi-access-point-network"></i>
                                                                </button>
                                                                <button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs">
                                                                    <i class="mdi mdi-close"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    --
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
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

    var rSelected = [];

    function updateAll(rConfirm = false) {
        if (!rConfirm) {
            new jBox("Confirm", {
                confirmButton: "Update",
                cancelButton: "Cancel",
                content: "Are you sure you want to update all running servers?",
                confirm: function() {
                    updateAll(true);
                }
            }).open();
        } else {
            $.getJSON("./api?action=update_all_servers", function(data) {
                $.toast("Servers are being updated in the background...");
            });
        }
    }

    function restartServices(rConfirm = false) {
        if (!rConfirm) {
            new jBox("Confirm", {
                confirmButton: "Update",
                cancelButton: "Cancel",
                content: "Are you sure you want to restart services on all running servers?",
                confirm: function() {
                    restartServices(true);
                }
            }).open();
        } else {
            $.getJSON("./api?action=restart_all_services", function(data) {
                $.toast("Services will be restarted shortly...");
            });
        }
    }

    function updateBinaries(rConfirm = false) {
        if (!rConfirm) {
            new jBox("Confirm", {
                confirmButton: "Update",
                cancelButton: "Cancel",
                content: "Are you sure you want to update binaries on all running servers?",
                confirm: function() {
                    updateBinaries(true);
                }
            }).open();
        } else {
            $.getJSON("./api?action=update_all_binaries", function(data) {
                $.toast("Binaries are being updated in the background...");
            });
        }
    }

    function api(rID, rType, rConfirm = false) {
        if ((window.rSelected) && (window.rSelected.length > 0)) {
            $.toast("Individual actions disabled in multi-select mode.");
            return;
        }
        if ((rType == "delete") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Delete",
                cancelButton: "Cancel",
                content: "Are you sure you want to delete this server and it's accompanying streams?",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "kill") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Kill",
                cancelButton: "Cancel",
                content: "Are you sure you want to kill all connections to this server?",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "restart") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Restart",
                cancelButton: "Cancel",
                content: "Are you sure you want to restart all running streams on this server?",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "start") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Start",
                cancelButton: "Cancel",
                content: "Are you sure you want to start all streams on this server? This will start EVERYTHING.",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "stop") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Stop",
                cancelButton: "Cancel",
                content: "Are you sure you want to stop all streams on this server?",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "disable") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Disable",
                cancelButton: "Cancel",
                content: "Are you sure you want to disable this server?",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "disable_proxy") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Disable",
                cancelButton: "Cancel",
                content: "Are you sure you want to disable all proxies on this server? All traffic will be routed through the original IP address.",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "enable_proxy") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Enable",
                cancelButton: "Cancel",
                content: "Are you sure you want to enable all proxies on this server? All traffic will be routed through the proxy servers.",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "update") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Update",
                cancelButton: "Cancel",
                content: "Are you sure you want to update this server? It will go offline until the update is completed.",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else {
            rConfirm = true;
        }
        if (rConfirm) {
            $.getJSON("./api?action=server&sub=" + rType + "&server_id=" + rID, function(data) {
                if (data.result === true) {
                    if (rType == "delete") {
                        if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {
                            $("#datatable").DataTable().rows(rRow).remove().draw(false);
                        }
                        $.toast("Server successfully deleted.");
                    } else if (rType == "kill") {
                        $.toast("All server connections have been killed.");
                    } else if (rType == "restart") {
                        $.toast("All streams on this server have been restarted.");
                    } else if (rType == "start") {
                        $.toast("All streams on this server have been started.");
                    } else if (rType == "stop") {
                        $.toast("All streams on this server have been stopped.");
                    } else if (rType == "update") {
                        $.toast("Updating server...");
                    } else if (rType == "disable") {
                        reloadPage();
                    } else if (rType == "enable") {
                        reloadPage();
                    } else if (rType == "disable_proxy") {
                        reloadPage();
                    } else if (rType == "enable_proxy") {
                        reloadPage();
                    }
                } else {
                    $.toast("An error occured while processing your request.");
                }
            });
        }
    }

    function multiAPI(rType, rConfirm = false) {
        if (rType == "clear") {
            if ("#header_stats") {
                $("#header_stats").show();
            }
            window.rSelected = [];
            $(".multiselect").hide();
            $("#datatable tr").removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
            return;
        }
        if (rType == "tools") {
            $(".bs-server-modal-center").data("id", "[" + window.rSelected.join(",") + "]");
            $(".bs-server-modal-center").modal("show");
            $("#reinstall_server").prop("disabled", true);
            return;
        }
        if ((rType == "delete") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Delete",
                cancelButton: "Cancel",
                content: "Are you sure you want to delete these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "purge") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Kill",
                cancelButton: "Cancel",
                content: "Are you sure you want to kill all connections?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "start") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Start",
                cancelButton: "Cancel",
                content: "Are you sure you want to start all streams on these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "stop") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Stop",
                cancelButton: "Cancel",
                content: "Are you sure you want to stop all streams on these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "restart") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Restart",
                cancelButton: "Cancel",
                content: "Are you sure you want to restart all streams on these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "enable_proxy") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Enable",
                cancelButton: "Cancel",
                content: "Are you sure you want to enable Proxy on these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "disable_proxy") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Disable",
                cancelButton: "Cancel",
                content: "Are you sure you want to disable Proxy on these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "enable") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Enable",
                cancelButton: "Cancel",
                content: "Are you sure you want to enable these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else if ((rType == "disable") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Disable",
                cancelButton: "Cancel",
                content: "Are you sure you want to disable these servers?",
                confirm: function() {
                    multiAPI(rType, true);
                }
            }).open();
        } else {
            rConfirm = true;
        }
        if (rConfirm) {
            $.getJSON("./api?action=multi&type=server&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {
                if (data.result == true) {
                    if (rType == "restart") {
                        $.toast("Streams have been restarted.");
                    } else if (rType == "start") {
                        $.toast("Streams have been started.");
                    } else if (rType == "stop") {
                        $.toast("Streams have been stopped.");
                    } else if (rType == "purge") {
                        $.toast("Connections have been killed.");
                    } else if (rType == "delete") {
                        $.toast("Servers have been deleted.");
                    } else if (rType == "enable_proxy") {
                        $.toast("Proxy has been enabled for selected servers.");
                    } else if (rType == "disable_proxy") {
                        $.toast("Proxy has been disabled for selected servers.");
                    } else if (rType == "enable") {
                        $.toast("Servers have been enabled.");
                    } else if (rType == "disable") {
                        $.toast("Servers have been disabled.");
                    }
                    reloadPage();
                } else {
                    $.toast("An error occured while processing your request.");
                }
            }).fail(function() {
                $.toast("An error occured while processing your request.");
            });
            multiAPI("clear");
        }
    }

    function bindServers() {
        $("#reinstall_server").unbind();
        $("#reinstall_server").click(function() {
            navigate('./server_install?id=' + $(".bs-server-modal-center").data("id"));
        });
        $("#restart_services_ssh").unbind();
        $("#restart_services_ssh").click(function() {
            $(".bs-server-modal-center").modal("hide");
            $.getJSON("./api?action=restart_services&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {
                if (data.result === true) {
                    $.toast("XC_VM will be restarted shortly.");
                } else {
                    $.toast("An error occured while processing your request.");
                }
                $(".bs-server-modal-center").data("id", "");
            });
        });
        $("#reboot_server_ssh").unbind();
        $("#reboot_server_ssh").click(function() {
            $(".bs-server-modal-center").modal("hide");
            $.getJSON("./api?action=reboot_server&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {
                if (data.result === true) {
                    $.toast("Server will be rebooted shortly.");
                } else {
                    $.toast("An error occured while processing your request.");
                }
                $(".bs-server-modal-center").data("id", "");
            });
        });
        $("#update_binaries").unbind();
        $("#update_binaries").click(function() {
            $(".bs-server-modal-center").modal("hide");
            $.getJSON("./api?action=update_binaries&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {
                if (data.result === true) {
                    $.toast("Binaries are updating in the background...");
                } else {
                    $.toast("An error occured while processing your request.");
                }
                $(".bs-server-modal-center").data("id", "");
            });
        });
        $("#update_server").unbind();
        $("#update_server").click(function() {
            $(".bs-server-modal-center").modal("hide");
            $.getJSON("./api?action=server&sub=update&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {
                if (data.result === true) {
                    $.toast("Server is updating in the background...");
                } else {
                    $.toast("An error occured while processing your request.");
                }
                $(".bs-server-modal-center").data("id", "");
            });
        });
        $(".btn-reboot-server").click(function() {
            $(".bs-server-modal-center").data("id", $(this).data("id"));
            $(".bs-server-modal-center").modal("show");
            $("#reinstall_server").prop("disabled", false);
        });
        $("#update_server").prop("disabled", false);
        $("#update_binaries").prop("disabled", false);
    }
    $(document).ready(function() {
        $("#datatable").DataTable({
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function() {
                bindServers();
                bindHref();
                refreshTooltips();
                <?php if (hasPermissions('adv', 'edit_server')): ?>
                    // Multi Actions
                    multiAPI("clear");
                    $("#datatable tr").click(function() {
                        if (window.rShiftHeld) {
                            if ($(this).hasClass('selectedfilter')) {
                                $(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                                window.rSelected.splice($.inArray($($(this).find("td:eq(0)").html()).data("id"), window.rSelected), 1);
                            } else {
                                $(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                                window.rSelected.push($($(this).find("td:eq(0)").html()).data("id"));
                            }
                        }
                        $("#multi_servers_selected").html(window.rSelected.length + " servers");
                        if (window.rSelected.length > 0) {
                            if ("#header_stats") {
                                $("#header_stats").hide();
                            }
                            $("#multiselect_servers").show();
                        } else {
                            if ("#header_stats") {
                                $("#header_stats").show();
                            }
                            $("#multiselect_servers").hide();
                        }
                    });
                <?php endif; ?>
            },
            responsive: false
        });
        $("#datatable").css("width", "100%");
    });
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>