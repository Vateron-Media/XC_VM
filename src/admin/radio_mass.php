<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rCategories = getCategories('radio');
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

foreach ($rServers as $rServer) {
    $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'Mass Edit Stations';
include 'header.php';
?>
<div class="wrapper boxed-layout-xl" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else { ?> style="display: none;" <?php } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Mass Edit Stations <small id="selected_count"></small></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
                } else { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Mass edit of stations was successfully executed!
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
                            <input type="hidden" name="streams" id="streams" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-play mr-1"></i>
                                            <span class="d-none d-sm-inline">Stations</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline">Servers</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="Search Stations...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="station_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                                        <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Categories</option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach ($rCategories as $rCategory) { ?>
                                                        <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="station_filter" class="form-control" data-toggle="select2">
                                                    <option value=""><?php echo $_['no_filter']; ?></option>
                                                    <option value="1">Online</option>
                                                    <option value="2">Down</option>
                                                    <option value="3">Stopped</option>
                                                    <option value="4">Starting</option>
                                                    <option value="5">On Demand</option>
                                                    <option value="6">Direct</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option<?php if ($rSettings['default_entries'] != $rShow) {
                                                                } else { ?> selected<?php } ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center">ID</th>
                                                        <th class="text-center">Icon</th>
                                                        <th>Station Name</th>
                                                        <th>Category</th>
                                                        <th><?php echo $_['servers']; ?></th>
                                                        <th class="text-center">Status</th>
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
                                                    To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Category</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control select2" multiple="multiple" data-toggle="select2" id="c_category_id" name="c_category_id[]" data-placeholder="Choose Categories">
                                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                                <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="station_server_id" name="c_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Server</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control select2" multiple="multiple" data-toggle="select2" id="c_server_id" name="c_server_id[]" data-placeholder="Choose Servers">
                                                            <?php foreach (getStreamingServers() as $rServer) { ?>
                                                                <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="allow_comments" name="c_allow_comments">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Allow Comments</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_allow_comments" name="c_allow_comments">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="autoplay" name="c_autoplay">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Autoplay</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_autoplay" name="c_autoplay">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="email_on_new_comment" name="c_email_on_new_comment">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Email on New Comment</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_email_on_new_comment" name="c_email_on_new_comment">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="load_balancing" name="c_load_balancing">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Load Balancing</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_load_balancing" name="c_load_balancing">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="total_listeners" name="c_total_listeners">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Total Listeners</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_total_listeners" name="c_total_listeners">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="current_listeners" name="c_current_listeners">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Current Listeners</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_current_listeners" name="c_current_listeners">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bitrate" name="c_bitrate">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Bitrate</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_bitrate" name="c_bitrate">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="max_listeners" name="c_max_listeners">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example
                                                    </div>
                                                        <label class=" col-md-2 col-form-label">Category</label>
                                                    <div class="col-md-9">
                                                        <select id="c_category_id" class="form-control" data-toggle="select2" multiple>
                                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                                <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="server_id" name="c_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Server</label>
                                                    <div class="col-md-9">
                                                        <select id="c_server_id" class="form-control" data-toggle="select2" multiple>
                                                            <?php foreach (getStreamingServers() as $rServer) { ?>
                                                                <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_username" name="c_stream_username">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Username</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_stream_username" name="c_stream_username" value="" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_password" name="c_stream_password">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Password</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_stream_password" name="c_stream_password" value="" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_allow_transcode" name="c_stream_allow_transcode">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Transcode</label>
                                                    <div class="col-md-9">
                                                        <select id="c_stream_allow_transcode" class="form-control" data-toggle="select2">
                                                            <option value="1">Enabled</option>
                                                            <option value="0">Disabled</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane" id="load-balancing">
                                    <div class="row">
                                        <div class="col-12">
                                            <p class="sub-header">
                                                To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                            </p>
                                            <div class="form-group row mb-4">
                                                <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                    <input type="checkbox" class="activate" data-name="load_balancer_enabled" name="c_load_balancer_enabled">
                                                    <label></label>
                                                </div>
                                                <label class="col-md-2 col-form-label">Load Balancer</label>
                                                <div class="col-md-9">
                                                    <select id="c_load_balancer_enabled" class="form-control" data-toggle="select2">
                                                        <option value="1">Enabled</option>
                                                        <option value="0">Disabled</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                    <input type="checkbox" class="activate" data-name="load_balancer_server_id" name="c_load_balancer_server_id">
                                                    <label></label>
                                                </div>
                                                <label class="col-md-2 col-form-label">Load Balancer Server</label>
                                                <div class="col-md-9">
                                                    <select id="c_load_balancer_server_id" class="form-control" data-toggle="select2">
                                                        <option value="">No Server</option>
                                                        <?php foreach (getStreamingServers() as $rServer) { ?>
                                                            <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <ul class="list-inline mb-0 wizard">
                                <li class="previous list-inline-item">
                                    <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                </li>
                                <li class="next list-inline-item float-right">
                                    <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                </li>
                            </ul>
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

			<?php
		echo '        ' . "\r\n\t\t" . 'var rSelected = [];' . "\r\n\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_search").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getServer() {' . "\r\n" . '            return $("#station_server_id").val();' . "\r\n" . '        }' . "\r\n" . '        function getFilter() {' . "\r\n\t\t\t" . 'return $("#station_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleStreams() {' . "\r\n\t\t\t" . '$("#datatable-mass tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t" . '}' . "\r\n" . '        function evaluateServers() {' . "\r\n" . '            var rOVal = $("#on_demand").val();' . "\r\n" . '            $("#on_demand").empty();' . "\r\n" . "            \$(\$('#server_tree').jstree(true).get_json('source', {flat:true})).each(function(index, value) {" . "\r\n" . '                if (value.parent != "#") {' . "\r\n" . '                    $("#on_demand").append(new Option(value.text, value.id));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#on_demand").val(rOVal).trigger("change");' . "\r\n" . '            if (!$("#on_demand").val()) {' . "\r\n" . '                $("#on_demand").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'})" . "\r\n\t\t\t" . "var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));" . "\r\n\t\t\t" . 'elems.forEach(function(html) {' . "\r\n\t\t\t\t" . 'if (($(html).attr("id") != "restart_on_edit") && ($(html).attr("id") != "reprocess_tmdb")) {' . "\r\n\t\t\t\t\t" . 'window.rSwitches[$(html).attr("id")].disable();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("input[type=checkbox].activate").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).is(":checked")) {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].enable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", false);' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#on_demand").prop("disabled", false);' . "\r\n" . '                            $("#server_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].disable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", true);' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#on_demand").prop("disabled", true);' . "\r\n" . '                            $("#server_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#server_tree').on('redraw.jstree', function (e, data) {" . "\r\n" . '                evaluateServers();' . "\r\n" . "            }).on('select_node.jstree', function (e, data) {" . "\r\n" . '                $("#c_server_tree").prop("checked", true);' . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'rTable = $("#datatable-mass").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "radio_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory(),' . "\r\n" . '                        d.filter = getFilter(),' . "\r\n" . '                        d.server = getServer()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,5]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#category_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#station_server_id').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#station_filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-mass").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . "\$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n\t\t\t\t" . 'rPass = false;' . "\r\n" . '                rSubmit = true;' . "\r\n\t\t\t\t" . "\$.each(\$('#server_tree').jstree(true).get_json('#', {flat:true}), function(k,v) {" . "\r\n\t\t\t\t\t" . 'if (v.parent == "source") {' . "\r\n\t\t\t\t\t\t" . 'rPass = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#streams").val(JSON.stringify(window.rSelected));' . "\r\n\t\t\t\t" . 'if (window.rSelected.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Select at least one stream to edit.");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
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