<div class="wrapper boxed-layout-ext">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Created Channels</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="<?= $rMobile ? 'collapse' : '' ?> form-group row mb-4">
                            <div class="col-md-5">
                                <input type="text" class="form-control" id="stream_search" value="<?= isset($rRequest['search']) ? htmlspecialchars($rRequest['search']) : '' ?>" placeholder="Search Channels...">
                            </div>
                            <div class="col-md-4">
                                <select id="stream_category_id" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Categories</option>
                                    <?php foreach ($categories as $rCategory): ?>
                                    <option value="<?= $rCategory['id'] ?>"<?= (isset($rRequest['category']) && $rRequest['category'] == $rCategory['id']) ? ' selected' : '' ?>><?= $rCategory['category_name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="stream_show_entries">Show</label>
                            <div class="col-md-2">
                                <select id="stream_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option<?= (isset($rRequest['entries']) ? $rRequest['entries'] == $rShow : $rSettings['default_entries'] == $rShow) ? ' selected' : '' ?> value="<?= $rShow ?>"><?= $rShow ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-streampage" class="table table-borderless table-striped dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Icon</th>
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
