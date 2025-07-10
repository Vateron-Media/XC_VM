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
<?php include 'footer.php'; ?>