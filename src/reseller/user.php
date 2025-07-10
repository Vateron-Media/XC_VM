<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

if (!(isset(CoreUtilities::$rRequest['id']) && (!($rUser = getRegisteredUser(CoreUtilities::$rRequest['id'])) || CoreUtilities::$rRequest['id'] == $rUserInfo['id']))) {
} else {
	goHome();
}

$_TITLE = 'User';
include 'header.php';
echo '<div class="wrapper boxed-layout">' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rUser)) {
	echo 'Edit';
} else {
	echo 'Add';
}

echo ' User</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n" . '                ';

if (!isset($rUser) || in_array($rUser['id'], $rPermissions['direct_reports'])) {
} else {
	$rOwner = getRegisteredUser($rUser['owner_id']);
	echo '                <div class="alert alert-info" role="alert">' . "\n" . "                    This user does not directly report to you, although you have the right to edit this user you should notify the user's parent <strong><a href=\"user?id=";
	echo $rOwner['id'];
	echo '">';
	echo $rOwner['username'];
	echo '</a></strong> when doing so.' . "\n" . '                </div>' . "\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\n\t\t\t\t\t\t\t";

if (!isset($rUser)) {
} else {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
	echo intval($rUser['id']);
	echo '" />' . "\n\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Details</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";

if (isset($rUser)) {
} else {
	echo '                                    <li class="nav-item">' . "\n" . '                                        <a href="#review-purchase" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n" . '                                            <i class="mdi mdi-book-open-variant mr-1"></i>' . "\n" . '                                            <span class="d-none d-sm-inline">Review Purchase</span>' . "\n" . '                                        </a>' . "\n" . '                                    </li>' . "\n" . '                                    ';
}

echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="user-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="username">Username</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input ';

if ($rPermissions['allow_change_username'] || !isset($rUser)) {
} else {
	echo 'disabled ';
}

echo 'type="text" class="form-control" id="username" name="username" value="';

if (isset($rUser)) {
	echo htmlspecialchars($rUser['username']);
} else {
	echo ($rPermissions['allow_change_username'] ? generateString(10) : '');
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';

if (!$rPermissions['allow_change_password'] && isset($rUser)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="password">';

	if (!isset($rUser)) {
	} else {
		echo 'Change ';
	}

	echo 'Password</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n" . '                                                        <input type="text" class="form-control" id="password" name="password"';

	if (!isset($rUser)) {
	} else {
		echo ' placeholder="Enter a new password here to change it"';
	}

	echo ' value="';
	echo (isset($rUser) ? '' : ($rPermissions['allow_change_username'] ? generateString((10 < CoreUtilities::$rSettings['pass_length'] ? CoreUtilities::$rSettings['pass_length'] : 10)) : ''));
	echo '" data-indicator="pwindicator">' . "\n" . '                                                        <div id="pwindicator">' . "\n" . '                                                            <div class="bar"></div>' . "\n" . '                                                            <div class="label"></div>' . "\n" . '                                                        </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
}

if (!isset($rUser)) {
} else {
	if (($rKey = array_search($rUser['id'], $rPermissions['all_reports'])) === false) {
	} else {
		unset($rPermissions['all_reports'][$rKey]);
	}

	if (($rKey = array_search($rUser['id'], $rPermissions['direct_reports'])) === false) {
	} else {
		unset($rPermissions['direct_reports'][$rKey]);
	}
}

if (0 >= count($rPermissions['all_reports'])) {
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="owner_id">Owner</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="owner_id" id="owner_id" class="form-control select2" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<optgroup label="Myself">' . "\n" . '                                                                <option value="';
	echo $rUserInfo['id'];
	echo '"';

	if (!(isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserInfo['id'])) {
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

			if (!(isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserID)) {
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

				if (!(isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserID)) {
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

if (1 >= count($rPermissions['subresellers'])) {
} else {
	echo '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="member_group_id">Member Group</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="member_group_id" id="member_group_id" class="form-control select2" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	foreach (getMemberGroups() as $rGroup) {
		if (in_array($rGroup['group_id'], $rPermissions['subresellers'])) {
			echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

			if (!isset($rUser)) {
			} else {
				if (intval($rUser['member_group_id']) != intval($rGroup['group_id'])) {
				} else {
					echo 'selected ';
				}
			}

			echo 'value="';
			echo intval($rGroup['group_id']);
			echo '">';
			echo $rGroup['group_name'];
			echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
		}
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                ';
}

echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="email">Email Address</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="email" id="email" class="form-control" name="email" value="';

if (!isset($rUser)) {
} else {
	echo htmlspecialchars($rUser['email']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="reseller_dns">Custom DNS</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="reseller_dns" name="reseller_dns" value="';

if (!isset($rUser)) {
} else {
	echo htmlspecialchars($rUser['reseller_dns']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="notes">Notes</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="notes" name="notes" class="form-control" rows="3" placeholder="">';

if (!isset($rUser)) {
} else {
	echo $rUser['notes'];
}

echo '</textarea>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            ';

if (isset($rUser)) {
	echo '                                            <li class="list-inline-item float-right">' . "\n" . '                                                <input name="submit_user" type="submit" class="btn btn-primary purchase" value="Edit" />' . "\n" . '                                            </li>' . "\n" . '                                            ';
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                            ';
}

echo "\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";

if (isset($rUser)) {
} else {
	echo '                                    <div class="tab-pane" id="review-purchase">' . "\n" . '                                        <div class="row">' . "\n" . '                                            <div class="col-12">' . "\n" . '                                                ';

	if ($rUserInfo['credits'] - $rPermissions['create_sub_resellers_price'] >= 0) {
	} else {
		echo '                                                <div class="alert alert-danger" role="alert" id="no-credits">' . "\n" . '                                                    <i class="mdi mdi-block-helper mr-2"></i> You do not have enough credits to complete this transaction!' . "\n" . '                                                </div>' . "\n" . '                                                ';
	}

	echo '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <table class="table table-striped table-borderless" id="credits-cost">' . "\n" . '                                                        <thead>' . "\n" . '                                                            <tr>' . "\n" . '                                                                <th class="text-center">Total Credits</th>' . "\n" . '                                                                <th class="text-center">Purchase Cost</th>' . "\n" . '                                                                <th class="text-center">Remaining Credits</th>' . "\n" . '                                                            </tr>' . "\n" . '                                                        </thead>' . "\n" . '                                                        <tbody>' . "\n" . '                                                            <tr>' . "\n" . '                                                                <td class="text-center">';
	echo number_format($rUserInfo['credits'], 0);
	echo '</td>' . "\n" . '                                                                <td class="text-center" id="cost_credits">';
	echo number_format($rPermissions['create_sub_resellers_price'], 0);
	echo '</td>' . "\n" . '                                                                <td class="text-center" id="remaining_credits">';
	echo number_format($rUserInfo['credits'] - $rPermissions['create_sub_resellers_price'], 0);
	echo '</td>' . "\n" . '                                                            </tr>' . "\n" . '                                                        </tbody>' . "\n" . '                                                    </table>' . "\n" . '                                                </div>' . "\n" . '                                            </div>' . "\n" . '                                        </div>' . "\n" . '                                        <ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n" . '                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n" . '                                            </li>' . "\n" . '                                            <li class="list-inline-item float-right">' . "\n" . '                                                <input ';

	if ($rUserInfo['credits'] - $rPermissions['create_sub_resellers_price'] >= 0) {
	} else {
		echo 'disabled ';
	}

	echo 'name="submit_user" type="submit" class="btn btn-primary purchase" value="Purchase" />' . "\n" . '                                            </li>' . "\n" . '                                        </ul>' . "\n" . '                                    </div>' . "\n" . '                                    ';
}

echo "\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div> ' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php';
