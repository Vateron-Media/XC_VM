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