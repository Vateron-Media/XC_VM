<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()): ?>
    <?php goHome(); ?>
<?php endif; ?>

<?php $_TITLE = 'Lines'; ?>
<?php include 'header.php'; ?>

<div class="wrapper" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Lines</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Line has been added / modified.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php if (!$rMobile) {
                                                                                } else {
                                                                                    echo 'collapse';
                                                                                } ?>">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="user_search" value="<?php if (!isset(CoreUtilities::$rRequest['search'])) {
                                                                                                } else {
                                                                                                    echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                } ?>" placeholder="Search Lines...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="user_reseller">Filter Results &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();"><i class="mdi mdi-close"></i></button></label>
                            <div class="col-md-3">
                                <select id="user_reseller" class="form-control" data-toggle="select2">
                                    <?php if (!(isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner']))))): ?>
                                    <?php else: ?>
                                        <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="user_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) {
                                                            echo ' selected';
                                                        } ?>>No Filter</option>
                                    <?php
                                    $filters = [
                                        1 => 'Active',
                                        2 => 'Disabled',
                                        3 => 'Banned',
                                        4 => 'Expired',
                                        5 => 'Trial',
                                        6 => 'Restreamer',
                                        7 => 'Ministra',
                                        8 => 'Expiring Soon',
                                    ];
                                    foreach ($filters as $key => $value) {
                                        $selected = (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == $key) ? ' selected' : '';
                                        echo "<option value=\"$key\"$selected>$value</option>\n";
                                    }
                                    ?>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="user_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="user_show_entries" class="form-control" data-toggle="select2">
                                    <?php
                                    $entriesOptions = [10, 25, 50, 250, 500, 1000];
                                    foreach ($entriesOptions as $rShow) {
                                        $selected = (isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || ($rSettings['default_entries'] == $rShow) ? ' selected' : '';
                                        echo "<option value=\"$rShow\"$selected>$rShow</option>\n";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>Username</th>
                                    <th>Password</th>
                                    <th>Owner</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Online</th>
                                    <th class="text-center">Trial</th>
                                    <th class="text-center">Restreamer</th>
                                    <th class="text-center">Active</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Expiration</th>
                                    <th class="text-center">Last Connection</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
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

    <?php
    echo "\t\t" . 'var rClearing = false;' . "\r\n" . '        var rSelected = [];' . "\r\n\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((window.rSelected) && (window.rSelected.length > 0)) {' . "\r\n" . '                $.toast("Individual actions disabled in multi-select mode.");' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this line?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "kill") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections for this line?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=line&sub=" + rType + "&user_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            $.toast("Line has been deleted.");' . "\r\n" . '                        } else if (rType == "enable") {' . "\r\n" . '                            $.toast("Line has been enabled.");' . "\r\n" . '                        } else if (rType == "disable") {' . "\r\n" . '                            $.toast("Line has been disabled.");' . "\r\n" . '                        } else if (rType == "unban") {' . "\r\n" . '                            $.toast("Line has been unbanned.");' . "\r\n" . '                        } else if (rType == "ban") {' . "\r\n" . '                            $.toast("Line has been banned.");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("All connections for this line have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable-users").DataTable().ajax.reload(null, false);' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function multiAPI(rType, rConfirm=false) {' . "\r\n" . '            if (rType == "clear") {' . "\r\n" . '                if ("#header_stats") {' . "\r\n" . '                    $("#header_stats").show();' . "\r\n" . '                }' . "\r\n" . '                window.rSelected = [];' . "\r\n" . '                $(".multiselect").hide();' . "\r\n" . "                \$(\"#datatable-users tr\").removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete these lines?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=multi&type=line&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "ban") {' . "\r\n" . '                            $.toast("Lines have been banned.");' . "\r\n" . '                        } else if (rType == "unban") {' . "\r\n" . '                            $.toast("Lines have been unbanned.");' . "\r\n" . '                        } else if (rType == "enable") {' . "\r\n" . '                            $.toast("Lines have been enabled.");' . "\r\n" . '                        } else if (rType == "disable") {' . "\r\n" . '                            $.toast("Lines have been disabled.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Lines have been deleted.");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable-users").DataTable().ajax.reload(null, false);' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                multiAPI("clear");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function openDownload(username, password) {' . "\r\n\t\t\t" . '$("#download_type").val("").trigger("change");' . "\r\n" . '            $("#output_type").val("").trigger("change");' . "\r\n\t\t\t" . '$("#download_button").attr("disabled", true);' . "\r\n\t\t\t" . "\$('.downloadModal').data('username', username);" . "\r\n\t\t\t" . "\$('.downloadModal').data('password', password);" . "\r\n\t\t\t" . "\$('.downloadModal').modal('show');" . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function doDownload() {' . "\r\n\t\t\t" . 'if ($("#download_url").val()) {' . "\r\n\t\t\t\t" . 'window.open($("#download_url").val());' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function copyDownload() {' . "\r\n\t\t\t" . '$("#download_url").select();' . "\r\n\t\t\t" . 'document.execCommand("copy");' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#user_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getReseller() {' . "\r\n\t\t\t" . 'return $("#user_reseller").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n\t\t\t" . "\$(\"#user_search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#user_filter').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#user_reseller').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#user_show_entries').val(\"";
    echo (intval($rSettings['default_entries']) ?: 10);
    echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-users').DataTable().search(\$(\"#user_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-users').DataTable().page.len(\$('#user_show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-users\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-users").DataTable().ajax.reload( null, false );' . "\r\n" . '            delParams(["search", "filter", "owner", "page", "entries"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '}' . "\r\n" . '        function clearOwner() {' . "\r\n" . "            \$('#user_reseller').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n" . '        function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "filter", "owner"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-users").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n" . '        var rSearch;' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . '            $("#output_type").change(function() {' . "\r\n" . '                $("#download_type").trigger("change");' . "\r\n" . '            });' . "\r\n" . '            $("#download_type").change(function() {' . "\r\n" . '                if ($("#download_type").val()) {' . "\r\n" . '                    ';
    $rURL = rtrim(CoreUtilities::$rServers[SERVER_ID]['site_url'], '/');
    echo '                    rText = "';
    echo $rURL;
    echo "/playlist/\" + \$('.downloadModal').data('username') + \"/\" + \$('.downloadModal').data('password') + \"/\" + decodeURIComponent(\$('.downloadModal select').val());" . "\r\n" . '                    if ($("#output_type").val().length > 0) {' . "\r\n" . "                        if (rText.indexOf('?output=') != -1) {" . "\r\n" . '                            rText = rText + "&key=" + $("#output_type").val().join(",");' . "\r\n" . '                        } else {' . "\r\n" . '                            rText = rText + "?key=" + $("#output_type").val().join(",");' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . "                    if (\$(\"#download_type\").find(':selected').data('text')) {" . "\r\n" . "                        rText = \$(\"#download_type\").find(':selected').data('text').replace(\"{DEVICE_LINK}\", '\"' + rText + '\"');" . "\r\n" . '                        $("#download_button").attr("disabled", true);' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#download_button").attr("disabled", false);' . "\r\n" . '                    }' . "\r\n" . '                    $("#download_url").val(rText);' . "\r\n" . '                } else {' . "\r\n" . '                    $("#download_url").val("");' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
    echo intval($rSettings['default_entries']);
    echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-users").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"," . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-users").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-users").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-users").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n" . '                    ';

    if (!hasPermissions('adv', 'edit_user')) {
    } else {
        echo '                    // Multi Actions' . "\r\n" . '                    multiAPI("clear");' . "\r\n" . '                    $("#datatable-users tr").click(function() {' . "\r\n" . '                        if (window.rShiftHeld) {' . "\r\n" . "                            if (\$(this).hasClass('selectedfilter')) {" . "\r\n" . "                                \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                                window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                            } else {            ' . "\r\n" . "                                \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                                window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#multi_lines_selected").html(window.rSelected.length + " lines");' . "\r\n" . '                        if (window.rSelected.length > 0) {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").hide();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_lines").show();' . "\r\n" . '                        } else {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").show();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_lines").hide();' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    ';
    }

    echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "lines";' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter();' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getReseller();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,4,5,6,7,8,9,10,11,12]},' . "\r\n\t\t\t\t\t";

    if (CoreUtilities::$rSettings['redis_handler']) {
        echo "\t\t\t\t\t" . '{"orderable": false, "targets": [5,8,12]}' . "\r\n\t\t\t\t\t";
    } else {
        echo "\t\t\t\t\t" . '{"orderable": false, "targets": [12]}' . "\r\n\t\t\t\t\t";
    }

    echo "\t\t\t\t" . '],' . "\r\n" . '                ';

    if ($rMobile) {
        echo 'scrollX: true,';
    }

    echo "\t\t\t\t" . 'order: [[ ';
    echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 0);
    echo ', "';
    echo (in_array(strtolower(CoreUtilities::$rRequest['dir'] ?? ''), ['asc', 'desc'], true) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
    echo '" ]],' . "\r\n\t\t\t\t" . 'pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n\t\t\t" . '});' . "\r\n" . '            function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n" . "            \$('#user_reseller').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-users").css("width", "100%");' . "\r\n\t\t\t" . "\$('#user_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#user_search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#user_search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'doSearch($(this).val());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#user_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#user_show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#user_show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#user_filter').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#user_filter").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("filter", $("#user_filter").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("filter");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#user_reseller').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#user_reseller").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("owner", $("#user_reseller").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("owner");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "if (\$('#user_search').val()) {" . "\r\n\t\t\t\t" . "rTable.search(\$('#user_search').val()).draw();" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-users").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n" . '            checkClear();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
    ?>
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<!-- WhatsApp Renewal Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" role="dialog" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="whatsappModalLabel"><i class="mdi mdi-whatsapp text-success"></i> WhatsApp Renewal Reminder</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="wa_language">Select Language / Sprache wählen / Dil Seçin</label>
                    <select id="wa_language" class="form-control">
                        <option value="de">🇩🇪 Deutsch</option>
                        <option value="en">🇬🇧 English</option>
                        <option value="tr">🇹🇷 Türkçe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Preview / Vorschau / Önizleme</label>
                    <textarea id="wa_message_preview" class="form-control" rows="5" readonly></textarea>
                </div>
                <input type="hidden" id="wa_phone" value="">
                <input type="hidden" id="wa_username" value="">
                <input type="hidden" id="wa_expdate" value="">
                <input type="hidden" id="wa_daysremaining" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a id="wa_send_btn" href="#" target="_blank" class="btn btn-success"><i class="mdi mdi-whatsapp"></i> Send via WhatsApp</a>
            </div>
        </div>
    </div>
</div>
<script>
// WhatsApp Renewal Messages
var waMessages = {
    de: "Hallo Lieber {USERNAME},\n\nIhr IPTV Abonnement endet am {EXPDATE} und es sind noch {DAYS} Tage übrig.\n\nMöchten Sie Ihr IPTV Abonnement verlängern?\n\nMit freundlichen Grüßen",
    en: "Hello Dear {USERNAME},\n\nYour IPTV subscription expires on {EXPDATE} and there are {DAYS} days remaining.\n\nWould you like to renew your IPTV subscription?\n\nBest regards",
    tr: "Merhaba Sayın {USERNAME},\n\nIPTV aboneliğiniz {EXPDATE} tarihinde sona eriyor ve {DAYS} gün kaldı.\n\nIPTV aboneliğinizi yenilemek ister misiniz?\n\nSaygılarımızla"
};

function updateWaPreview() {
    var lang = $("#wa_language").val();
    var username = $("#wa_username").val();
    var expdate = $("#wa_expdate").val();
    var days = $("#wa_daysremaining").val();
    
    var message = waMessages[lang]
        .replace("{USERNAME}", username)
        .replace("{EXPDATE}", expdate)
        .replace("{DAYS}", days);
    
    $("#wa_message_preview").val(message);
    
    var phone = $("#wa_phone").val().replace(/[^0-9]/g, '');
    var encodedMessage = encodeURIComponent(message);
    $("#wa_send_btn").attr("href", "https://wa.me/" + phone + "?text=" + encodedMessage);
}

function openWhatsApp(username, contact, expTimestamp) {
    if (!contact) {
        $.toast({
            heading: 'No WhatsApp Number',
            text: 'This line has no WhatsApp number set.',
            icon: 'warning',
            position: 'top-right'
        });
        return;
    }
    
    var expDate = expTimestamp ? new Date(expTimestamp * 1000) : null;
    var expDateStr = expDate ? expDate.toLocaleDateString('de-DE') : 'Never';
    var daysRemaining = 0;
    
    if (expDate) {
        var today = new Date();
        var diffTime = expDate - today;
        daysRemaining = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        if (daysRemaining < 0) daysRemaining = 0;
    }
    
    $("#wa_phone").val(contact);
    $("#wa_username").val(username);
    $("#wa_expdate").val(expDateStr);
    $("#wa_daysremaining").val(daysRemaining);
    
    updateWaPreview();
    $("#whatsappModal").modal("show");
}

$(document).ready(function() {
    $("#wa_language").change(function() {
        updateWaPreview();
    });
});
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>