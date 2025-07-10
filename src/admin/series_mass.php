<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rCategories = getCategories('series');
$_TITLE = 'Mass Edit Series';
include 'header.php';
?>
<div class="wrapper boxed-layout-xl"
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
                    <h4 class="page-title">Mass Edit Series <small id="selected_count"></small></h4>
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
                        Mass edit of Series was successfully executed!
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="series" id="series" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-youtube-tv mr-1"></i>
                                            <span class="d-none d-sm-inline">Series</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-5 col-6">
                                                <input type="text" class="form-control" id="stream_search" value=""
                                                    placeholder="Search Series...">
                                            </div>
                                            <div class="col-md-4 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Categories</option>
                                                    <option value="-1">No TMDb Match</option>
                                                    <option value="-2">No Categories</option>
                                                    <?php foreach ($rCategories as $rCategory) : ?>
                                                        <option value="<?= $rCategory['id'] ?>"
                                                            <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                echo ' selected';
                                                            } ?>>
                                                            <?= $rCategory['category_name'] ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                                        <option value="<?= $rShow ?>"
                                                            <?= $rSettings['default_entries'] == $rShow ? ' selected' : '' ?>>
                                                            <?= $rShow ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <button type="button" class="btn btn-info waves-effect waves-light"
                                                    onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
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
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    To mass edit any of the below options, tick the checkbox next to it
                                                    and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div
                                                        class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id"
                                                            name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="category_id">Select
                                                        Categories</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="category_id[]" id="category_id"
                                                            class="form-control select2-multiple" data-toggle="select2"
                                                            multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rCategories as $rCategory) : ?>
                                                                <option value="<?= intval($rCategory['id']) ?>">
                                                                    <?= htmlspecialchars($rCategory['category_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="category_id_type" id="category_id_type"
                                                            class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) : ?>
                                                                <option value="<?= $rType ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div
                                                        class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bouquets"
                                                            name="c_bouquets">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="bouquets">Select
                                                        Bouquets</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="bouquets[]" id="bouquets"
                                                            class="form-control select2-multiple" data-toggle="select2"
                                                            multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet) : ?>
                                                                <option value="<?= $rBouquet['id'] ?>">
                                                                    <?= $rBouquet['bouquet_name'] ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="bouquets_type" id="bouquets_type"
                                                            class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) : ?>
                                                                <option value="<?= $rType ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label"
                                                        for="reprocess_tmdb">Re-Process TMDb Data</label>
                                                    <div class="col-md-2">
                                                        <input name="reprocess_tmdb" id="reprocess_tmdb" type="checkbox"
                                                            data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_series" type="submit" class="btn btn-primary"
                                                    value="Edit Series" />
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