<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id'])) {
    $rLine = getUser(CoreUtilities::$rRequest['id']);

    if (!$rLine || !hasPermissions('adv', 'edit_user')) {
        goHome();
    }

    if ($rLine['is_mag']) {
        $db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rLine['id']);

        if ($db->num_rows() > 0) {
            header('Location: mag?id=' . intval($db->get_row()['mag_id']));
            exit;
        } else {
            goHome();
        }
    }

    if ($rLine['is_e2']) {
        $db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rLine['id']);

        if ($db->num_rows() > 0) {
            header('Location: enigma?id=' . intval($db->get_row()['device_id']));
            exit;
        } else {
            goHome();
        }
    }
} else {
    if (!hasPermissions('adv', 'add_user')) {
        goHome();
    }
}

$rRegisteredUsers = getRegisteredUsers();
$_TITLE = 'Line';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        echo '';
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
                    <h4 class="page-title"><?php echo isset($rLine) ? 'Edit' : 'Add'; ?> Line</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rLine)) { ?>
                                <input type="hidden" name="edit" value="<?php echo $rLine['id']; ?>" />
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
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Advanced</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#restrictions" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-hazard-lights mr-1"></i>
                                            <span class="d-none d-sm-inline">Restrictions</span>
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
                                                    <label class="col-md-4 col-form-label" for="username">Username</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="username" name="username" placeholder="Auto-generate if blank" value="<?php echo isset($rLine) ? htmlspecialchars($rLine['username']) : ''; ?>" data-indicator="unindicator">
                                                        <div id="unindicator">
                                                            <div class="bar"></div>
                                                            <div class="label"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="password">Password</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="password" name="password" placeholder="Auto-generate if blank" value="<?php echo isset($rLine) ? htmlspecialchars($rLine['password']) : ''; ?>" data-indicator="pwindicator">
                                                        <div id="pwindicator">
                                                            <div class="bar"></div>
                                                            <div class="label"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="member_id">Owner</label>
                                                    <div class="col-md-6">
                                                        <select name="member_id" id="member_id" class="form-control select2" data-toggle="select2">
                                                            <?php
                                                            if (isset($rLine['member_id']) && ($rOwner = getRegisteredUser(intval($rLine['member_id'])))) {
                                                                echo '<option value="' . intval($rOwner['id']) . '" selected="selected">' . $rOwner['username'] . '</option>';
                                                            } else {
                                                                echo '<option value="' . $rUserInfo['id'] . '">' . $rUserInfo['username'] . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <a href="javascript: void(0);" onClick="clearOwner();" class="btn btn-warning" style="width: 100%">Clear</a>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="exp_date">Expiry</label>
                                                    <div class="col-md-3">
                                                        <input type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="<?php echo isset($rLine) ? (is_null($rLine['exp_date']) ? '' : date('Y-m-d H:i:s', $rLine['exp_date'])) : date('Y-m-d H:i:s', time() + 2592000); ?>" data-toggle="date-picker" data-single-date-picker="true">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="exp_date">Never Expire</label>
                                                    <div class="col-md-2">
                                                        <input name="no_expire" id="no_expire" type="checkbox" <?php echo isset($rLine) && is_null($rLine['exp_date']) ? 'checked' : ''; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="max_connections">Max Connections</label>
                                                    <div class="col-md-3">
                                                        <input type="text" class="form-control text-center" id="max_connections" name="max_connections" value="<?php echo isset($rLine) ? htmlspecialchars($rLine['max_connections']) : '1'; ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="contact">WhatsApp <i title="Enter WhatsApp number with country code (e.g. +491234567890)" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="contact" name="contact" placeholder="+491234567890" value="<?php echo isset($rLine) ? htmlspecialchars($rLine['contact']) : ''; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="admin_notes">Admin Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="admin_notes" name="admin_notes" class="form-control" rows="3"><?php echo isset($rLine) ? htmlspecialchars($rLine['admin_notes']) : ''; ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="reseller_notes">Reseller Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3"><?php echo isset($rLine) ? htmlspecialchars($rLine['reseller_notes']) : ''; ?></textarea>
                                                    </div>
                                                </div>
                                                <ul class="list-inline wizard mb-0">
                                                    <li class="nextb list-inline-item float-right">
                                                        <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="force_server_id">Forced Connection <i title="Force this user to connect to a specific server. Otherwise, the server with the lowest load will be selected." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="force_server_id" id="force_server_id" class="form-control select2" data-toggle="select2">
                                                            <option <?php echo !isset($rLine) || intval($rLine['force_server_id']) == 0 ? 'selected' : ''; ?> value="0">Disabled</option>
                                                            <?php
                                                            foreach ($rServers as $rServer) {
                                                                echo '<option ' . (isset($rLine) && intval($rLine['force_server_id']) == intval($rServer['id']) ? 'selected' : '') . ' value="' . $rServer['id'] . '">' . htmlspecialchars($rServer['server_name']) . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_stalker">Ministra Portal <i title="Select this option if you intend to use this account with your Ministra portal. Output formats, expiration and connections below will be ignored. Only MPEG-TS output is allowed." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="is_stalker" id="is_stalker" type="checkbox" <?php echo isset($rLine) && $rLine['is_stalker'] == 1 ? 'checked' : ''; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_restreamer">Restreamer <i title="If selected, this user will not be blocked for restreaming channels." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="is_restreamer" id="is_restreamer" type="checkbox" <?php echo isset($rLine) && $rLine['is_restreamer'] == 1 ? 'checked' : ''; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_trial">Trial Account</label>
                                                    <div class="col-md-2">
                                                        <input name="is_trial" id="is_trial" type="checkbox" <?php echo isset($rLine) && $rLine['is_trial'] == 1 ? 'checked' : ''; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_isplock">Lock to ISP</label>
                                                    <div class="col-md-2">
                                                        <input name="is_isplock" id="is_isplock" type="checkbox" <?php echo isset($rLine) && $rLine['is_isplock'] == 1 ? 'checked' : ''; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="isp_clear">Current ISP</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="<?php echo isset($rLine) ? htmlspecialchars($rLine['isp_desc']) : ''; ?>">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" onclick="clearISP()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="access_token">Access Token <i title="Generate an access token that can be used in place of username and password. If you use this option, playlists generated will contain the access token as auth." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" readonly class="form-control" id="access_token" name="access_token" value="<?php echo isset($rLine) ? htmlspecialchars($rLine['access_token']) : ''; ?>">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" onclick="generateToken()" class="btn btn-info waves-effect waves-light"><i class="mdi mdi-refresh"></i></a>
                                                            <a href="javascript:void(0)" onclick="clearToken()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="forced_country">Forced Country <i title="Force user to connect to loadbalancer associated with the selected country." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="forced_country" id="forced_country" class="form-control select2" data-toggle="select2">
                                                            <?php
                                                            foreach ($rCountries as $rCountry) {
                                                                echo '<option ' . (isset($rLine) && $rLine['forced_country'] == $rCountry['id'] ? 'selected' : '') . ' value="' . $rCountry['id'] . '">' . $rCountry['name'] . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="access_output">Access Output</label>
                                                    <div class="col-md-8">
                                                        <?php
                                                        foreach (getOutputs() as $rOutput) {
                                                            $checked = isset($rLine) ? (in_array($rOutput['access_output_id'], json_decode($rLine['allowed_outputs'], true)) ? ' checked' : '') : ' checked';
                                                            echo '<div class="checkbox form-check-inline"><input data-size="large" type="checkbox" id="access_output_' . $rOutput['access_output_id'] . '" name="access_output[]" value="' . $rOutput['access_output_id'] . '"' . $checked . '><label for="access_output_' . $rOutput['access_output_id'] . '"> ' . $rOutput['output_name'] . ' </label></div>';
                                                        }
                                                        ?>
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
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="restrictions">
                                        <div class="row">
                                            <div class="col-12">
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
                                                            <?php
                                                            if (isset($rLine)) {
                                                                foreach (json_decode($rLine['allowed_ips'], true) as $rIP) {
                                                                    echo '<option value="' . $rIP . '">' . $rIP . '</option>';
                                                                }
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="ua_field">Allowed User-Agents</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="ua_field" class="form-control" value="">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" id="add_ua" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
                                                            <a href="javascript:void(0)" id="remove_ua" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="allowed_ua">&nbsp;</label>
                                                    <div class="col-md-8">
                                                        <select id="allowed_ua" name="allowed_ua[]" size=6 class="form-control" multiple="multiple">
                                                            <?php
                                                            if (isset($rLine)) {
                                                                foreach (json_decode($rLine['allowed_ua'], true) as $rUA) {
                                                                    echo '<option value="' . $rUA . '">' . $rUA . '</option>';
                                                                }
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="bypass_ua">Bypass UA Restrictions</label>
                                                    <div class="col-md-2">
                                                        <input name="bypass_ua" id="bypass_ua" type="checkbox" <?php echo isset($rLine) && $rLine['bypass_ua'] == 1 ? 'checked' : ''; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
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
                                        </div>
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
                                                            <?php
                                                            foreach (getBouquets() as $rBouquet) {
                                                                $selected = isset($rLine) && in_array($rBouquet['id'], json_decode($rLine['bouquet'], true)) ? " class='selected selectedfilter ui-selected'" : "";
                                                                echo "<tr$selected><td class='text-center'>" . $rBouquet['id'] . "</td><td>" . $rBouquet['bouquet_name'] . "</td><td class='text-center'>" . count(json_decode($rBouquet['bouquet_channels'], true)) . "</td><td class='text-center'>" . count(json_decode($rBouquet['bouquet_movies'], true)) . "</td><td class='text-center'>" . count(json_decode($rBouquet['bouquet_series'], true)) . "</td><td class='text-center'>" . count(json_decode($rBouquet['bouquet_radios'], true)) . "</td></tr>";
                                                            }
                                                            ?>
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
                                                <a href="javascript: void(0);" onClick="toggleBouquets()" class="btn btn-info">Toggle All</a>
                                                <input name="submit_line" type="submit" class="btn btn-primary" value="<?php echo isset($rLine) ? 'Edit' : 'Add'; ?>" />
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
		if (isset($rUser)) {
			echo "\t\t" . 'var rBouquets = ';
			echo $rUser['bouquet'];
			echo ';' . "\r\n\t\t";
		} else {
			echo "\t\t" . 'var rBouquets = [];' . "\r\n\t\t";
		}

		echo "\r\n" . '        function generateToken() {' . "\r\n\t\t\t" . "var result           = '';" . "\r\n\t\t\t" . "var characters       = 'ABCDEF0123456789';" . "\r\n\t\t\t" . 'var charactersLength = characters.length;' . "\r\n\t\t\t" . 'for ( var i = 0; i < 32; i++ ) {' . "\r\n\t\t\t\t" . 'result += characters.charAt(Math.floor(Math.random() * charactersLength));' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$("#access_token").val(result);' . "\r\n\t\t" . '}' . "\r\n" . '        function clearToken() {' . "\r\n" . '            $("#access_token").val("");' . "\r\n" . '        }' . "\r\n\t\t" . 'function toggleBouquets() {' . "\r\n\t\t\t" . '$("#datatable-bouquets tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.splice(parseInt($.inArray($(this).find("td:eq(0)").text()), window.rBouquets), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.push(parseInt($(this).find("td:eq(0)").text()));' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n" . '        function clearISP() {' . "\r\n" . '            $("#isp_clear").val("");' . "\r\n" . '        }' . "\r\n" . '        function clearOwner() {' . "\r\n" . "            \$('#member_id').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select.select2').select2({width: '100%'});" . "\r\n" . "            \$('#member_id').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#exp_date').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'minDate: new Date(),' . "\r\n" . '                timePicker: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD HH:mm'" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#username').pwstrength();" . "\r\n" . "            \$('#password').pwstrength();" . "\r\n\t\t\t" . '$("#datatable-bouquets").DataTable({' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rBouquets) !== -1) {' . "\r\n\t\t\t\t\t\t" . '$(row).addClass("selected");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-bouquets").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.splice(parseInt($.inArray($(ui.selected).find("td:eq(0)").text()), window.rBouquets), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rBouquets.push(parseInt($(ui.selected).find("td:eq(0)").text()));' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#no_expire").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).prop("checked")) {' . "\r\n\t\t\t\t\t" . '$("#exp_date").prop("disabled", true);' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$("#exp_date").removeAttr("disabled");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#add_ip").click(function() {' . "\r\n\t\t\t\t" . 'if (($("#ip_field").val()) && (isValidIP($("#ip_field").val()))) {' . "\r\n\t\t\t\t\t" . 'var o = new Option($("#ip_field").val(), $("#ip_field").val());' . "\r\n\t\t\t\t\t" . '$("#allowed_ips").append(o);' . "\r\n\t\t\t\t\t" . '$("#ip_field").val("");' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("Please enter a valid IP address.");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#remove_ip").click(function() {' . "\r\n\t\t\t\t" . "\$('#allowed_ips option:selected').remove();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#add_ua").click(function() {' . "\r\n\t\t\t\t" . 'if ($("#ua_field").val()) {' . "\r\n\t\t\t\t\t" . 'var o = new Option($("#ua_field").val(), $("#ua_field").val());' . "\r\n\t\t\t\t\t" . '$("#allowed_ua").append(o);' . "\r\n\t\t\t\t\t" . '$("#ua_field").val("");' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("Please enter a user-agent.");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#remove_ua").click(function() {' . "\r\n\t\t\t\t" . "\$('#allowed_ua option:selected').remove();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#max_connections").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t\r\n" . "            \$('#username').keypress(function (e) {" . "\r\n" . '                var regex = new RegExp("^[a-zA-Z0-9@._-]+$");' . "\r\n" . '                var str = String.fromCharCode(!e.charCode ? e.which : e.charCode);' . "\r\n" . '                if (regex.test(str)) {' . "\r\n" . '                    return true;' . "\r\n" . '                }' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                return false;' . "\r\n" . '            });' . "\r\n" . "            \$('#password').keypress(function (e) {" . "\r\n" . '                var regex = new RegExp("^[a-zA-Z0-9@._-]+$");' . "\r\n" . '                var str = String.fromCharCode(!e.charCode ? e.which : e.charCode);' . "\r\n" . '                if (regex.test(str)) {' . "\r\n" . '                    return true;' . "\r\n" . '                }' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                return false;' . "\r\n" . '            });' . "\r\n" . '            ';

		if (!isset($rLine)) {
		} else {
			echo "            \$('#username').trigger('keyup');" . "\r\n" . "            \$('#password').trigger('keyup');" . "\r\n" . '            ';
		}

		echo '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . 'var rBouquets = [];' . "\r\n\t\t\t\t" . '$("#datatable-bouquets tr.selected").each(function() {' . "\r\n\t\t\t\t\t" . 'rBouquets.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#bouquets_selected").val(JSON.stringify(rBouquets));' . "\r\n\t\t\t\t" . "\$(\"#allowed_ua option\").prop('selected', true);" . "\r\n\t\t\t\t" . "\$(\"#allowed_ips option\").prop('selected', true);" . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
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