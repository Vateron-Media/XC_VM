<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'Stream Errors';
include 'header.php';
?>
<div class="wrapper"
	<?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
	} else {
		echo ' style="display: none;"';
	} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">Stream Logs</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<div class="form-group row mb-4">
							<div class="col-md-3">
								<input type="text" class="form-control" id="log_search" value=""
									placeholder="Search Logs...">
							</div>
							<label class="col-md-1 col-form-label text-center" for="reseller">Server</label>
							<div class="col-md-3">
								<select id="reseller" class="form-control" data-toggle="select2">
									<option value="" selected>All Servers</option>
									<?php foreach (getStreamingServers() as $rServer) { ?>
										<option value="<?= intval($rServer['id']); ?>"><?= $rServer['server_name']; ?>
										</option>
									<?php } ?>
								</select>
							</div>
							<label class="col-md-1 col-form-label text-center" for="range">Dates</label>
							<div class="col-md-2">
								<input type="text" class="form-control text-center date" id="range" name="range"
									data-toggle="date-picker" data-single-date-picker="true" autocomplete="off"
									placeholder="All Dates">
							</div>
							<label class="col-md-1 col-form-label text-center" for="show_entries">Show</label>
							<div class="col-md-1">
								<select id="show_entries" class="form-control" data-toggle="select2">
									<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
										<option<?php if ($rSettings['default_entries'] == $rShow) {
													echo ' selected';
												} ?>
											value="<?= $rShow; ?>"><?= $rShow; ?></option>
										<?php } ?>
								</select>
							</div>
						</div>
						<table id="datatable-activity"
							class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">ID</th>
									<th>Stream</th>
									<th>Server</th>
									<th>Error</th>
									<th class="text-center">Date</th>
								</tr>
							</thead>
							<tbody></tbody>
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

	function getServer() {
		return $("#server").val();
	}

	function getRange() {
		return $("#range").val();
	}

	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$('#range').daterangepicker({
			singleDatePicker: false,
			showDropdowns: true,
			locale: {
				format: 'YYYY-MM-DD'
			},
			autoUpdateInput: false
		}).val("");
		$('#range').on('apply.daterangepicker', function(ev, picker) {
			$(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
			$("#datatable-activity").DataTable().ajax.reload(null, false);
		});
		$('#range').on('cancel.daterangepicker', function(ev, picker) {
			$(this).val('');
			$("#datatable-activity").DataTable().ajax.reload(null, false);
		});
		$('#range').on('change', function() {
			$("#datatable-activity").DataTable().ajax.reload(null, false);
		});
		$('#range_clear_to').daterangepicker({
			singleDatePicker: true,
			showDropdowns: true,
			locale: {
				format: 'YYYY-MM-DD'
			},
			autoUpdateInput: false
		}).val("");
		$('#range_clear_from').daterangepicker({
			singleDatePicker: true,
			showDropdowns: true,
			locale: {
				format: 'YYYY-MM-DD'
			},
			autoUpdateInput: false
		}).val("");
		$('#range_clear_from').on('apply.daterangepicker', function(ev, picker) {
			$(this).val(picker.startDate.format('YYYY-MM-DD'));
		});
		$('#range_clear_from').on('cancel.daterangepicker', function(ev, picker) {
			$(this).val('');
		});
		$('#range_clear_to').on('apply.daterangepicker', function(ev, picker) {
			$(this).val(picker.startDate.format('YYYY-MM-DD'));
		});
		$('#range_clear_to').on('cancel.daterangepicker', function(ev, picker) {
			$(this).val('');
		});
		$("#btn-clear-logs").click(function() {
			$(".bs-logs-modal-center").modal("show");
		});
		$("#clear_logs").click(function() {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "<?php echo $_['clear_confirm']; ?>",
				confirm: function() {
					$(".bs-logs-modal-center").modal("hide");
					$.getJSON("./api?action=clear_logs&type=streams_errors&from=" + encodeURIComponent($("#range_clear_from").val()) + "&to=" + encodeURIComponent($("#range_clear_to").val()), function(data) {
						$.toast("Logs have been cleared.");
						$("#datatable-activity").DataTable().ajax.reload(null, false);
					});
				}
			}).open();
		});
		$("#datatable-activity").DataTable({
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
			serverSide: true,
			ajax: {
				url: "./table",
				"data": function(d) {
					d.id = "stream_errors",
						d.range = getRange(),
						d.server = getServer()
				}
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0, 4]
			}],
			"order": [
				[0, "desc"]
			],
			pageLength: <?php echo (intval($rSettings['default_entries']) ?: 10); ?>
		});
		$("#datatable-activity").css("width", "100%");
		$('#log_search').keyup(function() {
			$('#datatable-activity').DataTable().search($(this).val()).draw();
		})
		$('#show_entries').change(function() {
			$('#datatable-activity').DataTable().page.len($(this).val()).draw();
		})
		$('#server').change(function() {
			$("#datatable-activity").DataTable().ajax.reload(null, false);
		})
		$("#btn-export-csv").click(function() {
			$.toast("Generating CSV report...");
			window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));
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