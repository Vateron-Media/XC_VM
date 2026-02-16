<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rType = isset(CoreUtilities::$rRequest['proxy']) ? 1 : 2;


if (isset(CoreUtilities::$rRequest['id'])) {
    if ($rType == 1) {
        $rServerArr = $rProxyServers[intval(CoreUtilities::$rRequest['id'])];
    } else {
        $rServerArr = $allServers[intval(CoreUtilities::$rRequest['id'])];
    }

    if (!$rServerArr) {
        goHome();
    }
}

$_TITLE = $rType == 1 ? 'Install Proxy' : 'Install Server';

include 'header.php';
echo '<div class="wrapper boxed-layout"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
    echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n" . '                    ';

if ($rType == 1) {
    echo "\t\t\t\t\t" . '<h4 class="page-title">';

    if (isset($rServerArr)) {
        if (isset(CoreUtilities::$rRequest['update'])) {
            echo 'Update Proxy';
        } else {
            echo 'Reinstall Proxy';
        }
    } else {
        echo 'Proxy Installation';
    }

    echo '</h4>' . "\n" . '                    ';
} else {
    echo '                    <h4 class="page-title">';

    if (isset($rServerArr)) {
        if (isset(CoreUtilities::$rRequest['update'])) {
            echo 'Update Server';
        } else {
            echo 'Reinstall Server';
        }
    } else {
        echo 'Server Installation';
    }

    echo '</h4>' . "\n" . '                    ';
}

echo "\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n" . '                        ';

if (isset($rServerArr) && $rServerArr['is_main'] == 1) {
    echo '                        <div class="alert alert-danger" role="alert">' . "\n" . '                            This is your main server, you cannot reinstall it from the XC_VM panel. To reinstall this server, please use the installation instructions on the billing panel.' . "\n" . '                        </div>' . "\n" . '                        ';
} else {
    echo "\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\n" . '                            ';

    if (!isset($rServerArr)) {
    } else {
        echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
        echo $rServerArr['id'];
        echo '" />' . "\n" . '                            ';
    }

    echo '                            <input type="hidden" id="parent_id" name="parent_id" value="" />' . "\n" . '                            <input type="hidden" name="type" value="';
    echo $rType;
    echo '" />' . "\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#server-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-creation mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
    echo $language::get('details');
    echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    ';

    if ($rType != 1) {
    } else {
        echo '                                    <li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#server-coverage" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-server mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Server Coverage</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    ';
    }

    echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="server-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="server_name">';
    echo $language::get('server_name');
    echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-9">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="server_name" name="server_name" ';

    if (!isset($rServerArr)) {
    } else {
        echo 'readonly ';
    }

    echo 'value="';

    if (!isset($rServerArr)) {
    } else {
        echo htmlspecialchars($rServerArr['server_name']);
    }

    echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="server_ip">Server IP</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="server_ip" name="server_ip" ';

    if (!isset($rServerArr)) {
    } else {
        echo 'readonly ';
    }

    echo 'value="';

    if (!isset($rServerArr)) {
    } else {
        echo htmlspecialchars($rServerArr['server_ip']);
    }

    echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-3 col-form-label" for="server_ip">SSH Port</label>' . "\n" . '                                                    <div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="ssh_port" name="ssh_port" value="22" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="root_username">SSH Username <i title="This needs to be either the root user, or sudoer. Root is recommended." class="tooltip text-secondary far fa-circle"></i></label>' . "\n" . '                                                    <div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="root_username" name="root_username" value="root" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-3 col-form-label" for="root_username">SSH Password</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="root_password" name="root_password" value="" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';

    if ($rType == 1) {
        echo '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<label class=\"col-md-3 col-form-label\" for=\"http_broadcast_port\">HTTP Port <i title=\"Enter a port number between 1024 and 65535. As XC_VM doesn't run as root, it cannot run on a port lower than 1024. This cannot be changed later.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="http_broadcast_port" name="http_broadcast_port" value="80" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . "                                                    <label class=\"col-md-3 col-form-label\" for=\"https_broadcast_port\">HTTPS Port <i title=\"Enter a port number between 1024 and 65535. As XC_VM doesn't run as root, it cannot run on a port lower than 1024. This cannot be changed later.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n" . '                                                    <div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="https_broadcast_port" name="https_broadcast_port" value="443" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
    }

    echo '                                                <div class="form-group row mb-4">' . "\n" . "                                                    <label class=\"col-md-3 col-form-label\" for=\"update_sysctl\">Update sysctl.conf <i title=\"Use the XC_VM sysctl.conf file. If you have your own custom sysctl.conf, then disable this or it will be overwritten. If you don't know what a sysctl configuration is, use this as it will correctly set your TCP settings and open file limits.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="update_sysctl" id="update_sysctl" type="checkbox" data-plugin="switchery" class="js-switch" checked data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n" . '                                                ';

    if ($rType != 1) {
    } else {
        echo '                                                <div class="form-group row mb-4">' . "\n" . "                                                    <label class=\"col-md-3 col-form-label\" for=\"use_private_ip\">Use Private IP <i title=\"Use the private IP of the load balancer you've selected to route traffic internally.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="use_private_ip" id="use_private_ip" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n" . '                                                ';
    }

    if (isset($rServerArr) && isset(CoreUtilities::$rRequest['update'])) {
        echo '                                                <div class="alert alert-info" role="alert">' . "\n" . '                                                    In order to update your XC_VM core from v';
        echo $rServerArr['xc_vm_version'];
        echo ' to v';
        echo $allServers[SERVER_ID]['xc_vm_version'];
        echo ", you will need to enter root SSH details.<br/>This will reinstall your server with the most up to date software, it shouldn't take too long, however your server will be offline to customers during the update process." . "\n" . '                                                </div>' . "\n" . '                                                ';
    } else {
        echo '                                                <div class="alert alert-warning mb-4" role="alert">' . "\n" . '                                                    ';

        if ($rType == 1) {
            echo '                                                    You will not be able to change the port or any other settings through the XC_VM Admin Panel after installation, you will be required to reinstall the proxy server entirely.<br/><br/>Installation will begin immediately, you will be alerted of progress on the Server View page.' . "\n" . '                                                    ';
        } else {
            echo '                                                    Installation will begin immediately, you will be alerted of progress on the Server View page. After installation is complete you can amend the ports and other server settings.' . "\n" . '                                                    ';
        }

        if (isset($rServerArr)) {
            echo '                                                    <br/><br/>As you are reinstalling the server, it will go offline until the installation is complete.' . "\n" . '                                                    ';
        } else {
            echo '                                                    <br/><br/>With new installations, the file limit is set in the system. A reboot is required for this, but you can do it at your own pace.' . "\n" . '                                                    ';
        }

        echo '                                                </div>' . "\n" . '                                                ';
    }

    echo '                                            </div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            ';

    if ($rType == 1) {
        echo "\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                            ';
    } else {
        echo '                                            <li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_server" type="submit" class="btn btn-primary" value="';
        echo $language::get('install_server');
        echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                            ';
    }

    echo "\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    ';

    if ($rType == 1) {
        echo '                                    <div class="tab-pane" id="server-coverage">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable" class="table table-borderless mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead class="bg-light">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>Server Name</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Server IP</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

        foreach (CoreUtilities::$rServers as $d58b4f8653a391d8 => $A08adcff1f387f4c) {
            if ($A08adcff1f387f4c['server_type'] == 0) {
                echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr';

                if (!(isset($rServerArr) && in_array($A08adcff1f387f4c['id'], CoreUtilities::$rServers[$rServerArr['id']]['parent_id']))) {
                } else {
                    echo " class='selected selectedfilter ui-selected'";
                }

                echo '>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
                echo $A08adcff1f387f4c['id'];
                echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
                echo $A08adcff1f387f4c['server_name'];
                echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
                echo $A08adcff1f387f4c['server_ip'];
                echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
            }
        }
        echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_server" type="submit" class="btn btn-primary" value="';
        echo $language::get('install_server');
        echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    ';
    }

    echo "\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n" . '                        ';
}

echo "\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div> ' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
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
    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });
        $("#ssh_port").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#rtmp_port").inputFilter(function(value) {
            return /^\d*$/.test(value) && (value === "" || parseInt(value) <= 65535);
        });
        $("#http_broadcast_port").inputFilter(function(value) {
            return /^\d*$/.test(value) && (value === "" || parseInt(value) <= 65535);
        });
        $("#https_broadcast_port").inputFilter(function(value) {
            return /^\d*$/.test(value) && (value === "" || parseInt(value) <= 65535);
        });
        $("form").submit(function(e) {
            e.preventDefault();
            <?php if ($rType == 1): ?>
                var rServers = [];
                $("#datatable tr.selected").each(function() {
                    rServers.push($(this).find("td:eq(0)").text());
                });
                if (rServers.length == 0) {
                    $.toast("Please select at least one server to apply the proxy to.");
                    return;
                }
                $("#parent_id").val("[" + rServers.join(",") + "]");
            <?php endif; ?>
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
        <?php if ($rType == 1): ?>
            $("#datatable").DataTable({
                columnDefs: [{
                    "className": "dt-center",
                    "targets": [0, 2]
                }],
                drawCallback: function() {
                    bindHref();
                    refreshTooltips();
                },
                paging: false,
                bInfo: false,
                searching: false
            });
            $("#datatable").selectable({
                filter: 'tr',
                selected: function(event, ui) {
                    if ($(ui.selected).hasClass('selectedfilter')) {
                        $(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                    } else {
                        $(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                    }
                }
            });
        <?php endif; ?>
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