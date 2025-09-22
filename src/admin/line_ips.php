<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$rRange = (intval(CoreUtilities::$rRequest['range']) ?: 0);
$rLineIPs = (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'lines_per_ip')) ?: array());
$_TITLE = 'Line IP Usage';
include 'header.php';
?>

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
					<h4 class="page-title">Line IP Usage</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<div class="form-group row mb-4">
							<div class="col-md-7">
								<input type="text" class="form-control" id="log_search" value="" placeholder="Search Logs...">
							</div>
							<div class="col-md-3">
								<select id="range" class="form-control" data-toggle="select2">
									<option value="0" <?= $rRange == 0 ? 'selected' : ''; ?>>All Time</option>
									<option value="604800" <?= $rRange == 604800 ? 'selected' : ''; ?>>Last 7 Days</option>
									<option value="86400" <?= $rRange == 86400 ? 'selected' : ''; ?>>Last 24 Hours</option>
									<option value="3600" <?= $rRange == 3600 ? 'selected' : ''; ?>>Last Hour</option>
								</select>
							</div>
							<div class="col-md-2">
								<select id="show_entries" class="form-control" data-toggle="select2">
									<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
										<option <?= $rSettings['default_entries'] == $rShow ? 'selected' : ''; ?> value="<?= $rShow; ?>"><?= $rShow; ?></option>
									<?php } ?>
								</select>
							</div>
						</div>
						<table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">User ID</th>
									<th>Username</th>
									<th class="text-center">IP Count</th>
									<th class="text-center">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($rLineIPs[$rRange] as $rRow) {
									$rButtons = $rRange == 0 ?
										'<a href="line_activity?user_id=' . $rRow['user_id'] . '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs">View Logs</button></a>' :
										'<a href="line_activity?user_id=' . $rRow['user_id'] . '&range=' . date($rSettings['date_format'], time() - $rRange) . ' - ' . date($rSettings['date_format'], time()) . '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs">View Logs</button></a>';

									if (hasPermissions('adv', 'edit_user')) {
										$rID = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['user_id'] . "</a>";
										$rUsername = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['username'] . "</a>";
									} else {
										$rID = $rRow['user_id'];
										$rUsername = $rRow['username'];
									}
								?>
									<tr>
										<td class="text-center"><?= $rID; ?></td>
										<td><?= $rUsername; ?></td>
										<td class="text-center"><?= $rRow['ip_count']; ?></td>
										<td class="text-center"><?= $rButtons; ?></td>
									</tr>
								<?php } ?>
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

	<?php if (CoreUtilities::$rSettings['enable_search']): ?>
		$(document).ready(function() {
			initSearch();
		});

	<?php endif; ?>
	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$('#range').on('change', function() {
			navigate("line_ips?range=" + $('#range').val());
		});
		var rTable = $("#datatable-activity").DataTable({
			language: {
				paginate: {
					previous: "<i class='mdi mdi-chevron-left'>",
					next: "<i class='mdi mdi-chevron-right'>"
				},
				infoFiltered: ""
			},
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			responsive: false,
			processing: true,
			columnDefs: [{
				"className": "dt-center",
				"targets": [0, 2, 3]
			}],
			"order": [
				[2, "desc"]
			],
			pageLength: <?php echo (intval($rSettings['default_entries']) ?: 10); ?>
		});
		$("#datatable-activity").css("width", "100%");
		$('#log_search').keyup(function() {
			rTable.search($(this).val()).draw();
		});
	});
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>