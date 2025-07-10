<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Movies';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext">' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">';
echo $_['movies'];
echo '</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-5">' . "\n" . '                                <input type="text" class="form-control" id="movies_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="';
echo $_['search_movies'];
echo '...">' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-4">' . "\n" . '                                <select id="movies_category_id" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['category'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['all_categories'];
echo '</option>' . "\n" . '                                    ';

foreach (getCategories('movie') as $rCategory) {
	if (!in_array($rCategory['id'], $rPermissions['category_ids'])) {
	} else {
		echo '                                    <option value="';
		echo $rCategory['id'];
		echo '"';

		if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id'])) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rCategory['category_name'];
		echo '</option>' . "\n" . '                                    ';
	}
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-1 col-form-label text-center" for="movies_show_entries">';
echo $_['show'];
echo '</label>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="movies_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

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
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n\t\t\t\t\t\t" . '<table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Image</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Name</th>' . "\n" . '                                    <th>Category</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Connections</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Kill</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php';
