<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Watch Folder Logs';
include 'header.php';
echo '<div class="wrapper"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Folder Watch / Plex Sync Output</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n\t\t\t\t\t\t" . '<form id="series_form">' . "\n\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="result_search" value="" placeholder="Search Results...">' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t" . '<select id="result_server" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>All Servers</option>' . "\n\t\t\t\t\t\t\t\t\t\t";

foreach ($rServers as $rServer) {
	echo "\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rServer['id'];
	echo '">';
	echo $rServer['server_name'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t" . '<select id="result_type" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>All Types</option>' . "\n\t\t\t\t\t\t\t\t\t\t";

foreach (array(1 => 'Movies', 2 => 'Series') as $rID => $rType) {
	echo "\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rID;
	echo '">';
	echo $rType;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t" . '<select id="result_status" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>All Statuses</option>' . "\n\t\t\t\t\t\t\t\t\t\t";

foreach (array(1 => 'Added', 2 => 'SQL Error', 3 => 'No Category', 4 => 'No Match', 5 => 'Invalid File') as $rID => $rType) {
	echo "\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rID;
	echo '">';
	echo $rType;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<label class="col-md-1 col-form-label text-center" for="result_show_entries">Show</label>' . "\n\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t" . '<select id="result_show_entries" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t";

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo "\t\t\t\t\t\t\t\t\t\t" . '<option';

	if ($rSettings['default_entries'] != $rShow) {
	} else {
		echo ' selected';
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t\t" . '<table id="datatable-md1" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Type</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Server</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Filename</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Status</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Date Added</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'var rClearing = false;' . "\r\n\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this record?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=watch_output&sub=" + rType + "&result_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            $.toast("Record successfully deleted.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable-md1").DataTable().ajax.reload( null, false );' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#result_server").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getType() {' . "\r\n\t\t\t" . 'return $("#result_type").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getStatus() {' . "\r\n\t\t\t" . 'return $("#result_status").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n\t\t\t" . "\$(\"#result_search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#result_server').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#result_type').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#result_status').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#result_show_entries').val(\"";
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-md1').DataTable().search(\$(\"#result_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-md1').DataTable().page.len(\$('#result_show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-md1\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-md1").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . '$("#datatable-md1").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "watch_output";' . "\r\n\t\t\t\t\t\t" . 'd.server = getServer();' . "\r\n\t\t\t\t\t\t" . 'd.type = getType();' . "\r\n\t\t\t\t\t\t" . 'd.status = getStatus();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,4,5,6]},' . "\r\n\t\t\t\t\t" . '{"orderable": false, "targets": [6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'order: [[ 5, "desc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-md1").css("width", "100%");' . "\r\n\t\t\t" . "\$('#result_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . "\$('#datatable-md1').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#result_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . "\$('#datatable-md1').DataTable().page.len(\$(this).val()).draw();" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#result_server').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . '$("#datatable-md1").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#result_type').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . '$("#datatable-md1").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#result_status').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . '$("#datatable-md1").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#datatable-md1').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t" . "\$('#range_clear_to').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_from').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#btn-clear-logs").click(function() {' . "\r\n\t\t\t\t" . '$(".bs-logs-modal-center").modal("show");' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#clear_logs").click(function() {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "';
		echo $_['clear_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        $(".bs-logs-modal-center").modal("hide");' . "\r\n" . '                        $.getJSON("./api?action=clear_logs&type=watch_logs&from=" + encodeURIComponent($("#range_clear_from").val()) + "&to=" + encodeURIComponent($("#range_clear_to").val()), function(data) {' . "\r\n" . '                            $.toast("Logs have been cleared.");' . "\r\n" . '                            $("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '                        });' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-md1").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
