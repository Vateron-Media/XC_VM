<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php $rCategories = getCategories('radio'); ?>
<?php $_TITLE = 'Radio Stations'; ?>
<?php include 'header.php'; ?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Radio Stations</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php if ($rMobile) {
                                                                                    echo 'collapse';
                                                                                } ?>">
                            <div class="col-md-2">
                                <input type="text" class="form-control" id="station_search" value="<?php if (isset(CoreUtilities::$rRequest['search'])) {
                                                                                                        echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                    } ?>" placeholder="Search Stations...">
                            </div>
                            <div class="col-md-3">
                                <select id="station_server_id" class="form-control" data-toggle="select2">
                                    <option value="" selected><?php echo $_['all_servers']; ?></option>
                                    <option value="-1" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == -1) {
                                                            echo ' selected';
                                                        } ?>>No Servers</option>
                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select id="station_category_id" class="form-control" data-toggle="select2">
                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                    <option value="-1" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1) {
                                                            echo ' selected';
                                                        } ?>>No Categories</option>
                                    <?php foreach ($rCategories as $rCategory) { ?>
                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rCategory['category_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="station_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) {
                                                            echo ' selected';
                                                        } ?>><?php echo $_['no_filter']; ?></option>
                                    <option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) {
                                                            echo ' selected';
                                                        } ?>>Online</option>
                                    <option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) {
                                                            echo ' selected';
                                                        } ?>>Down</option>
                                    <option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) {
                                                            echo ' selected';
                                                        } ?>>Stopped</option>
                                    <option value="4" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) {
                                                            echo ' selected';
                                                        } ?>>Starting</option>
                                    <option value="5" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) {
                                                            echo ' selected';
                                                        } ?>>On Demand</option>
                                    <option value="6" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6) {
                                                            echo ' selected';
                                                        } ?>>Direct</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="station_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="station_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option value="<?php echo $rShow; ?>" <?php if ((isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || (!isset(CoreUtilities::$rRequest['entries']) && $rSettings['default_entries'] == $rShow)) {
                                                                                    echo ' selected';
                                                                                } ?>><?php echo $rShow; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Icon</th>
                                    <th>Name</th>
                                    <?php if ($rSettings['streams_grouped'] == 1) { ?>
                                        <th><?php echo $_['servers']; ?></th>
                                    <?php } else { ?>
                                        <th><?php echo $_['server']; ?></th>
                                    <?php } ?>
                                    <th class="text-center">Clients</th>
                                    <th class="text-center">Uptime</th>
                                    <th class="text-center">Actions</th>
                                    <th class="text-center">Stream Info</th>
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