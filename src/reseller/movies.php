<?php
include 'session.php';
include 'functions.php';

if (!checkResellerPermissions()) {
    goHome();
}

$_TITLE = 'Movies';
include 'header.php';
?>

<div class="wrapper boxed-layout-ext">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['movies']; ?></h4>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">

                        <div id="collapse_filters"
                             class="<?php echo $rMobile ? 'collapse' : ''; ?> form-group row mb-4">

                            <div class="col-md-5">
                                <input type="text"
                                       class="form-control"
                                       id="movies_search"
                                       value="<?php echo isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : ''; ?>"
                                       placeholder="<?php echo $_['search_movies']; ?>...">
                            </div>

                            <div class="col-md-4">
                                <select id="movies_category_id" class="form-control" data-toggle="select2">
                                    <option value=""
                                        <?php echo !isset(CoreUtilities::$rRequest['category']) ? 'selected' : ''; ?>>
                                        <?php echo $_['all_categories']; ?>
                                    </option>
                                    <?php foreach (getCategories('movie') as $rCategory): ?>
                                        <?php if (in_array($rCategory['id'], $rPermissions['category_ids'])): ?>
                                            <option value="<?php echo $rCategory['id']; ?>"
                                                <?php echo (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) ? 'selected' : ''; ?>>
                                                <?php echo $rCategory['category_name']; ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <label class="col-md-1 col-form-label text-center" for="movies_show_entries">
                                <?php echo $_['show']; ?>
                            </label>

                            <div class="col-md-2">
                                <select id="movies_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                        <option value="<?php echo $rShow; ?>"
                                            <?php
                                            if (isset(CoreUtilities::$rRequest['entries'])) {
                                                echo (CoreUtilities::$rRequest['entries'] == $rShow) ? 'selected' : '';
                                            } else {
                                                echo ($rSettings['default_entries'] == $rShow) ? 'selected' : '';
                                            }
                                            ?>>
                                            <?php echo $rShow; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Image</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Kill</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include 'footer.php'; ?>
