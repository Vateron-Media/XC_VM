<?php
include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
    $rCategoryArr = getCategory(CoreUtilities::$rRequest['id']);

    if ($rCategoryArr && hasPermissions('adv', 'edit_cat')) {
    } else {
        exit();
    }
}

$_TITLE = 'Stream Category';
include 'header.php'; ?>
<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                    } else { ?> style="display: none;" <?php } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">
                        <?php if (isset($rCategoryArr)) {
                            echo 'Edit';
                        } else {
                            echo 'Add';
                        } ?>
                        Category</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (!isset($rCategoryArr)) {
                            } else { ?>
                                <input type="hidden" name="edit" value="<?= intval($rCategoryArr['id']); ?>" />
                                <input type="hidden" name="cat_order" value="<?= intval($rCategoryArr['cat_order']); ?>" />
                            <?php } ?>
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#category-details" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <?php if (!isset($rCategoryArr)) {
                                    } else { ?>
                                        <li class="nav-item">
                                            <a href="#view-channels" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-play mr-1"></i>
                                                <span class="d-none d-sm-inline">View Streams</span>
                                            </a>
                                        </li>
                                    <?php } ?>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="category-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <?php if (!isset($rCategoryArr)) { ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="category_type">Category
                                                            Type</label>
                                                        <div class="col-md-8">
                                                            <select name="category_type" id="category_type"
                                                                class="form-control select2" data-toggle="select2">
                                                                <?php foreach (array('live' => 'Live TV', 'movie' => 'Movie', 'series' => 'TV Series', 'radio' => 'Radio Station') as $rGroupID => $rGroup) { ?>
                                                                    <option <?php if (!isset($rCategoryArr)) {
                                                                            } else {
                                                                                if ($rCategoryArr['category_type'] == $rGroupID) {
                                                                                    echo 'selected ';
                                                                                }
                                                                            } ?> value="<?= $rGroupID; ?>"><?= $rGroup; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                <?php } else { ?>
                                                    <input type="hidden" name="category_type"
                                                        value="<?= htmlspecialchars($rCategoryArr['category_type']); ?>" />
                                                <?php } ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="category_name">Category
                                                        Name</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="category_name"
                                                            name="category_name"
                                                            value="<?= isset($rCategoryArr) ? htmlspecialchars($rCategoryArr['category_name']) : ''; ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="is_adult">Adult
                                                        Content</label>
                                                    <div class="col-md-2">
                                                        <input name="is_adult" id="is_adult" type="checkbox" <?php if (!isset($rCategoryArr)) {
                                                                                                                } else {
                                                                                                                    if ($rCategoryArr['is_adult'] == 1) {
                                                                                                                        echo 'checked ';
                                                                                                                    }
                                                                                                                } ?>data-plugin="switchery" class="js-switch"
                                                            data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_category" type="submit" class="btn btn-primary"
                                                    value="<?php if (isset($rCategoryArr)) {
                                                                echo 'Edit';
                                                            } else {
                                                                echo 'Add';
                                                            } ?>" />
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="view-channels">
                                        <div class="row">
                                            <div class="col-12" style="overflow-x:auto;">
                                                <table id="datatable"
                                                    class="table table-striped table-borderless dt-responsive nowrap">
                                                    <thead>
                                                        <tr>
                                                            <th class="text-center">Stream ID</th>
                                                            <th>Stream Name</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>
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
<?php include 'footer.php';
		echo '        ' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'})" . "\r\n\t\t\t";

		if (!isset($rCategoryArr)) {
		} else {
			echo "\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t";

			if ($rCategoryArr['category_type'] == 'live') {
				echo "\t\t\t\t\t\t" . 'd.id = "streams_short";' . "\r\n\t\t\t\t\t\t";
			} else {
				if ($rCategoryArr['category_type'] == 'movie') {
					echo "\t\t\t\t\t\t" . 'd.id = "movies_short";' . "\r\n\t\t\t\t\t\t";
				} else {
					if ($rCategoryArr['category_type'] == 'radio') {
						echo "\t\t\t\t\t\t" . 'd.id = "radios_short";' . "\r\n\t\t\t\t\t\t";
					} else {
						echo "\t\t\t\t\t\t" . 'd.id = "series_short";' . "\r\n\t\t\t\t\t\t";
					}
				}
			}

			echo "\t\t\t\t\t\t" . 'd.category_id = ';
			echo $rCategoryArr['id'];
			echo ';' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t";
		}

		echo '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>