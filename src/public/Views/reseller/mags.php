<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= $language::get('mag_devices') ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    Device has been added / modified.
                </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="<?= $rMobile ? 'collapse' : '' ?> form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="mag_search" value="<?= isset($rRequest['search']) ? htmlspecialchars($rRequest['search']) : '' ?>" placeholder="<?= $language::get('search_devices') ?>...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="mag_reseller"><?= $language::get('filter_results') ?></label>
                            <div class="col-md-3">
                                <select id="mag_reseller" class="form-control" data-toggle="select2">
                                    <optgroup label="Global">
                                        <option value=""<?= !isset($rRequest['owner']) ? ' selected' : '' ?>>All Owners</option>
                                        <option value="<?= $rUserInfo['id'] ?>"<?= (isset($rRequest['owner']) && $rRequest['owner'] == $rUserInfo['id']) ? ' selected' : '' ?>>My Devices</option>
                                    </optgroup>
                                    <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                    <optgroup label="Direct Reports">
                                        <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                        <option value="<?= $rUserID ?>"<?= (isset($rRequest['owner']) && $rRequest['owner'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                    <?php if (count($rPermissions['all_reports']) > count($rPermissions['direct_reports'])): ?>
                                    <optgroup label="Indirect Reports">
                                        <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                        <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                        <option value="<?= $rUserID ?>"<?= (isset($rRequest['owner']) && $rRequest['owner'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="mag_filter" class="form-control" data-toggle="select2">
                                    <option value=""<?= !isset($rRequest['filter']) ? ' selected' : '' ?>><?= $language::get('no_filter') ?></option>
                                    <option value="1"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 1) ? ' selected' : '' ?>><?= $language::get('active') ?></option>
                                    <option value="2"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 2) ? ' selected' : '' ?>><?= $language::get('disabled') ?></option>
                                    <option value="3"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 4) ? ' selected' : '' ?>><?= $language::get('expired') ?></option>
                                    <option value="4"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 5) ? ' selected' : '' ?>><?= $language::get('trial') ?></option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="mag_show_entries"><?= $language::get('show') ?></label>
                            <div class="col-md-1">
                                <select id="mag_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option<?= (isset($rRequest['entries']) ? $rRequest['entries'] == $rShow : $rSettings['default_entries'] == $rShow) ? ' selected' : '' ?> value="<?= $rShow ?>"><?= $rShow ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th><?= $language::get('username') ?></th>
                                    <th class="text-center"><?= $language::get('mac_address') ?></th>
                                    <th class="text-center">Device</th>
                                    <th><?= $language::get('owner') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                    <th class="text-center"><?= $language::get('online') ?></th>
                                    <th class="text-center"><?= $language::get('trial') ?></th>
                                    <th class="text-center"><?= $language::get('expiration') ?></th>
                                    <th class="text-center"><?= $language::get('actions') ?></th>
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
