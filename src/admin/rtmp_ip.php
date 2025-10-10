<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

if (isset(CoreUtilities::$rRequest['id']) && !($rIPArr = getRTMPIP(CoreUtilities::$rRequest['id']))) {
	goHome();
}

$_TITLE = 'RTMP IP';
include 'header.php';
?>

<div class="wrapper boxed-layout"
	<?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
		echo ' style="display: none;"';
	} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?php echo isset($rIPArr['id']) ? 'Edit' : 'Add'; ?> RTMP IP</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form action="#" method="POST" data-parsley-validate="">
							<?php if (isset($rIPArr['id'])): ?>
								<input type="hidden" name="edit" value="<?php echo $rIPArr['id']; ?>" />
							<?php endif; ?>
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#ip-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline">Details</span>
										</a>
									</li>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="ip-details">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="ip">IP Address</label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="ip" name="ip"
															value="<?php echo isset($rIPArr) ? htmlspecialchars($rIPArr['ip']) : ''; ?>"
															required data-parsley-trigger="change">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="password">Password</label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="password"
															name="password" placeholder="Auto-generate if blank"
															value="<?php echo isset($rIPArr) ? htmlspecialchars($rIPArr['password']) : ''; ?>"
															data-indicator="pwindicator">
														<div id="pwindicator">
															<div class="bar"></div>
															<div class="label"></div>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="notes">Notes</label>
													<div class="col-md-8">
														<textarea class="form-control" id="notes"
															name="notes"><?php echo isset($rIPArr) ? htmlspecialchars($rIPArr['notes']) : ''; ?></textarea>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="push">Push <i
															title="Allow this IP to publish RTMP streams to your service."
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="push" id="push" type="checkbox"
															<?php if (isset($rIPArr) && $rIPArr['push'] == 1) {
																echo 'checked';
															} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="pull">Pull <i
															title="Allow this IP to request RTMP streams from your service."
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="pull" id="pull" type="checkbox"
															<?php if (isset($rIPArr) && $rIPArr['pull'] == 1) {
																echo 'checked';
															} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
											</div>
										</div>
									</div>
									<ul class="list-inline wizard mb-0">
										<li class="list-inline-item float-right">
											<input name="submit_ip" type="submit" class="btn btn-primary"
												value="<?php echo isset($rIPArr['id']) ? 'Edit' : 'Add'; ?>" />
										</li>
									</ul>
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

	$(document).ready(function() {
		$('#password').pwstrength();
		$("form").submit(function(e) {
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("form")[0]));
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