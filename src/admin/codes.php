<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()): ?>
	<?php goHome(); ?>
<?php endif; ?>

<?php $_TITLE = 'Access Codes'; ?>
<?php include 'header.php'; ?>

<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'): ?> style="display: none;" <?php endif; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">Access Codes</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						Your webserver has been modified to accept this access code.
					</div>
				<?php else: ?>
					<div class="alert alert-info" role="alert">
						Access codes are tied directly into your webserver, any modifications will reload your webserver settings and can cause a few seconds of inaccessibility.
					</div>
				<?php endif; ?>

				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center"><?= $_['id']; ?></th>
									<th>Access Code</th>
									<th class="text-center">Type</th>
									<th class="text-center">Enabled</th>
									<th class="text-center"><?= $_['actions']; ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (getcodes() as $rCode): ?>
									<tr id="code-<?= $rCode['id']; ?>">
										<td class="text-center"><?= $rCode['id']; ?></td>
										<td><?= $rCode['code']; ?></td>
										<td class="text-center"><?= array('Admin', 'Reseller', 'Ministra', 'Admin API', 'Reseller API', 'Ministra XC_VM - Disbanded', 'Web Player')[$rCode['type']]; ?></td>
										<td class="text-center">
											<?php if ($rCode['enabled']): ?>
												<i class="text-success fas fa-square"></i>
											<?php else: ?>
												<i class="text-secondary fas fa-square"></i>
											<?php endif; ?>
										</td>
										<td class="text-center">
											<div class="btn-group">
												<a href="./code?id=<?= $rCode['id']; ?>"><button type="button" data-toggle="tooltip" data-placement="top" title="Edit Code" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
												<button type="button" data-toggle="tooltip" data-placement="top" title="Delete Code" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= $rCode['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
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

<?php include 'footer.php'; ?>