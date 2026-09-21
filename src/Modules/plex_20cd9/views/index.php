<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
	<div class="alert alert-success alert-dismissible fade show" role="alert">
		The server is now being synced. It will be scanned during the next Plex Sync run.
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
					<th class="text-center">Plex IP</th>
					<th>Server Name</th>
					<th>Library</th>
					<th class="text-center">Last Run</th>
					<th class="text-center">Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ((is_array($rPlexServers ?? null) ? $rPlexServers : []) as $rServer) :
					$rDate = ($rServer['last_run'] > 0) ? date('Y-m-d H:i:s', $rServer['last_run']) : 'Never';
					$rLibraryName = 'Unknown';
					foreach ((array) json_decode($rServer['plex_libraries'], true) as $rLibrary) {
						if (intval($rLibrary['key']) == intval($rServer['directory'])) {
							$rLibraryName = $rLibrary['title'];
							break;
						}
					}
					$rServerAdd = is_null($rServer['server_add']) ? 0 : count((array) json_decode($rServer['server_add'], true));
				?>
					<tr id="folder-<?= intval($rServer['id']); ?>">
						<td class="text-center"><?= intval($rServer['id']); ?></td>
						<td class="text-center">
							<?php if ($rServer['active']) : ?>
								<i class="icon-base ti tabler-square-rounded-filled text-success"></i>
							<?php else : ?>
								<i class="icon-base ti tabler-square-rounded-filled text-secondary"></i>
							<?php endif; ?>
						</td>
						<td class="text-center"><?= $rServer['plex_ip']; ?></td>
						<td>
							<?= $rServers[$rServer['server_id']]['server_name']; ?>
							<?php if ($rServerAdd > 0) : ?>
								<span class="badge bg-label-info">+<?= $rServerAdd; ?></span>
							<?php endif; ?>
						</td>
						<td><?= $rLibraryName; ?></td>
						<td class="text-center"><?= $rDate; ?></td>
						<td class="text-center">
							<div class="d-inline-flex gap-1">
								<a href="./plex_add?id=<?= intval($rServer['id']); ?>" class="btn btn-sm btn-icon btn-label-secondary" title="Edit"><i class="icon-base ti tabler-pencil"></i></a>
								<button type="button" class="btn btn-sm btn-icon btn-label-secondary" onClick="api(<?= intval($rServer['id']); ?>, 'force');" title="Run now"><i class="icon-base ti tabler-refresh"></i></button>
								<button type="button" class="btn btn-sm btn-icon btn-label-danger" onClick="api(<?= intval($rServer['id']); ?>, 'delete');" title="Delete"><i class="icon-base ti tabler-x"></i></button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
