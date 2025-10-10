<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'HMAC Keys';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">HMAC Keys</h4>
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
						HMAC Token has been authenticated and can now be used.
					</div>
				<?php endif; ?>
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center"><?php echo $_['id']; ?></th>
									<th>Description</th>
									<th class="text-center">Enabled</th>
									<th class="text-center"><?php echo $_['actions']; ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (getHMACTokens() as $rHMAC): ?>
									<tr id="hmac-<?php echo $rHMAC['id']; ?>">
										<td class="text-center"><?php echo $rHMAC['id']; ?></td>
										<td><?php echo $rHMAC['notes']; ?></td>
										<td class="text-center">
											<?php if ($rHMAC['enabled']): ?>
												<i class="text-success fas fa-square"></i>
											<?php else: ?>
												<i class="text-secondary fas fa-square"></i>
											<?php endif; ?>
										</td>
										<td class="text-center">
											<div class="btn-group">
												<a href="./hmac?id=<?php echo $rHMAC['id']; ?>">
													<button type="button" data-toggle="tooltip" data-placement="top" title="" data-original-title="Edit Key" class="btn btn-light waves-effect waves-light btn-xs">
														<i class="mdi mdi-pencil-outline"></i>
													</button>
												</a>
												<button type="button" data-toggle="tooltip" data-placement="top" title="" data-original-title="Delete Key" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rHMAC['id']; ?>, 'delete');"">
													<i class=" mdi mdi-close"></i>
												</button>
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



	function api(rID, rType, rConfirm = false) {
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this HMAC Key?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=hmac&sub=" + rType + "&hmac_id=" + rID, function(data) {
				if (data.result === true) {
					if (rType == "delete") {
						if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {
							$("#datatable").DataTable().rows(rRow).remove().draw(false);
						}
						$.toast("HMAC Key has been deleted.");
					}
				} else {
					$.toast("<?php echo $_['error_occured']; ?>");
				}
			});
		}
	}
	$(document).ready(function() {
		$("#datatable").DataTable({
			language: {
				paginate: {
					previous: "<i class='mdi mdi-chevron-left'>",
					next: "<i class='mdi mdi-chevron-right'>"
				}
			},
			order: [
				[1, "asc"]
			],
			columnDefs: [{
				"visible": false,
				"targets": [0]
			}],
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			responsive: false
		});
		$("#datatable").css("width", "100%");
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