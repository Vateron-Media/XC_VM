<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php $rCategories = getCategories('radio'); ?>
<?php $_TITLE = 'Radio Stations'; ?>
<?php include 'header.php'; ?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Radio Stations</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php if ($rMobile) {
                                                                                    echo 'collapse';
                                                                                } ?>">
                            <div class="col-md-2">
                                <input type="text" class="form-control" id="station_search" value="<?php if (isset(CoreUtilities::$rRequest['search'])) {
                                                                                                        echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                    } ?>" placeholder="Search Stations...">
                            </div>
                            <div class="col-md-3">
                                <select id="station_server_id" class="form-control" data-toggle="select2">
                                    <option value="" selected><?php echo $_['all_servers']; ?></option>
                                    <option value="-1" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == -1) {
                                                            echo ' selected';
                                                        } ?>>No Servers</option>
                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select id="station_category_id" class="form-control" data-toggle="select2">
                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                    <option value="-1" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1) {
                                                            echo ' selected';
                                                        } ?>>No Categories</option>
                                    <?php foreach ($rCategories as $rCategory) { ?>
                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rCategory['category_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="station_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) {
                                                            echo ' selected';
                                                        } ?>><?php echo $_['no_filter']; ?></option>
                                    <option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) {
                                                            echo ' selected';
                                                        } ?>>Online</option>
                                    <option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) {
                                                            echo ' selected';
                                                        } ?>>Down</option>
                                    <option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) {
                                                            echo ' selected';
                                                        } ?>>Stopped</option>
                                    <option value="4" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) {
                                                            echo ' selected';
                                                        } ?>>Starting</option>
                                    <option value="5" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) {
                                                            echo ' selected';
                                                        } ?>>On Demand</option>
                                    <option value="6" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6) {
                                                            echo ' selected';
                                                        } ?>>Direct</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="station_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="station_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option value="<?php echo $rShow; ?>" <?php if ((isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || (!isset(CoreUtilities::$rRequest['entries']) && $rSettings['default_entries'] == $rShow)) {
                                                                                    echo ' selected';
                                                                                } ?>><?php echo $rShow; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Icon</th>
                                    <th>Name</th>
                                    <?php if ($rSettings['streams_grouped'] == 1) { ?>
                                        <th><?php echo $_['servers']; ?></th>
                                    <?php } else { ?>
                                        <th><?php echo $_['server']; ?></th>
                                    <?php } ?>
                                    <th class="text-center">Clients</th>
                                    <th class="text-center">Uptime</th>
                                    <th class="text-center">Actions</th>
                                    <th class="text-center">Stream Info</th>
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
	<?php
		echo "\t\t" . 'var rClearing = false;' . "\r\n" . '        var rSelected = [];' . "\r\n\r\n" . '        function viewSources(rTitle, rID) {' . "\r\n" . '            $("#datatable-sources").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "radios";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rID;' . "\r\n" . '                        d.single = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [4,5,6,9]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,7,8]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-streams-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n" . '        function openImage(elem) {' . "\r\n\t\t\t" . 'rPath = $(elem).data("src");' . "\r\n\t\t\t" . 'if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: rPath,' . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        function viewLiveConnections(rStreamID, rServerID=-1) {' . "\r\n" . '            $("#datatable-live").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n" . '                        d.server_id = rServerID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,7,8,9,10,11]},' . "\r\n" . '                    {"visible": false, "targets": [0,3,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-live-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function getStreamIDs() {' . "\r\n" . '            var rStreamIDs = [];' . "\r\n" . '            var rIndexes = [];' . "\r\n" . '            $("#datatable-streampage").DataTable().rows().every(function (rowIdx, tableLoop, rowLoop) {' . "\r\n" . '                rStreamIDs.push($($("#datatable-streampage").DataTable().row(rowIdx).data()[0]).text());' . "\r\n" . '                rIndexes.push(rowIdx);' . "\r\n" . '            });' . "\r\n" . '            return [rStreamIDs, rIndexes];' . "\r\n" . '        }' . "\r\n" . '        function refreshInformation() {' . "\r\n" . '            if (!window.rProcessing) {' . "\r\n" . '                var rUpdateColumns = [4,5,6,7];' . "\r\n" . '                var rStreamIDs = getStreamIDs();' . "\r\n" . '                if (rStreamIDs[0].length > 0) {' . "\r\n" . '                    $.getJSON("./table?" + $.param($("#datatable-streampage").DataTable().ajax.params()) + "&refresh=" + rStreamIDs[0].join(","), function(rTable) {' . "\r\n" . '                        if (!window.rProcessing) {' . "\r\n" . '                            $(rTable.data).each(function(rIndex, rItem) {' . "\r\n" . '                                for (i in rUpdateColumns) {' . "\r\n" . '                                    var rIndex = rStreamIDs[0].indexOf($(rItem[0]).text());' . "\r\n" . '                                    if (rIndex >= 0) {' . "\r\n" . "                                        if (\$('#datatable-streampage').DataTable().cell(rStreamIDs[1][rIndex], rUpdateColumns[i]).data() != rItem[rUpdateColumns[i]]) {" . "\r\n" . "                                            \$('#datatable-streampage').DataTable().cell(rStreamIDs[1][rIndex], rUpdateColumns[i]).data(rItem[rUpdateColumns[i]]);" . "\r\n" . '                                        }' . "\r\n" . '                                    }' . "\r\n" . '                                }' . "\r\n" . '                            });' . "\r\n" . '                            bindHref(); refreshTooltips(false);' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                }' . "\r\n" . '            }' . "\r\n" . '            clearTimeout(window.rRefresh);' . "\r\n" . '            window.rRefresh = setTimeout(refreshInformation, 5000);' . "\r\n" . '        }' . "\r\n\t\t" . 'function api(rID, rServerID, rType, rConfirm=false) {' . "\r\n" . '            if ((window.rSelected) && (window.rSelected.length > 0)) {' . "\r\n" . '                $.toast("Individual actions disabled in multi-select mode.");' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this station?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rServerID == "kill") && (!rConfirm)) {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '                rServerID = -1;' . "\r\n" . '                rType = "kill";' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=stream&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Station successfully started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Station successfully stopped.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Station successfully restarted.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Station successfully deleted.");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        if ($(".bs-streams-modal-center").is(":visible")) {' . "\r\n" . '                            $("#datatable-sources").DataTable().ajax.reload( null, false );' . "\r\n" . '                        }' . "\r\n" . '                        if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                            $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function multiAPI(rType, rConfirm=false) {' . "\r\n" . '            if (rType == "clear") {' . "\r\n" . '                if ("#header_stats") {' . "\r\n" . '                    $("#header_stats").show();' . "\r\n" . '                }' . "\r\n" . '                window.rSelected = [];' . "\r\n" . '                $(".multiselect").hide();' . "\r\n" . "                \$(\"#datatable-streampage tr\").removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete these stations?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=multi&type=radio&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Stations have been started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Stations have been stopped.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Stations have been restarted.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Stations have been deleted.");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                multiAPI("clear");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-streampage").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#station_category_id").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#station_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#station_server_id").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n\t\t\t" . "\$(\"#station_search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#station_filter').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#station_server_id').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#station_category_id').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#station_show_entries').val(\"";
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-streampage').DataTable().search(\$(\"#station_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-streampage').DataTable().page.len(\$('#station_show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-streampage\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-streampage").DataTable().ajax.reload( null, false );' . "\r\n" . '            delParams(["search", "server", "filter", "category", "page", "entries"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '}' . "\r\n" . '        function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "server", "category", "filter"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        var rSearch;' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . 'var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
		echo intval($rSettings['default_entries']);
		echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-streampage").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    window.rProcessing = false;' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-streampage").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-streampage").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-streampage").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n" . '                    clearTimeout(window.rRefresh);' . "\r\n" . '                    if ($("#datatable-streampage").DataTable().rows().count() <= 50) {' . "\r\n" . '                        setTimeout(refreshInformation, 5000);' . "\r\n" . '                    }' . "\r\n" . '                    ';

		if (!hasPermissions('adv', 'edit_radio')) {
		} else {
			echo '                    // Multi Actions' . "\r\n" . '                    multiAPI("clear");' . "\r\n" . '                    $("#datatable-streampage tr").click(function() {' . "\r\n" . '                        if (window.rShiftHeld) {' . "\r\n" . "                            if (\$(this).hasClass('selectedfilter')) {" . "\r\n" . "                                \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                                window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                            } else {            ' . "\r\n" . "                                \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                                window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#multi_streams_selected").html(window.rSelected.length + " stations");' . "\r\n" . '                        if (window.rSelected.length > 0) {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").hide();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_streams").show();' . "\r\n" . '                        } else {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").show();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_streams").hide();' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "radios",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory();' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter();' . "\r\n\t\t\t\t\t\t" . 'd.server = getServer();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,4,5,6,7]},' . "\r\n\t\t\t\t\t";

		if (CoreUtilities::$rSettings['redis_handler']) {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [4,6]},' . "\r\n\t\t\t\t\t";
		} else {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [6]},' . "\r\n\t\t\t\t\t";
		}

		echo '                    ';

		if ($rSettings['show_images']) {
		} else {
			echo '                    {"visible": false, "targets": [1]}' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t" . '],' . "\r\n" . '                ';

		if (!$rMobile) {
		} else {
			echo 'scrollX: true,';
		}

		echo "\t\t\t\t" . 'order: [[ ';
		echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 0);
		echo ', "';
		echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
		echo '" ]],' . "\r\n\t\t\t\t" . 'pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n\t\t\t" . "}).on('processing.dt', function (e, settings, processing) {" . "\r\n" . '                window.rProcessing = processing;' . "\r\n" . '            });' . "\r\n" . '            function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n\t\t\t" . '$("#datatable-streampage").css("width", "100%");' . "\r\n\t\t\t" . "\$('#station_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#station_search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#station_search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'doSearch($(this).val());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#station_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#station_show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#station_show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#station_category_id').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#station_category_id").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("category", $("#station_category_id").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("category");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#station_server_id').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#station_server_id").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("server", $("#station_server_id").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("server");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#station_filter').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#station_filter").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("filter", $("#station_filter").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("filter");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "if (\$('#station_search').val()) {" . "\r\n\t\t\t\t" . "rTable.search(\$('#station_search').val()).draw();" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-streampage").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n" . '            checkClear();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>