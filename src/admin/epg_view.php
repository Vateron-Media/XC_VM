<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if ($rMobile) {
    header('Location: dashboard');
    exit;
}

$rPageInt = max(intval(CoreUtilities::$rRequest['page']), 1);
$rLimit = max(intval(CoreUtilities::$rRequest['entries']), CoreUtilities::$rSettings['default_entries']);
$rStart = ($rPageInt - 1) * $rLimit;
$rWhere = $rWhereV = array();
$rWhere[] = '`type` = 1 AND `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL';

if (isset(CoreUtilities::$rRequest['category']) && intval(CoreUtilities::$rRequest['category']) > 0) {
    $rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
    $rWhereV[] = json_encode(intval(CoreUtilities::$rRequest['category']));
}

if (!empty(CoreUtilities::$rRequest['search'])) {
    $rWhere[] = '(`stream_display_name` LIKE ? OR `id` LIKE ?)';
    $rWhereV[] = '%' . CoreUtilities::$rRequest['search'] . '%';
    $rWhereV[] = CoreUtilities::$rRequest['search'];
}

$rWhereString = (count($rWhere) > 0) ? 'WHERE ' . implode(' AND ', $rWhere) : '';

$rOrderBy = '`stream_display_name` ASC';
if (!empty(CoreUtilities::$rRequest['sort']) && in_array(CoreUtilities::$rRequest['sort'], array('name', 'added'))) {
    $rOrderBy = $rOrder[CoreUtilities::$rRequest['sort']];
}

$rStreamIDs = array();
$db->query('SELECT COUNT(`id`) AS `count` FROM `streams` ' . $rWhereString . ';', ...$rWhereV);
$rCount = $db->get_row()['count'];
$db->query('SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';', ...$rWhereV);

foreach ($db->get_rows() as $rRow) {
    $rStreamIDs[] = $rRow['id'];
}
$rPages = ceil($rCount / $rLimit);
$rPagination = array();

foreach (range(max($rPageInt - 2, 1), min($rPageInt + 2, $rPages)) as $i) {
    $rPagination[] = $i;
}
$_TITLE = 'TV Guide';
include 'header.php';
?>
<div class="wrapper " <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">TV Guide</h4>
                </div>
                <form method="GET" action="epg_view">
                    <div class="card">
                        <div class="card-body">
                            <div id="collapse_filters" class="form-group row" style="margin-bottom: 0;">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" id="search" name="search" value="<?php echo isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : ''; ?>" placeholder="Search Streams...">
                                </div>
                                <div class="col-md-3">
                                    <select id="category" name="category" class="form-control" data-toggle="select2">
                                        <option value="" <?php if (!isset(CoreUtilities::$rRequest['category'])) {
                                                                echo ' selected';
                                                            } ?>><?php echo $_['all_categories']; ?></option>
                                        <?php foreach (getCategories('live') as $rCategory) { ?>
                                            <option value="<?php echo intval($rCategory['id']); ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                        echo ' selected';
                                                                                                    } ?>><?php echo $rCategory['category_name']; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select id="sort" name="sort" class="form-control" data-toggle="select2">
                                        <?php foreach (array('' => 'Default Sort', 'name' => 'Alphabetical', 'added' => 'Date Added') as $rSort => $rText) { ?>
                                            <option value="<?php echo $rSort; ?>" <?php if (isset(CoreUtilities::$rRequest['sort']) && CoreUtilities::$rRequest['sort'] == $rSort) {
                                                                                        echo ' selected';
                                                                                    } ?>><?php echo $rText; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <label class="col-md-1 col-form-label text-center" for="user_show_entries">Show</label>
                                <div class="col-md-1">
                                    <select id="entries" name="entries" class="form-control" data-toggle="select2">
                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                            <option value="<?php echo $rShow; ?>" <?php if ($rLimit == $rShow) {
                                                                                        echo ' selected';
                                                                                    } ?>><?php echo $rShow; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="btn-group col-md-2">
                                    <button type="submit" class="btn btn-info">Search</button>
                                    <button type="button" onClick="clearForm()" class="btn btn-warning"><i class="mdi mdi-filter-remove"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php if (0 < count($rStreamIDs)) { ?>
            <div class="listings-grid-container">
                <a href="#" class="listings-direction-link left day-nav-arrow js-day-nav-arrow" data-direction="prev"><span class="isvg isvg-left-dir"></span></a>
                <a href="#" class="listings-direction-link right day-nav-arrow js-day-nav-arrow" data-direction="next"><span class="isvg isvg-right-dir"></span></a>
                <div class="listings-day-slider-wrapper">
                    <div class="listings-day-slider js-listings-day-slider">
                        <div class="js-listings-day-nav-inner"></div>
                    </div>
                </div>
                <div class="js-billboard-fix-point"></div>
                <div class="listings-grid-inner">
                    <div class="time-nav-bar cf js-time-nav-bar">
                        <div class="listings-mobile-nav">
                            <a class="listings-now-btn js-now-btn" href="#">NOW</a>
                        </div>
                        <div class="listings-times-wrapper">
                            <a href="#" class="listings-direction-link left js-time-nav-arrow" data-direction="prev"><span class="isvg isvg-left-dir text-white"></span></a>
                            <a href="#" class="listings-direction-link right js-time-nav-arrow" data-direction="next"><span class="isvg isvg-right-dir text-white"></span></a>
                            <div class="times-slider js-times-slider"></div>
                        </div>
                        <div class="listings-loader js-listings-loader"><span class="isvg isvg-loader animate-spin"></span></div>
                    </div>
                    <div class="listings-wrapper cf js-listings-wrapper">
                        <div class="listings-timeline js-listings-timeline"></div>
                        <div class="js-listings-container"></div>
                    </div>
                </div>
            </div>
            <?php if (1 < $rPages) { ?>
                <ul class="paginator">
                    <?php if (1 < $rPageInt) { ?>
                        <li class="paginator__item paginator__item--prev">
                            <a href="epg_view?search=<?php echo urlencode(CoreUtilities::$rRequest['search'] ?: '') ?>&category=<?php echo intval(CoreUtilities::$rRequest['category'] ?: '') ?>&sort=<?php echo urlencode(CoreUtilities::$rRequest['sort'] ?: '') ?>&entries=<?php echo intval(CoreUtilities::$rRequest['entries'] ?: '') ?>&page=<?php echo ($rPageInt - 1) ?>"><i class="mdi mdi-chevron-left"></i></a>
                        </li>
                    <?php } ?>
                    <?php foreach ($rPagination as $i) { ?>
                        <li class="paginator__item<?php echo ($rPageInt == $i ? ' paginator__item--active' : '') ?>">
                            <a href="epg_view?search=<?php echo urlencode(CoreUtilities::$rRequest['search'] ?: '') ?>&category=<?php echo intval(CoreUtilities::$rRequest['category'] ?: '') ?>&sort=<?php echo urlencode(CoreUtilities::$rRequest['sort'] ?: '') ?>&entries=<?php echo intval(CoreUtilities::$rRequest['entries'] ?: '') ?>&page=<?php echo $i ?>"><?php echo $i ?></a>
                        </li>
                    <?php } ?>
                    <?php if ($rPageInt < $rPages) { ?>
                        <li class="paginator__item paginator__item--next">
                            <a href="epg_view?search=<?php echo urlencode(CoreUtilities::$rRequest['search'] ?: '') ?>&category=<?php echo intval(CoreUtilities::$rRequest['category'] ?: '') ?>&sort=<?php echo urlencode(CoreUtilities::$rRequest['sort'] ?: '') ?>&entries=<?php echo intval(CoreUtilities::$rRequest['entries'] ?: '') ?>&page=<?php echo ($rPageInt + 1) ?>"><i class="mdi mdi-chevron-right"></i></a>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>
        <?php } else { ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
                No Live Streams or Programmes have been found matching your search terms.
            </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo "\t\t\r\n\t\t" . 'function selectChannel(rID) {' . "\r\n\t\t\t" . 'navigate("stream_view?id=" + rID);' . "\r\n\t\t" . '}' . "\r\n\t\t\r\n\t\t" . 'function clearForm() {' . "\r\n\t\t\t" . 'window.location.href = "epg_view";' . "\r\n\t\t" . '}' . "\r\n\t\t\r\n\t\t" . 'function showGuide(rID, rStreamID) {' . "\r\n\t\t\t" . '$("#programmeLabel").html("");' . "\r\n\t\t\t" . '$("#programmeDescription").html("");' . "\r\n\t\t\t" . '$("#programmeStart").html("");' . "\r\n" . '            $("#programmeRecord").unbind();' . "\r\n\t\t\t" . '$.getJSON("./api?action=get_programme&id=" + rID + "&stream_id=" + rStreamID + "&timezone=" + Intl.DateTimeFormat().resolvedOptions().timeZone, function(data) {' . "\r\n\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t" . '$("#programmeLabel").html(data.data.title);' . "\r\n\t\t\t\t\t" . '$("#programmeDescription").html(data.data.description);' . "\r\n\t\t\t\t\t" . '$("#programmeStart").html(data.data.date)' . "\r\n\t\t\t\t\t" . '$(".bs-programme").modal("show");' . "\r\n" . '                    if (data.available) {' . "\r\n" . '                        $("#programmeRecord").click(function() {' . "\r\n" . '                            navigate("record?id=" + rStreamID + "&programme=" + rID);' . "\r\n" . '                        });' . "\r\n" . '                        $("#programmeRecord").show();' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#programmeRecord").hide();' . "\r\n" . '                    }' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t\r\n\t\t\t" . 'window.XC_VM.Listings.DefaultChannels = "';
		echo implode(',', $rStreamIDs);
		echo '";' . "\r\n\t\t\t";

		if (isset(CoreUtilities::$rRequest['category']) && 0 < intval(CoreUtilities::$rRequest['category'])) {
			echo "\t\t\t" . 'window.XC_VM.Listings.Category = ';
			echo intval(CoreUtilities::$rRequest['category']);
			echo ';' . "\r\n\t\t\t";
		}

		echo "\t\t\t\r\n\t\t\t" . 'XC_VM.Listings.Settings.init();' . "\r\n\t\t\t" . 'XC_VM.Listings.Grid.init();' . "\r\n\t\t\t" . 'XC_VM.Listings.Nav.init();' . "\r\n\t\t" . '});' . "\r\n\t\t\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>