<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rCategories = getCategories('series');
$_TITLE = 'TV Series';
include 'header.php';
?>

<div class="wrapper"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">TV Series</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Series has been added / modified.
                    </div>
                <?php elseif (isset($_STATUS) && $_STATUS == STATUS_SUCCESS_MULTI): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Series are being imported in the background, series that can't be matched will not be added. Check
                        Watch Folder logs for information.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php if ($rMobile) echo 'collapse'; ?>">
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="series_search"
                                    value="<?php if (isset(CoreUtilities::$rRequest['search'])) echo htmlspecialchars(CoreUtilities::$rRequest['search']); ?>"
                                    placeholder="Search Series...">
                            </div>
                            <div class="col-md-3">
                                <select id="series_category_id" class="form-control" data-toggle="select2">
                                    <option value="">All Categories</option>
                                    <option value="-1"
                                        <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1) echo 'selected'; ?>>
                                        No TMDb Match</option>
                                    <option value="-2"
                                        <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -2) echo 'selected'; ?>>
                                        No Categories</option>
                                    <?php foreach ($rCategories as $rCategory): ?>
                                        <option value="<?= $rCategory['id']; ?>"
                                            <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) echo 'selected'; ?>>
                                            <?= $rCategory['category_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="series_show_entries">Show</label>
                            <div class="col-md-2">
                                <select id="series_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                        <option value="<?= $rShow; ?>"
                                            <?php if ((isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || (!isset(CoreUtilities::$rRequest['entries']) && $rSettings['default_entries'] == $rShow)) echo 'selected'; ?>>
                                            <?= $rShow; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-streampage"
                            class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Image</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th class="text-center">Seasons</th>
                                    <th class="text-center">Episodes</th>
                                    <th class="text-center">TMDb</th>
                                    <th class="text-center">First Aired</th>
                                    <th class="text-center">Last Updated</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<?php include 'footer.php'; ?>