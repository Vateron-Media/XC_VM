<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
    exit;
}

$rSeries = getSeries();
$rServerTree = array(
    array(
        'id' => 'source',
        'parent' => '#',
        'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>",
        'icon' => 'mdi mdi-play',
        'state' => array('opened' => true)
    ),
    array(
        'id' => 'offline',
        'parent' => '#',
        'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
        'icon' => 'mdi mdi-stop',
        'state' => array('opened' => true)
    )
);

foreach ($rServers as $rServer) {
    $rServerTree[] = array(
        'id' => $rServer['id'],
        'parent' => 'offline',
        'text' => $rServer['server_name'],
        'icon' => 'mdi mdi-server-network',
        'state' => array('opened' => true)
    );
}

$_TITLE = 'Mass Edit Episodes';
include 'header.php';
?>
<div class="wrapper boxed-layout-xl" <?php echo empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ? '' : ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['mass_edit_episodes']; ?> <small id="selected_count"></small></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?php echo $_['mass_edit_episodes_success']; ?>
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
                                            <span class="d-none d-sm-inline"><?php echo $_['episodes']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['servers']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <!-- Tab Content -->
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <!-- Stream Selection Tab -->
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="<?php echo $_['search_episodes']; ?>...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="series_id" class="form-control" data-toggle="select2">
                                                    <option value=""><?php echo $_['all_series']; ?></option>
                                                    <?php foreach ($rSeries as $rSerie) { ?>
                                                        <option value="<?php echo $rSerie['id']; ?>"><?php echo $rSerie['title']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="episode_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                                        <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['no_filter']; ?></option>
                                                    <option value="1"><?php echo $_['encoded']; ?></option>
                                                    <option value="2"><?php echo $_['encoding']; ?></option>
                                                    <option value="3"><?php echo $_['down']; ?></option>
                                                    <option value="4"><?php echo $_['ready']; ?></option>
                                                    <option value="5"><?php echo $_['direct']; ?></option>
                                                    <option value="7">Transcoding</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <!-- Dynamic Episode Listing Table Here -->
                                        <table id="datatable-mass" class="table table-borderless mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                                    <th class="text-center">Image</th>
                                                    <th><?php echo $_['name']; ?></th>
                                                    <th><?php echo $_['server']; ?></th>
                                                    <th class="text-center"><?php echo $_['status']; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>

                                    </div>

                                    <!-- Stream Details Tab -->
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    <?php echo $_['mass_edit_info']; ?>
                                                </p>
                                                <!-- Series Name -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="serie_name" name="c_serie_name">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="serie_name"><?php echo $_['series_name']; ?></label>
                                                    <div class="col-md-8">
                                                        <select disabled name="serie_name" id="serie_name" class="form-control" data-toggle="select2">
                                                            <?php foreach ($rSeries as $rSerie) { ?>
                                                                <option value="<?php echo $rSerie['id']; ?>"><?php echo $rSerie['title']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Direct Source Switch -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="direct_source" data-type="switch" name="c_direct_source">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="direct_source"><?php echo $_['direct_source']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="direct_source" id="direct_source" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <!-- Create Symlink -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="movie_symlink" data-type="switch" name="c_movie_symlink">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="movie_symlink"><?php echo $_['create_symlink']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="movie_symlink" id="movie_symlink" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>

                                                <!-- Remove Subtitles -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="remove_subtitles" data-type="switch" name="c_remove_subtitles">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="remove_subtitles"><?php echo $_['remove_subtitles']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="remove_subtitles" id="remove_subtitles" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>

                                                <!-- Native Frames -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="read_native" data-type="switch" name="c_read_native">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="read_native"><?php echo $_['native_frames']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="read_native" id="read_native" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>

                                                <!-- Target Container -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="target_container" name="c_target_container">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="target_container"><?php echo $_['target_container']; ?></label>
                                                    <div class="col-md-8">
                                                        <select disabled name="target_container" id="target_container" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer) { ?>
                                                                <option value="<?php echo $rContainer; ?>"><?php echo strtoupper($rContainer); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <!-- Transcoding Profile -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="transcode_profile_id"><?php echo $_['transcoding_profile']; ?></label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">
                                                            <option selected value="0"><?php echo $_['transcoding_disabled']; ?></option>
                                                            <?php foreach (getTranscodeProfiles() as $rProfile) { ?>
                                                                <option value="<?php echo $rProfile['profile_id']; ?>"><?php echo $rProfile['profile_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                        <!-- Next and Previous Buttons -->
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['next']; ?></a>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- Load Balancing Tab -->
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <!-- Server Tree Selection -->
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="server_tree"><?php echo $_['server_tree']; ?></label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>
                                                <!-- Server Type Selection -->
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="server_type">Server Type</label>
                                                    <div class="col-md-2">
                                                        <select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED') as $rValue => $rType) { ?>
                                                                <option value="<?php echo $rValue; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="reencode_on_edit"><?php echo $_['reencode_on_edit']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="reprocess_tmdb"><?php echo $_['reprocess_tmdb_data']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="reprocess_tmdb" id="reprocess_tmdb" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Submit Button -->
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_stream" type="submit" class="btn btn-primary" value="<?php echo $_['edit_episodes']; ?>" />
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
		echo '        ' . "\r\n" . '        var rSelected = [];' . "\r\n\r\n" . '        function getSeries() {' . "\r\n" . '            return $("#series_id").val();' . "\r\n" . '        }' . "\r\n" . '        function getServer() {' . "\r\n" . '            return $("#episode_server_id").val();' . "\r\n" . '        }' . "\r\n" . '        function getFilter() {' . "\r\n" . '            return $("#filter").val();' . "\r\n" . '        }' . "\r\n" . '        function toggleStreams() {' . "\r\n" . '            $("#datatable-mass tr").each(function() {' . "\r\n" . "                if (\$(this).hasClass('selected')) {" . "\r\n" . "                    \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                    if ($(this).find("td:eq(0)").text()) {' . "\r\n" . '                        window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                    }' . "\r\n" . '                } else {            ' . "\r\n" . "                    \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                    if ($(this).find("td:eq(0)").text()) {' . "\r\n" . '                        window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n" . '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n" . "            \$('select').select2({width: '100%'})" . "\r\n" . "            var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));" . "\r\n" . '            elems.forEach(function(html) {' . "\r\n" . '                if (($(html).attr("id") != "reencode_on_edit") && ($(html).attr("id") != "reprocess_tmdb")) {' . "\r\n" . '                    window.rSwitches[$(html).attr("id")].disable();' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . "            \$('#server_tree').on('select_node.jstree', function (e, data) {" . "\r\n" . '                $("#c_server_tree").prop("checked", true);' . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n" . "                'check_callback': function (op, node, parent, position, more) {" . "\r\n" . '                    switch (op) {' . "\r\n" . "                        case 'move_node':" . "\r\n" . '                            if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n" . '                            return true;' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . "                'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo '            }, "plugins" : [ "dnd" ]' . "\r\n" . '            });' . "\r\n" . '            $("input[type=checkbox].activate").change(function() {' . "\r\n" . '                if ($(this).is(":checked")) {' . "\r\n" . '                    if ($(this).data("type") == "switch") {' . "\r\n" . '                        window.rSwitches[$(this).data("name")].enable();' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#" + $(this).data("name")).prop("disabled", false);' . "\r\n" . '                        if ($(this).data("name") == "days_to_restart") {' . "\r\n" . '                            $("#time_to_restart").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#server_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                } else {' . "\r\n" . '                    if ($(this).data("type") == "switch") {' . "\r\n" . '                        window.rSwitches[$(this).data("name")].disable();' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#" + $(this).data("name")).prop("disabled", true);' . "\r\n" . '                        if ($(this).data("name") == "days_to_restart") {' . "\r\n" . '                            $("#time_to_restart").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#server_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $(".clockpicker").clockpicker();' . "\r\n" . '            $("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            rTable = $("#datatable-mass").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "episode_list",' . "\r\n" . '                        d.series = getSeries(),' . "\r\n" . '                        d.filter = getFilter(),' . "\r\n" . '                        d.server = getServer()' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,1,4]},' . "\r\n" . '                    {"orderable": false, "targets": [1]}' . "\r\n" . '                ],' . "\r\n" . '                "rowCallback": function(row, data) {' . "\r\n" . '                    if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n" . "                        \$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                order: [[ 0, "desc" ]]' . "\r\n" . '            });' . "\r\n" . "            \$('#stream_search').keyup(function(){" . "\r\n" . '                rTable.search($(this).val()).draw();' . "\r\n" . '            })' . "\r\n" . "            \$('#show_entries').change(function(){" . "\r\n" . '                rTable.page.len($(this).val()).draw();' . "\r\n" . '            })' . "\r\n" . "            \$('#series_id').change(function(){" . "\r\n" . '                rTable.ajax.reload(null, false);' . "\r\n" . '            })' . "\r\n" . "            \$('#episode_server_id').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#filter').change(function(){" . "\r\n" . '                rTable.ajax.reload( null, false );' . "\r\n" . '            })' . "\r\n" . '            $("#datatable-mass").selectable({' . "\r\n" . "                filter: 'tr'," . "\r\n" . '                selected: function (event, ui) {' . "\r\n" . "                    if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n" . "                        \$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                        window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                    } else {            ' . "\r\n" . "                        \$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                        window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n" . '                    }' . "\r\n" . '                    $("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n" . '                rPass = false;' . "\r\n" . '                rSubmit = true;' . "\r\n" . "                \$.each(\$('#server_tree').jstree(true).get_json('#', {flat:true}), function(k,v) {" . "\r\n" . '                    if (v.parent == "source") {' . "\r\n" . '                        rPass = true;' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '                $("#streams").val(JSON.stringify(window.rSelected));' . "\r\n" . '                if (window.rSelected.length == 0) {' . "\r\n" . '                    $.toast("';
		echo $_['select_at_least_one_episode'];
		echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n" . '                }' . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>