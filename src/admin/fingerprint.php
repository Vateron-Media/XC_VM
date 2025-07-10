<?php include 'session.php';
include 'functions.php';
if (!checkPermissions()) {
    goHome();
}
$_TITLE = 'Fingerprint Stream';
include 'header.php'; ?>
<div class="wrapper boxed-layout-ext">
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        echo ' style="display: none;"';
    } ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['fingerprint_stream']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <div id="basicwizard">
                            <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                <li class="nav-item" id="stream-selection-tab">
                                    <a href="#stream-selection" id="stream-selection-nav" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-play mr-1"></i>
                                        <span class="d-none d-sm-inline"><?php echo $_['stream']; ?></span>
                                    </a>
                                </li>
                                <li class="nav-item disabled" id="stream-activity-tab">
                                    <a href="#stream-activity" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-account-group mr-1"></i>
                                        <span class="d-none d-sm-inline"><?php echo $_['activity']; ?></span>
                                    </a>
                                </li>
                            </ul>
                            <div class="tab-content b-0 mb-0 pt-0">
                                <div class="tab-pane" id="stream-selection">
                                    <div class="row">
                                        <?php if (CoreUtilities::$rSettings['redis_handler']) { ?>
                                            <div class="col-md-6 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="<?php echo $_['search_streams']; ?>">
                                            </div>
                                            <div class="col-md-6 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                    <?php foreach (getCategories('live') as $rCategory) { ?>
                                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                            echo ' selected';
                                                                                                        } ?>>
                                                            <?php echo $rCategory['category_name']; ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        <?php } else { ?>
                                            <div class="col-md-5 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="<?php echo $_['search_streams']; ?>">
                                            </div>
                                            <div class="col-md-4 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                    <?php foreach (getCategories('live') as $rCategory) { ?>
                                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                            echo ' selected';
                                                                                                        } ?>><?php echo $rCategory['category_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <label class="col-md-1 col-2 col-form-label text-center" for="show_entries"><?php echo $_['show']; ?></label>
                                            <div class="col-md-2 col-8">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) {
                                                                                                    echo 'selected';
                                                                                                } ?>><?php echo $rShow; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        <?php } ?>
                                        <table id="datatable-md1" class="table table-striped table-borderless mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                                    <th><?php echo $_['stream_name']; ?></th>
                                                    <th><?php echo $_['category']; ?></th>
                                                    <th class="text-center"><?php echo $_['clients']; ?></th>
                                                    <th class="text-center"></th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane" id="stream-activity">
                                    <div class="row">
                                        <div class="alert alert-warning alert-dismissible fade show col-md-12 col-12 text-center" role="alert">
                                            <?php echo $_['warning_fingerprint']; ?>
                                        </div>
                                    </div>
                                    <div class="row" id="filter_selection">
                                        <label class="col-md-1 col-2 col-form-label text-center" for="fingerprint_type"><?php echo $_['type']; ?></label>
                                        <div class="col-md-2 col-6">
                                            <select id="fingerprint_type" class="form-control text-center" data-toggle="select2">
                                                <option value="1"><?php echo $_['activity_id']; ?></option>
                                                <option value="2"><?php echo $_['username']; ?></option>
                                                <option value="3"><?php echo $_['message']; ?></option>
                                            </select>
                                        </div>
                                        <label class="col-md-1 col-2 col-form-label text-center" for="font_size"><?php echo $_['size']; ?></label>
                                        <div class="col-md-1 col-2">
                                            <input type="text" class="form-control text-center" id="font_size" value="36" placeholder="">
                                        </div>
                                        <label class="col-md-1 col-2 col-form-label text-center" for="font_color"><?php echo $_['colour']; ?></label>
                                        <div class="col-md-2 col-2">
                                            <input type="text" id="font_color" class="form-control text-center" value="#ffffff">
                                        </div>
                                        <label class="col-md-1 col-2 col-form-label text-center" for="position"><?php echo $_['position']; ?></label>
                                        <div class="col-md-1 col-2">
                                            <input type="text" class="form-control text-center" id="position_x" value="10" placeholder="X">
                                        </div>
                                        <div class="col-md-1 col-2">
                                            <input type="text" class="form-control text-center" id="position_y" value="10" placeholder="Y">
                                        </div>
                                        <div class="col-md-1 col-2">
                                            <button type="button" class="btn btn-info waves-effect waves-light" onClick="activateFingerprint()">
                                                <i class="mdi mdi-fingerprint"></i>
                                            </button>
                                        </div>
                                        <div class="col-md-12 col-2" style="margin-top:10px;display:none;" id="custom_message_div">
                                            <input type="text" class="form-control" id="custom_message" value="" placeholder="<?php echo $_['custom_message']; ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <table id="datatable-md2" class="table table-striped table-borderless mb-0">
                                            <thead>
                                                <tr>
                                                    <th></th>
                                                    <th></th>
                                                    <th>Username</th>
                                                    <th>Stream</th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th class="text-center">IP</th>
                                                    <th class="text-center">Duration</th>
                                                    <th></th>
                                                    <th></th>
                                                    <th class="text-center"><?php echo $_['actions']; ?></th>
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
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>