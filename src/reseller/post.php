<?php

echo ' ';
$rICount = count(get_included_files());
include 'session.php';
include 'functions.php';
session_start();
session_write_close();
$_PAGE = getPageName();
$_ERRORS = array();

foreach (get_defined_constants(true)['user'] as $rKey => $rValue) {
	if (substr($rKey, 0, 7) != 'STATUS_') {
	} else {
		$_ERRORS[intval($rValue)] = $rKey;
	}
}

if (1 < $rICount) {
	echo '<script>' . "\r\n" . 'var rCurrentPage = "';
	echo $_PAGE;
	echo '";' . "\r\n" . 'var rErrors = ';
	echo json_encode($_ERRORS);
	echo ';' . "\r\n" . 'function submitForm(rType, rData, rCallback=callbackForm) {' . "\r\n" . '    $.ajax({' . "\r\n" . '        type: "POST",' . "\r\n" . '        url: "post.php?action=" + encodeURIComponent(rType),' . "\r\n" . '        data: rData,' . "\r\n" . '        processData: false,' . "\r\n" . '        contentType: false,' . "\r\n" . '        success: function(rReturn) {' . "\r\n" . '            try {' . "\r\n" . '                var rJSON = $.parseJSON(rReturn);' . "\r\n" . '            } catch (e) {' . "\r\n" . '                var rJSON = {"status": 0, "result": false};' . "\r\n" . '              }' . "\r\n" . '            rCallback(rJSON);' . "\r\n" . '        }' . "\r\n" . '    });' . "\r\n" . '}' . "\r\n" . 'function callbackForm(rData) {' . "\r\n" . '    if (rData.location) {' . "\r\n" . '        if (rData.reload) {' . "\r\n" . '            window.location.href = rData.location;' . "\r\n" . '        } else {' . "\r\n" . '            navigate(rData.location);' . "\r\n" . '        }' . "\r\n" . '    } else {' . "\r\n" . "        \$(':input[type=\"submit\"]').prop('disabled', false);" . "\r\n\r\n" . '        switch (window.rCurrentPage) {' . "\r\n" . '            case "edit_profile":' . "\r\n" . '                switch (window.rErrors[rData.status]) {' . "\r\n" . '                    case "STATUS_INVALID_EMAIL":' . "\r\n" . '                        showError("Please enter a valid email address.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    case "STATUS_INVALID_PASSWORD":' . "\r\n" . '                        showError("Your password must be at least ';
	echo CoreUtilities::$rSettings['pass_length'];
	echo ' characters long.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    default:' . "\r\n" . '                        showError("An error occured while processing your request.");' . "\r\n" . '                        break;' . "\r\n" . '                }' . "\r\n" . '                break;' . "\r\n\r\n" . '            case "mag":' . "\r\n" . '            case "enigma":' . "\r\n" . '                switch (window.rErrors[rData.status]) {' . "\r\n" . '                    case "STATUS_INVALID_TYPE":' . "\r\n" . '                        showError("This package is not supported.");' . "\r\n" . '                        break;' . "\r\n" . '                    ' . "\r\n" . '                    case "STATUS_NO_TRIALS":' . "\r\n" . '                        showError("You cannot generate trials at this time.");' . "\r\n" . '                        break;' . "\r\n" . '                        ' . "\r\n" . '                    case "STATUS_INSUFFICIENT_CREDITS":' . "\r\n" . '                        showError("You do not have enough credits to make this purchase.");' . "\r\n" . '                        break;' . "\r\n" . '                        ' . "\r\n" . '                    case "STATUS_INVALID_PACKAGE":' . "\r\n" . '                        showError("Please select a valid package.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    case "STATUS_INVALID_MAC":' . "\r\n" . '                        showError("Please enter a valid MAC address.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    case "STATUS_EXISTS_MAC":' . "\r\n" . '                        showError("The MAC address you entered is already in use.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    default:' . "\r\n" . '                        showError("An error occured while processing your request.");' . "\r\n" . '                        break;' . "\r\n" . '                }' . "\r\n" . '                break;' . "\r\n\r\n" . '            case "ticket":' . "\r\n" . '                switch (window.rErrors[rData.status]) {' . "\r\n" . '                    case "STATUS_INVALID_DATA":' . "\r\n" . '                        showError("Please ensure you enter both a title and message.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    default:' . "\r\n" . '                        showError("An error occured while processing your request.");' . "\r\n" . '                        break;' . "\r\n" . '                }' . "\r\n" . '                break;' . "\r\n\r\n" . '            case "line":' . "\r\n" . '                switch (window.rErrors[rData.status]) {' . "\r\n" . '                    case "STATUS_INVALID_TYPE":' . "\r\n" . '                        showError("This package is not supported.");' . "\r\n" . '                        break;' . "\r\n" . '                    ' . "\r\n" . '                    case "STATUS_NO_TRIALS":' . "\r\n" . '                        showError("You cannot generate trials at this time.");' . "\r\n" . '                        break;' . "\r\n" . '                        ' . "\r\n" . '                    case "STATUS_INSUFFICIENT_CREDITS":' . "\r\n" . '                        showError("You do not have enough credits to make this purchase.");' . "\r\n" . '                        break;' . "\r\n" . '                        ' . "\r\n" . '                    case "STATUS_INVALID_PACKAGE":' . "\r\n" . '                        showError("Please select a valid package.");' . "\r\n" . '                        break;' . "\r\n" . '                        ' . "\r\n" . '                    case "STATUS_INVALID_USERNAME":' . "\r\n" . '                        showError("Username is too short! It must be at least ';
	echo $rPermissions['minimum_username_length'];
	echo ' characters long.");' . "\r\n" . '                        break;' . "\r\n" . '                        ' . "\r\n" . '                    case "STATUS_INVALID_PASSWORD":' . "\r\n" . '                        showError("Password is too short! It must be at least ';
	echo $rPermissions['minimum_password_length'];
	echo ' characters long.");' . "\r\n" . '                        break;                    ' . "\r\n\r\n" . '                    case "STATUS_EXISTS_USERNAME":' . "\r\n" . '                        showError("The username you selected already exists. Please use another.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    default:' . "\r\n" . '                        showError("An error occured while processing your request.");' . "\r\n" . '                        break;' . "\r\n" . '                }' . "\r\n" . '                break;' . "\r\n\r\n" . '            case "user":' . "\r\n" . '                switch (window.rErrors[rData.status]) {' . "\r\n" . '                    case "STATUS_INVALID_PASSWORD":' . "\r\n" . '                        showError("Password is too short! It must be at least ';
	echo $rPermissions['minimum_password_length'];
	echo ' characters long.");' . "\r\n" . '                        break;' . "\r\n" . '                    ' . "\r\n" . '                    case "STATUS_INVALID_USERNAME":' . "\r\n" . '                        showError("Username is too short! It must be at least ';
	echo $rPermissions['minimum_username_length'];
	echo ' characters long.");' . "\r\n" . '                        break;' . "\r\n" . '                    ' . "\r\n" . '                    case "STATUS_INSUFFICIENT_CREDITS":' . "\r\n" . '                        showError("You do not have enough credits to make this purchase.");' . "\r\n" . '                        break;' . "\r\n" . '                    ' . "\r\n" . '                    case "STATUS_INVALID_SUBRESELLER":' . "\r\n" . '                        showError("You are not set up to create subresellers. Please open a ticket.");' . "\r\n" . '                        break;' . "\r\n" . '                    ' . "\r\n" . '                    case "STATUS_EXISTS_USERNAME":' . "\r\n" . '                        showError("The username you selected already exists. Please use another.");' . "\r\n" . '                        break;' . "\r\n\r\n" . '                    default:' . "\r\n" . '                        showError("An error occured while processing your request.");' . "\r\n" . '                        break;' . "\r\n" . '                }' . "\r\n" . '                break;' . "\r\n\r\n" . '            default:' . "\r\n" . '                showError("An error occured while processing your request.");' . "\r\n" . '                break;' . "\r\n" . '        }' . "\r\n" . '    }' . "\r\n" . '}' . "\r\n" . '</script>' . "\r\n";
} else {
	$rAction = CoreUtilities::$rRequest['action'];
	$rData = CoreUtilities::$rRequest;
	unset($rData['action']);

	if (count($rData) != 0) {
	} else {
		$rData = json_decode(file_get_contents('php://input'), true);
	}

	if (!$rData) {
		echo json_encode(array('result' => false));

		exit();
	}

	switch ($rAction) {
		case 'edit_profile':
			$rReturn = ResellerAPI::editResellerProfile($rData);
			setcookie('hue', $rData['hue'], time() + 315360000);
			setcookie('theme', $rData['theme'], time() + 315360000);

			if ($rReturn['status'] == STATUS_SUCCESS) {
				echo json_encode(array('result' => true, 'location' => 'edit_profile?status=' . intval($rReturn['status']), 'status' => $rReturn['status'], 'reload' => true));

				exit();
			}

			echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));

			exit();

		case 'line':
			$rReturn = ResellerAPI::processLine($rData);

			if ($rReturn['status'] == STATUS_SUCCESS) {
				echo json_encode(array('result' => true, 'location' => 'lines?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

				exit();
			}

			echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));

			exit();

		case 'mag':
			$rReturn = ResellerAPI::processMAG($rData);

			if ($rReturn['status'] == STATUS_SUCCESS) {
				echo json_encode(array('result' => true, 'location' => 'mags?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

				exit();
			}

			echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));

			exit();

		case 'enigma':
			$rReturn = ResellerAPI::processEnigma($rData);

			if ($rReturn['status'] == STATUS_SUCCESS) {
				echo json_encode(array('result' => true, 'location' => 'enigmas?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

				exit();
			}

			echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));

			exit();

		case 'ticket':
			$rReturn = ResellerAPI::submitTicket($rData);

			if ($rReturn['status'] == STATUS_SUCCESS) {
				echo json_encode(array('result' => true, 'location' => 'ticket_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

				exit();
			}

			echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));

			exit();

		case 'user':
			$rReturn = ResellerAPI::processUser($rData);

			if ($rReturn['status'] == STATUS_SUCCESS) {
				echo json_encode(array('result' => true, 'location' => 'users?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

				exit();
			}

			echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));

			exit();
	}
}
