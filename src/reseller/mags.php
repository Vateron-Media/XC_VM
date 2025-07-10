<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

$_TITLE = 'MAG Devices';
include 'header.php';
echo '<div class="wrapper">' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">';
echo $_['mag_devices'];
echo '</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\r\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\r\n" . '                        <span aria-hidden="true">&times;</span>' . "\r\n" . '                    </button>' . "\r\n" . '                    Device has been added / modified.' . "\r\n" . '                </div>' . "\r\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\r\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\r\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\r\n" . '                            <div class="col-md-3">' . "\r\n" . '                                <input type="text" class="form-control" id="mag_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="';
echo $_['search_devices'];
echo '...">' . "\r\n" . '                            </div>' . "\r\n" . '                            <label class="col-md-2 col-form-label text-center" for="mag_reseller">';
echo $_['filter_results'];
echo '</label>' . "\r\n" . '                            <div class="col-md-3">' . "\r\n" . '                                <select id="mag_reseller" class="form-control" data-toggle="select2">' . "\r\n" . '                                    <optgroup label="Global">' . "\r\n" . '                                        <option value=""';

if (isset(CoreUtilities::$rRequest['owner'])) {
} else {
	echo ' selected';
}

echo '>All Owners</option>' . "\r\n" . '                                        <option value="';
echo $rUserInfo['id'];
echo '"';

if (!(isset(CoreUtilities::$rRequest['owner']) && CoreUtilities::$rRequest['owner'] == $rUserInfo['id'])) {
} else {
	echo ' selected';
}

echo '>My Devices</option>' . "\r\n" . '                                    </optgroup>' . "\r\n" . '                                    ';

if (0 >= count($rPermissions['direct_reports'])) {
} else {
	echo '                                    <optgroup label="Direct Reports">' . "\r\n" . '                                        ';

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
		echo '</option>' . "\r\n" . '                                        ';
	}
	echo '                                    </optgroup>' . "\r\n" . '                                    ';
}

if (count($rPermissions['direct_reports']) >= count($rPermissions['all_reports'])) {
} else {
	echo '                                    <optgroup label="Indirect Reports">' . "\r\n" . '                                        ';

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
			echo '</option>' . "\r\n" . '                                            ';
		}
	}
	echo '                                    </optgroup>' . "\r\n" . '                                    ';
}

echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="col-md-2">' . "\r\n" . '                                <select id="mag_filter" class="form-control" data-toggle="select2">' . "\r\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['filter'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['no_filter'];
echo '</option>' . "\r\n" . '                                    <option value="1"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['active'];
echo '</option>' . "\r\n" . '                                    <option value="2"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['disabled'];
echo '</option>' . "\r\n" . '                                    <option value="3"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['expired'];
echo '</option>' . "\r\n" . '                                    <option value="4"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['trial'];
echo '</option>' . "\r\n" . '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                            <label class="col-md-1 col-form-label text-center" for="mag_show_entries">';
echo $_['show'];
echo '</label>' . "\r\n" . '                            <div class="col-md-1">' . "\r\n" . '                                <select id="mag_show_entries" class="form-control" data-toggle="select2">' . "\r\n" . '                                    ';

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
	echo '</option>' . "\r\n" . '                                    ';
}
echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                        </div>' . "\r\n\t\t\t\t\t\t" . '<table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\r\n\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['id'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['username'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['mac_address'];
echo '</th>' . "\r\n" . '                                    <th class="text-center">Device</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['owner'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['status'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['online'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['trial'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['expiration'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['actions'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\r\n\t\t\t\t\t\t" . '</table>' . "\r\n\r\n\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t" . '</div> ' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
include 'footer.php';
