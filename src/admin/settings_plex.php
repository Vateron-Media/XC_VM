<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rBouquets = getBouquets();
$_TITLE = 'Plex Settings';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        echo '';
    } else {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Plex Settings</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Plex settings successfully updated!
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#setup" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Setup</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#categories" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-movie mr-1"></i>
                                            <span class="d-none d-sm-inline">Movie Categories</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#categories-tv" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-youtube-tv mr-1"></i>
                                            <span class="d-none d-sm-inline">TV Categories</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="setup">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="thread_count_movie">Movie Thread Count <i
                                                            title="Number of threads to run simultaneously for movies."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="thread_count_movie" name="thread_count_movie"
                                                            value="<?= htmlspecialchars($rSettings['thread_count_movie']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label"
                                                        for="thread_count_show">Series Thread Count <i
                                                            title="Number of threads to run simultaneously for TV series. This should be lower as the series thread will be responsible for grabbing all episodes. So this is the number of TV series to scan at once rather than episodes. Try 1/10th of movie thread limit."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="thread_count_show" name="thread_count_show"
                                                            value="<?= htmlspecialchars($rSettings['thread_count_show']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="scan_seconds">Scan
                                                        Frequency <i title="Scan a library every X seconds."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="scan_seconds" name="scan_seconds"
                                                            value="<?= htmlspecialchars($rSettings['scan_seconds']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="max_genres">Max
                                                        Categories & Bouquets <i
                                                            title="Maximum number of TMDb genres to convert into categories and bouquets. Enter 0 for unlimited."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="max_genres" name="max_genres"
                                                            value="<?= htmlspecialchars($rSettings['max_genres']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_settings" type="submit" class="btn btn-primary"
                                                    value="Save Changes" />
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="categories">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    Select a Category and / or Bouquet to apply to each Genre.
                                                </p>
                                                <?php
                                                $db->query('SELECT * FROM `watch_categories` WHERE `type` = 3 ORDER BY `genre` ASC;');
                                                if ($db->num_rows() > 0) {
                                                    foreach ($db->get_rows() as $rRow) {
                                                ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-2 col-form-label"
                                                                for="genre_<?= $rRow['genre_id']; ?>"><?= $rRow['genre']; ?></label>
                                                            <div class="col-md-4">
                                                                <select name="genre_<?= $rRow['genre_id']; ?>"
                                                                    id="genre_<?= $rRow['genre_id']; ?>"
                                                                    class="form-control select2" data-toggle="select2">
                                                                    <option value="0"
                                                                        <?= intval($rRow['category_id']) == 0 ? 'selected' : ''; ?>>
                                                                        Do Not Use</option>
                                                                    <?php
                                                                    foreach (getCategories('movie') as $rCategory) {
                                                                    ?>
                                                                        <option value="<?= $rCategory['id']; ?>"
                                                                            <?= intval($rRow['category_id']) == intval($rCategory['id']) ? 'selected' : ''; ?>>
                                                                            <?= $rCategory['category_name']; ?>
                                                                        </option>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-2 col-form-label"
                                                                for="bouquet_<?= $rRow['genre_id']; ?>">Bouquets</label>
                                                            <div class="col-md-4">
                                                                <select name="bouquet_<?= $rRow['genre_id']; ?>[]"
                                                                    id="bouquet_<?= $rRow['genre_id']; ?>"
                                                                    class="form-control select2-multiple" data-toggle="select2"
                                                                    multiple="multiple" data-placeholder="Choose...">
                                                                    <?php
                                                                    foreach ($rBouquets as $rBouquet) {
                                                                    ?>
                                                                        <option value="<?= $rBouquet['id']; ?>"
                                                                            <?= in_array(intval($rBouquet['id']), json_decode($rRow['bouquets'], true)) ? 'selected' : ''; ?>>
                                                                            <?= $rBouquet['bouquet_name']; ?>
                                                                        </option>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                <?php
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_settings" type="submit" class="btn btn-primary"
                                                    value="Save Changes" />
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="categories-tv">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    Select a Category and / or Bouquet to apply to each Genre.
                                                </p>
                                                <?php
                                                $db->query('SELECT * FROM `watch_categories` WHERE `type` = 4 ORDER BY `genre` ASC;');
                                                if ($db->num_rows() > 0) {
                                                    foreach ($db->get_rows() as $rRow) {
                                                ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-2 col-form-label"
                                                                for="genretv_<?= $rRow['genre_id']; ?>"><?= $rRow['genre']; ?></label>
                                                            <div class="col-md-4">
                                                                <select name="genretv_<?= $rRow['genre_id']; ?>"
                                                                    id="genretv_<?= $rRow['genre_id']; ?>"
                                                                    class="form-control select2" data -toggle="select2">
                                                                    <option value="0"
                                                                        <?= intval($rRow['category_id']) == 0 ? 'selected' : ''; ?>>
                                                                        Do Not Use</option>
                                                                    <?php
                                                                    foreach (getCategories('series') as $rCategory) {
                                                                    ?>
                                                                        <option value="<?= $rCategory['id']; ?>"
                                                                            <?= intval($rRow['category_id']) == intval($rCategory['id']) ? 'selected' : ''; ?>>
                                                                            <?= $rCategory['category_name']; ?>
                                                                        </option>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-2 col-form-label"
                                                                for="bouquettv_<?= $rRow['genre_id']; ?>">Bouquets</label>
                                                            <div class="col-md-4">
                                                                <select name="bouquettv_<?= $rRow['genre_id']; ?>[]"
                                                                    id="bouquettv_<?= $rRow['genre_id']; ?>"
                                                                    class="form-control select2-multiple" data-toggle="select2"
                                                                    multiple="multiple" data-placeholder="Choose...">
                                                                    <?php
                                                                    foreach ($rBouquets as $rBouquet) {
                                                                    ?>
                                                                        <option value="<?= $rBouquet['id']; ?>"
                                                                            <?= in_array(intval($rBouquet['id']), json_decode($rRow['bouquets'], true)) ? 'selected' : ''; ?>>
                                                                            <?= $rBouquet['bouquet_name']; ?>
                                                                        </option>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                <?php
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_settings" type="submit" class="btn btn-primary"
                                                    value="Save Changes" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<?php include 'footer.php'; ?>