<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$rCategories = getCategories('movie');

if (isset(CoreUtilities::$rRequest['submit_stream'])) {
	$rReturn = API::massEditMovies(CoreUtilities::$rRequest);
	$_STATUS = $rReturn['status'];

	if ($_STATUS == 0) {
		header('Location: ./movies_mass?status=0');
		exit();
	}
}

$rTranscodeProfiles = getTranscodeProfiles();
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

foreach ($rServers as $rServer) {
	$rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'Mass Edit Movies';
include 'header.php';
?>
<div class="wrapper boxed-layout-xl" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') echo ' style="display: none;"'; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?= $_['mass_edit_movies']; ?><small id="selected_count"></small></h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button><?= $_['mass_edit_movies_success']; ?>
					</div>
				<?php endif; ?>
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
											<span class="d-none d-sm-inline"><?= $_['movies']; ?></span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline"><?= $_['details']; ?></span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-server-network mr-1"></i>
											<span class="d-none d-sm-inline"><?= $_['servers']; ?></span>
										</a>
									</li>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="stream-selection">
										<div class="row">
											<div class="col-md-2 col-6">
												<input type="text" class="form-control" id="stream_search" value="" placeholder="Search Movies...">
											</div>
											<div class="col-md-3 col-6">
												<select id="movie_server_id" class="form-control" data-toggle="select2">
													<option value="" selected>All Servers</option>
													<option value="-1">No Servers</option>
													<?php foreach (getStreamingServers() as $rServer):
														echo '<option value="' . intval($rServer['id']) . '">' . $rServer['server_name'] . '</option>' . "\n";
													endforeach;
													?>
												</select>
											</div>
											<div class="col-md-3 col-6">
												<select id="category_search" class="form-control" data-toggle="select2">
													<option value="" selected><?= $_['all_categories']; ?></option>
													<option value="-1">No Categories</option>
													<?php foreach ($rCategories as $cat):
														echo '<option value="' . $cat['id'] . '"' .
															((isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $cat['id']) ? ' selected' : '') .
															'>' . $cat['category_name'] . '</option>' . "\n";
													endforeach; ?>
												</select>
											</div>
											<div class="col-md-2 col-6">
												<select id="filter" class="form-control" data-toggle="select2">
													<option value="" selected><?= $_['no_filter']; ?></option>
													<option value="1"><?= $_['encoded']; ?></option>
													<option value="2"><?= $_['encoding']; ?></option>
													<option value="3"><?= $_['down']; ?></option>
													<option value="4"><?= $_['ready']; ?></option>
													<option value="5"><?= $_['direct']; ?></option>
													<option value="6"><?= $_['no_tmdb_match']; ?></option>
													<option value="8">Transcoding</option>
												</select>
											</div>
											<div class="col-md-1 col-6">
												<select id="show_entries" class="form-control" data-toggle="select2">
													<?php foreach ([10, 25, 50, 250, 500, 1000] as $value):
														echo '<option' . ($rSettings['default_entries'] == $value ? ' selected' : '') . ' value="' . $value . '">' . $value . '</option>' . "\n";
													endforeach; ?>
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
														<th class="text-center"><?= $_['id']; ?></th>
														<th class="text-center">Image</th>
														<th><?= $_['name']; ?></th>
														<th><?= $_['category']; ?></th>
														<th><?= $_['servers']; ?></th>
														<th class="text-center"><?= $_['status']; ?></th>
														<th class="text-center">TMDb</th>
													</tr>
												</thead>
												<tbody></tbody>
											</table>
										</div>
									</div>
									<div class="tab-pane" id="stream-details">
										<div class="row">
											<div class="col-12">
												<p class="sub-header"><?= $_['mass_edit_info']; ?> </p>
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="category_id">Select Categories</label>
													<div class="col-md-6">
														<select disabled name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach ($rCategories as $cat) echo '<option value="' . intval($cat['id']) . '">' . htmlspecialchars($cat['category_name']) . '</option>' . "\n"; ?>
														</select>
													</div>
													<div class="col-md-2">
														<select disabled name="category_id_type" id="category_id_type" class="form-control" data-toggle="select2">
															<?php foreach (['SET', 'ADD', 'DEL'] as $value) echo '<option value="' . $value . '">' . $value . '</option>' . "\n"; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="bouquets" name="c_bouquets">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="bouquets"><?= $_['select_bouquets']; ?></label>
													<div class="col-md-6">
														<select disabled name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach (getBouquets() as $bouquet) echo '<option value="' . $bouquet['id'] . '">' . $bouquet['bouquet_name'] . '</option>' . "\n"; ?>
														</select>
													</div>
													<div class="col-md-2">
														<select disabled name="bouquets_type" id="bouquets_type" class="form-control" data-toggle="select2">
															<?php foreach (['SET', 'ADD', 'DEL'] as $action) echo '<option value="' . $action . '">' . $action . '</option>' . "\n"; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="direct_source" data-type="switch" name="c_direct_source">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="direct_source"><?= $_['direct_source']; ?></label>
													<div class="col-md-2">
														<input name="direct_source" id="direct_source" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="direct_proxy">Direct Stream</label>
													<div class="col-md-2">
														<input name="direct_proxy" id="direct_proxy" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="direct_proxy" data-type="switch" name="c_direct_proxy">
														<label></label>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="movie_symlink" data-type="switch" name="c_movie_symlink">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="movie_symlink"><?= $_['create_symlink']; ?></label>
													<div class="col-md-2">
														<input name="movie_symlink" id="movie_symlink" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="read_native"><?= $_['native_frames']; ?></label>
													<div class="col-md-2">
														<input name="read_native" id="read_native" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="read_native" data-type="switch" name="c_read_native">
														<label></label>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="target_container" name="c_target_container">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="target_container"><?= $_['target_container']; ?></label>
													<div class="col-md-2">
														<select disabled name="target_container" id="target_container" class="form-control" data-toggle="select2">
															<?php foreach (['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'] as $format) echo '<option value="' . $format . '">' . $format . '</option>' . "\n"; ?>
														</select>
													</div>
													<label class="col-md-3 col-form-label" for="remove_subtitles"><?= $_['remove_subtitles']; ?></label>
													<div class="col-md-2">
														<input name="remove_subtitles" id="remove_subtitles" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="remove_subtitles" data-type="switch" name="c_remove_subtitles">
														<label></label>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="transcode_profile_id"><?= $_['transcoding_profile']; ?></label>
													<div class="col-md-8">
														<select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">
															<option selected value="0"><?= $_['transcoding_disabled']; ?></option>
															<?php foreach ($rTranscodeProfiles as $profile) echo '<option value="' . $profile['profile_id'] . '">' . $profile['profile_name'] . '</option>' . "\n"; ?>
														</select>
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="prevb list-inline-item">
												<a href="javascript: void(0);" class="btn btn-secondary"><?= $_['prev']; ?></a>
											</li>
											<li class="nextb list-inline-item float-right">
												<a href="javascript: void(0);" class="btn btn-secondary"><?= $_['next']; ?></a>
											</li>
										</ul>
									</div>
									<div class="tab-pane" id="load-balancing">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
														<input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">
														<label></label>
													</div>
													<label class="col-md-3 col-form-label" for="server_tree"><?= $_['server_tree']; ?></label>
													<div class="col-md-8">
														<div id="server_tree"></div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="col-md-1"></div>
													<label class="col-md-3 col-form-label" for="server_type">Server Type</label>
													<div class="col-md-2">
														<select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">
															<?php foreach (['SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED'] as $value => $label) echo '<option value="' . $value . '">' . $label . '</option>' . "\n"; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="col-md-1"></div>
													<label class="col-md-3 col-form-label" for="reencode_on_edit"><?= $_['reencode_on_edit']; ?></label>
													<div class="col-md-2">
														<input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<div class="col-md-1"></div>
													<label class="col-md-3 col-form-label" for="reprocess_tmdb"><?= $_['reprocess_tmdb_data']; ?></label>
													<div class="col-md-2">
														<input name="reprocess_tmdb" id="reprocess_tmdb" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="prevb list-inline-item">
												<a href="javascript: void(0);" class="btn btn-secondary"><?= $_['prev']; ?></a>
											</li>
											<li class="nextb list-inline-item float-right">
												<input name="submit_stream" type="submit" class="btn btn-primary" value="<?= $_['edit_movies']; ?>" />
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

<?php 
echo '        ' . "\r\n\t\t" . 'var rSelected = [];' . "\r\n\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_search").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getServer() {' . "\r\n" . '            return $("#movie_server_id").val();' . "\r\n" . '        }' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleStreams() {' . "\r\n\t\t\t" . '$("#datatable-mass tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'})" . "\r\n\t\t\t" . "var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));" . "\r\n\t\t\t" . 'elems.forEach(function(html) {' . "\r\n\t\t\t\t" . 'if (($(html).attr("id") != "reencode_on_edit") && ($(html).attr("id") != "reprocess_tmdb")) {' . "\r\n\t\t\t\t\t" . 'window.rSwitches[$(html).attr("id")].disable();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#server_tree').on('select_node.jstree', function (e, data) {" . "\r\n" . '                $("#c_server_tree").prop("checked", true);' . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
echo json_encode(($rServerTree ?: array()));
echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("input[type=checkbox].activate").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).is(":checked")) {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].enable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "days_to_restart") {' . "\r\n\t\t\t\t\t\t\t" . '$("#time_to_restart").prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#server_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].disable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "days_to_restart") {' . "\r\n\t\t\t\t\t\t\t" . '$("#time_to_restart").prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#server_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$(".clockpicker").clockpicker();' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . 'rTable = $("#datatable-mass").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "movie_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory(),' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter(),' . "\r\n" . '                        d.server = getServer()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,5,6]},' . "\r\n" . '                    {"orderable": false, "targets": [1,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
echo (intval($rSettings['default_entries']) ?: 10);
echo ',' . "\r\n" . '                order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#category_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#movie_server_id').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-mass").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " ';
echo $_['selected'];
echo '")' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . "\$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n\t\t\t\t" . 'rPass = false;' . "\r\n" . '                rSubmit = true;' . "\r\n\t\t\t\t" . "\$.each(\$('#server_tree').jstree(true).get_json('#', {flat:true}), function(k,v) {" . "\r\n\t\t\t\t\t" . 'if (v.parent == "source") {' . "\r\n\t\t\t\t\t\t" . 'rPass = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#streams").val(JSON.stringify(window.rSelected));' . "\r\n\t\t\t\t" . 'if (window.rSelected.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
echo $_['select_at_least_one_movie'];
echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
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