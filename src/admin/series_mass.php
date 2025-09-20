<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rCategories = getCategories('series');
$_TITLE = 'Mass Edit Series';
include 'header.php';
?>
<div class="wrapper boxed-layout-xl"
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
                    <h4 class="page-title">Mass Edit Series <small id="selected_count"></small></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Mass edit of Series was successfully executed!
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="series" id="series" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-youtube-tv mr-1"></i>
                                            <span class="d-none d-sm-inline">Series</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-5 col-6">
                                                <input type="text" class="form-control" id="stream_search" value=""
                                                    placeholder="Search Series...">
                                            </div>
                                            <div class="col-md-4 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Categories</option>
                                                    <option value="-1">No TMDb Match</option>
                                                    <option value="-2">No Categories</option>
                                                    <?php foreach ($rCategories as $rCategory) : ?>
                                                        <option value="<?= $rCategory['id'] ?>"
                                                            <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                echo ' selected';
                                                            } ?>>
                                                            <?= $rCategory['category_name'] ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                                        <option value="<?= $rShow ?>"
                                                            <?= $rSettings['default_entries'] == $rShow ? ' selected' : '' ?>>
                                                            <?= $rShow ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <button type="button" class="btn btn-info waves-effect waves-light"
                                                    onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center">ID</th>
                                                        <th class="text-center">Image</th>
                                                        <th>Name</th>
                                                        <th>Category</th>
                                                        <th class="text-center">Seasons</th>
                                                        <th class="text-center">Episodes</th>
                                                        <th class="text-center">TMDb</th>
                                                        <th class="text-center">First Aired</th>
                                                        <th class="text-center">Last Updated</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    To mass edit any of the below options, tick the checkbox next to it
                                                    and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div
                                                        class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id"
                                                            name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="category_id">Select
                                                        Categories</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="category_id[]" id="category_id"
                                                            class="form-control select2-multiple" data-toggle="select2"
                                                            multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rCategories as $rCategory) : ?>
                                                                <option value="<?= intval($rCategory['id']) ?>">
                                                                    <?= htmlspecialchars($rCategory['category_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="category_id_type" id="category_id_type"
                                                            class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) : ?>
                                                                <option value="<?= $rType ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div
                                                        class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bouquets"
                                                            name="c_bouquets">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="bouquets">Select
                                                        Bouquets</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="bouquets[]" id="bouquets"
                                                            class="form-control select2-multiple" data-toggle="select2"
                                                            multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet) : ?>
                                                                <option value="<?= $rBouquet['id'] ?>">
                                                                    <?= $rBouquet['bouquet_name'] ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="bouquets_type" id="bouquets_type"
                                                            class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) : ?>
                                                                <option value="<?= $rType ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label"
                                                        for="reprocess_tmdb">Re-Process TMDb Data</label>
                                                    <div class="col-md-2">
                                                        <input name="reprocess_tmdb" id="reprocess_tmdb" type="checkbox"
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_series" type="submit" class="btn btn-primary"
                                                    value="Edit Series" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
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
				<?php if (!$rMobile || $rSettings['header_stats']): ?>
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
		echo '        ' . "\r\n\t\t" . 'var rSelected = [];' . "\r\n\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_search").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleStreams() {' . "\r\n\t\t\t" . '$("#datatable-mass tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'})" . "\r\n\t\t\t" . "\$('#server_tree').on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("input[type=checkbox].activate").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).is(":checked")) {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].enable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "days_to_restart") {' . "\r\n\t\t\t\t\t\t\t" . '$("#time_to_restart").prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].disable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "days_to_restart") {' . "\r\n\t\t\t\t\t\t\t" . '$("#time_to_restart").prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$(".clockpicker").clockpicker();' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . 'rTable = $("#datatable-mass").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "series_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,4,5,6,7,8]},' . "\r\n\t\t\t\t\t" . '{"orderable": false, "targets": [1,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#category_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-mass").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . '$("#series").val(JSON.stringify(window.rSelected));' . "\r\n\t\t\t\t" . 'if (window.rSelected.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Select at least one stream to edit.");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t"; 
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>