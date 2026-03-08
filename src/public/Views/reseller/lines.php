<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Lines</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    Line has been added / modified.
                </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="<?= $rMobile ? 'collapse' : '' ?> form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="user_search" value="<?= isset($rRequest['search']) ? htmlspecialchars($rRequest['search']) : '' ?>" placeholder="Search Lines...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="user_reseller">Filter Results</label>
                            <div class="col-md-3">
                                <select id="user_reseller" class="form-control" data-toggle="select2">
                                    <optgroup label="Global">
                                        <option value=""<?= !isset($rRequest['owner']) ? ' selected' : '' ?>>All Owners</option>
                                        <option value="<?= $rUserInfo['id'] ?>"<?= (isset($rRequest['owner']) && $rRequest['owner'] == $rUserInfo['id']) ? ' selected' : '' ?>>My Lines</option>
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
                                <select id="user_filter" class="form-control" data-toggle="select2">
                                    <option value=""<?= !isset($rRequest['filter']) ? ' selected' : '' ?>>No Filter</option>
                                    <option value="1"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 1) ? ' selected' : '' ?>>Active</option>
                                    <option value="2"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 2) ? ' selected' : '' ?>>Disabled</option>
                                    <option value="3"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 3) ? ' selected' : '' ?>>Banned</option>
                                    <option value="4"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 4) ? ' selected' : '' ?>>Expired</option>
                                    <option value="5"<?= (isset($rRequest['filter']) && $rRequest['filter'] == 5) ? ' selected' : '' ?>>Trial</option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="user_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="user_show_entries" class="form-control" data-toggle="select2">
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
                                    <th>Password</th>
                                    <th>Owner</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Online</th>
                                    <th class="text-center">Trial</th>
                                    <th class="text-center">Active</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Expiration</th>
                                    <th class="text-center">Last Connection</th>
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

<!-- WhatsApp Renewal Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" role="dialog" aria-labelledby="whatsappModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="whatsappModalLabel"><i class="mdi mdi-whatsapp text-success"></i> WhatsApp Renewal Reminder</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="wa_language">Select Language / Sprache wählen / Dil Seçin</label>
                    <select id="wa_language" class="form-control">
                        <option value="de">🇩🇪 Deutsch</option>
                        <option value="en">🇬🇧 English</option>
                        <option value="tr">🇹🇷 Türkçe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Preview / Vorschau / Önizleme</label>
                    <textarea id="wa_message_preview" class="form-control" rows="5" readonly></textarea>
                </div>
                <input type="hidden" id="wa_phone" value="">
                <input type="hidden" id="wa_username" value="">
                <input type="hidden" id="wa_expdate" value="">
                <input type="hidden" id="wa_daysremaining" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <a id="wa_send_btn" href="#" target="_blank" class="btn btn-success"><i class="mdi mdi-whatsapp"></i> Send via WhatsApp</a>
            </div>
        </div>
    </div>
</div>
