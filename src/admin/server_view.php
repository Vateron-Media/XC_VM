<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
    exit();
}

if (isset($allServers[CoreUtilities::$rRequest['id']])) {
    $rServer = $allServers[CoreUtilities::$rRequest['id']];
} elseif (isset($rProxyServers[CoreUtilities::$rRequest['id']])) {
    $rServer = $rProxyServers[CoreUtilities::$rRequest['id']];
} else {
    exit();
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
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
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
                            <?php if (hasPermissions('adv', 'streams')): ?>
                                <a href="./streams?filter=2&server=<?php echo $rServer['id']; ?>">
                                <?php endif; ?>
                                <div
                                    class="card cta-box <?php echo $rSettings['dark_mode'] ? '' : 'bg-info'; ?> text-white">
                                    <div class="card-body active-connections">
                                        <div class="media align-items-center">
                                            <div class="col-3">
                                                <div class="avatar-sm bg-light">
                                                    <i class="fe-pause avatar-title font-22 <?php echo $rSettings['dark_mode'] ? 'text-white' : 'text-info'; ?>"></i>
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
                                <?php if (hasPermissions('adv', 'streams')): ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="card-box">
                    <div class="col-md-12 align-self-center">
                        <?php if ($rServer['server_online']): ?>
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
                            <?php if ($rServer['server_type'] == 0): ?>
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
                            <?php endif; ?>
                            <h5 class="mb-1 mt-0">Network Input<small class="text-muted ml-2">of
                                    <?php echo number_format($rServer['network_guaranteed_speed'], 0); ?> Mbps</small></h5>
                            <div class="progress-w-percent" id="watchdog_input">
                                <span class="progress-value font-weight-bold">0 Mbps</span>
                                <div class="progress progress-sm">
                                    <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
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
                        <?php else: ?>
                            <div class="text-center" style="padding-top: 15px;">
                                <i class="fe-alert-triangle avatar-title font-24 text-danger"></i><br />
                                <h4 class="header-title text-danger">Server Offline</h4>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (is_array($rServer['gpu_info'])):
                    $rGPUID = 0;
                    foreach ($rServer['gpu_info']['gpus'] as $rGPU):
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
                    endforeach;
                endif;
                if ($rHasCert): ?>
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
                            <?php if ($rCertValid): ?>
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
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- Additional UI elements can be added here -->
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
        <?php if (!$rMobile || $rSettings['header_stats']): ?>
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

    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });

    <?php endif;
    if (in_array($allServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4)) || in_array($rProxyServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4))): ?>

        function getInstallStatus() {
            $.getJSON("./api?action=install_status&server_id=<?php echo intval(CoreUtilities::$rRequest['id']); ?>", function(data) {
                if (data.result === true) {
                    $("#server_install").html(data.data);
                    if (data.status == 3) {
                        setTimeout(getInstallStatus, 1000);
                    } else if (data.status == 1) {
                        setTimeout(navigate, 3000, './server_view?id=<?php echo intval(CoreUtilities::$rRequest['id']); ?>');
                    }
                } else {
                    $("#server_install").html("No status available...");
                }
                if ($("#server_install").length) {
                    $("#server_install").scrollTop($("#server_install")[0].scrollHeight - $("#server_install").height());
                }
            });
        }
    <?php endif; ?>

    function viewLiveConnections(rStreamID, rServerID = -1) {
        $("#datatable-live").DataTable({
            destroy: true,
            ordering: true,
            paging: true,
            searching: true,
            processing: true,
            serverSide: true,
            searchDelay: 250,
            bInfo: true,
            drawCallback: function() {
                bindHref();
                refreshTooltips(false);
            },
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "live_connections";
                    d.stream_id = rStreamID;
                    d.server_id = rServerID;
                }
            },
            columnDefs: [{
                    "className": "dt-center",
                    "targets": [1, 7, 8, 9, 10, 11]
                },
                {
                    "visible": false,
                    "targets": [0, 3, 5, 6]
                }
            ],
        });
        $(".bs-live-modal-center").modal("show");
    }

    function getBarColour(rInt) {
        if (rInt >= 75) {
            return "bg-danger";
        } else if (rInt >= 50) {
            return "bg-warning";
        } else {
            return "bg-success";
        }
    }

    function getStats(auto = true) {
        var rStart = Date.now();
        rURL = "./api?action=server_view&server_id=<?php echo intval(CoreUtilities::$rRequest['id']); ?>";
        $.getJSON(rURL, function(data) {
            $("#open_connections").html(data.data.open_connections);
            $("#total_running_streams").html(data.data.total_running_streams);
            $("#online_users").html(data.data.online_users);
            $("#offline_streams").html(data.data.offline_streams);
            if (data.data.watchdog) {
                // CPU
                $("#watchdog_cpu span").html($.number(data.data.watchdog.cpu, 2) + "%");
                $("#watchdog_cpu .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(data.data.watchdog.cpu));
                $("#watchdog_cpu .progress-bar").css("width", $.number(data.data.watchdog.cpu, 0) + "%");
                $("#watchdog_cpu .progress-bar").data("aria-valuenow", $.number(data.data.watchdog.cpu, 0));
                // Memory
                $("#watchdog_mem span").html($.number(data.data.watchdog.total_mem_used_percent, 2) + "%");
                $("#watchdog_mem .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(data.data.watchdog.total_mem_used_percent));
                $("#watchdog_mem .progress-bar").css("width", $.number(data.data.watchdog.total_mem_used_percent, 0) + "%");
                $("#watchdog_mem .progress-bar").data("aria-valuenow", $.number(data.data.watchdog.total_mem_used_percent, 0));
                // Disk Space
                rDiskUsage = (data.data.watchdog.total_disk_space - data.data.watchdog.free_disk_space) / data.data.watchdog.total_disk_space * 100;
                $("#watchdog_disk span").html($.number(rDiskUsage, 2) + "%");
                $("#watchdog_disk .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(rDiskUsage));
                $("#watchdog_disk .progress-bar").css("width", $.number(rDiskUsage, 0) + "%");
                $("#watchdog_disk .progress-bar").data("aria-valuenow", $.number(rDiskUsage, 0));
                // IO Usage
                if (data.data.watchdog.iostat_info) {
                    $("#watchdog_io span").html($.number(data.data.watchdog.iostat_info["avg-cpu"].iowait, 2) + "%");
                    $("#watchdog_io .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(data.data.watchdog.iostat_info["avg-cpu"].iowait));
                    $("#watchdog_io .progress-bar").css("width", $.number(data.data.watchdog.iostat_info["avg-cpu"].iowait, 0) + "%");
                    $("#watchdog_io .progress-bar").data("aria-valuenow", $.number(data.data.watchdog.iostat_info["avg-cpu"].iowait, 0));
                    $("#watchdog_idle").html(" " + $.number(data.data.watchdog.iostat_info["avg-cpu"].idle, 0) + "% Idle");
                }
                // Network Input
                rUsage = $.number(((data.data.watchdog.bytes_received / 125000) / data.netspeed) * 100, 0);
                $("#watchdog_input span").html($.number(data.data.watchdog.bytes_received / 125000, 0) + " Mbps");
                $("#watchdog_input .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(rUsage));
                $("#watchdog_input .progress-bar").css("width", $.number(rUsage, 0) + "%");
                $("#watchdog_input .progress-bar").data("aria-valuenow", $.number(rUsage, 0));
                // Network Output
                rUsage = $.number(((data.data.watchdog.bytes_sent / 125000) / data.netspeed) * 100, 0);
                $("#watchdog_output span").html($.number(data.data.watchdog.bytes_sent / 125000, 0) + " Mbps");
                $("#watchdog_output .progress-bar").removeClass("bg-danger").removeClass("bg-warning").removeClass("bg-success").addClass(getBarColour(rUsage));
                $("#watchdog_output .progress-bar").css("width", $.number(rUsage, 0) + "%");
                $("#watchdog_output .progress-bar").data("aria-valuenow", $.number(rUsage, 0));
            }
            if (auto) {
                if (Date.now() - rStart < 1000) {
                    setTimeout(getStats, 1000 - (Date.now() - rStart));
                } else {
                    getStats();
                }
            }
        }).fail(function() {
            if (auto) {
                setTimeout(getStats, 1000);
            }
        });
    }

    function getFPMStatus(rServerID) {
        $.getJSON("./api?action=fpm_status&server_id=" + rServerID, function(data) {
            if (data.result) {
                new jBox('Modal', {
                    attach: '#fpmModal',
                    title: 'PHP-FPM Status',
                    content: data.data
                }).open();
            }
        });
    }

    function api(rID, rServerID, rType, rConfirm = false) {
        if ((rType == "purge") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Kill",
                cancelButton: "Cancel",
                content: "Are you sure you want to kill all connections?",
                confirm: function() {
                    api(rID, rServerID, rType, true);
                }
            }).open();
        } else if ((rServerID == "kill") && (!rConfirm)) {
            rConfirm = true;
            rServerID = -1;
            rType = "kill";
        } else {
            rConfirm = true;
        }
        if (rConfirm) {
            $.getJSON("./api?action=stream&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {
                if (data.result == true) {
                    if (rType == "start") {
                        $.toast("Stream successfully started.");
                    } else if (rType == "stop") {
                        $.toast("Stream successfully stopped.");
                    } else if (rType == "restart") {
                        $.toast("Stream successfully restarted.");
                    } else if (rType == "kill") {
                        $.toast("Connection has been killed.");
                        if ($(".bs-live-modal-center").is(":visible")) {
                            $("#datatable-live").DataTable().ajax.reload(null, false);
                        }
                    } else if (rType == "purge") {
                        $.toast("Connections have been killed.");
                    }
                    $("#datatable_streams").DataTable().ajax.reload(null, false);
                    $("#datatable_connections").DataTable().ajax.reload(null, false);
                } else {
                    $.toast("An error occured while processing your request.");
                }
            }).fail(function() {
                $.toast("An error occured while processing your request.");
            });
        }
    }
    $(document).ready(function() {
        <?php if (in_array($allServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4)) || in_array($rProxyServers[intval(CoreUtilities::$rRequest['id'])]['status'], array(3, 4))): ?>
            getInstallStatus();
        <?php endif; ?>
        getStats();
        $("#datatable_streams").DataTable({
            ordering: true,
            paging: true,
            searching: true,
            processing: true,
            serverSide: true,
            searchDelay: 250,
            bInfo: true,
            bAutoWidth: false,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "streams";
                    d.server = <?php echo CoreUtilities::$rRequest['id']; ?>;
                    d.filter = 1;
                    d.simple = true;
                }
            },
            columnDefs: [{
                    "className": "dt-center",
                    "targets": [0, 4, 5, 6]
                },
                {
                    "visible": false,
                    "targets": [1, 3, 7, 8, 9]
                }
            ],
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function() {
                bindHref();
                refreshTooltips(false);
            },
            pageLength: <?php echo (intval($rSettings['default_entries']) ?: 10); ?>
        });
        $("#datatable_connections").DataTable({
            ordering: true,
            paging: true,
            searching: true,
            processing: true,
            serverSide: true,
            searchDelay: 250,
            bInfo: true,
            bAutoWidth: false,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "live_connections";
                    d.server_id = <?php echo CoreUtilities::$rRequest['id']; ?>;
                }
            },
            columnDefs: [{
                    "className": "dt-center",
                    "targets": [7, 8, 9, 11]
                },
                {
                    "visible": false,
                    "targets": [0, 1, 4, 5, 6, 10]
                }
            ],
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function() {

                bindHref();
                refreshTooltips(false);
            },
            pageLength: <?php echo (intval($rSettings['default_entries']) ?: 10); ?>
        });
        rDates = <?php echo json_encode($rStats['dates']); ?>;
        rCPUOptions = {
            chart: {
                height: 380,
                type: "area",
                stacked: false,
                zoom: {
                    type: 'x',
                    enabled: true,
                    autoScaleYaxis: true
                },
                events: {
                    beforeZoom: function(ctx) {
                        ctx.w.config.xaxis.range = undefined
                    }
                },
                animations: {
                    enabled: false
                }
            },
            colors: ["#5089de", "#56c2d6", "#51b089"],
            dataLabels: {
                enabled: false
            },
            stroke: {
                width: [2],
                curve: "smooth"
            },
            series: [{
                    name: "CPU Usage",
                    data: <?php echo json_encode($rStats['cpu']); ?>
                },
                {
                    name: "Memory Usage",
                    data: <?php echo json_encode($rStats['memory']); ?>
                },
                {
                    name: "IO Usage",
                    data: <?php echo json_encode($rStats['io']); ?>
                }
            ],
            fill: {
                type: "gradient",
                gradient: {
                    opacityFrom: .6,
                    opacityTo: .8
                }
            },
            xaxis: {
                type: "datetime",
                min: rDates[0],
                max: rDates[1],
                range: 3600000,
                labels: {
                    formatter: function(value, timestamp, opts) {
                        var d = new Date(timestamp);
                        return ("0" + d.getHours()).slice(-2) + ":" + ("0" + d.getMinutes()).slice(-2);
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function(value, {
                        series,
                        seriesIndex,
                        dataPointIndex,
                        w
                    }) {
                        return value + "%";
                    }
                }
            }
        };
        (rCPUChart = new ApexCharts(document.querySelector("#cpu_chart"), rCPUOptions)).render();
        rNetworkOptions = {
            chart: {
                height: 380,
                type: "area",
                stacked: false,
                zoom: {
                    type: 'x',
                    enabled: true,
                    autoScaleYaxis: true
                },
                events: {
                    beforeZoom: function(ctx) {
                        ctx.w.config.xaxis.range = undefined
                    }
                },
                animations: {
                    enabled: false
                }
            },
            colors: ["#03a9f4", "#81d4fa"],
            dataLabels: {
                enabled: false
            },
            stroke: {
                width: [2],
                curve: "smooth"
            },
            series: [{
                    name: "Input",
                    data: <?php echo json_encode($rStats['input']); ?>
                },
                {
                    name: "Output",
                    data: <?php echo json_encode($rStats['output']); ?>
                }
            ],
            fill: {
                type: "gradient",
                gradient: {
                    opacityFrom: .6,
                    opacityTo: .8
                }
            },
            xaxis: {
                type: "datetime",
                min: rDates[0],
                max: rDates[1],
                range: 3600000,
                labels: {
                    formatter: function(value, timestamp, opts) {
                        var d = new Date(timestamp);
                        return ("0" + d.getHours()).slice(-2) + ":" + ("0" + d.getMinutes()).slice(-2);
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function(value, {
                        series,
                        seriesIndex,
                        dataPointIndex,
                        w
                    }) {
                        return value + " Mbps";
                    }
                }
            }
        };
        (rNetworkChart = new ApexCharts(document.querySelector("#network_chart"), rNetworkOptions)).render();
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>