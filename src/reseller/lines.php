<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Lines';
include 'header.php';
echo '<div class="wrapper">' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Lines</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    Line has been added / modified.' . "\n" . '                </div>' . "\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <input type="text" class="form-control" id="user_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="Search Lines...">' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-2 col-form-label text-center" for="user_reseller">Filter Results</label>' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <select id="user_reseller" class="form-control" data-toggle="select2">' . "\n" . '                                    <optgroup label="Global">' . "\n" . '                                        <option value=""';

if (isset(CoreUtilities::$rRequest['owner'])) {
} else {
	echo ' selected';
}

echo '>All Owners</option>' . "\n" . '                                        <option value="';
echo $rUserInfo['id'];
echo '"';

if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserInfo['id'])) {
} else {
	echo ' selected';
}

echo '>My Lines</option>' . "\n" . '                                    </optgroup>' . "\n" . '                                    ';

if (0 >= count($rPermissions['direct_reports'])) {
} else {
	echo '                                    <optgroup label="Direct Reports">' . "\n" . '                                        ';

	foreach ($rPermissions['direct_reports'] as $rUserID) {
		$rRegisteredUser = $rPermissions['users'][$rUserID];
		echo '                                        <option value="';
		echo $rUserID;
		echo '"';

		if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserID)) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rRegisteredUser['username'];
		echo '</option>' . "\n" . '                                        ';
	}
	echo '                                    </optgroup>' . "\n" . '                                    ';
}

if (count($rPermissions['direct_reports']) >= count($rPermissions['all_reports'])) {
} else {
	echo '                                    <optgroup label="Indirect Reports">' . "\n" . '                                        ';

	foreach ($rPermissions['all_reports'] as $rUserID) {
		if (in_array($rUserID, $rPermissions['direct_reports'])) {
		} else {
			$rRegisteredUser = $rPermissions['users'][$rUserID];
			echo '                                            <option value="';
			echo $rUserID;
			echo '"';

			if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserID)) {
			} else {
				echo ' selected';
			}

			echo '>';
			echo $rRegisteredUser['username'];
			echo '</option>' . "\n" . '                                            ';
		}
	}
	echo '                                    </optgroup>' . "\n" . '                                    ';
}

echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="user_filter" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['filter'])) {
} else {
	echo ' selected';
}

echo '>No Filter</option>' . "\n" . '                                    <option value="1"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
} else {
	echo ' selected';
}

echo '>Active</option>' . "\n" . '                                    <option value="2"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
} else {
	echo ' selected';
}

echo '>Disabled</option>' . "\n" . '                                    <option value="3"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3)) {
} else {
	echo ' selected';
}

echo '>Banned</option>' . "\n" . '                                    <option value="4"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
} else {
	echo ' selected';
}

echo '>Expired</option>' . "\n" . '                                    <option value="5"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5)) {
} else {
	echo ' selected';
}

echo '>Trial</option>' . "\n" . '                                    ' . "\n" . '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-1 col-form-label text-center" for="user_show_entries">Show</label>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="user_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo '                                    <option';

	if (isset(CoreUtilities::$rRequest['entries'])) {
		if (CoreUtilities::$rRequest['entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	} else {
		if ($rSettings['default_entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n\t\t\t\t\t\t" . '<table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Username</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Password</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Owner</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Status</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Online</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Trial</th>' . "\n" . '                                    <th class="text-center">Active</th>' . "\n" . '                                    <th class="text-center">Connections</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Expiration</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Last Connection</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php';
