<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Mass Edit Devices';
include 'header.php'; ?>
<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Mass Edit Devices <small id="selected_count"></small></h4>
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
                        Mass edit of devices was successfully executed!
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="devices_selected" id="devices_selected" value="" />
                            <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#user-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-group mr-1"></i>
                                            <span class="d-none d-sm-inline">Devices</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#events" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-email mr-1"></i>
                                            <span class="d-none d-sm-inline">Events</span>
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
                                                <input type="text" class="form-control" id="user_search" value="" placeholder="Search Devices...">
                                            </div>
                                            <div class="col-md-3">
                                                <select id="reseller_search" class="form-control" data-toggle="select2">
                                                    <?php if (!(isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner']))))) {
                                                    } else { ?>
                                                        <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <label class="col-md-1 col-form-label text-center" for="reseller_search"><button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();">CLEAR</button></label>
                                            <div class="col-md-2">
                                                <select id="filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected>No Filter</option>
                                                    <option value="1">Active</option>
                                                    <option value="2">Disabled</option>
                                                    <option value="3">Banned</option>
                                                    <option value="4">Expired</option>
                                                    <option value="5">Trial</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-8">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                    <?php } ?>
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
                                                        <th class="text-center"><?php echo $_['id']; ?></th>
                                                        <th><?php echo $_['username']; ?></th>
                                                        <th class="text-center"><?php echo $_['mac_address']; ?></th>
                                                        <th class="text-center">Device</th>
                                                        <th><?php echo $_['owner']; ?></th>
                                                        <th class="text-center"><?php echo $_['status']; ?></th>
                                                        <th class="text-center"><?php echo $_['online']; ?></th>
                                                        <th class="text-center"><?php echo $_['trial']; ?></th>
                                                        <th class="text-center"><?php echo $_['expiration']; ?></th>
                                                        <th class="text-center"><?php echo $_['actions']; ?></th>
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
                                                            <?php foreach (getRegisteredUsers() as $rRegisteredUser) { ?>
                                                                <option value="<?php echo $rRegisteredUser['id']; ?>"><?php echo $rRegisteredUser['username']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="parent_password" name="c_parent_password">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="parent_password">Adult Pin</label>
                                                    <div class="col-md-2">
                                                        <input disabled type="text" class="form-control text-center" id="parent_password" name="parent_password" value="0000">
                                                    </div>
                                                    <label class="col-md-1 col-form-label" for="exp_date">Expiry</label>
                                                    <div class="col-md-3">
                                                        <input disabled type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="" data-toggle="date-picker" data-single-date-picker="true">
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
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
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
                                                            <?php foreach ($rCountries as $rCountry) { ?>
                                                                <option value="<?php echo $rCountry['id']; ?>"><?php echo $rCountry['name']; ?></option>
                                                            <?php } ?>
                                                        </select>
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
                                                        <input type="checkbox" class="activate" data-name="lock_device" data-type="switch" name="c_lock_device">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="lock_device">Device Lock</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="lock_device" id="lock_device" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-5 col-form-label" for="reset_device_lock">Reset Device Lock</label>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" name="reset_device_lock">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="is_trial" data-type="switch" name="c_is_trial">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="is_trial">Trial Device</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="is_trial" id="is_trial" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="modern_theme">Modern Theme</label>
                                                    <div class="col-md-2">
                                                        <input disabled name="modern_theme" id="modern_theme" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary">
                                                        <input type="checkbox" class="activate" data-name="modern_theme" data-type="switch" name="c_modern_theme">
                                                        <label></label>
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
                                    <div class="tab-pane" id="events">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label" for="m_message_type">Event Type</label>
                                                    <div class="col-9">
                                                        <select id="m_message_type" name="message_type" class="form-control" data-toggle="select2">
                                                            <option value="" selected><?php echo $_['select_an_event']; ?>:</option>
                                                            <optgroup label="">
                                                                <option value="play_channel"><?php echo $_['play_channel']; ?></option>
                                                                <option value="reload_portal"><?php echo $_['reload_portal']; ?></option>
                                                                <option value="reboot"><?php echo $_['reboot_device']; ?></option>
                                                                <option value="send_msg"><?php echo $_['send_message']; ?></option>
                                                                <option value="cut_off"><?php echo $_['close_portal']; ?></option>
                                                            </optgroup>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4" id="m_send_msg_form">
                                                    <label class="col-md-3 col-form-label" for="m_message">Message</label>
                                                    <div class="col-md-9">
                                                        <textarea id="m_message" name="message" class="form-control" rows="3" placeholder="<?php echo $_['enter_a_custom_message']; ?>..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4" id="m_play_channel_form">
                                                    <label class="col-md-3 col-form-label" for="m_selected_channel"><?php echo $_['channel']; ?></label>
                                                    <div class="col-md-9">
                                                        <select id="m_selected_channel" name="selected_channel" class="form-control select2" data-toggle="select2" style="width:100%;"></select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4" id="m_reboot_portal_form">
                                                    <label class="col-md-9 col-form-label" for="m_reboot_portal"><?php echo $_['reboot_on_confirmation']; ?></label>
                                                    <div class="col-md-3">
                                                        <input name="reboot_portal" id="m_reboot_portal" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
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
                                                            <?php foreach (getBouquets() as $rBouquet): ?>
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
                                                        <label class="custom-control-label" for="c_bouquets">Tick this box to apply the above bouquets to all selected devices. Selecting none will remove bouquets from devices.</label>
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
                                                <input name="submit_device" type="submit" class="btn btn-primary" value="Mass Edit" />
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