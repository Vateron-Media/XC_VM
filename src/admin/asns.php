<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = "ASN's";
include 'header.php';
?>
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
                    <h4 class="page-title">Autonomous System Numbers</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <form id="asn_search">
                            <div class="form-group row mb-4">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" id="asn_search_box" placeholder="Search ASN's...">
                                </div>
                                <label class="col-md-2 col-form-label text-center" for="asn_type">Filter Results</label>
                                <div class="col-md-3">
                                    <select id="asn_type" class="form-control" data-toggle="select2">
                                        <option value="" selected>All Types</option>
                                        <option value="isp">ISP</option>
                                        <option value="hosting">Hosting Server</option>
                                        <option value="education">Education</option>
                                        <option value="business">Business</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select id="asn_filter" class="form-control" data-toggle="select2">
                                        <option value="" selected>No Filter</option>
                                        <option value="0">Allowed</option>
                                        <option value="1">Blocked</option>
                                    </select>
                                </div>
                                <label class="col-md-1 col-form-label text-center" for="asn_show_entries">Show</label>
                                <div class="col-md-1">
                                    <select id="asn_show_entries" class="form-control" data-toggle="select2">
                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                            <option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?>><?php echo $rShow; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </form>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ASN</th>
                                    <th>Name</th>
                                    <th>Domain</th>
                                    <th class="text-center">Country</th>
                                    <th class="text-center">IP Count</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">Status</th>
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
</div>
<?php include 'footer.php'; ?>