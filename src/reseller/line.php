<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
	$rLine = getUser(CoreUtilities::$rRequest['id']);

	if (!(!$rLine || $rLine['is_mag'] || $rLine['is_e2']) && hasPermissions('line', $rLine['id'])) {
	} else {
		goHome();
	}

	if (0 >= $rLine['package_id']) {
	} else {
		$rOrigPackage = getPackage($rLine['package_id']);
	}
}

$_TITLE = 'Line';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext">' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rLine)) {
	echo 'Edit';
} else {
	echo 'Add';
}

if (!isset(CoreUtilities::$rRequest['trial'])) {
} else {
	echo ' Trial';
}

echo ' Line</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n" . '                ';

if (!$rGenTrials && !isset($rLine) && isset(CoreUtilities::$rRequest['trial'])) {
	echo '                <div class="alert alert-danger alert-dismissible fade show" role="alert">' . "\n" . '                    ';

	if ($rSettings['disable_trial']) {
		echo 'Trials have been disabled by the administrator. Please try again later.';
	} else {
		echo 'You have used your allowance of trials for this period. Please try again later.';
	}

	echo '                </div>' . "\n" . '                ';
} else {
	if (!(isset($rLine) && $rLine['is_trial'])) {
	} else {
		echo '                <div class="alert alert-info" role="alert">' . "\n" . '                    This user is on a trial package. Adding a new package will convert it to an official package.' . "\n" . '                </div>' . "\n" . '                ';
	}

	if (!isset($rLine) || in_array($rLine['member_id'], array_merge(array($rUserInfo['id']), $rPermissions['direct_reports']))) {
	} else {
		$rOwner = getRegisteredUser($rLine['member_id']);
		echo '                <div class="alert alert-info" role="alert">' . "\n" . "                    This line does not belong to you, although you have the right to edit this line you should notify the line's owner <strong><a href=\"user?id=";
		echo $rOwner['id'];
		echo '">';
		echo $rOwner['username'];
		echo '</a></strong> when doing so.' . "\n" . '                </div>' . "\n" . '                ';
	}

	echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\n\t\t\t\t\t\t\t";

	if (isset($rLine)) {
		echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
		echo $rLine['id'];
		echo '" />' . "\n\t\t\t\t\t\t\t";
	} else {
		if (!isset(CoreUtilities::$rRequest['trial'])) {
		} else {
			echo '                            <input type="hidden" name="trial" value="1" />' . "\n" . '                            ';
		}
	}

	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />' . "\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Details</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    ';

	if (!$rPermissions['allow_restrictions']) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#restrictions" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-hazard-lights mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Restrictions</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    ';
	}

	echo '                                    <li class="nav-item">' . "\n" . '                                        <a href="#review-purchase" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n" . '                                            <i class="mdi mdi-book-open-variant mr-1"></i>' . "\n" . '                                            <span class="d-none d-sm-inline">Review Purchase</span>' . "\n" . '                                        </a>' . "\n" . '                                    </li>' . "\n\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="user-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n" . '                                                ';

	if ($rPermissions['allow_change_username']) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Username</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="username" name="username" placeholder="Auto-generate if blank" value="';

		if (!isset($rLine)) {
		} else {
			echo htmlspecialchars($rLine['username']);
		}

		echo '" data-indicator="unindicator">' . "\n" . '                                                        <div id="unindicator">' . "\n" . '                                                            <div class="bar"></div>' . "\n" . '                                                            <div class="label"></div>' . "\n" . '                                                        </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
	} else {
		if (!isset($rLine)) {
		} else {
			echo '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Username</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" readonly class="form-control" id="username" value="';
			echo htmlspecialchars($rLine['username']);
			echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
		}
	}

	if ($rPermissions['allow_change_password']) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="password">Password</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="password" name="password" placeholder="Auto-generate if blank" value="';

		if (!isset($rLine)) {
		} else {
			echo htmlspecialchars($rLine['password']);
		}

		echo '" data-indicator="pwindicator">' . "\n" . '                                                        <div id="pwindicator">' . "\n" . '                                                            <div class="bar"></div>' . "\n" . '                                                            <div class="label"></div>' . "\n" . '                                                        </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
	} else {
		if (!isset($rLine)) {
		} else {
			echo '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="password">Password</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" readonly class="form-control" id="password" value="';
			echo htmlspecialchars($rLine['password']);
			echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
		}
	}

	if (0 >= count($rPermissions['all_reports'])) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="member_id">Owner</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="member_id" id="member_id" class="form-control select2" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<optgroup label="Myself">' . "\n" . '                                                                <option value="';
		echo $rUserInfo['id'];
		echo '"';

		if (!(isset($rLine['member_id']) && $rLine['member_id'] == $rUserInfo['id'])) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rUserInfo['username'];
		echo '</option>' . "\n" . '                                                            </optgroup>' . "\n" . '                                                            ';

		if (0 >= count($rPermissions['direct_reports'])) {
		} else {
			echo '                                                            <optgroup label="Direct Reports">' . "\n" . '                                                                ';

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
				echo '</option>' . "\n" . '                                                                ';
			}
			echo '                                                            </optgroup>' . "\n" . '                                                            ';
		}

		if (count($rPermissions['direct_reports']) >= count($rPermissions['all_reports'])) {
		} else {
			echo '                                                            <optgroup label="Indirect Reports">' . "\n" . '                                                                ';

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
					echo '</option>' . "\n" . '                                                                    ';
				}
			}
			echo '                                                            </optgroup>' . "\n" . '                                                            ';
		}

		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
	}

	if (!isset($rOrigPackage)) {
	} else {
		echo '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="orig_package">Original Package</label>' . "\n" . '                                                    <div class="col-md-8">' . "\n" . '                                                        <input type="text" readonly class="form-control" id="orig_package" name="orig_package" value="';
		echo $rOrigPackage['package_name'];
		echo '">' . "\n" . '                                                    </div>' . "\n" . '                                                </div>' . "\n" . '                                                ';
	}

	echo '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="package">';

	if (!isset($rLine)) {
	} else {
		echo 'Add ';
	}

	echo 'Package</label>' . "\n" . '                                                    <div class="col-md-8">' . "\n" . '                                                        <select name="package" id="package" class="form-control select2" data-toggle="select2">' . "\n" . '                                                            ';

	if (!isset($rLine)) {
	} else {
		echo '                                                            <option value="">No Changes</option>' . "\n" . '                                                            ';
	}

	foreach (getPackages($rUserInfo['member_group_id'], 'line') as $rPackage) {
		if (!($rPackage['is_trial'] && isset(CoreUtilities::$rRequest['trial']) || $rPackage['is_official'] && !isset(CoreUtilities::$rRequest['trial']))) {
		} else {
			echo '                                                                <option value="';
			echo intval($rPackage['id']);
			echo '">';
			echo htmlspecialchars($rPackage['package_name']);
			echo '</option>' . "\n" . '                                                                ';
		}
	}
	echo '                                                        </select>' . "\n" . '                                                    </div>' . "\n" . '                                                </div>' . "\n" . '                                                <div id="package_info" style="display: none;">' . "\n" . '                                                    <div class="form-group row mb-4">' . "\n" . '                                                        <label class="col-md-4 col-form-label" for="package_cost">Package Cost</label>' . "\n" . '                                                        <div class="col-md-2">' . "\n" . '                                                            <input readonly type="text" class="form-control text-center" id="package_cost" name="package_cost" value="">' . "\n" . '                                                        </div>' . "\n" . '                                                        <label class="col-md-3 col-form-label" for="package_duration">Duration</label>' . "\n" . '                                                        <div class="col-md-3">' . "\n" . '                                                            <input readonly type="text" class="form-control text-center" id="package_duration" name="package_duration" value="">' . "\n" . '                                                        </div>' . "\n" . '                                                    </div>' . "\n" . '                                                </div>' . "\n" . '                                                <div class="form-group row mb-4" id="package_warning" style="display:none;">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="max_connections">Warning Notice</label>' . "\n" . '                                                    <div class="col-md-8">' . "\n" . '                                                        <div class="alert alert-warning" role="alert">' . "\n" . '                                                            The package you have selected is incompatible with the existing package. This could be due to the number of connections or other restrictions.<br/><br/>You can still upgrade to this package, however the time added will be from today and not from the end of the original package.' . "\n" . '                                                        </div>' . "\n" . '                                                    </div>' . "\n" . '                                                </div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="max_connections">Max Connections</label>' . "\n" . '                                                    <div class="col-md-2">' . "\n" . '                                                        <input readonly type="text" class="form-control text-center" id="max_connections" name="max_connections" value="';

	if (isset($rLine)) {
		echo htmlspecialchars($rLine['max_connections']);
	} else {
		echo '1';
	}

	echo '">' . "\n" . '                                                    </div>' . "\n" . '                                                    <label class="col-md-3 col-form-label" for="exp_date">Expiration Date</label>' . "\n" . '                                                    <div class="col-md-3">' . "\n" . '                                                        <input readonly type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="';

	if (!isset($rLine)) {
	} else {
		if (!is_null($rLine['exp_date'])) {
			echo date('Y-m-d H:i', $rLine['exp_date']);
		} else {
			echo '" disabled="disabled';
		}
	}

	echo '">' . "\n" . '                                                    </div>' . "\n" . '                                                </div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="contact">Contact Email</label>' . "\n" . '                                                    <div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="contact" name="contact" value="';

	if (!isset($rLine)) {
	} else {
		echo htmlspecialchars($rLine['contact']);
	}

	echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="reseller_notes">Reseller Notes</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3" placeholder="">';

	if (!isset($rLine)) {
	} else {
		echo htmlspecialchars($rLine['reseller_notes']);
	}

	echo '</textarea>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    ';

	if (!$rPermissions['allow_restrictions']) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="restrictions">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="ip_field">Allowed IP Addresses</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="ip_field" class="form-control" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="allowed_ips">&nbsp;</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="allowed_ips" name="allowed_ips[]" size=6 class="form-control" multiple="multiple">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

		if (!isset($rLine)) {
		} else {
			foreach (json_decode($rLine['allowed_ips'], true) as $rIP) {
				echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
				echo $rIP;
				echo '">';
				echo $rIP;
				echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
			}
		}

		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="ua_field">Allowed User-Agents</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="ua_field" class="form-control" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" id="add_ua" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" id="remove_ua" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="allowed_ua">&nbsp;</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="allowed_ua" name="allowed_ua[]" size=6 class="form-control" multiple="multiple">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

		if (!isset($rLine)) {
		} else {
			foreach (json_decode($rLine['allowed_ua'], true) as $rUA) {
				echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
				echo $rUA;
				echo '">';
				echo $rUA;
				echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
			}
		}

		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="bypass_ua">Bypass UA Restrictions</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="bypass_ua" id="bypass_ua" type="checkbox" ';

		if (!isset($rLine)) {
		} else {
			if ($rLine['bypass_ua'] != 1) {
			} else {
				echo 'checked ';
			}
		}

		echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="is_isplock">Lock to ISP</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="is_isplock" id="is_isplock" type="checkbox" ';

		if (!isset($rLine)) {
		} else {
			if ($rLine['is_isplock'] != 1) {
			} else {
				echo 'checked ';
			}
		}

		echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="isp_clear">Current ISP</label>' . "\n" . '                                                    <div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="';

		if (!isset($rLine)) {
		} else {
			echo htmlspecialchars($rLine['isp_desc']);
		}

		echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" onclick="clearISP()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    ';
	}

	echo '                                    <div class="tab-pane" id="review-purchase">' . "\n" . '                                        <div class="row">' . "\n" . '                                            <div class="col-12">' . "\n" . '                                                <div class="alert alert-danger" role="alert" style="display:none;" id="no-credits">' . "\n" . '                                                    <i class="mdi mdi-block-helper mr-2"></i> You do not have enough credits to complete this transaction!' . "\n" . '                                                </div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <table class="table table-striped table-borderless" id="credits-cost">' . "\n" . '                                                        <thead>' . "\n" . '                                                            <tr>' . "\n" . '                                                                <th class="text-center">Total Credits</th>' . "\n" . '                                                                <th class="text-center">Purchase Cost</th>' . "\n" . '                                                                <th class="text-center">Remaining Credits</th>' . "\n" . '                                                            </tr>' . "\n" . '                                                        </thead>' . "\n" . '                                                        <tbody>' . "\n" . '                                                            <tr>' . "\n" . '                                                                <td class="text-center">';
	echo number_format($rUserInfo['credits'], 0);
	echo '</td>' . "\n" . '                                                                <td class="text-center" id="cost_credits">0</td>' . "\n" . '                                                                <td class="text-center" id="remaining_credits">';
	echo number_format($rUserInfo['credits'], 0);
	echo '</td>' . "\n" . '                                                            </tr>' . "\n" . '                                                        </tbody>' . "\n" . '                                                    </table>' . "\n" . '                                                    <table id="datatable-review" class="table table-striped table-borderless dt-responsive nowrap" style="margin-top:30px;">' . "\n" . '                                                        <thead>' . "\n" . '                                                            <tr>' . "\n" . '                                                                <th class="text-center"></th>' . "\n" . '                                                                <th>';
	echo $_['bouquet_name'];
	echo '</th>' . "\n" . '                                                                <th class="text-center">';
	echo $_['streams'];
	echo '</th>' . "\n" . '                                                                <th class="text-center">';
	echo $_['movies'];
	echo '</th>' . "\n" . '                                                                <th class="text-center">';
	echo $_['series'];
	echo '</th>' . "\n" . '                                                                <th class="text-center">';
	echo $_['stations'];
	echo '</th>' . "\n" . '                                                            </tr>' . "\n" . '                                                        </thead>' . "\n" . '                                                        <tbody></tbody>' . "\n" . '                                                    </table>' . "\n" . '                                                </div>' . "\n" . '                                            </div> <!-- end col -->' . "\n" . '                                        </div> <!-- end row -->' . "\n" . '                                        <ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n" . '                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n" . '                                            </li>' . "\n" . '                                            <li class="next list-inline-item float-right">' . "\n" . '                                                <input name="submit_line" id="submit_button" type="submit" class="btn btn-primary purchase" value="Purchase" />' . "\n" . '                                            </li>' . "\n" . '                                        </ul>' . "\n" . '                                    </div>' . "\n\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n" . '                ';
}

echo "\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php';
