<?php

use XcVm\Module\Watch\WatchService;

?>
<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
	<div class="alert alert-success alert-dismissible fade show" role="alert">
		The folder is now being watched. It will be scanned during the next Watch Folder run.
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
	</div>
<?php endif; ?>

<div class="card">
	<div class="card-datatable table-responsive">
		<table id="datatable" class="table" style="width:100%">
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
								<i class="icon-base ti tabler-square-rounded-filled text-success"></i>
							<?php else : ?>
								<i class="icon-base ti tabler-square-rounded-filled text-secondary"></i>
							<?php endif; ?>
						</td>
						<td><?= array('movie' => 'Movies', 'series' => 'Series')[$rFolder['type']]; ?></td>
						<td><?= $rServers[$rFolder['server_id']]['server_name']; ?></td>
						<td><?= $rFolder['directory']; ?></td>
						<td class="text-center"><?= $rDate; ?></td>
						<td class="text-center">
							<div class="d-inline-flex gap-1">
								<a href="./watch_add?id=<?= intval($rFolder['id']); ?>" class="btn btn-sm btn-icon btn-label-secondary" title="Edit"><i class="icon-base ti tabler-pencil"></i></a>
								<button type="button" class="btn btn-sm btn-icon btn-label-secondary" onClick="api(<?= intval($rFolder['id']); ?>, 'force');" title="Run now"><i class="icon-base ti tabler-refresh"></i></button>
								<button type="button" class="btn btn-sm btn-icon btn-label-danger" onClick="api(<?= intval($rFolder['id']); ?>, 'delete');" title="Delete"><i class="icon-base ti tabler-x"></i></button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
