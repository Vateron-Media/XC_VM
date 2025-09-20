<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rCategories = getCategories('live');
$rTranscodeProfiles = getTranscodeProfiles();
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

foreach ($rServers as $rServer) {
    $rServerTree[] = array('id' => intval($rServer['id']), 'parent' => 'offline', 'text' => htmlspecialchars($rServer['server_name']), 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'Mass Edit Channels';
include 'header.php'; ?>
<div class="wrapper boxed-layout-xl" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Mass Edit Channels <small id="selected_count"></small></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
                } else { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Mass edit of channels was successfully executed!
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
                            <input type="hidden" name="streams" id="streams" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-play mr-1"></i>
                                            <span class="d-none d-sm-inline">Channels</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline">Servers</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="Search Channels...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="stream_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                                        <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Categories</option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach ($rCategories as $rCategory) { ?>
                                                        <option value="<?php echo intval($rCategory['id']); ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                                    echo ' selected';
                                                                                                                } ?>>
                                                            <?php echo htmlspecialchars($rCategory['category_name']); ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="stream_filter" class="form-control" data-toggle="select2">
                                                    <option value="">No Filter</option>
                                                    <option value="1">Online</option>
                                                    <option value="2">Stopped</option>
                                                    <option value="3">Creating</option>
                                                    <option value="4">Transcoding</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-8">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option<?php if ($rSettings['default_entries'] == $rShow) {
                                                                    echo ' selected';
                                                                } ?> value="<?php echo $rShow; ?>">
                                                            <?php echo $rShow; ?>
                                                            </option>
                                                        <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-2">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center">ID</th>
                                                        <th class="text-center">Icon</th>
                                                        <th>Stream Name</th>
                                                        <th>Category</th>
                                                        <th>Server</th>
                                                        <th class="text-center">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="category_id">Select Categories</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                                <option value="<?php echo intval($rCategory['id']); ?>"><?php echo htmlspecialchars($rCategory['category_name']); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="category_id_type" id="category_id_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) { ?>
                                                                <option value="<?php echo $rType; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bouquets" name="c_bouquets">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="bouquets">Select Bouquets</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet) { ?>
                                                                <option value="<?php echo intval($rBouquet['id']); ?>"><?php echo htmlspecialchars($rBouquet['bouquet_name']); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="bouquets_type" id="bouquets_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) { ?>
                                                                <option value="<?php echo $rType; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="rtmp_output" data-type="switch" name="c_rtmp_output">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="rtmp_output">Output RTMP</label>
                                                    <div class="col-md-2">
                                                        <input name="rtmp_output" id="rtmp_output" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="allow_record">Allow Recording</label>
                                                    <div class="col-md-2">
                                                        <input name="allow_record" id="allow_record" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="allow_record" data-type="switch" name="c_allow_record">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="transcode_profile_id">Transcoding Profile</label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">
                                                            <option selected value="0">Transcoding Disabled</option>
                                                            <?php foreach ($rTranscodeProfiles as $rProfile) { ?>
                                                                <option value="<?php echo intval($rProfile['profile_id']); ?>"><?php echo $rProfile['profile_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
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
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="server_tree">Server Tree</label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="server_type">Server Type</label>
                                                    <div class="col-md-2">
                                                        <select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED') as $rValue => $rType) { ?>
                                                                <option value="<?php echo $rValue; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="on_demand">On-Demand Servers</label>
                                                    <div class="col-md-8">
                                                        <select disabled name="on_demand[]" id="on_demand" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="reencode_on_edit"><?php echo $_['reencode_on_edit']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="restart_on_edit">Restart on Edit</label>
                                                    <div class="col-md-2">
                                                        <input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_stream" type="submit" class="btn btn-primary" value="Edit Channels" />
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

    <?php endif; ?>
    var rSelected = [];

    function getCategory() {
        return $("#category_search").val();
    }

    function getServer() {
        return $("#stream_server_id").val();
    }

    function getFilter() {
        return $("#stream_filter").val();
    }

    function toggleStreams() {
        $("#datatable-mass tr").each(function() {
            if ($(this).hasClass('selected')) {
                $(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);
                }
            } else {
                $(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                if ($(this).find("td:eq(0)").text()) {
                    window.rSelected.push($(this).find("td:eq(0)").text());
                }
            }
        });
        $("#selected_count").html(" - " + window.rSelected.length + " selected")
    }

    function evaluateServers() {
        var rOVal = $("#on_demand").val();
        $("#on_demand").empty();
        $($('#server_tree').jstree(true).get_json('source', {
            flat: true
        })).each(function(index, value) {
            if (value.parent != "#") {
                $("#on_demand").append(new Option(value.text, value.id));
            }
        });
        $("#on_demand").val(rOVal).trigger("change");
        if (!$("#on_demand").val()) {
            $("#on_demand").val(0).trigger("change");
        }
    }
    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        })
        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function(html) {
            if (($(html).attr("id") != "restart_on_edit") && ($(html).attr("id") != "reencode_on_edit")) {
                window.rSwitches[$(html).attr("id")].disable();
            }
        });
        $('#server_tree').on('redraw.jstree', function(e, data) {
            evaluateServers();
        }).on('select_node.jstree', function(e, data) {
            $("#c_server_tree").prop("checked", true);
            if (data.node.parent == "offline") {
                $('#server_tree').jstree("move_node", data.node.id, "#source", "last");
            } else {
                $('#server_tree').jstree("move_node", data.node.id, "#offline", "first");
            }
        }).jstree({
            'core': {
                'check_callback': function(op, node, parent, position, more) {
                    switch (op) {
                        case 'move_node':
                            if ((node.id == "offline") || (node.id == "source")) {
                                return false;
                            }
                            if (parent.id == "#") {
                                return false;
                            }
                            return true;
                    }
                },
                'data': <?php echo json_encode(($rServerTree ?: array())); ?>
            },
            "plugins": ["dnd"]
        });
        $("input[type=checkbox].activate").change(function() {
            if ($(this).is(":checked")) {
                if ($(this).data("type") == "switch") {
                    window.rSwitches[$(this).data("name")].enable();
                } else {
                    $("#" + $(this).data("name")).prop("disabled", false);
                    if ($(this).data("name") == "server_tree") {
                        $("#on_demand").prop("disabled", false);
                        $("#server_type").prop("disabled", false);
                    }
                    if ($(this).data("name") == "category_id") {
                        $("#category_id_type").prop("disabled", false);
                    }
                    if ($(this).data("name") == "bouquets") {
                        $("#bouquets_type").prop("disabled", false);
                    }
                }
            } else {
                if ($(this).data("type") == "switch") {
                    window.rSwitches[$(this).data("name")].disable();
                } else {
                    $("#" + $(this).data("name")).prop("disabled", true);
                    if ($(this).data("name") == "server_tree") {
                        $("#on_demand").prop("disabled", true);
                        $("#server_type").prop("disabled", true);
                    }
                    if ($(this).data("name") == "category_id") {
                        $("#category_id_type").prop("disabled", true);
                    }
                    if ($(this).data("name") == "bouquets") {
                        $("#bouquets_type").prop("disabled", true);
                    }
                }
            }
        });
        rTable = $("#datatable-mass").DataTable({
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function() {
                $("#datatable-mass a").removeAttr("href");
                bindHref();
                refreshTooltips();
            },
            processing: true,
            serverSide: true,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "stream_list",
                        d.category = getCategory(),
                        d.filter = getFilter(),
                        d.server = getServer(),
                        d.only_channels = 1
                }
            },
            columnDefs: [{
                "className": "dt-center",
                "targets": [0, 1, 5]
            }],
            "rowCallback": function(row, data) {
                if ($.inArray(data[0], window.rSelected) !== -1) {
                    $(row).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                }
            },
            pageLength: <?php echo (intval($rSettings['default_entries']) ?: 10); ?>,
            order: [
                [0, "desc"]
            ]
        });
        $('#stream_search').keyup(function() {
            rTable.search($(this).val()).draw();
        })
        $('#show_entries').change(function() {
            rTable.page.len($(this).val()).draw();
        })
        $('#stream_filter').change(function() {
            rTable.ajax.reload(null, false);
        })
        $('#stream_server_id').change(function() {
            rTable.ajax.reload(null, false);
        })
        $('#category_search').change(function() {
            rTable.ajax.reload(null, false);
        })
        $("#datatable-mass").selectable({
            filter: 'tr',
            selected: function(event, ui) {
                if ($(ui.selected).hasClass('selectedfilter')) {
                    $(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
                    window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);
                } else {
                    $(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
                    window.rSelected.push($(ui.selected).find("td:eq(0)").text());
                }
                $("#selected_count").html(" - " + window.rSelected.length + " selected")
            }
        });
        $("form").submit(function(e) {
            e.preventDefault();
            $("#server_tree_data").val(JSON.stringify($('#server_tree').jstree(true).get_json('source', {
                flat: true
            })));
            rPass = false;
            rSubmit = true;
            $.each($('#server_tree').jstree(true).get_json('#', {
                flat: true
            }), function(k, v) {
                if (v.parent == "source") {
                    rPass = true;
                }
            });
            $("#streams").val(JSON.stringify(window.rSelected));
            if (window.rSelected.length == 0) {
                $.toast("Select at least one stream to edit.");
                rSubmit = false;
            }
            if (rSubmit) {
                $(':input[type="submit"]').prop('disabled', true);
                submitForm(window.rCurrentPage, new FormData($("form")[0]));
            }
        });
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>