<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id'])) {
    $rDevice = getMag(CoreUtilities::$rRequest['id']);
    if (!$rDevice['user_id']) {
        exit();
    }
}

if (!isset($rDevice) || isset($rDevice['user'])) {
    $rDevice['user'] = array('bouquet' => array());
}

$_TITLE = 'MAG Device';
include 'header.php'; ?>

<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') echo '';
                                    else echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">
                        <?php echo isset($rDevice) ? 'Edit' : 'Add'; ?> MAG Device
                    </h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (!isset($rDevice['mag_id']) || isset($_STATUS)) {
                            } else { ?>
                                <input type="hidden" name="edit" value="<?php echo intval($rDevice['mag_id']); ?>" />
                            <?php } ?>
                            <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <?php if (isset($rDevice['mag_id'])) { ?>
                                        <li class="nav-item">
                                            <a href="#device-info" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi mdi-cellphone-key mr-1"></i>
                                                <span class="d-none d-sm-inline">Device Info</span>
                                            </a>
                                        </li>
                                    <?php } ?>
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Advanced</span>
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
                                    <div class="tab-pane" id="user-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="mac">MAC Address</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="mac" name="mac" value="<?php echo isset($rDevice) ? htmlspecialchars($rDevice['mac']) : '00:1A:79:'; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="pair_id"><?php echo $_['paired_user']; ?></label>
                                                    <div class="col-md-6">
                                                        <select id="pair_id" name="pair_id" class="form-control" data-toggle="select2">
                                                            <?php if (isset($rDevice) && 0 < $rDevice['user']['pair_id']) { ?>
                                                                <option value="<?php echo $rDevice['user']['pair_id']; ?>" selected="selected"><?php echo $rDevice['paired']['username']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <a href="javascript: void(0);" onClick="unpairUser();" class="btn btn-warning" style="width: 100%">Unpair</a>
                                                    </div>
                                                </div>
                                                <div id="linked_info">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="member_id">Owner</label>
                                                        <div class="col-md-6">
                                                            <select name="member_id" id="member_id" class="form-control select2" data-toggle="select2">
                                                                <?php if (isset($rDevice['user']['member_id']) && ($rOwner = getRegisteredUser(intval($rDevice['user']['member_id'])))) { ?>
                                                                    <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                                                <?php } else { ?>
                                                                    <option value="<?php echo $rUserInfo['id']; ?>"><?php echo $rUserInfo['username']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <a href="javascript: void(0);" onClick="clearOwner();" class="btn btn-warning" style="width: 100%">Clear</a>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="is_trial">Trial Device</label>
                                                        <div class="col-md-3">
                                                            <input name="is_trial" id="is_trial" type="checkbox" <?php if (isset($rDevice) && $rDevice['user']['is_trial'] == 1) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="is_isplock">Lock to ISP</label>
                                                        <div class="col-md-2">
                                                            <input name="is_isplock" id="is_isplock" type="checkbox" <?php if (isset($rDevice) && $rDevice['user']['is_isplock'] == 1) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="exp_date">Expiry</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="<?php if (isset($rDevice)) {
                                                                                                                                                                if (!empty($rDevice['user']['exp_date'])) {
                                                                                                                                                                    echo date('Y-m-d H:i:s', $rDevice['user']['exp_date']);
                                                                                                                                                                } else {
                                                                                                                                                                    echo '" disabled="disabled';
                                                                                                                                                                }
                                                                                                                                                            } else {
                                                                                                                                                                echo date('Y-m-d H:i:s', time() + 2592000);
                                                                                                                                                            } ?>" data-toggle="date-picker" data-single-date-picker="true">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="exp_date">Never Expire</label>
                                                        <div class="col-md-2">
                                                            <input name="no_expire" id="no_expire" type="checkbox" <?php if (isset($rDevice) && is_null($rDevice['user']['exp_date'])) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="parent_password">Adult Pin</label>
                                                    <div class="col-md-3">
                                                        <input type="text" class="form-control text-center" id="parent_password" name="parent_password" value="<?php echo isset($rDevice) ? htmlspecialchars($rDevice['parent_password']) : '0000'; ?>">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="lock_device">Device Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="lock_device" id="lock_device" type="checkbox" <?php if (isset($rDevice) && $rDevice['lock_device'] == 1) echo 'checked ';
                                                                                                                    else echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="admin_notes">Admin Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="admin_notes" name="admin_notes" class="form-control" rows="3" placeholder=""><?php if (isset($rDevice)) echo htmlspecialchars($rDevice['user']['admin_notes']); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="reseller_notes">Reseller Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3" placeholder=""><?php if (isset($rDevice)) echo htmlspecialchars($rDevice['user']['reseller_notes']); ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <?php if (isset($rDevice['mag_id'])) { ?>
                                        <div class="tab-pane" id="device-info">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="username">Line Username</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control sticky" id="username" name="username" value="<?php echo $rDevice['user']['username']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="password">Line Password</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control sticky" id="password" name="password" value="<?php echo $rDevice['user']['password']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="sn">Serial Number</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="sn" name="sn" value="<?php echo $rDevice['sn']; ?>">
                                                        </div>
                                                        <label class="col-md-2 col-form-label" for="stb_type">STB Type</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="stb_type" name="stb_type" value="<?php echo $rDevice['stb_type']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="image_version">Image Version</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="image_version" name="image_version" value="<?php echo $rDevice['image_version']; ?>">
                                                        </div>
                                                        <label class="col-md-2 col-form-label" for="hw_version">HW Version</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="hw_version" name="hw_version" value="<?php echo $rDevice['hw_version']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="device_id">Primary Device ID</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="device_id" name="device_id" value="<?php echo $rDevice['device_id']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="device_id2">Secondary Device ID</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="device_id2" name="device_id2" value="<?php echo $rDevice['device_id2']; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="ver">Version</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="ver" name="ver" value="<?php echo $rDevice['ver']; ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="prevb list-inline-item">
                                                    <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                                </li>
                                                <li class="list-inline-item">
                                                    <a href="javascript: void(0);" onClick="clearDevice();" class="btn btn-warning">Clear Device Info</a>
                                                </li>
                                                <li class="nextb list-inline-item float-right">
                                                    <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                                </li>
                                            </ul>
                                        </div>
                                    <?php } ?>
                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="alert alert-warning" role="alert" id="advanced_warning" style="display: none;">
                                                    This device is linked to a user, the options for that user will be used.
                                                </div>
                                                <div id="advanced_info">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="force_server_id">Forced Connection <i title="Force this user to connect to a specific server. Otherwise, the server with the lowest load will be selected." class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-8">
                                                            <select name="force_server_id" id="force_server_id" class="form-control select2" data-toggle="select2">
                                                                <option <?php if (!isset($rDevice) || intval($rDevice['user']['force_server_id']) == 0) echo 'selected '; ?>value="0">Disabled</option>
                                                                <?php foreach ($rServers as $rServer) { ?>
                                                                    <option <?php if (isset($rDevice) && intval($rDevice['user']['force_server_id']) == intval($rServer['id'])) echo 'selected '; ?>value="<?php echo $rServer['id']; ?>"><?php echo htmlspecialchars($rServer['server_name']); ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="forced_country">Forced Country <i title="Force user to connect to loadbalancer associated with the selected country." class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-8">
                                                            <select name="forced_country" id="forced_country" class="form-control select2" data-toggle="select2">
                                                                <?php foreach ($rCountries as $rCountry) { ?>
                                                                    <option <?php if (isset($rDevice) && $rDevice['user']['forced_country'] == $rCountry['id']) echo 'selected '; ?>value="<?php echo $rCountry['id']; ?>"><?php echo $rCountry['name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="isp_clear">Current ISP</label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="<?php if (isset($rDevice['user'])) echo htmlspecialchars($rDevice['user']['isp_desc']); ?>">
                                                            <div class="input-group-append">
                                                                <a href="javascript:void(0)" onclick="clearISP()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="ip_field">Allowed IP Addresses</label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" id="ip_field" class="form-control" value="">
                                                            <div class="input-group-append">
                                                                <a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
                                                                <a href="javascript:void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="allowed_ips">&nbsp;</label>
                                                        <div class="col-md-8">
                                                            <select id="allowed_ips" name="allowed_ips[]" size=6 class="form-control" multiple="multiple">
                                                                <?php if (isset($rDevice)) {
                                                                    foreach (json_decode($rDevice['user']['allowed_ips'], true) as $rIP) { ?>
                                                                        <option value="<?php echo $rIP; ?>"><?php echo $rIP; ?></option>
                                                                <?php }
                                                                } ?>
                                                            </select>
                                                        </div>
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
                                                <div class="alert alert-warning" role="alert" id="bouquet_warning" style="display: none;">
                                                    This device is linked to a user, the bouquets for that user will be used.
                                                </div>
                                                <div class="form-group row mb-4" id="bouquets_info">
                                                    <table id="datatable-bouquets" class="table table-borderless mb-0">
                                                        <thead class="bg-light">
                                                            <tr>
                                                                <th class="text-center">ID</th>
                                                                <th>Bouquet Name</th>
                                                                <th class="text-center"><?php echo $_['streams']; ?></th>
                                                                <th class="text-center"><?php echo $_['movies']; ?></th>
                                                                <th class="text-center"><?php echo $_['series']; ?></th>
                                                                <th class="text-center"><?php echo $_['stations']; ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach (getBouquets() as $rBouquet) { ?>
                                                                <tr<?php if (isset($rDevice) && in_array($rBouquet['id'], json_decode($rDevice['user']['bouquet'], true))) echo " class='selected selectedfilter ui-selected'"; ?>>
                                                                    <td class="text-center"><?php echo $rBouquet['id']; ?></td>
                                                                    <td><?php echo $rBouquet['bouquet_name']; ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_channels'], true)); ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_movies'], true)); ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_series'], true)); ?></td>
                                                                    <td class="text-center"><?php echo count(json_decode($rBouquet['bouquet_radios'], true)); ?></td>
                                                                    </tr>
                                                                <?php } ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <a href="javascript: void(0);" onClick="toggleBouquets()" class="btn btn-info" id="toggle_bouquets">Toggle All</a>
                                                <input name="submit_device" type="submit" class="btn btn-primary" value="<?php echo isset($rDevice) ? 'Edit' : 'Add'; ?>" />
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
		echo '        ' . "\r\n\t\t";

		if (isset($rDevice)) {
			echo "\t\t" . 'var rBouquets = ';
			echo $rDevice['user']['bouquet'];
			echo ';' . "\r\n\t\t";
		} else {
			echo "\t\t" . 'var rBouquets = [];' . "\r\n\t\t";
		}

		echo "\r\n\t\t" . 'function toggleBouquets() {' . "\r\n" . '            if (!$("#pair_id").val()) {' . "\r\n" . '                $("#datatable-bouquets tr").each(function() {' . "\r\n" . "                    if (\$(this).hasClass('selected')) {" . "\r\n" . "                        \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                        if ($(this).find("td:eq(0)").text()) {' . "\r\n" . '                            window.rBouquets.splice(parseInt($.inArray($(this).find("td:eq(0)").text()), window.rBouquets), 1);' . "\r\n" . '                        }' . "\r\n" . '                    } else {            ' . "\r\n" . "                        \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                        if ($(this).find("td:eq(0)").text()) {' . "\r\n" . '                            window.rBouquets.push(parseInt($(this).find("td:eq(0)").text()));' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearDevice() {' . "\r\n\t\t\t" . '$("#device-info input").each(function() {' . "\r\n\t\t\t\t" . 'if (!$(this).hasClass("sticky")) {' . "\r\n\t\t\t\t\t" . '$(this).val("");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearISP() {' . "\r\n" . '            $("#isp_clear").val("");' . "\r\n" . '        }' . "\r\n\t\t" . 'function unpairUser() {' . "\r\n\t\t\t" . '$("#pair_id").val("").trigger("change");' . "\r\n\t\t" . '}' . "\r\n" . '        function evaluatePair() {' . "\r\n" . '            if ($("#pair_id").val()) {' . "\r\n" . '                $("#toggle_bouquets").addClass("disabled");' . "\r\n" . '                $("#advanced_warning").show();' . "\r\n" . '                $("#bouquet_warning").show();' . "\r\n" . '                $("#linked_info").hide();' . "\r\n" . '                $("#bouquets_info").hide();' . "\r\n" . '                $("#advanced_info").hide();' . "\r\n" . '            } else {' . "\r\n" . '                $("#toggle_bouquets").removeClass("disabled");' . "\r\n" . '                $("#advanced_warning").hide();' . "\r\n" . '                $("#bouquet_warning").hide();' . "\r\n" . '                $("#linked_info").show();' . "\r\n" . '                $("#bouquets_info").show();' . "\r\n" . '                $("#advanced_info").show();' . "\r\n" . '            }' . "\r\n" . '            $(["exp_date", "is_trial", "no_expire", "force_server_id", "forced_country", "ip_field", "allowed_ips"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if ($("#pair_id").val()) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n\t\t" . 'function clearOwner() {' . "\r\n" . "            \$('#member_id').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select.select2').select2({width: '100%'});" . "\r\n" . "            \$('#member_id').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#exp_date').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'minDate: new Date(),' . "\r\n" . '                timePicker: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD HH:mm'" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#pair_id').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'userlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: '";
		echo $_['search_user'];
		echo "'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t\r\n\t\t\t" . '$("#datatable-bouquets").DataTable({' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rBouquets) !== -1) {' . "\r\n\t\t\t\t\t\t" . '$(row).addClass("selected");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-bouquets").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n" . '                    if (!$("#pair_id").val()) {' . "\r\n" . "                        if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n" . "                            \$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                            window.rBouquets.splice(parseInt($.inArray($(ui.selected).find("td:eq(0)").text()), window.rBouquets), 1);' . "\r\n" . '                        } else {            ' . "\r\n" . "                            \$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                            window.rBouquets.push(parseInt($(ui.selected).find("td:eq(0)").text()));' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#no_expire").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).prop("checked")) {' . "\r\n\t\t\t\t\t" . '$("#exp_date").prop("disabled", true);' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$("#exp_date").removeAttr("disabled");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#add_ip").click(function() {' . "\r\n" . '                if (!$("#pair_id").val()) {' . "\r\n" . '                    if (($("#ip_field").val()) && (isValidIP($("#ip_field").val()))) {' . "\r\n" . '                        var o = new Option($("#ip_field").val(), $("#ip_field").val());' . "\r\n" . '                        $("#allowed_ips").append(o);' . "\r\n" . '                        $("#ip_field").val("");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("Please enter a valid IP address.");' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#remove_ip").click(function() {' . "\r\n" . '                if (!$("#pair_id").val()) {' . "\r\n" . "                    \$('#allowed_ips option:selected').remove();" . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#pair_id").change(function() {' . "\r\n" . '                evaluatePair();' . "\r\n" . '            });' . "\r\n" . '            $("#mac").on("input", function(e) {' . "\r\n" . '                var rRegex = /([a-f0-9]{2})([a-f0-9]{2})/i, rString = e.target.value.replace(/[^a-f0-9]/ig, "");' . "\r\n" . '                while (rRegex.test(rString)) {' . "\r\n" . "                    rString = rString.replace(rRegex, '\$1' + ':' + '\$2');" . "\r\n" . '                }' . "\r\n" . '                e.target.value = rString.slice(0, 17).toUpperCase();' . "\r\n" . '            });' . "\r\n" . '            evaluatePair();' . "\r\n" . '            $("#no_expire").trigger("change");' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . 'var rBouquets = [];' . "\r\n\t\t\t\t" . '$("#datatable-bouquets tr.selected").each(function() {' . "\r\n\t\t\t\t\t" . 'rBouquets.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#bouquets_selected").val(JSON.stringify(rBouquets));' . "\r\n\t\t\t\t" . "\$(\"#allowed_ips option\").prop('selected', true);" . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n\r\n\t\t";
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