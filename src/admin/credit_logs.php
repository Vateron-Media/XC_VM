<?php include 'session.php';
include 'functions.php';
if (!checkPermissions()) {
    goHome();
}
$_TITLE = 'Credit Logs';
include 'header.php'; ?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['credit_logs']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="log_search" value="" placeholder="Search Logs...">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="reseller">Reseller &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();"><i class="mdi mdi-close"></i></button></label>
                            <div class="col-md-3">
                                <select id="reseller" class="form-control" data-toggle="select2"></select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="range">Dates</label>
                            <div class="col-md-2">
                                <input type="text" class="form-control text-center date" id="range" name="range" data-toggle="date-picker" data-single-date-picker="true" autocomplete="off" placeholder="Dates">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) {
                                                                                    echo ' selected';
                                                                                } ?>><?php echo $rShow; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['owner']; ?></th>
                                    <th><?php echo $_['target']; ?></th>
                                    <th class="text-center"><?php echo $_['amount']; ?></th>
                                    <th><?php echo $_['reason']; ?></th>
                                    <th class="text-center"><?php echo $_['date']; ?></th>
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
			
			<?php 
		echo '        ' . "\r\n\t\t" . 'function getReseller() {' . "\r\n\t\t\t" . 'return $("#reseller").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getRange() {' . "\r\n\t\t\t" . 'return $("#range").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function clearOwner() {' . "\r\n" . "            \$('#reseller').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . "            \$('#reseller').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: false,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range').on('change', function() {" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_from').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#btn-clear-logs").click(function() {' . "\r\n\t\t\t\t" . '$(".bs-logs-modal-center").modal("show");' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#clear_logs").click(function() {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "';
		echo $_['clear_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        $(".bs-logs-modal-center").modal("hide");' . "\r\n" . '                        $.getJSON("./api?action=clear_logs&type=users_credits_logs&from=" + encodeURIComponent($("#range_clear_from").val()) + "&to=" + encodeURIComponent($("#range_clear_to").val()), function(data) {' . "\r\n" . '                            $.toast("';
		echo $_['clear_success'];
		echo '");' . "\r\n" . '                            $("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '                        });' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "credits_log",' . "\r\n\t\t\t\t\t\t" . 'd.range = getRange(),' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getReseller()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3,5]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"order": [[ 0, "desc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#log_search').keyup(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$(this).val()).draw();" . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#reseller').change(function(){" . "\r\n\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>