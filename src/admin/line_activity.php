<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['user_id'])) {
} else {
    $rSearchUser = getUser(CoreUtilities::$rRequest['user_id']);
}

if (!isset(CoreUtilities::$rRequest['stream_id'])) {
} else {
    $rSearchStream = getStream(CoreUtilities::$rRequest['stream_id']);
}

$_TITLE = 'Activity Logs';
include 'header.php';
?>

<div class="wrapper" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Activity Logs</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4<?php if (!$rMobile) {
                                                                                } else {
                                                                                    echo ' collapse';
                                                                                } ?>">
                            <div class="col-md-2">
                                <input type="text" class="form-control" id="act_search" value="<?php if (!isset(CoreUtilities::$rRequest['search'])) {
                                                                                                } else {
                                                                                                    echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                } ?>" placeholder="<?php echo $_['search_logs']; ?>...">
                            </div>
                            <div class="col-md-2">
                                <select id="act_server" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (isset(CoreUtilities::$rRequest['server'])) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>><?php echo $_['all_servers']; ?></option>
                                    <?php foreach (CoreUtilities::$rServers as $rServer) { ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="act_stream" class="form-control" data-toggle="select2">
                                    <?php if (!isset($rSearchStream)) {
                                    } else { ?>
                                        <option value="<?php echo intval($rSearchStream['id']); ?>" selected="selected"><?php echo $rSearchStream['stream_display_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="act_line" class="form-control" data-toggle="select2">
                                    <?php if (!isset($rSearchUser)) {
                                    } else { ?>
                                        <option value="<?php echo intval($rSearchUser['id']); ?>" selected="selected"><?php echo $rSearchUser['username']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control text-center date" id="act_range" name="range" value="<?php if (!isset(CoreUtilities::$rRequest['range'])) {
                                                                                                                            } else {
                                                                                                                                echo htmlspecialchars(CoreUtilities::$rRequest['range']);
                                                                                                                            } ?>" data-toggle="date-picker" data-single-date-picker="true" placeholder="All Dates">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="act_show_entries"><?php echo $_['show']; ?></label>
                            <div class="col-md-1">
                                <select id="act_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option<?php if ($rSettings['default_entries'] == $rShow) {
                                                    echo ' selected';
                                                } ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                        <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th>Line</th>
                                    <th>Stream</th>
                                    <th>Server</th>
                                    <th>Player</th>
                                    <th>ISP</th>
                                    <th class="text-center">IP</th>
                                    <th class="text-center">Start</th>
                                    <th class="text-center">Stop</th>
                                    <th class="text-center">Duration</th>
                                    <th class="text-center">Output</th>
                                    <th class="text-center">Restreamer</th>
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
		echo '        ' . "\r\n\t\t" . 'var rClearing = false;' . "\r\n\r\n\t\t" . 'function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getStream() {' . "\r\n\t\t\t" . 'return $("#act_stream").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getLine() {' . "\r\n\t\t\t" . 'return $("#act_line").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#act_server").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getRange() {' . "\r\n\t\t\t" . 'return $("#act_range").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n" . "            \$(\"#act_search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$(\"#act_stream\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#act_line').val(\"\").trigger('change');" . "\r\n" . "            \$('#act_server').val(\"\").trigger('change');" . "\r\n" . "            \$('#act_server').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#act_range').val(\"\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(\"#act_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$('#act_show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-activity\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '            delParams(["search", "stream_id", "server", "user_id", "range", "page", "entries"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '}' . "\r\n" . '        function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "stream_id", "server", "user_id", "range"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        var rSearch;' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . "\$('#act_range').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: false,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#act_range').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    if ($("#act_range").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("range", $("#act_range").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("range");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#act_range').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    if ($("#act_range").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("range", $("#act_range").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("range");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#act_range').on('change', function() {" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    if ($("#act_range").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("range", $("#act_range").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("range");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD'" . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'autoUpdateInput: false' . "\r\n\t\t\t" . '}).val("");' . "\r\n\t\t\t" . "\$('#range_clear_from').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_from').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('apply.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val(picker.startDate.format('YYYY-MM-DD'));" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#range_clear_to').on('cancel.daterangepicker', function(ev, picker) {" . "\r\n\t\t\t\t" . "\$(this).val('');" . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#act_line').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'userlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'All Lines'" . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#act_stream').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'streamlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'All Streams'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#btn-clear-logs").click(function() {' . "\r\n\t\t\t\t" . '$(".bs-logs-modal-center").modal("show");' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#clear_logs").click(function() {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "';
		echo $_['clear_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        $(".bs-logs-modal-center").modal("hide");' . "\r\n" . '                        $.getJSON("./api?action=clear_logs&type=lines_activity&from=" + encodeURIComponent($("#range_clear_from").val()) + "&to=" + encodeURIComponent($("#range_clear_to").val()), function(data) {' . "\r\n" . '                            $.toast("Logs have been cleared.");' . "\r\n" . '                            $("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '                        });' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '});' . "\r\n" . '            var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
		echo intval($rSettings['default_entries']);
		echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-activity").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-activity").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-activity").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "line_activity";' . "\r\n\t\t\t\t\t\t" . 'd.range = getRange();' . "\r\n\t\t\t\t\t\t" . 'd.user = getLine();' . "\r\n\t\t\t\t\t\t" . 'd.stream = getStream();' . "\r\n\t\t\t\t\t\t" . 'd.server = getServer();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [5,6,7,8,9,10]},' . "\r\n" . '                    {"className": "ellipsis", "targets": [3]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                ';

		if ($rMobile) {
			echo 'scrollX: true,';
		}

		echo "\t\t\t\t" . 'order: [[ ';
		echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 7);
		echo ', "';
		echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
		echo '" ]],' . "\r\n" . '                pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n\t\t\t" . '});' . "\r\n" . '            function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#act_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . 'doSearch($(this).val());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#act_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#act_filter').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n\t\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t";

		if (isset(CoreUtilities::$rRequest['range'])) {
			echo "\t\t\t" . '$("#act_range").val("';
			echo str_replace('"', '\\"', htmlspecialchars(CoreUtilities::$rRequest['range']));
			echo "\").trigger('change');" . "\r\n\t\t\t";
		}

		echo "\t\t\t" . "\$('#act_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#act_search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#act_search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#act_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#live_show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#act_line').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#act_line").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("user_id", $("#act_line").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("user_id");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#act_stream').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#act_stream").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("stream_id", $("#act_stream").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("stream_id");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#act_server').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#act_server").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("server", $("#act_server").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("server");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            if (\$('#act_search').val()) {" . "\r\n\t\t\t\t" . "rTable.search(\$('#act_search').val()).draw();" . "\r\n\t\t\t" . '}' . "\r\n" . '            checkClear();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
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