<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

if (isset(CoreUtilities::$rRequest['user_id'])) {
	$rSearchUser = getUser(CoreUtilities::$rRequest['user_id']);
}

if (isset(CoreUtilities::$rRequest['stream_id'])) {
	$rSearchStream = getStream(CoreUtilities::$rRequest['stream_id']);
}

$_TITLE = 'Live Connections';
include 'header.php';
?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
							echo 'style="display: none;"';
						} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?php echo $_['live_connections']; ?></h4>
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
							<?php if (CoreUtilities::$rSettings['redis_handler']): ?>
								<div class="col-md-3">
									<select id="live_server" class="form-control" data-toggle="select2">
										<option value="" <?php if (!isset(CoreUtilities::$rRequest['server'])): ?> selected<?php endif; ?>>
											<?php echo $_['all_servers']; ?>
										</option>
										<?php foreach (CoreUtilities::$rServers as $rServer): ?>
											<?php if ($rServer['enabled']): ?>
												<option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']): ?> selected<?php endif; ?>>
													<?php echo $rServer['server_name']; ?>
												</option>
											<?php endif; ?>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-4">
									<select id="live_stream" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchStream)): ?>
											<option value="<?php echo intval($rSearchStream['id']); ?>" selected="selected">
												<?php echo $rSearchStream['stream_display_name']; ?>
											</option>
										<?php endif; ?>
									</select>
								</div>
								<div class="col-md-3">
									<select id="live_line" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchUser)): ?>
											<option value="<?php echo intval($rSearchUser['id']); ?>" selected="selected">
												<?php echo $rSearchUser['username']; ?>
											</option>
										<?php endif; ?>
									</select>
								</div>
								<label class="col-md-1 col-form-label text-center" for="live_show_entries"><?php echo $_['show']; ?></label>
								<div class="col-md-1">
									<select id="live_show_entries" class="form-control" data-toggle="select2">
										<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
											<option <?php if ($rSettings['default_entries'] == $rShow): ?> selected<?php endif; ?> value="<?php echo $rShow; ?>">
												<?php echo $rShow; ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php else: ?>
								<div class="col-md-2">
									<input type="text" class="form-control" id="live_search" value="<?php echo isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : ''; ?>" placeholder="<?php echo $_['search_logs']; ?>...">
								</div>
								<div class="col-md-2">
									<select id="live_server" class="form-control" data-toggle="select2">
										<option value="" <?php if (!isset(CoreUtilities::$rRequest['server'])) echo ' selected'; ?>><?php echo $_['all_servers']; ?></option>
										<?php foreach (CoreUtilities::$rServers as $rServer): ?>
											<?php if ($rServer['enabled']): ?>
												<option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) echo ' selected'; ?>><?php echo $rServer['server_name']; ?></option>
											<?php endif; ?>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="live_stream" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchStream)): ?>
											<option value="<?php echo intval($rSearchStream['id']); ?>" selected="selected"><?php echo $rSearchStream['stream_display_name']; ?></option>
										<?php endif; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="live_line" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchUser)): ?>
											<option value="<?php echo intval($rSearchUser['id']); ?>" selected="selected"><?php echo $rSearchUser['username']; ?></option>
										<?php endif; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="live_filter" class="form-control" data-toggle="select2">
										<option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) echo ' selected'; ?>>No Filter</option>
										<option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) echo ' selected'; ?>>User Lines</option>
										<option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) echo ' selected'; ?>>MAG Devices</option>
										<option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) echo ' selected'; ?>>Enigma2 Devices</option>
										<option value="4" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) echo ' selected'; ?>>Trial Lines</option>
										<option value="5" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) echo ' selected'; ?>>Restreamers</option>
										<option value="6" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6) echo ' selected'; ?>>Ministra Lines</option>
									</select>
								</div>
								<label class="col-md-1 col-form-label text-center" for="live_show_entries"><?php echo $_['show']; ?></label>
								<div class="col-md-1">
									<select id="live_show_entries" class="form-control" data-toggle="select2">
										<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
											<option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?>><?php echo $rShow; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>
						</div>
						<table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">ID</th>
									<th class="text-center">Quality</th>
									<th>Line</th>
									<th>Stream</th>
									<th>Server</th>
									<th>Player</th>
									<th>ISP</th>
									<th class="text-center">IP</th>
									<th class="text-center">Duration</th>
									<th class="text-center">Output</th>
									<th class="text-center">Restreamer</th>
									<th class="text-center"><?php echo $_['actions']; ?></th>
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