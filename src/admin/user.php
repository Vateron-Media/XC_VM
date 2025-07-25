<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$rUser = isset(CoreUtilities::$rRequest['id']) ? getRegisteredUser(CoreUtilities::$rRequest['id']) : null;
if ($rUser === false) {
	goHome();
}

$rPackages = $rUser ? getPackages($rUser['member_group_id']) : [];
$_TITLE = 'User';
include 'header.php';
?>

<div class="wrapper boxed-layout" <?= empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ? '' : 'style="display: none;"' ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"> <?= $rUser ? 'Edit' : 'Add' ?> User</h4>
				</div>
			</div>
		</div>

		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form action="#" method="POST" data-parsley-validate="">
							<?php if ($rUser): ?>
								<input type="hidden" name="edit" value="<?= intval($rUser['id']) ?>" />
							<?php endif; ?>

							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline">Details</span>
										</a>
									</li>
									<?php if ($rUser): ?>
										<li class="nav-item">
											<a href="#override" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
												<i class="mdi mdi-pencil-plus mr-1"></i>
												<span class="d-none d-sm-inline">Overrides</span>
											</a>
										</li>
									<?php endif; ?>
								</ul>

								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="user-details">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="username">Username</label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="username" name="username" value="<?= $rUser ? htmlspecialchars($rUser['username']) : generateString(10) ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="password">
														<?= $rUser ? 'Change ' : '' ?>Password
													</label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="password" name="password"
															<?= $rUser ? 'placeholder="Enter a new password here to change it"' : '' ?>
															value="<?= $rUser ? '' : generateString(max(10, $rPermissions['minimum_password_length'])) ?>"
															data-indicator="pwindicator">
														<div id="pwindicator">
															<div class="bar"></div>
															<div class="label"></div>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="email">Email Address</label>
													<div class="col-md-8">
														<input type="email" id="email" class="form-control" name="email" value="<?= $rUser ? htmlspecialchars($rUser['email']) : '' ?>">
													</div>
												</div>
											</div>
										</div>
									</div>

									<?php if ($rUser): ?>
										<div class="tab-pane" id="override">
											<div class="row">
												<div class="col-12">
													<?php if (count($rPackages) > 0): ?>
														<p class="sub-header">Leave the override cell blank to disable package override for the selected package.</p>
														<table id="datatable" class="table table-striped table-borderless mb-0">
															<thead>
																<tr>
																	<th class="text-center">#</th>
																	<th>Package</th>
																	<th class="text-center">Credits</th>
																	<th class="text-center">Override</th>
																</tr>
															</thead>
															<tbody>
																<?php $rOverride = json_decode($rUser['override_packages'], true) ?? [];
																foreach ($rPackages as $rPackage):
																	if (!$rPackage['is_official']) continue; ?>
																	<tr>
																		<td class="text-center"> <?= intval($rPackage['id']) ?> </td>
																		<td> <?= $rPackage['package_name'] ?> </td>
																		<td class="text-center"> <?= intval($rPackage['official_credits']) ?> </td>
																		<td class="text-center">
																			<input class="form-control text-center" onkeypress="return isNumberKey(event)"
																				name="override_<?= intval($rPackage['id']) ?>" type="text"
																				value="<?= isset($rOverride[$rPackage['id']]) ? intval($rOverride[$rPackage['id']]['official_credits']) : '' ?>"
																				style="width:100px; display: inline;">
																		</td>
																	</tr>
																<?php endforeach; ?>
															</tbody>
														</table><br /><br />
													<?php else: ?>
														<div class="alert alert-info" role="alert">
															No packages have been allocated to this user group. You can modify the package or group settings.
														</div>
													<?php endif; ?>
												</div>
											</div>
										</div>
									<?php endif; ?>
								</div>
							</div>

							<div class="form-group row">
								<div class="col-md-12 text-right">
									<input name="submit_user" type="submit" class="btn btn-primary" value="<?= $rUser ? 'Edit' : 'Add' ?> User" />
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include 'footer.php'; ?>