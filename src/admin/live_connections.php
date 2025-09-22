<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

if (isset(CoreUtilities::$rRequest['user_id'])) {
	$rSearchUser = getUser(CoreUtilities::$rRequest['user_id']);
}

if (isset(CoreUtilities::$rRequest['stream_id'])) {
	$rSearchStream = getStream(CoreUtilities::$rRequest['stream_id']);
}

$_TITLE = 'Live Connections';
include 'header.php';
?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
							echo 'style="display: none;"';
						} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?php echo $_['live_connections']; ?></h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<div id="collapse_filters" class="form-group row mb-4 <?php if ($rMobile) {
																					echo 'collapse';
																				} ?>">
							<?php if (CoreUtilities::$rSettings['redis_handler']): ?>
								<div class="col-md-3">
									<select id="live_server" class="form-control" data-toggle="select2">
										<option value="" <?php if (!isset(CoreUtilities::$rRequest['server'])): ?> selected<?php endif; ?>>
											<?php echo $_['all_servers']; ?>
										</option>
										<?php foreach (CoreUtilities::$rServers as $rServer): ?>
											<?php if ($rServer['enabled']): ?>
												<option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']): ?> selected<?php endif; ?>>
													<?php echo $rServer['server_name']; ?>
												</option>
											<?php endif; ?>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-4">
									<select id="live_stream" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchStream)): ?>
											<option value="<?php echo intval($rSearchStream['id']); ?>" selected="selected">
												<?php echo $rSearchStream['stream_display_name']; ?>
											</option>
										<?php endif; ?>
									</select>
								</div>
								<div class="col-md-3">
									<select id="live_line" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchUser)): ?>
											<option value="<?php echo intval($rSearchUser['id']); ?>" selected="selected">
												<?php echo $rSearchUser['username']; ?>
											</option>
										<?php endif; ?>
									</select>
								</div>
								<label class="col-md-1 col-form-label text-center" for="live_show_entries"><?php echo $_['show']; ?></label>
								<div class="col-md-1">
									<select id="live_show_entries" class="form-control" data-toggle="select2">
										<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
											<option <?php if ($rSettings['default_entries'] == $rShow): ?> selected<?php endif; ?> value="<?php echo $rShow; ?>">
												<?php echo $rShow; ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php else: ?>
								<div class="col-md-2">
									<input type="text" class="form-control" id="live_search" value="<?php echo isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : ''; ?>" placeholder="<?php echo $_['search_logs']; ?>...">
								</div>
								<div class="col-md-2">
									<select id="live_server" class="form-control" data-toggle="select2">
										<option value="" <?php if (!isset(CoreUtilities::$rRequest['server'])) echo ' selected'; ?>><?php echo $_['all_servers']; ?></option>
										<?php foreach (CoreUtilities::$rServers as $rServer): ?>
											<?php if ($rServer['enabled']): ?>
												<option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) echo ' selected'; ?>><?php echo $rServer['server_name']; ?></option>
											<?php endif; ?>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="live_stream" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchStream)): ?>
											<option value="<?php echo intval($rSearchStream['id']); ?>" selected="selected"><?php echo $rSearchStream['stream_display_name']; ?></option>
										<?php endif; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="live_line" class="form-control" data-toggle="select2">
										<?php if (isset($rSearchUser)): ?>
											<option value="<?php echo intval($rSearchUser['id']); ?>" selected="selected"><?php echo $rSearchUser['username']; ?></option>
										<?php endif; ?>
									</select>
								</div>
								<div class="col-md-2">
									<select id="live_filter" class="form-control" data-toggle="select2">
										<option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) echo ' selected'; ?>>No Filter</option>
										<option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) echo ' selected'; ?>>User Lines</option>
										<option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) echo ' selected'; ?>>MAG Devices</option>
										<option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) echo ' selected'; ?>>Enigma2 Devices</option>
										<option value="4" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) echo ' selected'; ?>>Trial Lines</option>
										<option value="5" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) echo ' selected'; ?>>Restreamers</option>
										<option value="6" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6) echo ' selected'; ?>>Ministra Lines</option>
									</select>
								</div>
								<label class="col-md-1 col-form-label text-center" for="live_show_entries"><?php echo $_['show']; ?></label>
								<div class="col-md-1">
									<select id="live_show_entries" class="form-control" data-toggle="select2">
										<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
											<option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?>><?php echo $rShow; ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>
						</div>
						<table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">ID</th>
									<th class="text-center">Quality</th>
									<th>Line</th>
									<th>Stream</th>
									<th>Server</th>
									<th>Player</th>
									<th>ISP</th>
									<th class="text-center">IP</th>
									<th class="text-center">Duration</th>
									<th class="text-center">Output</th>
									<th class="text-center">Restreamer</th>
									<th class="text-center"><?php echo $_['actions']; ?></th>
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

			<?php if (CoreUtilities::$rSettings['enable_search']): ?>
				$(document).ready(function() {
					initSearch();
				});

			<?php endif; 
		echo '        ' . "\r\n\t\t" . 'var rClearing = false;' . "\r\n\r\n\t\t" . 'function api(rID, rType) {' . "\r\n\t\t\t" . '$.getJSON("./api?action=line_activity&sub=" + rType + "&pid=" + rID, function(data) {' . "\r\n\t\t\t\t" . 'if (data.result === true) {' . "\r\n\t\t\t\t\t" . 'if (rType == "kill") {' . "\r\n\t\t\t\t\t\t" . '$.toast("';
		echo $_['connection_has_been_killed'];
		echo '");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload(null, false);' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getStream() {' . "\r\n\t\t\t" . 'return $("#live_stream").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getLine() {' . "\r\n\t\t\t" . 'return $("#live_line").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getFilter() {' . "\r\n\t\t\t" . 'return $("#live_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#live_server").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n" . "            \$(\"#live_search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$(\"#live_stream\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#live_line').val(\"\").trigger('change');" . "\r\n" . "            \$('#live_server').val(\"\").trigger('change');" . "\r\n" . "            \$('#live_filter').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#live_show_entries').val(\"";
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(\"#live_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$('#live_show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-activity\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '            delParams(["search", "stream_id", "server", "user_id", "page", "entries", "filter"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '}' . "\r\n" . '        function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "stream_id", "server", "user_id", "filter"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        function getRowIDs() {' . "\r\n" . '            var rRowIDs = [];' . "\r\n" . '            var rIndexes = [];' . "\r\n" . '            $("#datatable-activity").DataTable().rows().every(function (rowIdx, tableLoop, rowLoop) {' . "\r\n" . '                if ($($("#datatable-activity").DataTable().row(rowIdx).data()[8]).text() != "CLOSED") {' . "\r\n" . '                    rRowIDs.push($("#datatable-activity").DataTable().row(rowIdx).data()[0]);' . "\r\n" . '                    rIndexes.push(rowIdx);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            return [rRowIDs, rIndexes];' . "\r\n" . '        }' . "\r\n" . '        function refreshInformation() {' . "\r\n" . '            if (!window.rProcessing) {' . "\r\n" . '                var rRowIDs = getRowIDs();' . "\r\n" . '                if (rRowIDs[0].length > 0) {' . "\r\n" . '                    $.getJSON("./table?" + $.param($("#datatable-activity").DataTable().ajax.params()) + "&refresh=" + rRowIDs[0].join(","), function(rTable) {' . "\r\n" . '                        if (!window.rProcessing) {' . "\r\n" . '                            var rActive = [];' . "\r\n" . '                            $(rTable.data).each(function(rIndex, rItem) {' . "\r\n" . '                                var rIndex = rRowIDs[0].indexOf(rItem[0]);' . "\r\n" . '                                if (rIndex >= 0) {' . "\r\n" . "                                    if (\$('#datatable-activity').DataTable().row(rRowIDs[1][rIndex]).data() != rItem) {" . "\r\n" . "                                        \$('#datatable-activity').DataTable().row(rRowIDs[1][rIndex]).data(rItem);" . "\r\n" . '                                    }' . "\r\n" . '                                }' . "\r\n" . '                                rActive.push(rIndex);' . "\r\n" . '                            });' . "\r\n" . '                            $(rRowIDs[1]).each(function(rIndex) {' . "\r\n" . '                                if (rActive.indexOf(rIndex) == -1) {' . "\r\n" . "                                    \$('#datatable-activity').DataTable().cell(rIndex, 8).data(\"<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed'>CLOSED</button>\");" . "\r\n" . '                                }' . "\r\n" . '                            });' . "\r\n" . '                            bindHref(); refreshTooltips(false);' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                }' . "\r\n" . '            }' . "\r\n" . '            clearTimeout(window.rRefresh);' . "\r\n" . '            window.rRefresh = setTimeout(refreshInformation, 5000);' . "\r\n" . '        }' . "\r\n" . '        var rSearch;' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . "            \$('#live_line').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'userlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'All Lines'" . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#live_stream').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'streamlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'All Streams'" . "\r\n\t\t\t" . '});' . "\r\n" . '            var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
		echo intval($rSettings['default_entries']);
		echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    window.rProcessing = false;' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-activity").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-activity").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-activity").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n" . '                    clearTimeout(window.rRefresh);' . "\r\n" . '                    if ($("#datatable-activity").DataTable().rows().count() <= 50) {' . "\r\n" . '                        setTimeout(refreshInformation, 5000);' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n" . '                        d.user_id = getLine();' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = getStream();' . "\r\n\t\t\t\t\t\t" . 'd.server_id = getServer();' . "\r\n" . '                        ';

		if (CoreUtilities::$rSettings['redis_handler']) {
		} else {
			echo '                        d.filter = getFilter();' . "\r\n" . '                        ';
		}

		echo "\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,7,8,9,10,11]},' . "\r\n\t\t\t\t\t";

		if (!CoreUtilities::$rSettings['redis_handler']) {
		} else {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [1,2,3,4,5,6,7,9,10,11]},' . "\r\n\t\t\t\t\t";
		}

		echo '                    {"visible": false, "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                ';

		if (!$rMobile) {
		} else {
			echo 'scrollX: true,';
		}

		echo "\t\t\t\t";

		if (CoreUtilities::$rSettings['redis_handler']) {
			echo "\t\t\t\t" . 'order: [[ 8, "';
			echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
			echo '" ]],' . "\r\n\t\t\t\t";
		} else {
			echo "\t\t\t\t" . 'order: [[ ';
			echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 8);
			echo ', "';
			echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
			echo '" ]],' . "\r\n\t\t\t\t";
		}

		echo '                pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n\t\t\t" . "}).on('processing.dt', function (e, settings, processing) {" . "\r\n" . '                window.rProcessing = processing;' . "\r\n" . '            });' . "\r\n" . '            function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#live_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#live_search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'doSearch($(this).val());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#live_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#live_show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#live_line').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_line").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("user_id", $("#live_line").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("user_id");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#live_stream').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_stream").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("stream_id", $("#live_stream").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("stream_id");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#live_filter').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_filter").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("filter", $("#live_filter").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("filter");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#live_server').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#live_server").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("server", $("#live_server").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("server");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            if (\$('#live_search').val()) {" . "\r\n\t\t\t\t" . "rTable.search(\$('#live_search').val()).draw();" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n" . '            checkClear();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>