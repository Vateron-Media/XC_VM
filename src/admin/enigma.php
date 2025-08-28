<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
	$rDevice = getEnigma(CoreUtilities::$rRequest['id']);

	if ($rDevice['user_id']) {
	} else {
		exit();
	}
}

if (!isset($rDevice) || isset($rDevice['user'])) {
} else {
	$rDevice['user'] = array('bouquet' => array());
}

$_TITLE = 'Enigma Device';
include 'header.php';
echo '<div class="wrapper boxed-layout"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rDevice)) {
	echo 'Edit';
} else {
	echo 'Add';
}

echo ' Enigma Device</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-xl-12">' . "\r\n\t\t\t\t" . '<div class="card">' . "\r\n\t\t\t\t\t" . '<div class="card-body">' . "\r\n\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\r\n\t\t\t\t\t\t\t";

if (!isset($rDevice['device_id']) || isset($_STATUS)) {
} else {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
	echo intval($rDevice['device_id']);
	echo '" />' . "\r\n\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />' . "\r\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\r\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Details</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t";

if (!isset($rDevice['device_id'])) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#device-info" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi mdi-cellphone-key mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Device Info</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-folder-alert-outline mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Advanced</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#bouquets" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-flower-tulip mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Bouquets</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="user-details">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="mac">MAC Address</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="mac" name="mac" value="';

if (!isset($rDevice)) {
} else {
	echo htmlspecialchars($rDevice['mac']);
}

echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="pair_id">Paired Line</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="pair_id" name="pair_id" class="form-control" data-toggle="select2">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

if (!(isset($rDevice) && 0 < $rDevice['user']['pair_id'])) {
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rDevice['user']['pair_id'];
	echo '" selected="selected">';
	echo $rDevice['paired']['username'];
	echo '</option>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" onClick="unpairUser();" class="btn btn-warning" style="width: 100%">Unpair</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n" . '                                                <div id="linked_info">' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="member_id">Owner</label>' . "\r\n" . '                                                        <div class="col-md-6">' . "\r\n" . '                                                            <select name="member_id" id="member_id" class="form-control select2" data-toggle="select2">' . "\r\n" . '                                                                ';

if (isset($rDevice['user']['member_id']) && ($rOwner = getRegisteredUser(intval($rDevice['user']['member_id'])))) {
	echo '                                                                <option value="';
	echo intval($rOwner['id']);
	echo '" selected="selected">';
	echo $rOwner['username'];
	echo '</option>' . "\r\n" . '                                                                ';
} else {
	echo '                                                                <option value="';
	echo $rUserInfo['id'];
	echo '">';
	echo $rUserInfo['username'];
	echo '</option>' . "\r\n" . '                                                                ';
}

echo '                                                            </select>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                        <div class="col-md-2">' . "\r\n" . '                                                            <a href="javascript: void(0);" onClick="clearOwner();" class="btn btn-warning" style="width: 100%">Clear</a>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="exp_date">Expiry</i></label>' . "\r\n" . '                                                        <div class="col-md-3">' . "\r\n" . '                                                            <input type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="';

if (isset($rDevice)) {
	if (!empty($rDevice['user']['exp_date'])) {
		echo date('Y-m-d H:i:s', $rDevice['user']['exp_date']);
	} else {
		echo '" disabled="disabled';
	}
} else {
	echo date('Y-m-d H:i:s', time() + 2592000);
}

echo '" data-toggle="date-picker" data-single-date-picker="true">' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                        <label class="col-md-3 col-form-label" for="exp_date">Never Expire</label>' . "\r\n" . '                                                        <div class="col-md-2">' . "\r\n" . '                                                            <input name="no_expire" id="no_expire" type="checkbox" ';

if (!isset($rDevice)) {
} else {
	if (!is_null($rDevice['user']['exp_date'])) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="is_trial">Trial Device</label>' . "\r\n" . '                                                        <div class="col-md-3">' . "\r\n" . '                                                            <input name="is_trial" id="is_trial" type="checkbox" ';

if (!isset($rDevice)) {
} else {
	if ($rDevice['user']['is_trial'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                        <label class="col-md-3 col-form-label" for="is_isplock">Lock to ISP</label>' . "\r\n" . '                                                        <div class="col-md-2">' . "\r\n" . '                                                            <input name="is_isplock" id="is_isplock" type="checkbox" ';

if (!isset($rDevice)) {
} else {
	if ($rDevice['user']['is_isplock'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="lock_device">Device Lock</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="lock_device" id="lock_device" type="checkbox" ';

if (isset($rDevice)) {
	if ($rDevice['lock_device'] != 1) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="admin_notes">Admin Notes</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="admin_notes" name="admin_notes" class="form-control" rows="3" placeholder="">';

if (!isset($rDevice)) {
} else {
	echo htmlspecialchars($rDevice['user']['admin_notes']);
}

echo '</textarea>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="reseller_notes">Reseller Notes</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3" placeholder="">';

if (!isset($rDevice)) {
} else {
	echo htmlspecialchars($rDevice['user']['reseller_notes']);
}

echo '</textarea>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t";

if (!isset($rDevice['device_id'])) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="device-info">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Line Username</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control sticky" id="username" name="username" value="';
	echo $rDevice['user']['username'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Line Password</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control sticky" id="password" name="password" value="';
	echo $rDevice['user']['password'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="modem_mac">Modem MAC</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="modem_mac" name="modem_mac" value="';
	echo $rDevice['modem_mac'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-2 col-form-label" for="local_ip">Local IP</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="local_ip" name="local_ip" value="';
	echo $rDevice['local_ip'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="enigma_version">Enigma Version</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="enigma_version" name="enigma_version" value="';
	echo $rDevice['enigma_version'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-2 col-form-label" for="cpu">CPU</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="cpu" name="cpu" value="';
	echo $rDevice['cpu'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="lversion">Linux Version</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="lversion" name="lversion" value="';
	echo $rDevice['lversion'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="token">Token</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="token" name="token" value="';
	echo $rDevice['token'];
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" onClick="clearDevice();" class="btn btn-warning">Clear Device Info</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="advanced-options">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n" . '                                                <div class="alert alert-warning" role="alert" id="advanced_warning" style="display: none;">' . "\r\n" . '                                                    This device is linked to a user, the options for that user will be used.' . "\r\n" . '                                                </div>' . "\r\n" . '                                                <div id="advanced_info">' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="force_server_id">Forced Connection <i title="Force this user to connect to a specific server. Otherwise, the server with the lowest load will be selected." class="tooltip text-secondary far fa-circle"></i></label>' . "\r\n" . '                                                        <div class="col-md-8">' . "\r\n" . '                                                            <select name="force_server_id" id="force_server_id" class="form-control select2" data-toggle="select2">' . "\r\n" . '                                                                <option ';

if (!isset($rDevice)) {
} else {
	if (intval($rDevice['user']['force_server_id']) != 0) {
	} else {
		echo 'selected ';
	}
}

echo 'value="0">Disabled</option>' . "\r\n" . '                                                                ';

foreach ($rServers as $rServer) {
	echo '                                                                <option ';

	if (!isset($rDevice)) {
	} else {
		if (intval($rDevice['user']['force_server_id']) != intval($rServer['id'])) {
		} else {
			echo 'selected ';
		}
	}

	echo 'value="';
	echo $rServer['id'];
	echo '">';
	echo htmlspecialchars($rServer['server_name']);
	echo '</option>' . "\r\n" . '                                                                ';
}
echo '                                                            </select>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="forced_country">Forced Country <i title="Force user to connect to loadbalancer associated with the selected country." class="tooltip text-secondary far fa-circle"></i></label>' . "\r\n" . '                                                        <div class="col-md-8">' . "\r\n" . '                                                            <select name="forced_country" id="forced_country" class="form-control select2" data-toggle="select2">' . "\r\n" . '                                                                ';

foreach ($rCountries as $rCountry) {
	echo '                                                                <option ';

	if (!isset($rDevice)) {
	} else {
		if ($rDevice['user']['forced_country'] != $rCountry['id']) {
		} else {
			echo 'selected ';
		}
	}

	echo 'value="';
	echo $rCountry['id'];
	echo '">';
	echo $rCountry['name'];
	echo '</option>' . "\r\n" . '                                                                ';
}
echo '                                                            </select>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="isp_clear">Current ISP</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="';

if (!isset($rDevice['user'])) {
} else {
	echo htmlspecialchars($rDevice['user']['isp_desc']);
}

echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" onclick="clearISP()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="ip_field">Allowed IP Addresses</label>' . "\r\n" . '                                                        <div class="col-md-8 input-group">' . "\r\n" . '                                                            <input type="text" id="ip_field" class="form-control" value="">' . "\r\n" . '                                                            <div class="input-group-append">' . "\r\n" . '                                                                <a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>' . "\r\n" . '                                                                <a href="javascript:void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\r\n" . '                                                            </div>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="allowed_ips">&nbsp;</label>' . "\r\n" . '                                                        <div class="col-md-8">' . "\r\n" . '                                                            <select id="allowed_ips" name="allowed_ips[]" size=6 class="form-control" multiple="multiple">' . "\r\n" . '                                                            ';

if (!isset($rDevice)) {
} else {
	foreach (json_decode($rDevice['user']['allowed_ips'], true) as $rIP) {
		echo '                                                            <option value="';
		echo $rIP;
		echo '">';
		echo $rIP;
		echo '</option>' . "\r\n" . '                                                            ';
	}
}

echo '                                                            </select>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="bouquets">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n" . '                                                <div class="alert alert-warning" role="alert" id="bouquet_warning" style="display: none;">' . "\r\n" . '                                                    This device is linked to a user, the bouquets for that user will be used.' . "\r\n" . '                                                </div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4" id="bouquets_info">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-bouquets" class="table table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead class="bg-light">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>Bouquet Name</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['streams'];
echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
echo $_['movies'];
echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
echo $_['series'];
echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
echo $_['stations'];
echo '</th' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (getBouquets() as $rBouquet) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr';

	if (!(isset($rDevice) && in_array($rBouquet['id'], json_decode($rDevice['user']['bouquet'], true)))) {
	} else {
		echo " class='selected selectedfilter ui-selected'";
	}

	echo '>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo $rBouquet['id'];
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rBouquet['bouquet_name'];
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo count(json_decode($rBouquet['bouquet_channels'], true));
	echo '</td>' . "\r\n" . '                                                                <td class="text-center">';
	echo count(json_decode($rBouquet['bouquet_movies'], true));
	echo '</td>' . "\r\n" . '                                                                <td class="text-center">';
	echo count(json_decode($rBouquet['bouquet_series'], true));
	echo '</td>' . "\r\n" . '                                                                <td class="text-center">';
	echo count(json_decode($rBouquet['bouquet_radios'], true));
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" onClick="toggleBouquets()" class="btn btn-info" id="toggle_bouquets">Toggle All</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_device" type="submit" class="btn btn-primary" value="';

if (isset($rDevice)) {
	echo 'Edit';
} else {
	echo 'Add';
}

echo '" />' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t" . '</form>' . "\r\n\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t" . '</div> ' . "\r\n\t\t\t" . '</div> ' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t";

		if (isset($rDevice)) {
			echo "\t\t" . 'var rBouquets = ';
			echo $rDevice['user']['bouquet'];
			echo ';' . "\r\n\t\t";
		} else {
			echo "\t\t" . 'var rBouquets = [];' . "\r\n\t\t";
		}

		echo "\r\n\t\t" . 'function toggleBouquets() {' . "\r\n" . '            if (!$("#pair_id").val()) {' . "\r\n" . '                $("#datatable-bouquets tr").each(function() {' . "\r\n" . "                    if (\$(this).hasClass('selected')) {" . "\r\n" . "                        \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                        if ($(this).find("td:eq(0)").text()) {' . "\r\n" . '                            window.rBouquets.splice(parseInt($.inArray($(this).find("td:eq(0)").text()), window.rBouquets), 1);' . "\r\n" . '                        }' . "\r\n" . '                    } else {            ' . "\r\n" . "                        \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                        if ($(this).find("td:eq(0)").text()) {' . "\r\n" . '                            window.rBouquets.push(parseInt($(this).find("td:eq(0)").text()));' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearDevice() {' . "\r\n\t\t\t" . '$("#device-info input").each(function() {' . "\r\n\t\t\t\t" . 'if (!$(this).hasClass("sticky")) {' . "\r\n\t\t\t\t\t" . '$(this).val("");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearISP() {' . "\r\n" . '            $("#isp_clear").val("");' . "\r\n" . '        }' . "\r\n\t\t" . 'function unpairUser() {' . "\r\n\t\t\t" . '$("#pair_id").val("").trigger("change");' . "\r\n\t\t" . '}' . "\r\n" . '        function evaluatePair() {' . "\r\n" . '            if ($("#pair_id").val()) {' . "\r\n" . '                $("#toggle_bouquets").addClass("disabled");' . "\r\n" . '                $("#advanced_warning").show();' . "\r\n" . '                $("#bouquet_warning").show();' . "\r\n" . '                $("#linked_info").hide();' . "\r\n" . '                $("#bouquets_info").hide();' . "\r\n" . '                $("#advanced_info").hide();' . "\r\n" . '            } else {' . "\r\n" . '                $("#toggle_bouquets").removeClass("disabled");' . "\r\n" . '                $("#advanced_warning").hide();' . "\r\n" . '                $("#bouquet_warning").hide();' . "\r\n" . '                $("#linked_info").show();' . "\r\n" . '                $("#bouquets_info").show();' . "\r\n" . '                $("#advanced_info").show();' . "\r\n" . '            }' . "\r\n" . '            $(["exp_date", "is_trial", "no_expire", "force_server_id", "forced_country", "ip_field", "allowed_ips"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if ($("#pair_id").val()) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function clearOwner() {' . "\r\n" . "            \$('#member_id').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select.select2').select2({width: '100%'});" . "\r\n" . "            \$('#member_id').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#exp_date').daterangepicker({" . "\r\n\t\t\t\t" . 'singleDatePicker: true,' . "\r\n\t\t\t\t" . 'showDropdowns: true,' . "\r\n\t\t\t\t" . 'minDate: new Date(),' . "\r\n" . '                timePicker: true,' . "\r\n\t\t\t\t" . 'locale: {' . "\r\n\t\t\t\t\t" . "format: 'YYYY-MM-DD HH:mm'" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#pair_id').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'userlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: '";
		echo $_['search_user'];
		echo "'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t\r\n\t\t\t" . '$("#datatable-bouquets").DataTable({' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rBouquets) !== -1) {' . "\r\n\t\t\t\t\t\t" . '$(row).addClass("selected");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-bouquets").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n" . '                    if (!$("#pair_id").val()) {' . "\r\n" . "                        if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n" . "                            \$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                            window.rBouquets.splice(parseInt($.inArray($(ui.selected).find("td:eq(0)").text()), window.rBouquets), 1);' . "\r\n" . '                        } else {            ' . "\r\n" . "                            \$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                            window.rBouquets.push(parseInt($(ui.selected).find("td:eq(0)").text()));' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#no_expire").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).prop("checked")) {' . "\r\n\t\t\t\t\t" . '$("#exp_date").prop("disabled", true);' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$("#exp_date").removeAttr("disabled");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#add_ip").click(function() {' . "\r\n" . '                if (!$("#pair_id").val()) {' . "\r\n" . '                    if (($("#ip_field").val()) && (isValidIP($("#ip_field").val()))) {' . "\r\n" . '                        var o = new Option($("#ip_field").val(), $("#ip_field").val());' . "\r\n" . '                        $("#allowed_ips").append(o);' . "\r\n" . '                        $("#ip_field").val("");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("Please enter a valid IP address.");' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#remove_ip").click(function() {' . "\r\n" . '                if (!$("#pair_id").val()) {' . "\r\n" . "                    \$('#allowed_ips option:selected').remove();" . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#pair_id").change(function() {' . "\r\n" . '                evaluatePair();' . "\r\n" . '            });' . "\r\n" . '            $("#mac").on("input", function(e) {' . "\r\n" . '                var rRegex = /([a-f0-9]{2})([a-f0-9]{2})/i, rString = e.target.value.replace(/[^a-f0-9]/ig, "");' . "\r\n" . '                while (rRegex.test(rString)) {' . "\r\n" . "                    rString = rString.replace(rRegex, '\$1' + ':' + '\$2');" . "\r\n" . '                }' . "\r\n" . '                e.target.value = rString.slice(0, 17).toUpperCase();' . "\r\n" . '            });' . "\r\n" . '            evaluatePair();' . "\r\n" . '            $("#no_expire").trigger("change");' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . 'var rBouquets = [];' . "\r\n\t\t\t\t" . '$("#datatable-bouquets tr.selected").each(function() {' . "\r\n\t\t\t\t\t" . 'rBouquets.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#bouquets_selected").val(JSON.stringify(rBouquets));' . "\r\n\t\t\t\t" . "\$(\"#allowed_ips option\").prop('selected', true);" . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>

