<?php

echo '<!DOCTYPE html>' . "\r\n" . '<html lang="en">' . "\r\n" . '<head>' . "\r\n\t" . '<meta charset="utf-8">' . "\r\n\t" . '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">' . "\r\n\t" . '<link rel="stylesheet" href="./css/bootstrap-reboot.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/bootstrap-grid.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/owl.carousel.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/jquery.mcustomscrollbar.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/nouislider.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/ionicons.min.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/photoswipe.css">' . "\r\n" . '    <link rel="stylesheet" href="./css/glightbox.css">' . "\r\n\t" . '<link rel="stylesheet" href="./css/default-skin.css">' . "\r\n" . '    <link rel="stylesheet" href="./css/jBox.all.min.css">' . "\r\n" . '    <link rel="stylesheet" href="./css/select2.min.css">' . "\r\n" . '    <link rel="stylesheet" href="./css/listings.css">' . "\r\n" . '    <link rel="stylesheet" href="./css/main.css">' . "\r\n" . '    <link rel="shortcut icon" href="img/favicon.ico">' . "\r\n\t" . '<title>';
echo (CoreUtilities::$rSettings['server_name'] ?: 'XC_VM Web Player');
echo ' - ';
echo $_TITLE;
echo '</title>' . "\r\n" . '    <style>' . "\r\n" . '    .seasons__cover {' . "\r\n" . '        filter: blur(';
echo (intval(CoreUtilities::$rSettings['player_blur']) ?: 0);
echo 'px) !important;' . "\r\n" . '        opacity: ';
echo (intval(CoreUtilities::$rSettings['player_opacity']) ?: 10);
echo '%;' . "\r\n" . '    }' . "\r\n" . '    .details__bg {' . "\r\n" . '        filter: blur(';
echo (intval(CoreUtilities::$rSettings['player_blur']) ?: 0);
echo 'px) !important;' . "\r\n" . '        opacity: ';
echo (intval(CoreUtilities::$rSettings['player_opacity']) ?: 10);
echo '%;' . "\r\n" . '    }' . "\r\n" . '    .home__bg {' . "\r\n" . '        filter: blur(';
echo (intval(CoreUtilities::$rSettings['player_blur']) ?: 0);
echo 'px) !important;' . "\r\n" . '        opacity: ';
echo (intval(CoreUtilities::$rSettings['player_opacity']) ?: 10);
echo '%;' . "\r\n" . '    }' . "\r\n" . '    </style>' . "\r\n" . '</head>' . "\r\n" . '<body class="body">' . "\r\n\t" . '<header class="header">' . "\r\n" . '        <div class="navbar-overlay bg-animate"></div>' . "\r\n\t\t" . '<div class="header__wrap">' . "\r\n\t\t\t" . '<div class="container">' . "\r\n\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t" . '<div class="header__content">' . "\r\n\t\t\t\t\t\t\t" . '<a class="header__logo" href="index.php">' . "\r\n\t\t\t\t\t\t\t\t" . '<img src="img/logo.png" alt="" height="48px">' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t\t" . '<ul class="header__nav">' . "\r\n\t\t\t\t\t\t\t\t" . '<li class="header__nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<a href="./index.php" class="header__nav-link';

if ($_PAGE != 'index') {
} else {
	echo ' header__nav-link--active';
}

echo '">Home</a>' . "\r\n\t\t\t\t\t\t\t\t" . '</li>' . "\r\n" . '                                ';

if (!(in_array(1, $rUserInfo['allowed_outputs']) && !CoreUtilities::$rSettings['disable_hls'])) {
} else {
	echo "\t\t\t\t\t\t\t\t" . '<li class="header__nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<a href="./live.php" class="header__nav-link';

	if ($_PAGE != 'live') {
	} else {
		echo ' header__nav-link--active';
	}

	echo '">Live TV</a>' . "\r\n\t\t\t\t\t\t\t\t" . '</li>' . "\r\n" . '                                ';
}

echo "\t\t\t\t\t\t\t\t" . '<li class="header__nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<a href="./movies.php" class="header__nav-link';

if ($_PAGE != 'movies') {
} else {
	echo ' header__nav-link--active';
}

echo '">Movies</a>' . "\r\n\t\t\t\t\t\t\t\t" . '</li>' . "\r\n" . '                                <li class="header__nav-item">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<a href="./series.php" class="header__nav-link';

if ($_PAGE != 'series') {
} else {
	echo ' header__nav-link--active';
}

echo '">TV Series</a>' . "\r\n\t\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t" . '<div class="header__auth">' . "\r\n\t\t\t\t\t\t\t\t" . '<button class="header__search-btn" type="button">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<i class="icon ion-ios-search"></i>' . "\r\n\t\t\t\t\t\t\t\t" . '</button>' . "\r\n" . '                                <a href="profile.php">' . "\r\n" . '                                    <button class="header__signout-btn" type="button">' . "\r\n" . '                                        <i class="icon ion-ios-person"></i>' . "\r\n" . '                                    </button>' . "\r\n" . '                                </a>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<button class="header__btn" type="button">' . "\r\n\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t" . '</button>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t\t" . '<form action="index" class="header__search" method="POST">' . "\r\n\t\t\t" . '<div class="container">' . "\r\n\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t" . '<div class="header__search-content">' . "\r\n\t\t\t\t\t\t\t" . '<input name="search" id="search__input" type="text" placeholder="Search...">' . "\r\n" . '                            <select name="type" id="search__select">' . "\r\n" . '                                <option value="live"';

if ($_PAGE != 'live') {
} else {
	echo ' selected';
}

echo '>Live TV</option>' . "\r\n" . '                                <option value="movies"';

if (!in_array($_PAGE, array('movies', 'movie'))) {
} else {
	echo ' selected';
}

echo '>Movies</option>' . "\r\n" . '                                <option value="series"';

if (!in_array($_PAGE, array('series', 'episodes'))) {
} else {
	echo ' selected';
}

echo '>TV Series</option>' . "\r\n" . '                            </select>' . "\r\n\t\t\t\t\t\t\t" . '<button type="button" id="search__button">' . "\r\n" . '                                <strong><i class="icon ion-ios-search"></i></strong>' . "\r\n" . '                            </button>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</form>' . "\r\n\t" . '</header>';
