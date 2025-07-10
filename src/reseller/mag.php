<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
	$rDevice = getMag(CoreUtilities::$rRequest['id']);

	if ($rDevice && $rDevice['user'] && $rDevice['user']['is_mag'] && hasPermissions('line', $rDevice['user']['id'])) {
	} else {
		goHome();
	}

	$rLine = $rDevice['user'];

	if (0 >= $rLine['package_id']) {
	} else {
		$rOrigPackage = getPackage($rLine['package_id']);
	}
}

$_TITLE = 'MAG Device';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext">' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rDevice)) {
	echo 'Edit';
} else {
	echo 'Add';
}

if (!isset(CoreUtilities::$rRequest['trial'])) {
} else {
	echo ' Trial';
}

echo ' MAG Device</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-xl-12">' . "\r\n" . '                ';

if (!$rGenTrials && !isset($rLine) && isset(CoreUtilities::$rRequest['trial'])) {
	echo '                <div class="alert alert-danger alert-dismissible fade show" role="alert">' . "\r\n" . '                    ';

	if ($rSettings['disable_trial']) {
		echo 'Trials have been disabled by the administrator. Please try again later.';
	} else {
		echo 'You have used your allowance of trials for this period. Please try again later.';
	}

	echo '                </div>' . "\r\n" . '                ';
} else {
	if (!(isset($rLine) && $rLine['is_trial'])) {
	} else {
		echo '                <div class="alert alert-info" role="alert">' . "\r\n" . '                    This device is on a trial package. Adding a new package will convert it to an official package.' . "\r\n" . '                </div>' . "\r\n" . '                ';
	}

	if (!isset($rLine) || in_array($rLine['member_id'], array_merge(array($rUserInfo['id']), $rPermissions['direct_reports']))) {
	} else {
		$rOwner = getRegisteredUser($rLine['member_id']);
		echo '                <div class="alert alert-info" role="alert">' . "\r\n" . "                    This device does not belong to you, although you have the right to edit this device you should notify the device's owner <strong><a href=\"user?id=";
		echo $rOwner['id'];
		echo '">';
		echo $rOwner['username'];
		echo '</a></strong> when doing so.' . "\r\n" . '                </div>' . "\r\n" . '                ';
	}

	echo "\t\t\t\t" . '<div class="card">' . "\r\n\t\t\t\t\t" . '<div class="card-body">' . "\r\n\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\r\n\t\t\t\t\t\t\t";

	if (isset($rDevice['mag_id']) && !isset($_STATUS)) {
		echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
		echo intval($rDevice['mag_id']);
		echo '" />' . "\r\n\t\t\t\t\t\t\t";
	} else {
		if (!isset(CoreUtilities::$rRequest['trial'])) {
		} else {
			echo '                            <input type="hidden" name="trial" value="1" />' . "\r\n" . '                            ';
		}
	}

	echo "\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\r\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Details</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t";

	if (!isset($rDevice['mag_id'])) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#device-info" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi mdi-cellphone-key mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Device Info</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t";
	}

	if (!$rPermissions['allow_restrictions']) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-hazard-lights mr-1"></i>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Restrictions</span>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n" . '                                    ';
	}

	echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n" . '                                        <a href="#review-purchase" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\r\n" . '                                            <i class="mdi mdi-book-open-variant mr-1"></i>' . "\r\n" . '                                            <span class="d-none d-sm-inline">Review Purchase</span>' . "\r\n" . '                                        </a>' . "\r\n" . '                                    </li>' . "\r\n\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="user-details">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="mac">MAC Address</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="mac" name="mac" value="';

	if (isset($rDevice)) {
		echo htmlspecialchars($rDevice['mac']);
	} else {
		echo '00:1A:79:';
	}

	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n" . '                                                ';

	if (0 >= count($rPermissions['all_reports'])) {
	} else {
		echo '                                                <div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="member_id">Owner</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="member_id" id="member_id" class="form-control select2" data-toggle="select2">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<optgroup label="Myself">' . "\r\n" . '                                                                <option value="';
		echo $rUserInfo['id'];
		echo '"';

		if (!(isset($rLine['member_id']) && $rLine['member_id'] == $rUserInfo['id'])) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rUserInfo['username'];
		echo '</option>' . "\r\n" . '                                                            </optgroup>' . "\r\n" . '                                                            ';

		if (0 >= count($rPermissions['direct_reports'])) {
		} else {
			echo '                                                            <optgroup label="Direct Reports">' . "\r\n" . '                                                                ';

			foreach ($rPermissions['direct_reports'] as $rUserID) {
				$rRegisteredUser = $rPermissions['users'][$rUserID];
				echo '                                                                <option value="';
				echo $rUserID;
				echo '"';

				if (!(isset($rLine['member_id']) && $rLine['member_id'] == $rUserID)) {
				} else {
					echo ' selected';
				}

				echo '>';
				echo $rRegisteredUser['username'];
				echo '</option>' . "\r\n" . '                                                                ';
			}
			echo '                                                            </optgroup>' . "\r\n" . '                                                            ';
		}

		if (count($rPermissions['direct_reports']) >= count($rPermissions['all_reports'])) {
		} else {
			echo '                                                            <optgroup label="Indirect Reports">' . "\r\n" . '                                                                ';

			foreach ($rPermissions['all_reports'] as $rUserID) {
				if (in_array($rUserID, $rPermissions['direct_reports'])) {
				} else {
					$rRegisteredUser = $rPermissions['users'][$rUserID];
					echo '                                                                    <option value="';
					echo $rUserID;
					echo '"';

					if (!(isset($rLine['member_id']) && $rLine['member_id'] == $rUserID)) {
					} else {
						echo ' selected';
					}

					echo '>';
					echo $rRegisteredUser['username'];
					echo '</option>' . "\r\n" . '                                                                    ';
				}
			}
			echo '                                                            </optgroup>' . "\r\n" . '                                                            ';
		}

		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n" . '                                                ';
	}

	if (!isset($rOrigPackage)) {
	} else {
		echo '                                                <div class="form-group row mb-4">' . "\r\n" . '                                                    <label class="col-md-4 col-form-label" for="orig_package">Original Package</label>' . "\r\n" . '                                                    <div class="col-md-8">' . "\r\n" . '                                                        <input type="text" readonly class="form-control" id="orig_package" name="orig_package" value="';
		echo $rOrigPackage['package_name'];
		echo '">' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n" . '                                                ';
	}

	echo '                                                <div class="form-group row mb-4">' . "\r\n" . '                                                    <label class="col-md-4 col-form-label" for="package">';

	if (!isset($rLine)) {
	} else {
		echo 'Add ';
	}

	echo 'Package</label>' . "\r\n" . '                                                    <div class="col-md-8">' . "\r\n" . '                                                        <select name="package" id="package" class="form-control select2" data-toggle="select2">' . "\r\n" . '                                                            ';

	if (!isset($rLine)) {
	} else {
		echo '                                                            <option value="">No Changes</option>' . "\r\n" . '                                                            ';
	}

	foreach (getPackages($rUserInfo['member_group_id'], 'mag') as $rPackage) {
		if (!($rPackage['is_trial'] && isset(CoreUtilities::$rRequest['trial']) || $rPackage['is_official'] && !isset(CoreUtilities::$rRequest['trial']))) {
		} else {
			echo '                                                                <option value="';
			echo intval($rPackage['id']);
			echo '">';
			echo htmlspecialchars($rPackage['package_name']);
			echo '</option>' . "\r\n" . '                                                                ';
		}
	}
	echo '                                                        </select>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n" . '                                                <div id="package_info" style="display: none;">' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="package_cost">Package Cost</label>' . "\r\n" . '                                                        <div class="col-md-3">' . "\r\n" . '                                                            <input readonly type="text" class="form-control text-center" id="package_cost" name="package_cost" value="">' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                        <label class="col-md-2 col-form-label" for="package_duration">Duration</label>' . "\r\n" . '                                                        <div class="col-md-3">' . "\r\n" . '                                                            <input readonly type="text" class="form-control text-center" id="package_duration" name="package_duration" value="">' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n" . '                                                <div class="form-group row mb-4" id="package_warning" style="display:none;">' . "\r\n" . '                                                    <label class="col-md-4 col-form-label" for="max_connections">Warning Notice</label>' . "\r\n" . '                                                    <div class="col-md-8">' . "\r\n" . '                                                        <div class="alert alert-warning" role="alert">' . "\r\n" . '                                                            The package you have selected is incompatible with the existing package. This could be due to the number of connections or other restrictions.<br/><br/>You can still upgrade to this package, however the time added will be from today and not from the end of the original package.' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n" . '                                                <div class="form-group row mb-4">' . "\r\n" . '                                                    <label class="col-md-4 col-form-label" for="exp_date">Expiration Date</label>' . "\r\n" . '                                                    <div class="col-md-3">' . "\r\n" . '                                                        <input readonly type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="';

	if (!isset($rLine)) {
	} else {
		if (!is_null($rLine['exp_date'])) {
			echo date('Y-m-d H:i', $rLine['exp_date']);
		} else {
			echo '" disabled="disabled';
		}
	}

	echo '">' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <label class="col-md-2 col-form-label" for="parent_password">Adult Pin</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="parent_password" name="parent_password" value="';

	if (isset($rDevice)) {
		echo htmlspecialchars($rDevice['parent_password']);
	} else {
		echo '0000';
	}

	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="reseller_notes">Reseller Notes</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3" placeholder="">';

	if (!isset($rDevice)) {
	} else {
		echo htmlspecialchars($rLine['reseller_notes']);
	}

	echo '</textarea>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t";

	if (!isset($rDevice['mag_id'])) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="device-info">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Line Username</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" readonly class="form-control sticky" id="username" value="';
		echo htmlspecialchars($rLine['username']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Line Password</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" readonly class="form-control sticky" id="password" value="';
		echo htmlspecialchars($rLine['password']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="sn">Serial Number</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="sn" name="sn" value="';
		echo htmlspecialchars($rDevice['sn']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-2 col-form-label" for="stb_type">STB Type</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="stb_type" name="stb_type" value="';
		echo htmlspecialchars($rDevice['stb_type']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="image_version">Image Version</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="image_version" name="image_version" value="';
		echo htmlspecialchars($rDevice['image_version']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-2 col-form-label" for="hw_version">HW Version</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="hw_version" name="hw_version" value="';
		echo htmlspecialchars($rDevice['hw_version']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="device_id">Primary Device ID</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="device_id" name="device_id" value="';
		echo htmlspecialchars($rDevice['device_id']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="device_id2">Secondary Device ID</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="device_id2" name="device_id2" value="';
		echo htmlspecialchars($rDevice['device_id2']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="ver">Version</label>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="ver" name="ver" value="';
		echo htmlspecialchars($rDevice['ver']);
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" onClick="clearDevice();" class="btn btn-warning">Clear Device Info</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t";
	}

	if (!$rPermissions['allow_restrictions']) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="advanced-options">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\r\n" . '                                                <div class="alert alert-warning" role="alert" id="advanced_warning" style="display: none;">' . "\r\n" . '                                                    This device is linked to a user, the options for that user will be used.' . "\r\n" . '                                                </div>' . "\r\n" . '                                                <div id="advanced_info">' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="ip_field">Allowed IP Addresses</label>' . "\r\n" . '                                                        <div class="col-md-8 input-group">' . "\r\n" . '                                                            <input type="text" id="ip_field" class="form-control" value="">' . "\r\n" . '                                                            <div class="input-group-append">' . "\r\n" . '                                                                <a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>' . "\r\n" . '                                                                <a href="javascript:void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\r\n" . '                                                            </div>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="allowed_ips">&nbsp;</label>' . "\r\n" . '                                                        <div class="col-md-8">' . "\r\n" . '                                                            <select id="allowed_ips" name="allowed_ips[]" size=6 class="form-control" multiple="multiple">' . "\r\n" . '                                                            ';

		if (!isset($rDevice)) {
		} else {
			foreach (json_decode($rLine['allowed_ips'], true) as $rIP) {
				echo '                                                            <option value="';
				echo $rIP;
				echo '">';
				echo $rIP;
				echo '</option>' . "\r\n" . '                                                            ';
			}
		}

		echo '                                                            </select>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="is_isplock">Lock to ISP</label>' . "\r\n" . '                                                        <div class="col-md-2">' . "\r\n" . '                                                            <input name="is_isplock" id="is_isplock" type="checkbox" ';

		if (!isset($rLine)) {
		} else {
			if ($rLine['is_isplock'] != 1) {
			} else {
				echo 'checked ';
			}
		}

		echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                    <div class="form-group row mb-4">' . "\r\n" . '                                                        <label class="col-md-4 col-form-label" for="isp_clear">Current ISP</label>' . "\r\n" . '                                                        <div class="col-md-8 input-group">' . "\r\n" . '                                                            <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="';

		if (!isset($rLine)) {
		} else {
			echo htmlspecialchars($rLine['isp_desc']);
		}

		echo '">' . "\r\n" . '                                                            <div class="input-group-append">' . "\r\n" . '                                                                <a href="javascript:void(0)" onclick="clearISP()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\r\n" . '                                                            </div>' . "\r\n" . '                                                        </div>' . "\r\n" . '                                                    </div>' . "\r\n" . '                                                </div>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n" . '                                    ';
	}

	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="review-purchase">' . "\r\n" . '                                        <div class="row">' . "\r\n" . '                                            <div class="col-12">' . "\r\n" . '                                                <div class="alert alert-danger" role="alert" style="display:none;" id="no-credits">' . "\r\n" . '                                                    <i class="mdi mdi-block-helper mr-2"></i> You do not have enough credits to complete this transaction!' . "\r\n" . '                                                </div>' . "\r\n" . '                                                <div class="form-group row mb-4">' . "\r\n" . '                                                    <table class="table table-striped table-borderless" id="credits-cost">' . "\r\n" . '                                                        <thead>' . "\r\n" . '                                                            <tr>' . "\r\n" . '                                                                <th class="text-center">Total Credits</th>' . "\r\n" . '                                                                <th class="text-center">Purchase Cost</th>' . "\r\n" . '                                                                <th class="text-center">Remaining Credits</th>' . "\r\n" . '                                                            </tr>' . "\r\n" . '                                                        </thead>' . "\r\n" . '                                                        <tbody>' . "\r\n" . '                                                            <tr>' . "\r\n" . '                                                                <td class="text-center">';
	echo number_format($rUserInfo['credits'], 0);
	echo '</td>' . "\r\n" . '                                                                <td class="text-center" id="cost_credits">0</td>' . "\r\n" . '                                                                <td class="text-center" id="remaining_credits">';
	echo number_format($rUserInfo['credits'], 0);
	echo '</td>' . "\r\n" . '                                                            </tr>' . "\r\n" . '                                                        </tbody>' . "\r\n" . '                                                    </table>' . "\r\n" . '                                                    <table id="datatable-review" class="table table-striped table-borderless dt-responsive nowrap" style="margin-top:30px;">' . "\r\n" . '                                                        <thead>' . "\r\n" . '                                                            <tr>' . "\r\n" . '                                                                <th class="text-center"></th>' . "\r\n" . '                                                                <th>';
	echo $_['bouquet_name'];
	echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
	echo $_['streams'];
	echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
	echo $_['movies'];
	echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
	echo $_['series'];
	echo '</th>' . "\r\n" . '                                                                <th class="text-center">';
	echo $_['stations'];
	echo '</th>' . "\r\n" . '                                                            </tr>' . "\r\n" . '                                                        </thead>' . "\r\n" . '                                                        <tbody></tbody>' . "\r\n" . '                                                    </table>' . "\r\n" . '                                                </div>' . "\r\n" . '                                            </div> <!-- end col -->' . "\r\n" . '                                        </div> <!-- end row -->' . "\r\n" . '                                        <ul class="list-inline wizard mb-0">' . "\r\n" . '                                            <li class="prevb list-inline-item">' . "\r\n" . '                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\r\n" . '                                            </li>' . "\r\n" . '                                            <li class="next list-inline-item float-right">' . "\r\n" . '                                                <input name="submit_line" id="submit_button" type="submit" class="btn btn-primary purchase" value="Purchase" />' . "\r\n" . '                                            </li>' . "\r\n" . '                                        </ul>' . "\r\n" . '                                    </div>' . "\r\n\t\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t\t\t" . '</form>' . "\r\n\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t" . '</div>' . "\r\n" . '                ';
}

echo "\t\t\t" . '</div> ' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
include 'footer.php';
