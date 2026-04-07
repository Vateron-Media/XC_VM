<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Users</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    User has been added / modified.
                </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="<?= $rMobile ? 'collapse' : '' ?> form-group row mb-4">
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="reg_search" value="<?= isset($rRequest['search']) ? htmlspecialchars($rRequest['search']) : '' ?>" placeholder="Search Users...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="reg_reseller">Filter Results</label>
                            <div class="col-md-2">
                                <select id="reg_filter" class="form-control" data-toggle="select2">
                                    <option value=""<?= !isset($rRequest['filter']) ? ' selected' : '' ?>>No Filter</option>
                                    <option value="1"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 1) ? ' selected' : '' ?>>Active</option>
                                    <option value="2"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 2) ? ' selected' : '' ?>>Disabled</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="reg_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="reg_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option<?= (isset($rRequest['entries']) ? $rRequest['entries'] == $rShow : $rSettings['default_entries'] == $rShow) ? ' selected' : '' ?> value="<?= $rShow ?>"><?= $rShow ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>Username</th>
                                    <th>Owner</th>
                                    <th class="text-center">IP</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Credits</th>
                                    <th class="text-center"># of Lines</th>
                                    <th class="text-center">Last Login</th>
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
