<?php
if (!isset($__viewMode)):

	include 'session.php';
	include 'functions.php';

	if (checkResellerPermissions()) {
	} else {
		goHome();
	}

	$_TITLE = 'Episodes';
	require_once __DIR__ . '/../public/Views/layouts/admin.php';
	renderUnifiedLayoutHeader('reseller');
endif;
echo '<div class="wrapper boxed-layout-ext">' . "\n" . '    <div class="container-fluid">' . "\n" . '        <div class="row">' . "\n" . '            <div class="col-12">' . "\n" . '                <div class="page-title-box">' . "\n" . '                    <div class="page-title-right">' . "\n" . '                        ';
include __DIR__ . '/topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n" . '                    <h4 class="page-title">';
echo $language::get('episodes');
echo '</h4>' . "\n" . '                </div>' . "\n" . '            </div>' . "\n" . '        </div>     ' . "\n" . '        <div class="row">' . "\n" . '            <div class="col-12">' . "\n" . '                <div class="card">' . "\n" . '                    <div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <input type="text" class="form-control" id="episodes_search" value="';

if (!isset(RequestManager::getAll()['search'])) {
} else {
	echo htmlspecialchars(RequestManager::getAll()['search']);
}

echo '" placeholder="';
echo $language::get('search_episodes');
echo '...">' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <select id="episodes_series" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(RequestManager::getAll()['series'])) {
} else {
	echo ' selected';
}

echo '>';
echo $language::get('all_series');
echo '</option>' . "\n" . '                                    ';

foreach (SeriesService::getList() as $rSeriesArr) {
	if (!in_array($rSeriesArr['id'], $rPermissions['series_ids'])) {
	} else {
		echo '                                    <option value="';
		echo $rSeriesArr['id'];
		echo '"';

		if (!(isset(RequestManager::getAll()['series']) && RequestManager::getAll()['series'] == $rSeriesArr['id'])) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rSeriesArr['title'];
		echo '</option>' . "\n" . '                                    ';
	}
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-3">' . "\n" . '                                <select id="series_category_id" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(RequestManager::getAll()['category'])) {
} else {
	echo ' selected';
}

echo '>';
echo $language::get('all_categories');
echo '</option>' . "\n" . '                                    ';

foreach (CategoryService::getAllByType('series') as $rCategory) {
	if (!in_array($rCategory['id'], $rPermissions['category_ids'])) {
	} else {
		echo '                                    <option value="';
		echo $rCategory['id'];
		echo '"';

		if (!(isset(RequestManager::getAll()['category']) && RequestManager::getAll()['category'] == $rCategory['id'])) {
		} else {
			echo ' selected';
		}

		echo '>';
		echo $rCategory['category_name'];
		echo '</option>' . "\n" . '                                    ';
	}
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-1 col-form-label text-center" for="episodes_show_entries">';
echo $language::get('show');
echo '</label>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="episodes_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo '                                    <option';

	if (isset(RequestManager::getAll()['entries'])) {
		if (RequestManager::getAll()['entries'] != $rShow) {
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
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                        <table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\n" . '                            <thead>' . "\n" . '                                <tr>' . "\n" . '                                    <th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Image</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Name</th>' . "\n" . '                                    <th>Category</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Connections</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Kill</th>' . "\n" . '                                </tr>' . "\n" . '                            </thead>' . "\n" . '                            <tbody></tbody>' . "\n" . '                        </table>' . "\n" . '                    </div> ' . "\n" . '                </div> ' . "\n" . '            </div>' . "\n" . '        </div>' . "\n" . '    </div>' . "\n" . '</div>' . "\n";
require_once __DIR__ . '/../public/Views/layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
