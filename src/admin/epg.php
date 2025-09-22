<?php

include 'session.php';
include 'functions.php';

if (isset(CoreUtilities::$rRequest['id']) && !($rEPGArr = getEPG(CoreUtilities::$rRequest['id']))) {
	exit();
}

$_TITLE = 'EPG';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
										echo ' style="display: block;"';
									} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?php echo isset($rEPGArr) ? $_['edit'] . ' ' : $_['add'] . ' ';
											echo $_['epg']; ?></h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form action="#" method="POST" data-parsley-validate="">
							<?php if (isset($rEPGArr)) { ?>
								<input type="hidden" name="edit" value="<?php echo $rEPGArr['id']; ?>" />
							<?php } ?>
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#category-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
										</a>
									</li>
									<?php if (isset($rEPGArr)) { ?>
										<li class="nav-item">
											<a href="#view-channels" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
												<i class="mdi mdi-play mr-1"></i>
												<span class="d-none d-sm-inline"><?php echo $_['view_channels']; ?></span>
											</a>
										</li>
									<?php } ?>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="category-details">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="epg_name"><?php echo $_['epg_name']; ?></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="epg_name" name="epg_name" value="<?php echo isset($rEPGArr) ? htmlspecialchars($rEPGArr['epg_name']) : ''; ?>" required data-parsley-trigger="change">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="epg_file"><?php echo $_['source']; ?></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="epg_file" name="epg_file" value="<?php echo isset($rEPGArr) ? htmlspecialchars($rEPGArr['epg_file']) : ''; ?>" required data-parsley-trigger="change">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="days_keep"><?php echo $_['days_to_keep']; ?></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="days_keep" name="days_keep" value="<?php echo isset($rEPGArr) ? htmlspecialchars($rEPGArr['days_keep']) : '7'; ?>" required data-parsley-trigger="change">
													</div>
													<label class="col-md-4 col-form-label" for="offset">Minute Offset</label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="offset" name="offset" value="<?php echo isset($rEPGArr) ? intval($rEPGArr['offset']) : '0'; ?>" required data-parsley-trigger="change">
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="list-inline-item float-right">
												<input name="submit_epg" type="submit" class="btn btn-primary" value="<?php echo isset($rEPGArr) ? $_['edit'] : $_['add']; ?>" />
											</li>
										</ul>
									</div>
									<div class="tab-pane" id="view-channels">
										<div class="row">
											<div class="col-12" style="overflow-x:auto;">
												<table id="datatable" class="table dt-responsive nowrap">
													<thead>
														<tr>
															<th><?php echo $_['key']; ?></th>
															<th><?php echo $_['channel_name']; ?></th>
															<th><?php echo $_['languages']; ?></th>
														</tr>
													</thead>
													<tbody>
														<?php $rEPGData = isset($rEPGArr['data']) ? json_decode($rEPGArr['data'], true) : array(); ?>
														<?php foreach ($rEPGData as $rEPGKey => $rEPGRow) { ?>
															<tr>
																<td><?php echo $rEPGKey; ?></td>
																<td><?php echo $rEPGRow['display_name']; ?></td>
																<td><?php echo implode(', ', $rEPGRow['langs']); ?></td>
															</tr>
														<?php } ?>
													</tbody>
												</table>
											</div>
										</div>
									</div>
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

	<?php if (CoreUtilities::$rSettings['enable_search']): ?>
		$(document).ready(function() {
			initSearch();
		});

	<?php endif; ?>
	$(document).ready(function() {
		$("#datatable").DataTable({
			language: {
				paginate: {
					previous: "<i class='mdi mdi-chevron-left'>",
					next: "<i class='mdi mdi-chevron-right'>"
				}
			},
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			responsive: false,
			bAutoWidth: false,
			bInfo: false
		});
		$("#days_keep").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#offset").inputFilter(function(value) {
			return /^-?[0-9]\d*(\.\d+)?$/.test(value);
		});
		$("form").submit(function(e) {
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("form")[0]));
		});
	});
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>