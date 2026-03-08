<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Reseller Logs</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="log_search" value="" placeholder="Search Logs...">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="reseller">Reseller</label>
                            <div class="col-md-3">
                                <select id="reseller" class="form-control" data-toggle="select2">
                                    <optgroup label="Global">
                                        <option value=""<?= !isset($rRequest['user_id']) ? ' selected' : '' ?>>All Users</option>
                                        <option value="<?= $rUserInfo['id'] ?>"<?= (isset($rRequest['user_id']) && $rRequest['user_id'] == $rUserInfo['id']) ? ' selected' : '' ?>>My Logs</option>
                                    </optgroup>
                                    <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                    <optgroup label="Direct Reports">
                                        <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                        <option value="<?= $rUserID ?>"<?= (isset($rRequest['user_id']) && $rRequest['user_id'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                    <?php if (count($rPermissions['all_reports']) > count($rPermissions['direct_reports'])): ?>
                                    <optgroup label="Indirect Reports">
                                        <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                        <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                        <option value="<?= $rUserID ?>"<?= (isset($rRequest['user_id']) && $rRequest['user_id'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="range">Dates</label>
                            <div class="col-md-2">
                                <input type="text" class="form-control text-center date" id="range" name="range" data-toggle="date-picker" data-single-date-picker="true" autocomplete="off" placeholder="All Dates">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option<?= ($rSettings['default_entries'] == $rShow) ? ' selected' : '' ?> value="<?= $rShow ?>"><?= $rShow ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>Reseller</th>
                                    <th>Line / User</th>
                                    <th>Action</th>
                                    <th class="text-center">Cost</th>
                                    <th class="text-center">Credits Remaining</th>
                                    <th class="text-center">Date</th>
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
