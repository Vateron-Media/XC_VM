<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
											echo ' style="display: none;"';
										} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">Watch Folder</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						The folder is now being watched. It will be scanned during the next Watch Folder run.
					</div>
				<?php endif; ?>

				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">ID</th>
									<th class="text-center">Status</th>
									<th>Type</th>
									<th>Server Name</th>
									<th>Directory</th>
									<th class="text-center">Last Run</th>
									<th class="text-center">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (WatchService::getWatchFolders() as $rFolder) :
									$rDate = ($rFolder['last_run'] > 0) ? date('Y-m-d H:i:s', $rFolder['last_run']) : 'Never';
								?>
									<tr id="folder-<?= intval($rFolder['id']); ?>">
										<td class="text-center"><?= intval($rFolder['id']); ?></td>
										<td class="text-center">
											<?php if ($rFolder['active']) : ?>
												<i class="text-success fas fa-square"></i>
											<?php else : ?>
												<i class="text-secondary fas fa-square"></i>
											<?php endif; ?>
										</td>
										<td><?= array('movie' => 'Movies', 'series' => 'Series')[$rFolder['type']]; ?></td>
										<td><?= $rServers[$rFolder['server_id']]['server_name']; ?></td>
										<td><?= $rFolder['directory']; ?></td>
										<td class="text-center"><?= $rDate; ?></td>
										<td class="text-center">
											<div class="btn-group">
												<a href="./watch_add?id=<?= intval($rFolder['id']); ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
												<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rFolder['id']); ?>, 'force');"><i class="mdi mdi-refresh"></i></button>
												<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rFolder['id']); ?>, 'delete');"><i class="mdi mdi-close"></i></button>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>