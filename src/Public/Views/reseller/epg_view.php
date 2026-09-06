<?php

/**
 * Reseller TV Guide / EPG grid (Bootstrap 5). Full-parity port of admin/epg_view.php
 * adapted to the reseller contract. The grid, day/time navigation and settings are
 * rendered by the legacy client-side engine in assets/old/js/listings.js
 * (window.XC_VM.Listings.Grid / .Nav / .Settings) — that engine is NOT
 * reimplemented, so its exact DOM (the .listings-grid-container tree with its
 * js-listings-* hooks) and its assets/old/css/listings.css stylesheet are kept
 * verbatim. Only the surrounding chrome (title row, filter card, programme modal,
 * feedback) is rebuilt for the new-UI shell. The GET filter form's field name=/id=
 * are preserved because they drive both the query and the grid.
 *
 * Data contract is unchanged from the legacy reseller view: the controller supplies
 * $rStreamIDs / $rCount / $rPageInt / $rPages / $rLimit / $rPagination /
 * $rCategories, and the grid engine pulls programmes from the reseller api
 * (action=get_epg) with per-programme detail from action=get_programme.
 *
 * Reseller difference vs admin: no "Record" button in the programme modal — the
 * reseller panel has no `record` route, so recording is not offered (the reseller
 * get_programme response still carries `available`, but it is not acted on here).
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;

?>

<!-- The listings grid engine (kept below) ships its own stylesheet; the new-UI
     shell does not load it, so pull it in on this page only (fonts/icons are
     embedded as data-URIs inside the file). -->
<link href="assets/old/css/listings.css" rel="stylesheet" type="text/css" />

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><?= $language::get('tv_guide') ?></h4>
</div>

<form method="GET" action="epg_view">
    <div class="card mb-3">
        <div class="card-body">
            <div id="collapse_filters" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="search"><?= $language::get('search') ?></label>
                    <input type="text" class="form-control" id="search" name="search" value="<?php echo RequestManager::has('search') ? htmlspecialchars(RequestManager::get('search')) : ''; ?>" placeholder="<?= $language::get('search_streams_placeholder') ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="category"><?= $language::get('category') ?></label>
                    <select id="category" name="category" class="form-select">
                        <option value="" <?php if (!RequestManager::has('category')) {
                                                echo ' selected';
                                            } ?>><?php echo $language::get('all_categories'); ?></option>
                        <?php foreach ($rCategories as $rCategory) { ?>
                            <option value="<?php echo intval($rCategory['id']); ?>" <?php if (RequestManager::has('category') && RequestManager::get('category') == $rCategory['id']) {
                                                                                        echo ' selected';
                                                                                    } ?>><?php echo htmlspecialchars((string) $rCategory['category_name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="sort"><?= $language::get('sort') ?></label>
                    <select id="sort" name="sort" class="form-select">
                        <?php foreach (array('' => $language::get('sort_default'), 'name' => $language::get('sort_alphabetical'), 'added' => $language::get('sort_date_added')) as $rSort => $rText) { ?>
                            <option value="<?php echo $rSort; ?>" <?php if (RequestManager::has('sort') && RequestManager::get('sort') == $rSort) {
                                                                        echo ' selected';
                                                                    } ?>><?php echo $rText; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="entries"><?= $language::get('show') ?></label>
                    <select id="entries" name="entries" class="form-select">
                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                            <option value="<?php echo $rShow; ?>" <?php if ($rLimit == $rShow) {
                                                                        echo ' selected';
                                                                    } ?>><?php echo $rShow; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><?= $language::get('search') ?></button>
                        <button type="button" onClick="clearForm()" class="btn btn-label-warning" title="<?= $language::get('clear') ?>"><i class="icon-base ti tabler-filter-off"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if (0 < count($rStreamIDs)) { ?>
    <div class="card mb-3">
        <div class="card-body">
            <!-- Grid DOM required by XC_VM.Listings.Grid/Nav/Settings (assets/old/js/listings.js) — kept verbatim. -->
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
                            <a class="listings-now-btn js-now-btn" href="#"><?= $language::get('now') ?></a>
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
        </div>
    </div>
    <?php if (1 < $rPages) { ?>
        <nav aria-label="TV Guide pagination">
            <ul class="pagination justify-content-center">
                <?php if (1 < $rPageInt) { ?>
                    <li class="page-item">
                        <a class="page-link" href="epg_view?search=<?php echo urlencode(RequestManager::get('search') ?: '') ?>&category=<?php echo intval(RequestManager::get('category') ?: '') ?>&sort=<?php echo urlencode(RequestManager::get('sort') ?: '') ?>&entries=<?php echo intval(RequestManager::get('entries') ?: '') ?>&page=<?php echo ($rPageInt - 1) ?>"><i class="icon-base ti tabler-chevron-left"></i></a>
                    </li>
                <?php } ?>
                <?php foreach ($rPagination as $i) { ?>
                    <li class="page-item<?php echo ($rPageInt == $i ? ' active' : '') ?>">
                        <a class="page-link" href="epg_view?search=<?php echo urlencode(RequestManager::get('search') ?: '') ?>&category=<?php echo intval(RequestManager::get('category') ?: '') ?>&sort=<?php echo urlencode(RequestManager::get('sort') ?: '') ?>&entries=<?php echo intval(RequestManager::get('entries') ?: '') ?>&page=<?php echo $i ?>"><?php echo $i ?></a>
                    </li>
                <?php } ?>
                <?php if ($rPageInt < $rPages) { ?>
                    <li class="page-item">
                        <a class="page-link" href="epg_view?search=<?php echo urlencode(RequestManager::get('search') ?: '') ?>&category=<?php echo intval(RequestManager::get('category') ?: '') ?>&sort=<?php echo urlencode(RequestManager::get('sort') ?: '') ?>&entries=<?php echo intval(RequestManager::get('entries') ?: '') ?>&page=<?php echo ($rPageInt + 1) ?>"><i class="icon-base ti tabler-chevron-right"></i></a>
                    </li>
                <?php } ?>
            </ul>
        </nav>
    <?php } ?>
<?php } else { ?>
    <div class="alert alert-warning" role="alert">
        <?= $language::get('no_live_streams_found') ?>
    </div>
<?php } ?>

<!-- Programme detail modal (BS5). Content ids are set by showGuide(). -->
<div class="modal fade" id="programmeModal" tabindex="-1" aria-labelledby="programmeLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="programmeLabel"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-body-secondary mb-2"><i class="icon-base ti tabler-clock me-1"></i><span id="programmeStart"></span></p>
                <p class="mb-0" id="programmeDescription"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('close') ?></button>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    function selectChannel(rID) {
        window.location.href = "stream_view?id=" + rID;
    }

    function clearForm() {
        window.location.href = "epg_view";
    }

    // Reseller programme detail. No "Record" button — the reseller panel has no
    // record route, so only the programme title / time / description are shown.
    function showGuide(rID, rStreamID) {
        $("#programmeLabel").html("");
        $("#programmeDescription").html("");
        $("#programmeStart").html("");
        $.getJSON("./api?action=get_programme&id=" + rID + "&stream_id=" + rStreamID + "&timezone=" + Intl.DateTimeFormat().resolvedOptions().timeZone, function(data) {
            if (data.result == true) {
                $("#programmeLabel").html(data.data.title);
                $("#programmeDescription").html(data.data.description);
                $("#programmeStart").html(data.data.date);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('programmeModal')).show();
            }
        });
    }

    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });

        window.XC_VM.Listings.DefaultChannels = "<?php echo implode(',', $rStreamIDs); ?>";
        <?php if (RequestManager::has('category') && 0 < intval(RequestManager::get('category'))) { ?>
            window.XC_VM.Listings.Category = <?php echo intval(RequestManager::get('category')); ?>;
        <?php } ?>

        XC_VM.Listings.Settings.init();
        XC_VM.Listings.Grid.init();
        XC_VM.Listings.Nav.init();

        <?php if (SettingsManager::get('enable_search')): ?>
            if (typeof initSearch === 'function') {
                initSearch();
            }
        <?php endif; ?>
    });
</script>
<script src="assets/old/js/listings.js"></script>
</body>

</html>
