<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= $language::get('live_connections') ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="<?= $rMobile ? 'collapse' : '' ?> form-group row mb-4">
                            <?php if ($redisEnabled): ?>
                            <div class="col-md-4">
                                <select id="live_stream" class="form-control" data-toggle="select2">
                                    <?php if (isset($rSearchStream)): ?>
                                    <option value="<?= intval($rSearchStream['id']) ?>" selected="selected"><?= $rSearchStream['stream_display_name'] ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select id="live_line" class="form-control" data-toggle="select2">
                                    <?php if (isset($rSearchLine)): ?>
                                    <option value="<?= intval($rSearchLine['id']) ?>" selected="selected"><?= $rSearchLine['username'] ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select id="live_user" class="form-control" data-toggle="select2">
                                    <optgroup label="Global">
                                        <option value=""<?= !isset($rRequest['user']) ? ' selected' : '' ?>>All Users</option>
                                        <option value="<?= $rUserInfo['id'] ?>"<?= (isset($rRequest['user']) && $rRequest['user'] == $rUserInfo['id']) ? ' selected' : '' ?>>My Lines</option>
                                    </optgroup>
                                    <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                    <optgroup label="Direct Reports">
                                        <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                        <option value="<?= $rUserID ?>"<?= (isset($rRequest['user']) && $rRequest['user'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                    <?php if (count($rPermissions['all_reports']) > count($rPermissions['direct_reports'])): ?>
                                    <optgroup label="Indirect Reports">
                                        <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                            <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                            <option value="<?= $rUserID ?>"<?= (isset($rRequest['user']) && $rRequest['user'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="live_search" value="<?= isset($rRequest['search']) ? htmlspecialchars($rRequest['search']) : '' ?>" placeholder="<?= $language::get('search_logs') ?>...">
                            </div>
                            <div class="col-md-3">
                                <select id="live_stream" class="form-control" data-toggle="select2">
                                    <?php if (isset($rSearchStream)): ?>
                                    <option value="<?= intval($rSearchStream['id']) ?>" selected="selected"><?= $rSearchStream['stream_display_name'] ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="live_line" class="form-control" data-toggle="select2">
                                    <?php if (isset($rSearchLine)): ?>
                                    <option value="<?= intval($rSearchLine['id']) ?>" selected="selected"><?= $rSearchLine['username'] ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="live_user" class="form-control" data-toggle="select2">
                                    <optgroup label="Global">
                                        <option value=""<?= !isset($rRequest['user']) ? ' selected' : '' ?>>All Users</option>
                                        <option value="<?= $rUserInfo['id'] ?>"<?= (isset($rRequest['user']) && $rRequest['user'] == $rUserInfo['id']) ? ' selected' : '' ?>>My Lines</option>
                                    </optgroup>
                                    <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                    <optgroup label="Direct Reports">
                                        <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                        <option value="<?= $rUserID ?>"<?= (isset($rRequest['user']) && $rRequest['user'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                    <?php if (count($rPermissions['all_reports']) > count($rPermissions['direct_reports'])): ?>
                                    <optgroup label="Indirect Reports">
                                        <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                            <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                            <option value="<?= $rUserID ?>"<?= (isset($rRequest['user']) && $rRequest['user'] == $rUserID) ? ' selected' : '' ?>><?= $rPermissions['users'][$rUserID]['username'] ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <label class="col-md-1 col-form-label text-center" for="live_show_entries"><?= $language::get('show') ?></label>
                            <div class="col-md-1">
                                <select id="live_show_entries" class="form-control" data-toggle="select2">
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
                                    <th class="text-center">Quality</th>
                                    <th>Line</th>
                                    <th>Stream</th>
                                    <th>Player</th>
                                    <th>ISP</th>
                                    <th class="text-center">IP</th>
                                    <th class="text-center">Duration</th>
                                    <th class="text-center">Output</th>
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
