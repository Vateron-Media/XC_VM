<?php
/**
 * EPG preview — clean view template.
 * Variables from controller: $rStreamIDs, $rCount, $rPageInt, $rPages, $rLimit, $rPagination, $rCategories
 * ViewGlobals: $rRequest, $language
 */

/** Helper: build pagination URL preserving current filters */
$epgUrl = function ($page) use ($rRequest) {
    return 'epg_view?search=' . urlencode($rRequest['search'] ?? '')
         . '&category=' . (isset($rRequest['category']) ? intval($rRequest['category']) : '')
         . '&sort=' . urlencode($rRequest['sort'] ?? '')
         . '&entries=' . (isset($rRequest['entries']) ? intval($rRequest['entries']) : '')
         . '&page=' . $page;
};
?>
<div class="wrapper "<?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'): ?> style="display: none;"<?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title">TV Guide</h4>
                </div>
                <form method="GET" action="epg_view">
                    <div class="card">
                        <div class="card-body">
                            <div id="collapse_filters" class="form-group row" style="margin-bottom: 0;">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" id="search" name="search" value="<?= isset($rRequest['search']) ? htmlspecialchars($rRequest['search']) : '' ?>" placeholder="Search Streams...">
                                </div>
                                <div class="col-md-3">
                                    <select id="category" name="category" class="form-control" data-toggle="select2">
                                        <option value=""<?= !isset($rRequest['category']) ? ' selected' : '' ?>><?= $language::get('all_categories') ?></option>
                                        <?php foreach ($rCategories as $rCategory): ?>
                                        <option value="<?= intval($rCategory['id']) ?>"<?= isset($rRequest['category']) && $rRequest['category'] == $rCategory['id'] ? ' selected' : '' ?>><?= htmlspecialchars($rCategory['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="col-md-1 col-form-label text-center" for="sort">Sort</label>
                                <div class="col-md-1">
                                    <select id="sort" name="sort" class="form-control" data-toggle="select2">
                                        <?php foreach (['' => 'Default', 'name' => 'A to Z', 'added' => 'Date Added'] as $rSort => $rText): ?>
                                        <option value="<?= $rSort ?>"<?= isset($rRequest['sort']) && $rRequest['sort'] == $rSort ? ' selected' : '' ?>><?= $rText ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="col-md-1 col-form-label text-center" for="entries">Show</label>
                                <div class="col-md-1">
                                    <select id="entries" name="entries" class="form-control" data-toggle="select2">
                                        <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                        <option<?= $rLimit == $rShow ? ' selected' : '' ?> value="<?= $rShow ?>"><?= $rShow ?></option>
                                        <?php endforeach; ?>
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
        <div class="row">
            <div class="col-12">
                <?php if (count($rStreamIDs) > 0): ?>
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
                    <?php if ($rPages > 1): ?>
                    <ul class="paginator">
                        <?php if ($rPageInt > 1): ?>
                        <li class="paginator__item paginator__item--prev">
                            <a href="<?= $epgUrl($rPageInt - 1) ?>"><i class="mdi mdi-chevron-left"></i></a>
                        </li>
                        <?php endif; ?>
                        <?php if ($rPagination[0] > 1): ?>
                        <li class="paginator__item<?= $rPageInt == 1 ? ' paginator__item--active' : '' ?>"><a href="<?= $epgUrl(1) ?>">1</a></li>
                        <?php if (count($rPagination) > 1): ?>
                        <li class="paginator__item"><a href="javascript: void(0);">...</a></li>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php foreach ($rPagination as $i): ?>
                        <li class="paginator__item<?= $rPageInt == $i ? ' paginator__item--active' : '' ?>"><a href="<?= $epgUrl($i) ?>"><?= $i ?></a></li>
                        <?php endforeach; ?>
                        <?php if ($rPagination[count($rPagination) - 1] < $rPages): ?>
                        <?php if (count($rPagination) > 1): ?>
                        <li class="paginator__item"><a href="javascript: void(0);">...</a></li>
                        <?php endif; ?>
                        <li class="paginator__item<?= $rPageInt == $rPages ? ' paginator__item--active' : '' ?>"><a href="<?= $epgUrl($rPages) ?>"><?= $rPages ?></a></li>
                        <?php endif; ?>
                        <?php if ($rPageInt < $rPages): ?>
                        <li class="paginator__item paginator__item--next">
                            <a href="<?= $epgUrl($rPageInt + 1) ?>"><i class="mdi mdi-chevron-right"></i></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    No Live Streams or Programmes have been found matching your search terms.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
