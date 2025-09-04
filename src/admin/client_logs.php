<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'Client Logs';
include 'header.php';
?>
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
					<h4 class="page-title"><?php echo $_['client_logs']; ?></h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<div id="collapse_filters" class="form-group row mb-4<?php if ($rMobile) {
																					echo ' collapse';
																				} ?>">
							<div class="col-md-3">
								<input type="text" class="form-control" id="log_search" value="" placeholder="<?php echo $_['search_logs']; ?>...">
							</div>
							<label class="col-md-1 col-form-label text-center" for="filter"><?php echo $_['reason']; ?></label>
							<div class="col-md-3">
								<select id="filter" class="form-control" data-toggle="select2">
									<option value="" selected><?php echo $_['all_reasons']; ?></option>
									<?php foreach ($rClientFilters as $rFilter => $rFilterName) { ?>
										<option value="<?php echo $rFilter; ?>"><?php echo $rFilterName; ?></option>
									<?php } ?>
								</select>
							</div>
							<label class="col-md-1 col-form-label text-center" for="range"><?php echo $_['dates']; ?></label>
							<div class="col-md-2">
								<input type="text" class="form-control text-center date" id="range" name="range" data-toggle="date-picker" data-single-date-picker="true" autocomplete="off" placeholder="<?php echo $_['all_dates']; ?>">
							</div>
							<label class="col-md-1 col-form-label text-center" for="show_entries"><?php echo $_['show']; ?></label>
							<div class="col-md-1">
								<select id="show_entries" class="form-control" data-toggle="select2">
									<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
										<option<?php if ($rSettings['default_entries'] == $rShow) {
													echo ' selected';
												} ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
										<?php } ?>
								</select>
							</div>
						</div>
						<table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center"><?php echo $_['id']; ?></th>
									<th><?php echo $_['username']; ?></th>
									<th><?php echo $_['stream']; ?></th>
									<th><?php echo $_['reason']; ?></th>
									<th><?php echo $_['user_agent']; ?></th>
									<th class="text-center"><?php echo $_['ip']; ?></th>
									<th class="text-center"><?php echo $_['date']; ?></th>
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
<?php include 'footer.php';
		echo '        ' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getRange() {' . "\r\n\t\t\t" . 'return $("#range").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n" . '        function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n" . "            \$(\"#filter\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$(\"#range\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#log_search').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#show_entries').val(\"";
		echo ($rSettings['default_entries'] ?: 10);
		echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(\"#log_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$('#show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-activity\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . "\$('#range').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: false,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range').on('change', function() {" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_from').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#btn-clear-logs").click(function() {' . "\r\n\t\t\t\t" . '$(".bs-logs-modal-center").modal("show");' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#clear_logs").click(function() {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "';
		echo $_['clear_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        $(".bs-logs-modal-center").modal("hide");' . "\r\n" . '                        $.getJSON("./api?action=clear_logs&type=lines_logs&from=" + encodeURIComponent($("#range_clear_from").val()) + "&to=" + encodeURIComponent($("#range_clear_to").val()), function(data) {' . "\r\n" . '                            $.toast("';
		echo $_['clear_success'];
		echo '");' . "\r\n" . '                            $("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '                        });' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "client_logs",' . "\r\n\t\t\t\t\t\t" . 'd.range = getRange(),' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                ';

		if ($rMobile) {
			echo 'scrollX: true,';
		}

		echo "\t\t\t\t" . '"order": [[ 0, "desc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                lengthMenu: [10, 25, 50, 250, 500, 1000]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#log_search').keyup(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#filter').change(function(){" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>