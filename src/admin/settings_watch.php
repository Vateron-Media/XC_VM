<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rBouquets = getBouquets();
$_TITLE = 'Watch Settings';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Folder Watch Settings</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Watch settings successfully updated!
                    </div>
                <?php } ?>
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
                                                    <label class="col-md-4 col-form-label" for="scan_seconds">Scan
                                                        Frequency <i title="Scan a folder every X seconds."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="scan_seconds" name="scan_seconds"
                                                            value="<?php echo htmlspecialchars($rSettings['scan_seconds']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="percentage_match">Match
                                                        Percentage <i
                                                            title="TMDb match tolerance. Will not accept match if below this percentage threshold."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="percentage_match" name="percentage_match"
                                                            value="<?php echo htmlspecialchars($rSettings['percentage_match']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="thread_count">Thread
                                                        Count <i title="Number of threads to run simultaneously."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="thread_count" name="thread_count"
                                                            value="<?php echo htmlspecialchars($rSettings['thread_count']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="max_genres">Max
                                                        Categories & Bouquets <i
                                                            title="Maximum number of TMDb genres to convert into categories and bouquets. Enter 0 for unlimited."
                                                            class="tooltip text-secondary far fa-circle"></i></label>

                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="max_genres" name="max_genres"
                                                            value="<?php echo htmlspecialchars($rSettings['max_genres']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="max_items">Max Items <i
                                                            title="Maximum number of items to add per folder per scan. Set this to 0 to scan everything."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center"
                                                            id="max_items" name="max_items"
                                                            value="<?php echo htmlspecialchars($rSettings['max_items']); ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="alternative_titles">Check Alternative Titles <i
                                                            title="If a title partially matches a TMDb result, check the alternative titles of that Movie or TV Show to see if any of those match the title."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="alternative_titles" id="alternative_titles"
                                                            type="checkbox"
                                                            <?php if ($rSettings['alternative_titles'] == 1) {
                                                                echo 'checked';
                                                            } ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="fallback_parser">Use
                                                        Fallback Parser <i
                                                            title="If no match is found using your preferred title parser, fallback to the parser you didn't select and run again."
                                                            class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="fallback_parser" id="fallback_parser"
                                                            type="checkbox"
                                                            <?php if ($rSettings['fallback_parser'] == 1) {
                                                                echo 'checked';
                                                            } ?>
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
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
                                                $db->query('SELECT * FROM `watch_categories` WHERE `type` = 1 ORDER BY `genre` ASC;');
                                                if ($db->num_rows() > 0) {
                                                    foreach ($db->get_rows() as $rRow) {
                                                ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-2 col-form-label"
                                                                for="genre_<?php echo $rRow['genre_id']; ?>"><?php echo $rRow['genre']; ?></label>
                                                            <div class="col-md-4">
                                                                <select name="genre_<?php echo $rRow['genre_id']; ?>"
                                                                    id="genre_<?php echo $rRow['genre_id']; ?>"
                                                                    class="form-control select2" data-toggle="select2">
                                                                    <option
                                                                        <?php if (intval($rRow['category_id']) == 0) {
                                                                            echo 'selected';
                                                                        } ?>
                                                                        value="0">Do Not Use</option>
                                                                    <?php foreach (getCategories('movie') as $rCategory) { ?>
                                                                        <option
                                                                            <?php if (intval($rRow['category_id']) == intval($rCategory['id'])) {
                                                                                echo 'selected';
                                                                            } ?>
                                                                            value="<?php echo $rCategory['id']; ?>">
                                                                            <?php echo $rCategory['category_name']; ?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-2 col-form-label"
                                                                for="bouquet_<?php echo $rRow['genre_id']; ?>">Bouquets</label>
                                                            <div class="col-md-4">
                                                                <select name="bouquet_<?php echo $rRow['genre_id']; ?>[]"
                                                                    id="bouquet_<?php echo $rRow['genre_id']; ?>"
                                                                    class="form-control select2-multiple" data-toggle="select2"
                                                                    multiple="multiple" data-placeholder="Choose...">
                                                                    <?php foreach ($rBouquets as $rBouquet) { ?>
                                                                        <option
                                                                            <?php if (in_array(intval($rBouquet['id']), json_decode($rRow['bouquets'], true))) {
                                                                                echo 'selected';
                                                                            } ?>
                                                                            value="<?php echo $rBouquet['id']; ?>">
                                                                            <?php echo $rBouquet['bouquet_name']; ?>
                                                                        </option>
                                                                    <?php } ?>
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
                                                $db->query('SELECT * FROM `watch_categories` WHERE `type` = 2 ORDER BY `genre` ASC;');
                                                if ($db->num_rows() > 0) {
                                                    foreach ($db->get_rows() as $rRow) {
                                                ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-2 col-form-label"
                                                                for="genretv_<?php echo $rRow['genre_id']; ?>"><?php echo $rRow['genre']; ?></label>
                                                            <div class="col-md-4">
                                                                <select name="genretv_<?php echo $rRow['genre_id']; ?>"
                                                                    id="genretv_<?php echo $rRow['genre_id']; ?>"
                                                                    class="form-control select2" data-toggle="select2">
                                                                    <option
                                                                        <?php if (intval($rRow['category_id']) == 0) {
                                                                            echo 'selected';
                                                                        } ?>
                                                                        value="0">Do Not Use</option>
                                                                    <?php foreach (getCategories('series') as $rCategory) { ?>
                                                                        <option
                                                                            <?php if (intval($rRow['category_id']) == intval($rCategory['id'])) {
                                                                                echo 'selected';
                                                                            } ?>
                                                                            value="<?php echo $rCategory['id']; ?>">
                                                                            <?php echo $rCategory['category_name']; ?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-2 col-form-label"
                                                                for="bouquettv_<?php echo $rRow['genre_id']; ?>">Bouquets</label>
                                                            <div class="col-md-4">
                                                                <select name="bouquettv_<?php echo $rRow['genre_id']; ?>[]"
                                                                    id="bouquettv_<?php echo $rRow['genre_id']; ?>"
                                                                    class="form-control select2-multiple" data-toggle="select2"
                                                                    multiple="multiple" data-placeholder="Choose...">
                                                                    <?php foreach ($rBouquets as $rBouquet) { ?>
                                                                        <option
                                                                            <?php if (in_array(intval($rBouquet['id']), json_decode($rRow['bouquets'], true))) {
                                                                                echo 'selected';
                                                                            } ?>
                                                                            value="<?php echo $rBouquet['id']; ?>">
                                                                            <?php echo $rBouquet['bouquet_name']; ?></option>
                                                                    <?php } ?>
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
    <?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . '            $("#scan_seconds").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#percentage_match").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#max_items").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#thread_count").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>