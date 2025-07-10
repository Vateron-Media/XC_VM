<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['line'])) {
} else {
	if (hasPermissions('line', CoreUtilities::$rRequest['line'])) {
		$rSearchLine = getUser(CoreUtilities::$rRequest['line']);
	} else {
		exit();
	}
}

if (!isset(CoreUtilities::$rRequest['stream'])) {
} else {
	$rSearchStream = getStream(CoreUtilities::$rRequest['stream']);
}

$_TITLE = 'Live Connections';
include 'header.php';
echo '<div class="wrapper">' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">';
echo $_['live_connections'];
echo '</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="card">' . "\r\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\r\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t";

if (CoreUtilities::$rSettings['redis_handler']) {
	echo '                            <div class="col-md-4">' . "\r\n" . '                                <select id="live_stream" class="form-control" data-toggle="select2">' . "\r\n" . '                                    ';

	if (!isset($rSearchStream)) {
	} else {
		echo '                                    <option value="';
		echo intval($rSearchStream['id']);
		echo '" selected="selected">';
		echo $rSearchStream['stream_display_name'];
		echo '</option>' . "\r\n" . '                                    ';
	}

	echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="col-md-3">' . "\r\n" . '                                <select id="live_line" class="form-control" data-toggle="select2">' . "\r\n" . '                                    ';

	if (!isset($rSearchLine)) {
	} else {
		echo '                                    <option value="';
		echo intval($rSearchLine['id']);
		echo '" selected="selected">';
		echo $rSearchLine['username'];
		echo '</option>' . "\r\n" . '                                    ';
	}

	echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="col-md-3">' . "\r\n" . '                                <select id="live_user" class="form-control" data-toggle="select2">' . "\r\n" . '                                    <optgroup label="Global">' . "\r\n" . '                                        <option value=""';

	if (isset(CoreUtilities::$rRequest['user'])) {
	} else {
		echo ' selected';
	}

	echo '>All Users</option>' . "\r\n" . '                                        <option value="';
	echo $rUserInfo['id'];
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['user']) && CoreUtilities::$rRequest['user'] == $rUserInfo['id'])) {
	} else {
		echo ' selected';
	}

	echo '>My Lines</option>' . "\r\n" . '                                    </optgroup>' . "\r\n" . '                                    ';

	if (0 >= count($rPermissions['direct_reports'])) {
	} else {
		echo '                                    <optgroup label="Direct Reports">' . "\r\n" . '                                        ';

		foreach ($rPermissions['direct_reports'] as $rUserID) {
			$rRegisteredUser = $rPermissions['users'][$rUserID];
			echo '                                        <option value="';
			echo $rUserID;
			echo '"';

			if (!(isset(CoreUtilities::$rRequest['user']) && CoreUtilities::$rRequest['user'] == $rUserID)) {
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

				if (!(isset(CoreUtilities::$rRequest['user']) && CoreUtilities::$rRequest['user'] == $rUserID)) {
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

	echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n\t\t\t\t\t\t\t";
} else {
	echo "\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n" . '                                <input type="text" class="form-control" id="live_search" value="';

	if (!isset(CoreUtilities::$rRequest['search'])) {
	} else {
		echo htmlspecialchars(CoreUtilities::$rRequest['search']);
	}

	echo '" placeholder="';
	echo $_['search_logs'];
	echo '...">' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="col-md-3">' . "\r\n" . '                                <select id="live_stream" class="form-control" data-toggle="select2">' . "\r\n" . '                                    ';

	if (!isset($rSearchStream)) {
	} else {
		echo '                                    <option value="';
		echo intval($rSearchStream['id']);
		echo '" selected="selected">';
		echo $rSearchStream['stream_display_name'];
		echo '</option>' . "\r\n" . '                                    ';
	}

	echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="col-md-2">' . "\r\n" . '                                <select id="live_line" class="form-control" data-toggle="select2">' . "\r\n" . '                                    ';

	if (!isset($rSearchLine)) {
	} else {
		echo '                                    <option value="';
		echo intval($rSearchLine['id']);
		echo '" selected="selected">';
		echo $rSearchLine['username'];
		echo '</option>' . "\r\n" . '                                    ';
	}

	echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="col-md-2">' . "\r\n" . '                                <select id="live_user" class="form-control" data-toggle="select2">' . "\r\n" . '                                    <optgroup label="Global">' . "\r\n" . '                                        <option value=""';

	if (isset(CoreUtilities::$rRequest['user'])) {
	} else {
		echo ' selected';
	}

	echo '>All Users</option>' . "\r\n" . '                                        <option value="';
	echo $rUserInfo['id'];
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['user']) && CoreUtilities::$rRequest['user'] == $rUserInfo['id'])) {
	} else {
		echo ' selected';
	}

	echo '>My Lines</option>' . "\r\n" . '                                    </optgroup>' . "\r\n" . '                                    ';

	if (0 >= count($rPermissions['direct_reports'])) {
	} else {
		echo '                                    <optgroup label="Direct Reports">' . "\r\n" . '                                        ';

		foreach ($rPermissions['direct_reports'] as $rUserID) {
			$rRegisteredUser = $rPermissions['users'][$rUserID];
			echo '                                        <option value="';
			echo $rUserID;
			echo '"';

			if (!(isset(CoreUtilities::$rRequest['user']) && CoreUtilities::$rRequest['user'] == $rUserID)) {
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

				if (!(isset(CoreUtilities::$rRequest['user']) && CoreUtilities::$rRequest['user'] == $rUserID)) {
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

	echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n\t\t\t\t\t\t\t";
}

echo '                            <label class="col-md-1 col-form-label text-center" for="live_show_entries">';
echo $_['show'];
echo '</label>' . "\r\n" . '                            <div class="col-md-1">' . "\r\n" . '                                <select id="live_show_entries" class="form-control" data-toggle="select2">' . "\r\n" . '                                    ';

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo '                                    <option';

	if ($rSettings['default_entries'] != $rShow) {
	} else {
		echo ' selected';
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\r\n" . '                                    ';
}
echo '                                </select>' . "\r\n" . '                            </div>' . "\r\n" . '                        </div>' . "\r\n\t\t\t\t\t\t" . '<table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">' . "\r\n\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n" . '                                    <th class="text-center">ID</th>' . "\r\n" . '                                    <th class="text-center">Quality</th>' . "\r\n" . '                                    <th>Line</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th>Stream</th>' . "\r\n" . '                                    <th>Player</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th>ISP</th>' . "\r\n" . '                                    <th class="text-center">IP</th>' . "\r\n" . '                                    <th class="text-center">Duration</th>' . "\r\n" . '                                    <th class="text-center">Output</th>' . "\r\n" . '                                    <th class="text-center">';
echo $_['actions'];
echo '</th>' . "\r\n\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\r\n\t\t\t\t\t\t" . '</table>' . "\r\n\r\n\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t" . '</div> ' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
include 'footer.php';
