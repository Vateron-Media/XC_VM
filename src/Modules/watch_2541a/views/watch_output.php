<div class="card">
	<div class="card-body border-bottom">
		<form id="series_form">
			<div class="row g-3 align-items-end">
				<div class="col-12 col-md-3">
					<label class="form-label" for="result_search">Search</label>
					<input type="text" class="form-control" id="result_search" value="" placeholder="Search Results...">
				</div>
				<div class="col-6 col-md-3">
					<label class="form-label" for="result_server">Server</label>
					<select id="result_server" class="form-select">
						<option value="" selected>All Servers</option>
						<?php foreach ((is_array($rServers ?? null) ? $rServers : []) as $rServer) : ?>
							<option value="<?= $rServer['id']; ?>"><?= $rServer['server_name']; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-6 col-md-2">
					<label class="form-label" for="result_type">Type</label>
					<select id="result_type" class="form-select">
						<option value="" selected>All Types</option>
						<?php foreach (array(1 => 'Movies', 2 => 'Series') as $rID => $rType) : ?>
							<option value="<?= $rID; ?>"><?= $rType; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-6 col-md-2">
					<label class="form-label" for="result_status">Status</label>
					<select id="result_status" class="form-select">
						<option value="" selected>All Statuses</option>
						<?php foreach (array(1 => 'Added', 2 => 'SQL Error', 3 => 'No Category', 4 => 'No Match', 5 => 'Invalid File') as $rID => $rType) : ?>
							<option value="<?= $rID; ?>"><?= $rType; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-6 col-md-2">
					<label class="form-label" for="result_show_entries">Show</label>
					<select id="result_show_entries" class="form-select">
						<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
							<option<?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?> value="<?= $rShow; ?>"><?= $rShow; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
		</form>
	</div>
	<div class="card-datatable table-responsive">
		<table id="datatable-md1" class="table" style="width:100%">
			<thead>
				<tr>
					<th class="text-center">ID</th>
					<th>Type</th>
					<th>Server</th>
					<th>Filename</th>
					<th class="text-center">Status</th>
					<th class="text-center">Date Added</th>
					<th class="text-center">Actions</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<!-- Clear logs by date range -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title mb-0">Clear Logs</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="row g-3">
					<div class="col-6">
						<label class="form-label" for="range_clear_from">From</label>
						<input type="text" class="form-control" id="range_clear_from" autocomplete="off">
					</div>
					<div class="col-6">
						<label class="form-label" for="range_clear_to">To</label>
						<input type="text" class="form-control" id="range_clear_to" autocomplete="off">
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-danger" id="clear_logs">Clear Logs</button>
			</div>
		</div>
	</div>
</div>
