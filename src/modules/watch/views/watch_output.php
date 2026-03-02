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
					<h4 class="page-title">Folder Watch / Plex Sync Output</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<form id="series_form">
							<div class="form-group row mb-4">
								<div class="col-md-3">
									<input type="text" class="form-control" id="result_search" value="" placeholder="Search Results...">
								</div>
								<div class="col-md-2">
									<select id="result_server" class="form-control" data-toggle="select2">
										<option value="" selected>All Servers</option>
										<?php foreach ((is_array($rServers ?? null) ? $rServers : []) as $rServer) : ?>
											<option value="<?= $rServer['id']; ?>"><?= $rServer['server_name']; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="result_type" class="form-control" data-toggle="select2">
										<option value="" selected>All Types</option>
										<?php foreach (array(1 => 'Movies', 2 => 'Series') as $rID => $rType) : ?>
											<option value="<?= $rID; ?>"><?= $rType; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="result_status" class="form-control" data-toggle="select2">
										<option value="" selected>All Statuses</option>
										<?php foreach (array(1 => 'Added', 2 => 'SQL Error', 3 => 'No Category', 4 => 'No Match', 5 => 'Invalid File') as $rID => $rType) : ?>
											<option value="<?= $rID; ?>"><?= $rType; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<label class="col-md-1 col-form-label text-center" for="result_show_entries">Show</label>
								<div class="col-md-2">
									<select id="result_show_entries" class="form-control" data-toggle="select2">
										<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
											<option<?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?> value="<?= $rShow; ?>"><?= $rShow; ?></option>
											<?php endforeach; ?>
									</select>
								</div>
							</div>
						</form>
						<table id="datatable-md1" class="table table-striped table-borderless dt-responsive nowrap font-normal">
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
			</div>
		</div>
	</div>
</div>