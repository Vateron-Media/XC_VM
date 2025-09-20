<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>
<?php $_TITLE = 'On-Demand Scanner'; ?>
<?php include 'header.php'; ?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'): ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">On-Demand Scanner</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (!CoreUtilities::$rSettings['on_demand_checker']): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">×</span>
                        </button>
                        On-Demand Scanner isn't active, please enable it in the <a href="settings">Settings</a> menu to continue.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="search" value="" placeholder="Search Streams...">
                            </div>
                            <div class="col-md-3">
                                <select id="server" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Servers</option>
                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']): ?> selected<?php endif; ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="category" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Categories</option>
                                    <?php foreach (getCategories('live') as $rCategory): ?>
                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']): ?> selected<?php endif; ?>><?php echo $rCategory['category_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])): ?> selected<?php endif; ?>><?php echo $_['no_filter']; ?></option>
                                    <option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1): ?> selected<?php endif; ?>>Ready</option>
                                    <option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2): ?> selected<?php endif; ?>>Down</option>
                                    <option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3): ?> selected<?php endif; ?>>Not Scanned</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                        <option <?php if ($rSettings['default_entries'] == $rShow): ?> selected<?php endif; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Icon</th>
                                    <th>Stream</th>
                                    <th>Server</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Response</th>
                                    <th class="text-center">Stream Info</th>
                                    <th class="text-center">Last Scanned</th>
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
        echo '        ' . "\r\n\t\t" . 'var rClearing = false;' . "\r\n\t\t\r\n" . '        function openImage(elem) {' . "\r\n\t\t\t" . 'rPath = $(elem).data("src");' . "\r\n\t\t\t" . 'if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: rPath,' . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t\r\n\t\t" . 'function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#server").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n\t\t\t" . "\$(\"#search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#filter').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#server').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#category').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#show_entries').val(\"";
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(\"#search\").val());" . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$('#show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-activity\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-activity").DataTable().ajax.reload( null, false );' . "\r\n" . '            delParams(["search", "server", "filter", "category", "page", "entries"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '}' . "\r\n" . '        function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "server", "category", "filter"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        var rSearch;        ' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . 'var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
		echo intval($rSettings['default_entries']);
		echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    window.rProcessing = false;' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-activity").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-activity").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-activity").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n" . '                    clearTimeout(window.rRefresh);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "ondemand";' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory();' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter();' . "\r\n\t\t\t\t\t\t" . 'd.server = getServer();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t";

		if ($rSettings['show_images']) {
		} else {
			echo '                    {"visible": false, "targets": [1]}, ' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,4,5,6,7]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t";

		if (!$rMobile) {
		} else {
			echo 'scrollX: true,';
		}

		echo '                order: [[ ';
		echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 7);
		echo ', "';
		echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
		echo '" ]],' . "\r\n\t\t\t\t" . 'pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n\t\t\t" . "}).on('processing.dt', function (e, settings, processing) {" . "\r\n" . '                window.rProcessing = processing;' . "\r\n" . '            });' . "\r\n\t\t\t" . 'function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'doSearch($(this).val());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#category').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#category").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("category", $("#category").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("category");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#server').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#server").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("server", $("#server").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("server");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#filter').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#filter").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("filter", $("#filter").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("filter");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "if (\$('#search').val()) {" . "\r\n\t\t\t\t" . "rTable.search(\$('#search').val()).draw();" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n" . '            checkClear();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>