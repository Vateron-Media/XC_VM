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
													<label class="col-md-4 col-form-label" for="member_group_id">Member Group</label>
													<div class="col-md-8">
														<select name="member_group_id" id="member_group_id" class="form-control select2" data-toggle="select2">
															<?php foreach (getMemberGroups() as $rGroup): ?>
																<option value="<?= intval($rGroup['group_id']) ?>"
																	<?= isset($rUser) && intval($rUser['member_group_id']) === intval($rGroup['group_id']) ? 'selected' : '' ?>>
																	<?= htmlspecialchars($rGroup['group_name']) ?>
																</option>
															<?php endforeach; ?>
														</select>
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
<script id="scripts">
	var resizeObserver = new ResizeObserver(entries => $(window).scroll());
	$(document).ready(function() {
		resizeObserver.observe(document.body)
		$("form").attr('autocomplete', 'off');
		$(document).keypress(function(event) {
			if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
		});
		$.fn.dataTable.ext.errMode = 'none';
		var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
		elems.forEach(function(html) {
			var switchery = new Switchery(html, {
				'color': '#414d5f'
			});
			window.rSwitches[$(html).attr("id")] = switchery;
		});
		setTimeout(pingSession, 30000);
		<?php if (!$rMobile && $rSettings['header_stats']): ?>
			headerStats();
		<?php endif; ?>
		bindHref();
		refreshTooltips();
		$(window).scroll(function() {
			if ($(this).scrollTop() > 200) {
				if ($(document).height() > $(window).height()) {
					$('#scrollToBottom').fadeOut();
				}
				$('#scrollToTop').fadeIn();
			} else {
				$('#scrollToTop').fadeOut();
				if ($(document).height() > $(window).height()) {
					$('#scrollToBottom').fadeIn();
				} else {
					$('#scrollToBottom').hide();
				}
			}
		});
		$("#scrollToTop").unbind("click");
		$('#scrollToTop').click(function() {
			$('html, body').animate({
				scrollTop: 0
			}, 800);
			return false;
		});
		$("#scrollToBottom").unbind("click");
		$('#scrollToBottom').click(function() {
			$('html, body').animate({
				scrollTop: $(document).height()
			}, 800);
			return false;
		});
		$(window).scroll();
		$(".nextb").unbind("click");
		$(".nextb").click(function() {
			var rPos = 0;
			var rActive = null;
			$(".nav .nav-item").each(function() {
				if ($(this).find(".nav-link").hasClass("active")) {
					rActive = rPos;
				}
				if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
					$(this).find(".nav-link").trigger("click");
					return false;
				}
				rPos += 1;
			});
		});
		$(".prevb").unbind("click");
		$(".prevb").click(function() {
			var rPos = 0;
			var rActive = null;
			$($(".nav .nav-item").get().reverse()).each(function() {
				if ($(this).find(".nav-link").hasClass("active")) {
					rActive = rPos;
				}
				if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
					$(this).find(".nav-link").trigger("click");
					return false;
				}
				rPos += 1;
			});
		});
		(function($) {
			$.fn.inputFilter = function(inputFilter) {
				return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
					if (inputFilter(this.value)) {
						this.oldValue = this.value;
						this.oldSelectionStart = this.selectionStart;
						this.oldSelectionEnd = this.selectionEnd;
					} else if (this.hasOwnProperty("oldValue")) {
						this.value = this.oldValue;
						this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
					}
				});
			};
		}(jQuery));
		<?php if ($rSettings['js_navigate']): ?>
			$(".navigation-menu li").mouseenter(function() {
				$(this).find(".submenu").show();
			});
			delParam("status");
			$(window).on("popstate", function() {
				if (window.rRealURL) {
					if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
						navigate(window.location.href.split("/").reverse()[0]);
					}
				}
			});
		<?php endif; ?>
		$(document).keydown(function(e) {
			if (e.keyCode == 16) {
				window.rShiftHeld = true;
			}
		});
		$(document).keyup(function(e) {
			if (e.keyCode == 16) {
				window.rShiftHeld = false;
			}
		});
		document.onselectstart = function() {
			if (window.rShiftHeld) {
				return false;
			}
		}
	});
	
	function clearOwner() {
		$('#owner_id').val("").trigger('change');
	}
	$(document).ready(function() {
		$('select.select2').select2({
			width: '100%'
		})
		$('#owner_id').select2({
			ajax: {
				url: './api',
				dataType: 'json',
				data: function(params) {
					return {
						search: params.term,
						action: 'reguserlist',
						page: params.page
					};
				},
				processResults: function(data, params) {
					params.page = params.page || 1;
					return {
						results: data.items,
						pagination: {
							more: (params.page * 100) < data.total_count
						}
					};
				},
				cache: true,
				width: "100%"
			},
			placeholder: 'Search for an owner...'
		});
		$("#member_group_id").select2({
			placeholder: "Select a Group"
		});
		var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
		$('#password').pwstrength({
			'increase': true
		});
		$("form").submit(function(e) {
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);
		});
	});
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>