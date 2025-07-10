<?php include 'session.php';
include 'functions.php';
if (checkPermissions()) {
} else {
    goHome();
}
$_TITLE = 'Created Channels';
include 'header.php'; ?>
<div class="wrapper" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Created Channels</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4<?php if (!$rMobile) {
                                                                                } else {
                                                                                    echo ' collapse';
                                                                                } ?>">
                            <div class="col-md-2">
                                <input type="text" class="form-control" id="stream_search" value="<?php if (!isset(CoreUtilities::$rRequest['search'])) {
                                                                                                    } else {
                                                                                                        echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                    } ?>" placeholder="Search Channels...">
                            </div>
                            <div class="col-md-3">
                                <select id="stream_server_id" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Servers</option>
                                    <option value="-1" <?php if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == -1)) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>No Servers</option>
                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id'])) {
                                                                                        } else {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select id="stream_category_id" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Categories</option>
                                    <option value="-1" <?php if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1)) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>No Categories</option>
                                    <?php foreach (getCategories('live') as $rCategory) { ?>
                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id'])) {
                                                                                        } else {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rCategory['category_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="stream_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (isset(CoreUtilities::$rRequest['filter'])) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>No Filter</option>
                                    <option value="1" <?php if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>Online</option>
                                    <option value="2" <?php if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>Stopped</option>
                                    <option value="3" <?php if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3)) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>Creating</option>
                                    <option value="4" <?php if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>>Transcoding</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="stream_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="stream_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option<?php if (isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) {
                                                    echo ' selected';
                                                } elseif ($rSettings['default_entries'] == $rShow) {
                                                    echo ' selected';
                                                } ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                        <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-streampage" class="table table-borderless table-striped dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Icon</th>
                                    <th>Name</th>
                                    <?php if ($rSettings['streams_grouped'] == 1) { ?>
                                        <th>Servers</th>
                                    <?php } else { ?>
                                        <th>Server</th>
                                    <?php } ?>
                                    <th class="text-center">Clients</th>
                                    <th class="text-center">Uptime</th>
                                    <th class="text-center">Actions</th>
                                    <th class="text-center">Player</th>
                                    <th class="text-center">Channel Info</th>
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