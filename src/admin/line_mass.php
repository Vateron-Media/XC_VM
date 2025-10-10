<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Mass Edit Lines';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Mass Edit Lines <small id="selected_count"></small></h4>
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
                        Mass edit of lines was successfully executed!
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="users_selected" id="users_selected" value="" />
                            <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#user-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-group mr-1"></i>
                                            <span class="d-none d-sm-inline">Lines</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#bouquets" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-flower-tulip mr-1"></i>
                                            <span class="d-none d-sm-inline">Bouquets</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="user-selection">
                                        <div class="row">
                                            <div class="col-md-3 col-6">
                                                <input type="text" class="form-control" id="user_search" value="" placeholder="Search Lines...">
                                            </div>
                                            <div class="col-md-3">
                                                <select id="reseller_search" class="form-control" data-toggle="select2">
                                                    <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))) : ?>
                                                        <option value="<?= intval($rOwner['id']); ?>" selected="selected"><?= $rOwner['username']; ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            <label class="col-md-1 col-form-label text-center" for="reseller_search">
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();">CLEAR</button>
                                            </label>
                                            <div class="col-md-2">
                                                <select id="filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected>No Filter</option>
                                                    <option value="1">Active</option>
                                                    <option value="2">Disabled</option>
                                                    <option value="3">Banned</option>
                                                    <option value="4">Expired</option>
                                                    <option value="5">Trial</option>
                                                    <option value="6">Restreamer</option>
                                                    <option value="7">Ministra</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-8">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                                        <option value="<?= $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?>><?= $rShow; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-2">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleUsers()">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0" style="overflow-x:auto;">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center">ID</th>
                                                        <th>Username</th>
                                                        <th></th>
                                                        <th>Owner</th>
                                                        <th class="text-center">Status</th>
                                                        <th></th>
                                                        <th class="text-center">Trial</th>
                                                        <th class="text-center">Restreamer</th>
                                                        <th></th>
                                                        <th class="text-center">Connections</th>
                                                        <th class="text-center">Expiration</th>
                                                        <th></th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="user-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="member_id" name="c_member_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="member_id">Owner</label>
                                                    <div class="col-md-8">
                                                        <select disabled name="member_id" id="member_id" class="form-control select2" data-toggle="select2">
                                                            <?php foreach (getRegisteredUsers() as $rRegisteredUser) : ?>
                                                                <option value="<?= $rRegisteredUser['id']; ?>"><?= $rRegisteredUser['username']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="max_connections" name="c_max_connections">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="max_connections">Max Connections</label>
                                                    <div class="col-md-2">
                                                        <input disabled type="text" class="form-control text-center" id="max_connections" name="max_connections" value="1">
                                                    </div>
                                                    <label class="col-md-1 col-form-label" for="exp_date">Expiry</label>
                                                    <div class="col-md-3">
                                                        <input disabled type="text" disabled class="form-control text-center date" id="exp_date" name="exp_date" value="" data-toggle="date-picker" data-single-date-picker="true">
                                                    </div>
                                                    <div class="col-md-1">
                                                        <div class="custom-control custom-checkbox mt-1">
                                                            <input disabled type="checkbox" class="custom-control-input" id="no_expire" name="no_expire">
                                                            <label class="custom-control-label" for="no_expire">Never</label>
                                                        </div>
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="exp_date" name="c_exp_date">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="admin_notes" name="c_admin_notes">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="admin_notes">Admin Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea disabled id="admin_notes" name="admin_notes" class="form-control" rows="3" placeholder=""></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="reseller_notes" name="c_reseller_notes">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="reseller_notes">Reseller Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea disabled id="reseller_notes" name="reseller_notes" class="form-control" rows="3" placeholder=""></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="force_server_id" name="c_force_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="force_server_id">Forced Connection</label>
                                                    <div class="col-md-8">
                                                        <select disabled name="force_server_id" id="force_server_id" class="form-control select2" data-toggle="select2">
                                                            <option selected value="0">Disabled</option>
                                                            <?php foreach ($rServers as $rServer) : ?>
                                                                <option value="<?= $rServer['id']; ?>"><?= $rServer['server_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="is_stalker" data-type="switch" name="c_is_stalker">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="is_stalker">Ministra Portal</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="is_stalker" id="is_stalker" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="is_restreamer">Restreamer</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="is_restreamer" id="is_restreamer" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="is_restreamer" data-type="switch" name="c_is_restreamer">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="is_isplock" data-type="switch" name="c_is_isplock">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="is_isplock">Lock to ISP</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="is_isplock" id="is_isplock" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-5 col-form-label" for="reset_isp_lock">Reset Current ISP</label>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" name="reset_isp_lock">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="is_trial" data-type="switch" name="c_is_trial">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="is_trial">Trial Account</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="is_trial" id="is_trial" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="access_output">Access Output</label>
                                                    <div class="col-md-3">
                                                        <?php foreach (getOutputs() as $rOutput) : ?>
                                                            <div class="checkbox form-check-inline">
                                                                <input disabled class="output" data-size="large" type="checkbox" id="access_output_<?= $rOutput['access_output_id']; ?>" name="access_output[]" value="<?= $rOutput['access_output_id']; ?>" checked>
                                                                <label for="access_output_<?= $rOutput['access_output_id']; ?>"> <?= $rOutput['output_name']; ?> </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="access_output" data-type="output" name="c_access_output">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="forced_country" name="c_forced_country">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="forced_country">Forced Country</label>
                                                    <div class="col-md-8">
                                                        <select disabled name="forced_country" id="forced_country" class="form-control select2" data-toggle="select2">
                                                            <?php foreach ($rCountries as $rCountry) : ?>
                                                                <option value="<?= $rCountry['id']; ?>"><?= $rCountry['name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="bouquets">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <table id="datatable-bouquets" class="table table-borderless mb-0">
                                                        <thead class="bg-light">
                                                            <tr>
                                                                <th class="text-center">ID</th>
                                                                <th>Bouquet Name</th>
                                                                <th class="text-center">Streams</th>
                                                                <th class="text-center">Movies</th>
                                                                <th class="text-center">Series</th>
                                                                <th class="text-center">Stations</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (getBouquets() as $rBouquet) : ?>
                                                                <tr>
                                                                    <td class="text-center"><?= $rBouquet['id']; ?></td>
                                                                    <td><?= $rBouquet['bouquet_name']; ?></td>
                                                                    <td class="text-center"><?= count(json_decode($rBouquet['bouquet_channels'], true)); ?></td>
                                                                    <td class="text-center"><?= count(json_decode($rBouquet['bouquet_movies'], true)); ?></td>
                                                                    <td class="text-center"><?= count(json_decode($rBouquet['bouquet_series'], true)); ?></td>
                                                                    <td class="text-center"><?= count(json_decode($rBouquet['bouquet_radios'], true)); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                    <div class="custom-control col-md-12 custom-checkbox text-center" style="margin-top:20px;">
                                                        <input type="checkbox" class="custom-control-input" id="c_bouquets" data-name="bouquets" data-type="bouquet" name="c_bouquets">
                                                        <label class="custom-control-label" for="c_bouquets">Tick this box to apply the above bouquets to all selected lines. Selecting none will remove bouquets from lines.</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" onClick="toggleBouquets()" class="btn btn-info">Toggle Bouquets</a>
                                                <input name="submit_line" type="submit" class="btn btn-primary" value="Mass Edit" />
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
		echo '        ' . "\r\n\t\t" . 'var rSelected = [];' . "\r\n\t\t" . 'var rBouquets = [];' . "\r\n\r\n\t\t" . 'function getReseller() {' . "\r\n\t\t\t" . 'return $("#reseller_search").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleUsers() {' . "\r\n\t\t\t" . '$("#datatable-mass tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleBouquets() {' . "\r\n\t\t\t" . '$("#datatable-bouquets tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.splice($.inArray($(this).find("td:eq(0)").text(), window.rBouquets), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . 'if (!$("#c_bouquets").is(":checked")) {' . "\r\n\t\t\t\t\t" . "\$(\"#c_bouquets\").prop('checked', true);" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearOwner() {' . "\r\n" . "            \$('#reseller_search').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . "            \$('#reseller_search').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#exp_date').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'minDate: new Date(),' . "\r\n" . '                timePicker: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD HH:mm'" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#no_expire").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).prop("checked")) {' . "\r\n\t\t\t\t\t" . '$("#exp_date").prop("disabled", true);' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$("#exp_date").removeAttr("disabled");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("input[type=checkbox].activate").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).is(":checked")) {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].enable();' . "\r\n\t\t\t\t\t" . '} else if ($(this).data("type") == "output") {' . "\r\n\t\t\t\t\t\t" . '$(".output").each(function() {' . "\r\n\t\t\t\t\t\t\t" . '$(this).prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '} else if ($(this).data("type") == "bouquet") {' . "\r\n\t\t\t\t\t\t" . '$(".bouquet-checkbox").each(function() {' . "\r\n\t\t\t\t\t\t\t" . '$(this).prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "exp_date") {' . "\r\n\t\t\t\t\t\t\t" . '$("#no_expire").prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . 'if (!$("#no_expire").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#exp_date").prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].disable();' . "\r\n\t\t\t\t\t" . '} else if ($(this).data("type") == "output") {' . "\r\n\t\t\t\t\t\t" . '$(".output").each(function() {' . "\r\n\t\t\t\t\t\t\t" . '$(this).prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '} else if ($(this).data("type") == "bouquet") {' . "\r\n\t\t\t\t\t\t" . '$(".bouquet-checkbox").each(function() {' . "\r\n\t\t\t\t\t\t\t" . '$(this).prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "exp_date") {' . "\r\n\t\t\t\t\t\t\t" . '$("#no_expire").prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . 'if (!$("#no_expire").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#exp_date").prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$(".clockpicker").clockpicker();' . "\r\n\t\t\t" . '$("#max_connections").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . 'rTable = $("#datatable-mass").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "lines",' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter(),' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getReseller()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,4,6,7,9,10]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [2,5,8,11,12]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n\t\t\t\t\t\t" . '$(row).addClass("selected");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . 'bTable = $("#datatable-bouquets").DataTable({' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rBouquets) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#user_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#reseller_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-mass").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-bouquets").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rBouquets), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'if (!$("#c_bouquets").is(":checked")) {' . "\r\n\t\t\t\t\t\t" . "\$(\"#c_bouquets\").prop('checked', true);" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . 'var rBouquets = [];' . "\r\n\t\t\t\t" . '$("#datatable-bouquets tr.selected").each(function() {' . "\r\n\t\t\t\t\t" . 'rBouquets.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#bouquets_selected").val(JSON.stringify(rBouquets));' . "\r\n\t\t\t\t" . '$("#users_selected").val(JSON.stringify(window.rSelected));' . "\r\n\t\t\t\t" . 'if (window.rSelected.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Select at least one user to edit.");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
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