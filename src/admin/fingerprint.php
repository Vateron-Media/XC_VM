<?php include 'session.php';
include 'functions.php';
if (!checkPermissions()) {
    goHome();
}
$_TITLE = 'Fingerprint Stream';
include 'header.php'; ?>
<div class="wrapper boxed-layout-ext">
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        echo ' style="display: none;"';
    } ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['fingerprint_stream']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <div id="basicwizard">
                            <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                <li class="nav-item" id="stream-selection-tab">
                                    <a href="#stream-selection" id="stream-selection-nav" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-play mr-1"></i>
                                        <span class="d-none d-sm-inline"><?php echo $_['stream']; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item disabled" id="stream-activity-tab">
                                    <a href="#stream-activity" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-account-group mr-1"></i>
                                        <span class="d-none d-sm-inline"><?php echo $_['activity']; ?></span>
                                    </a>
                                </li>
                            </ul>
                            <div class="tab-content b-0 mb-0 pt-0">
                                <div class="tab-pane" id="stream-selection">
                                    <div class="row">
                                        <?php if (CoreUtilities::$rSettings['redis_handler']) { ?>
                                            <div class="col-md-6 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="<?php echo $_['search_streams']; ?>">
                                            </div>
                                            <div class="col-md-6 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                    <?php foreach (getCategories('live') as $rCategory) { ?>
                                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                            echo ' selected';
                                                                                                        } ?>>
                                                            <?php echo $rCategory['category_name']; ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        <?php } else { ?>
                                            <div class="col-md-5 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="<?php echo $_['search_streams']; ?>">
                                            </div>
                                            <div class="col-md-4 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                    <?php foreach (getCategories('live') as $rCategory) { ?>
                                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                            echo ' selected';
                                                                                                        } ?>><?php echo $rCategory['category_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <label class="col-md-1 col-2 col-form-label text-center" for="show_entries"><?php echo $_['show']; ?></label>
                                            <div class="col-md-2 col-8">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) {
                                                                                                    echo 'selected';
                                                                                                } ?>><?php echo $rShow; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        <?php } ?>
                                        <table id="datatable-md1" class="table table-striped table-borderless mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                                    <th><?php echo $_['stream_name']; ?></th>
                                                    <th><?php echo $_['category']; ?></th>
                                                    <th class="text-center"><?php echo $_['clients']; ?></th>
                                                    <th class="text-center"></th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane" id="stream-activity">
                                    <div class="row">
                                        <div class="alert alert-warning alert-dismissible fade show col-md-12 col-12 text-center" role="alert">
                                            <?php echo $_['warning_fingerprint']; ?>
                                        </div>
                                    </div>
                                    <div class="row" id="filter_selection">
                                        <label class="col-md-1 col-2 col-form-label text-center" for="fingerprint_type"><?php echo $_['type']; ?></label>
                                        <div class="col-md-2 col-6">
                                            <select id="fingerprint_type" class="form-control text-center" data-toggle="select2">
                                                <option value="1"><?php echo $_['activity_id']; ?></option>
                                                <option value="2"><?php echo $_['username']; ?></option>
                                                <option value="3"><?php echo $_['message']; ?></option>
                                            </select>
                                        </div>
                                        <label class="col-md-1 col-2 col-form-label text-center" for="font_size"><?php echo $_['size']; ?></label>
                                        <div class="col-md-1 col-2">
                                            <input type="text" class="form-control text-center" id="font_size" value="36" placeholder="">
                                        </div>
                                        <label class="col-md-1 col-2 col-form-label text-center" for="font_color"><?php echo $_['colour']; ?></label>
                                        <div class="col-md-2 col-2">
                                            <input type="text" id="font_color" class="form-control text-center" value="#ffffff">
                                        </div>
                                        <label class="col-md-1 col-2 col-form-label text-center" for="position"><?php echo $_['position']; ?></label>
                                        <div class="col-md-1 col-2">
                                            <input type="text" class="form-control text-center" id="position_x" value="10" placeholder="X">
                                        </div>
                                        <div class="col-md-1 col-2">
                                            <input type="text" class="form-control text-center" id="position_y" value="10" placeholder="Y">
                                        </div>
                                        <div class="col-md-1 col-2">
                                            <button type="button" class="btn btn-info waves-effect waves-light" onClick="activateFingerprint()">
                                                <i class="mdi mdi-fingerprint"></i>
                                            </button>
                                        </div>
                                        <div class="col-md-12 col-2" style="margin-top:10px;display:none;" id="custom_message_div">
                                            <input type="text" class="form-control" id="custom_message" value="" placeholder="<?php echo $_['custom_message']; ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <table id="datatable-md2" class="table table-striped table-borderless mb-0">
                                            <thead>
                                                <tr>
                                                    <th></th>
                                                    <th></th>
                                                    <th>Username</th>
                                                    <th>Stream</th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th class="text-center">IP</th>
                                                    <th class="text-center">Duration</th>
                                                    <th></th>
                                                    <th></th>
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
		echo '        ' . "\r\n\t\t" . 'var rStreamID = -1;' . "\r\n\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_search").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getStreamID() {' . "\r\n\t\t\t" . 'return window.rStreamID;' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectFingerprint(rID) {' . "\r\n\t\t\t" . '$("#stream-activity-tab").attr("disabled", false);' . "\r\n\t\t\t" . "\$('[href=\"#stream-activity\"]').tab('show');" . "\r\n\t\t\t" . 'window.rStreamID = rID;' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function activateFingerprint() {' . "\r\n\t\t\t" . 'rArray = {"id": window.rStreamID, "font_size": $("#font_size").val(), "font_color": $("#font_color").val(), "message": "", "type": $("#fingerprint_type").val(), "xy_offset": ""};' . "\r\n\t\t\t" . 'if (rArray.type == 3) {' . "\r\n\t\t\t\t" . 'rArray["message"] = $("#custom_message").val();' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . 'if (($("#position_x").val() >= 0) && ($("#position_y").val() >= 0)) {' . "\r\n\t\t\t\t" . 'rArray["xy_offset"] = $("#position_x").val() + "x" + $("#position_y").val();' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . 'if ((rArray["font_size"] > 0) && (rArray["font_color"]) && ((rArray["message"]) || (rArray["type"] != 3))  && (rArray["font_size"] > 0) && (rArray["xy_offset"])) {' . "\r\n\t\t\t\t" . '$.getJSON("./api?action=fingerprint&data=" + encodeURIComponent(JSON.stringify(rArray)), function(data) {' . "\r\n\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t" . '$.toast("';
		echo $_['fingerprint_success'];
		echo '");' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#datatable-md2").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t\t" . '$("#filter_selection").fadeOut(500, function() {' . "\r\n\t\t\t\t\t" . "\$('#datatable-md2').parents('div.dataTables_wrapper').first().fadeIn(500);" . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$.toast("';
		echo $_['fingerprint_fail'];
		echo '");' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function api(rID, rType, rAID) {' . "\r\n\t\t\t" . '$.getJSON("./api?action=line_activity&sub=" + rType + "&pid=" + rID, function(data) {' . "\r\n\t\t\t\t" . 'if (data.result === true) {' . "\r\n" . '                    if (rType == "kill") {' . "\r\n\t\t\t\t\t\t" . '$.toast("';
		echo $_['connection_has_been_killed'];
		echo '");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#datatable-md2").DataTable().ajax.reload(null, false);' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'})" . "\r\n\t\t\t" . '$("#font_color").colorpicker({format:"auto"});' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#datatable-md1").DataTable({' . "\r\n\t\t\t\t";

		if (CoreUtilities::$rSettings['redis_handler']) {
			echo "\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "stream_unique",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3,4]},' . "\r\n\t\t\t\t\t" . '{"orderable": false, "targets": [3,4]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'order: [[ 1, "asc" ]],' . "\r\n\t\t\t\t";
		} else {
			echo "\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "stream_unique",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
			echo (intval($rSettings['default_entries']) ?: 10);
			echo ',' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3,4]},' . "\r\n\t\t\t\t\t" . '{"orderable": false, "targets": [4]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'order: [[ 3, "desc" ]],' . "\r\n\t\t\t\t";
		}

		echo "\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . '$("#datatable-md1").DataTable().search($(this).val()).draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . '$("#datatable-md1").DataTable().page.len($(this).val()).draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#category_search').change(function(){" . "\r\n\t\t\t\t" . '$("#datatable-md1").DataTable().ajax.reload(null, false);' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-md2").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'rowCallback: function (row, data) {' . "\r\n\t\t\t\t\t" . '$(row).attr("id", "row-" + data[0]);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections",' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = getStreamID(),' . "\r\n\t\t\t\t\t\t" . 'd.fingerprint = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [7,8,11]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,4,5,6,9,10]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n\t\t\t\t" . 'order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#fingerprint_type").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).val() == 3) {' . "\r\n\t\t\t\t\t" . '$("#custom_message_div").show();' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$("#custom_message_div").hide();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#font_size").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#position_x").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#position_y").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . "\$('#datatable-md2').parents('div.dataTables_wrapper').first().hide();" . "\r\n\t\t\t" . '$(".nav li.disabled a").click(function() {' . "\r\n\t\t\t\t" . 'return false;' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#stream-selection-nav").click(function() {' . "\r\n\t\t\t\t" . '$("#stream-activity-tab").attr("disabled", true);' . "\r\n\t\t\t\t" . 'window.rStreamID = -1;' . "\r\n\t\t\t\t" . '$("#filter_selection").show();' . "\r\n\t\t\t\t" . "\$('#datatable-md2').parents('div.dataTables_wrapper').first().hide();" . "\r\n\t\t\t\t" . '$("#datatable-md1").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
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