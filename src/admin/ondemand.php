<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>
<?php $_TITLE = 'On-Demand Scanner'; ?>
<?php include 'header.php'; ?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'): ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">On-Demand Scanner</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (!CoreUtilities::$rSettings['on_demand_checker']): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">×</span>
                        </button>
                        On-Demand Scanner isn't active, please enable it in the <a href="settings">Settings</a> menu to continue.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="search" value="" placeholder="Search Streams...">
                            </div>
                            <div class="col-md-3">
                                <select id="server" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Servers</option>
                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']): ?> selected<?php endif; ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="category" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Categories</option>
                                    <?php foreach (getCategories('live') as $rCategory): ?>
                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']): ?> selected<?php endif; ?>><?php echo $rCategory['category_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])): ?> selected<?php endif; ?>><?php echo $_['no_filter']; ?></option>
                                    <option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1): ?> selected<?php endif; ?>>Ready</option>
                                    <option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2): ?> selected<?php endif; ?>>Down</option>
                                    <option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3): ?> selected<?php endif; ?>>Not Scanned</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                        <option <?php if ($rSettings['default_entries'] == $rShow): ?> selected<?php endif; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Icon</th>
                                    <th>Stream</th>
                                    <th>Server</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Response</th>
                                    <th class="text-center">Stream Info</th>
                                    <th class="text-center">Last Scanned</th>
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