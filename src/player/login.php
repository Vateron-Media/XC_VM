<?php

$rSkipVerify = true;
include 'functions.php';

if (!file_exists('install.php') || file_exists('config.php') || extension_loaded('xc_vm')) {
} else {
	header('Location: install.php');
}

destroySession();
define('CLIENT_INVALID', 0);
define('CLIENT_IS_E2', 1);
define('CLIENT_IS_MAG', 2);
define('CLIENT_IS_STALKER', 3);
define('CLIENT_EXPIRED', 4);
define('CLIENT_BANNED', 5);
define('CLIENT_DISABLED', 6);
define('CLIENT_DISALLOWED', 7);
$rErrors = array('Invalid username or password.', 'Enigma lines are not permitted here.', 'MAG lines are not permitted here.', 'Stalker lines are not permitted here.', 'Your line has expired.', 'Your line has been banned.', 'Your line has been disabled.', 'You are not allowed to access this player.');

if (empty(CoreUtilities::$rRequest['username']) && empty(CoreUtilities::$rRequest['password'])) {
} else {
	$rIP = CoreUtilities::getUserIP();
	$rCountryCode = (PLATFORM == 'xc_vm' ? CoreUtilities::getIPInfo($rIP)['country']['iso_code'] : null);
	$rUserInfo = CoreUtilities::getUserInfo(null, CoreUtilities::$rRequest['username'], CoreUtilities::$rRequest['password'], true);
	$rDeny = true;
	$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));

	if ($rUserInfo) {
		if (!$rUserInfo['is_e2']) {


			if (!$rUserInfo['is_mag']) {


				if (!$rUserInfo['is_stalker']) {


					if (is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time()) {


						if ($rUserInfo['admin_enabled'] != 0) {


							if ($rUserInfo['enabled'] != 0) {


								$rDeny = false;

								if (empty($rUserInfo['allowed_ips']) || in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {


									if (empty($rCountryCode)) {
									} else {
										$rForceCountry = !empty($rUserInfo['forced_country']);

										if (!($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country'])) {


											if ($rForceCountry || in_array('ALL', CoreUtilities::$rSettings['allow_countries']) || in_array($rCountryCode, CoreUtilities::$rSettings['allow_countries'])) {
											} else {
												$_STATUS = CLIENT_DISALLOWED;
											}
										} else {
											$_STATUS = CLIENT_DISALLOWED;
										}
									}

									if (empty($rUserInfo['allowed_ua']) || in_array($rUserAgent, $rUserInfo['allowed_ua'])) {


										if (!$rUserInfo['isp_violate']) {


											if (!$rUserInfo['isp_is_server'] || $rUserInfo['is_restreamer']) {


												$_SESSION['phash'] = $rUserInfo['id'];
												$_SESSION['pverify'] = md5($rUserInfo['username'] . '||' . $rUserInfo['password']);
												header('Location: index.php');
											} else {
												$_STATUS = CLIENT_DISALLOWED;
											}
										} else {
											$_STATUS = CLIENT_DISALLOWED;
										}
									} else {
										$_STATUS = CLIENT_DISALLOWED;
									}
								} else {
									$_STATUS = CLIENT_DISALLOWED;
								}
							} else {
								$_STATUS = CLIENT_DISABLED;
							}
						} else {
							$_STATUS = CLIENT_BANNED;
						}
					} else {
						$_STATUS = CLIENT_EXPIRED;
					}
				} else {
					$_STATUS = CLIENT_IS_STALKER;
				}
			} else {
				$_STATUS = CLIENT_IS_MAG;
			}
		} else {
			$_STATUS = CLIENT_IS_E2;
		}
	} else {
		$_STATUS = CLIENT_INVALID;
	}

	if (!$rDeny) {
	} else {
		CoreUtilities::checkFlood();
	}
}

echo '<!DOCTYPE html>' . "\r\n" . '<html lang="en">' . "\r\n" . '<head>' . "\r\n\t" . '<meta charset="utf-8">' . "\r\n\t" . '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">' . "\r\n\t" . '<link rel="stylesheet" href="css/bootstrap-reboot.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="css/bootstrap-grid.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="css/default-skin.css">' . "\r\n\t" . '<link rel="stylesheet" href="css/main.css">' . "\r\n\t" . '<link rel="shortcut icon" href="img/favicon.ico">' . "\r\n\t" . '<title>';
echo CoreUtilities::$rSettings['server_name'];
echo '</title>' . "\r\n" . '</head>' . "\r\n" . '<body class="body" style="padding-bottom: 0 !important;">' . "\r\n\t" . '<div class="sign">' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<div class="sign__content">' . "\r\n" . '                        ';

if (file_exists('install.php')) {
	echo '                        <div class="alert bg-animate" style="color: #fff;padding-top: 80px; padding-bottom: 80px;">' . "\r\n" . '                            Installation has been completed!<br/><br/>Please delete <strong>install.php</strong> to continue.' . "\r\n" . '                        </div>' . "\r\n" . '                        ';
} else {
	echo "\t\t\t\t\t\t" . '<form action="./login.php" class="sign__form" method="post">' . "\r\n\t\t\t\t\t\t\t" . '<span class="sign__logo">' . "\r\n" . '                                <img src="img/logo.png" alt="" height="80px">' . "\r\n" . '                            </span>' . "\r\n\t\t\t\t\t\t\t" . '<div class="sign__group">' . "\r\n\t\t\t\t\t\t\t\t" . '<input type="text" name="username" class="sign__input" placeholder="Username">' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="sign__group">' . "\r\n\t\t\t\t\t\t\t\t" . '<input type="password" name="password" class="sign__input" placeholder="Password">' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n" . '                            ';

	if (!isset($_STATUS)) {
	} else {
		echo '                            <div class="alert alert-danger">' . "\r\n" . '                                ';
		echo $rErrors[$_STATUS];
		echo '                            </div>' . "\r\n" . '                            ';
	}

	echo '                            <button class="sign__btn" type="submit">LOGIN</button>' . "\r\n\t\t\t\t\t\t" . '</form>' . "\r\n" . '                        ';
}


echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n\t" . '<script src="js/jquery-3.5.1.min.js"></script>' . "\r\n\t" . '<script src="js/bootstrap.bundle.min.js"></script>' . "\r\n" . '</body>' . "\r\n" . '</html>';
