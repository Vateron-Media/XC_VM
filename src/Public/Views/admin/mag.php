<?php

/**
 * MAG device add / edit (Bootstrap 5). Reached full-page from the mags table
 * ("Add" → mag) inside the new-UI shell, and as an iframe modal
 * ("Edit" → mag?id=X&modal=1) inside the modal shell. Vertical tabbed layout:
 * Details (MAC, paired user, owner, expiry, switches, adult PIN, notes), Device
 * Info (read-back STB fields — edit only), Advanced (forced connection/country,
 * ISP, allowed-IP whitelist) and Bouquets (checkbox table). Pairing a user hides
 * and disables the owner/expiry/advanced/bouquet inputs (the paired line's
 * settings are used instead), mirroring the legacy evaluatePair(). Posts to
 * post.php?action=mag via fetch; in the modal it posts xcModalSaved to the
 * parent (which closes the modal and reloads the table), full-page it returns to
 * the list.
 */

use XcVm\Core\Reference\GeoReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\User\UserRepository;

$rIsEdit      = isset($rDevice['mag_id']);
$rUser        = $rDevice['user'] ?? [];
$rPairId      = (int) ($rUser['pair_id'] ?? 0);
$rSelBouquets = is_array($rUser['bouquet'] ?? null)
    ? $rUser['bouquet']
    : (json_decode((string) ($rUser['bouquet'] ?? '[]'), true) ?: []);
$rDeviceIPs   = (isset($rUser['allowed_ips']) && $rUser['allowed_ips'] !== '')
    ? (json_decode((string) $rUser['allowed_ips'], true) ?: [])
    : [];
$rOwnerRow    = (isset($rUser['member_id']) && ($rTmp = UserRepository::getRegisteredUserById((int) $rUser['member_id']))) ? $rTmp : null;
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="mags" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('mag_device'); ?></h4>
    </div>
<?php endif; ?>

<form id="mag-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rDevice['mag_id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if ($rIsEdit): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-device" role="tab"><i class="icon-base ti tabler-device-mobile me-1"></i><?= $language::get('device_info'); ?></button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bouquets" role="tab"><i class="icon-base ti tabler-list me-1"></i><?= $language::get('bouquets'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="mac"><?= $language::get('mac_address'); ?></label>
                            <input type="text" class="form-control" id="mac" name="mac" value="<?= $rIsEdit ? htmlspecialchars((string) $rDevice['mac'], ENT_QUOTES) : '00:1A:79:'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pair_id"><?= $language::get('paired_user'); ?></label>
                            <div class="d-flex align-items-start gap-2">
                                <div class="flex-grow-1">
                                    <select id="pair_id" name="pair_id" class="form-select">
                                        <?php if ($rPairId > 0): ?>
                                            <option value="<?= $rPairId; ?>" selected><?= htmlspecialchars((string) ($rDevice['paired']['username'] ?? ''), ENT_QUOTES); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <button type="button" class="btn btn-label-warning flex-shrink-0" id="unpair-user"><?= $language::get('unpair'); ?></button>
                            </div>
                        </div>
                    </div>

                    <div id="linked_info">
                        <div class="mb-6">
                            <label class="form-label" for="member_id"><?= $language::get('owner'); ?></label>
                            <div class="d-flex align-items-start gap-2">
                                <div class="flex-grow-1">
                                    <select name="member_id" id="member_id" class="form-select">
                                        <?php if ($rOwnerRow): ?>
                                            <option value="<?= (int) $rOwnerRow['id']; ?>" selected><?= htmlspecialchars((string) $rOwnerRow['username'], ENT_QUOTES); ?></option>
                                        <?php else: ?>
                                            <option value="<?= (int) ($rUserInfo['id'] ?? 0); ?>"><?= htmlspecialchars((string) ($rUserInfo['username'] ?? ''), ENT_QUOTES); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <button type="button" class="btn btn-label-warning flex-shrink-0" id="clear-owner"><?= $language::get('clear'); ?></button>
                            </div>
                        </div>

                        <div class="row mb-6">
                            <div class="col-md-8">
                                <label class="form-label" for="exp_date"><?= $language::get('expiry'); ?></label>
                                <input type="text" class="form-control" id="exp_date" name="exp_date" value="<?= $rIsEdit ? (empty($rUser['exp_date']) ? '' : date('Y-m-d H:i:s', (int) $rUser['exp_date'])) : date('Y-m-d H:i:s', time() + 2592000); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">&nbsp;</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="no_expire" name="no_expire" value="1" <?= ($rIsEdit && is_null($rUser['exp_date'] ?? null)) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="no_expire"><?= $language::get('never_expire'); ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-6">
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_trial" name="is_trial" value="1" <?= ($rIsEdit && ($rUser['is_trial'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_trial"><?= $language::get('trial_device'); ?></label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_isplock" name="is_isplock" value="1" <?= ($rIsEdit && ($rUser['is_isplock'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_isplock"><?= $language::get('lock_to_isp'); ?></label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="parent_password"><?= $language::get('adult_pin'); ?></label>
                            <input type="text" class="form-control" id="parent_password" name="parent_password" value="<?= $rIsEdit ? htmlspecialchars((string) ($rDevice['parent_password'] ?? ''), ENT_QUOTES) : '0000'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="lock_device" name="lock_device" value="1" <?= (!$rIsEdit || ($rDevice['lock_device'] ?? 0) == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="lock_device"><?= $language::get('device_lock'); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="admin_notes"><?= $language::get('admin_notes'); ?></label>
                            <textarea id="admin_notes" name="admin_notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) ($rUser['admin_notes'] ?? ''), ENT_QUOTES) : ''; ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="reseller_notes"><?= $language::get('reseller_notes'); ?></label>
                            <textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) ($rUser['reseller_notes'] ?? ''), ENT_QUOTES) : ''; ?></textarea>
                        </div>
                    </div>
                </div>

                <?php if ($rIsEdit): ?>
                    <div class="tab-pane fade" id="tab-device" role="tabpanel">
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="username"><?= $language::get('line_username'); ?></label>
                                <input type="text" class="form-control sticky" id="username" name="username" value="<?= htmlspecialchars((string) ($rUser['username'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="password"><?= $language::get('line_password'); ?></label>
                                <input type="text" class="form-control sticky" id="password" name="password" value="<?= htmlspecialchars((string) ($rUser['password'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="sn"><?= $language::get('serial_number'); ?></label>
                                <input type="text" class="form-control" id="sn" name="sn" value="<?= htmlspecialchars((string) ($rDevice['sn'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="stb_type"><?= $language::get('stb_type'); ?></label>
                                <input type="text" class="form-control" id="stb_type" name="stb_type" value="<?= htmlspecialchars((string) ($rDevice['stb_type'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="image_version"><?= $language::get('image_version'); ?></label>
                                <input type="text" class="form-control" id="image_version" name="image_version" value="<?= htmlspecialchars((string) ($rDevice['image_version'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="hw_version"><?= $language::get('hw_version'); ?></label>
                                <input type="text" class="form-control" id="hw_version" name="hw_version" value="<?= htmlspecialchars((string) ($rDevice['hw_version'] ?? ''), ENT_QUOTES); ?>">
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="device_id"><?= $language::get('primary_device_id'); ?></label>
                            <input type="text" class="form-control" id="device_id" name="device_id" value="<?= htmlspecialchars((string) ($rDevice['device_id'] ?? ''), ENT_QUOTES); ?>">
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="device_id2"><?= $language::get('secondary_device_id'); ?></label>
                            <input type="text" class="form-control" id="device_id2" name="device_id2" value="<?= htmlspecialchars((string) ($rDevice['device_id2'] ?? ''), ENT_QUOTES); ?>">
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="ver"><?= $language::get('version'); ?></label>
                            <input type="text" class="form-control" id="ver" name="ver" value="<?= htmlspecialchars((string) ($rDevice['ver'] ?? ''), ENT_QUOTES); ?>">
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-label-warning" id="clear-device"><?= $language::get('clear_device_info'); ?></button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="alert alert-warning d-none" role="alert" id="advanced_warning"><?= $language::get('this_device_is_linked_to_a_user_the_options_for_that_user_will_be_used') ?: 'This device is linked to a user, the options for that user will be used.'; ?></div>
                    <div id="advanced_info">
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="force_server_id">Forced Connection <i title="<?= $language::get('force_this_user_to_connect_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                                <select name="force_server_id" id="force_server_id" class="form-select">
                                    <option value="0" <?= (!$rIsEdit || (int) ($rUser['force_server_id'] ?? 0) === 0) ? 'selected' : ''; ?>><?= $language::get('disabled'); ?></option>
                                    <?php foreach ($rServers as $rServer): ?>
                                        <option value="<?= (int) $rServer['id']; ?>" <?= ($rIsEdit && (int) ($rUser['force_server_id'] ?? 0) === (int) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="forced_country">Forced Country <i title="<?= $language::get('force_user_to_connect_to_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                                <select name="forced_country" id="forced_country" class="form-select">
                                    <?php foreach (GeoReference::countries() as $rCountry): ?>
                                        <option value="<?= htmlspecialchars((string) $rCountry['id'], ENT_QUOTES); ?>" <?= ($rIsEdit && ($rUser['forced_country'] ?? null) == $rCountry['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCountry['name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-6">
                            <label class="form-label" for="isp_clear"><?= $language::get('current_isp'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="<?= $rIsEdit ? htmlspecialchars((string) ($rUser['isp_desc'] ?? ''), ENT_QUOTES) : ''; ?>">
                                <button type="button" class="btn btn-label-danger" id="clear-isp"><i class="icon-base ti tabler-x"></i></button>
                            </div>
                        </div>

                        <div>
                            <label class="form-label" for="ip_field"><?= $language::get('allowed_ip_addresses'); ?></label>
                            <div class="input-group mb-3">
                                <input type="text" id="ip_field" class="form-control" placeholder="0.0.0.0">
                                <button type="button" id="add_ip" class="btn btn-primary"><i class="icon-base ti tabler-plus"></i></button>
                                <button type="button" id="remove_ip" class="btn btn-label-danger"><i class="icon-base ti tabler-trash"></i></button>
                            </div>
                            <select id="allowed_ips" name="allowed_ips[]" size="6" class="form-select" multiple>
                                <?php foreach ($rDeviceIPs as $rIP): ?>
                                    <option value="<?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-bouquets" role="tabpanel">
                    <div class="alert alert-warning d-none" role="alert" id="bouquet_warning"><?= $language::get('this_device_is_linked_to_a_user_the_bouquets_for_that_user_will_be_used') ?: 'This device is linked to a user, the bouquets for that user will be used.'; ?></div>
                    <div id="bouquets_info">
                        <div class="d-flex justify-content-end mb-4">
                            <button type="button" class="btn btn-label-secondary btn-sm" id="bqt-toggle"><?= $language::get('toggle_all'); ?></button>
                        </div>
                        <div class="card-datatable table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width:1%"></th>
                                        <th class="text-center"><?= $language::get('id'); ?></th>
                                        <th><?= $language::get('bouquet_name'); ?></th>
                                        <th class="text-center"><?= $language::get('streams'); ?></th>
                                        <th class="text-center"><?= $language::get('movies'); ?></th>
                                        <th class="text-center"><?= $language::get('series'); ?></th>
                                        <th class="text-center"><?= $language::get('stations'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input mag-bouquet-cb" type="checkbox" value="<?= (int) $rBouquet['id']; ?>" id="mag-bouquet-<?= (int) $rBouquet['id']; ?>" <?= in_array($rBouquet['id'], $rSelBouquets) ? 'checked' : ''; ?>>
                                                </div>
                                            </td>
                                            <td class="text-center"><label class="form-check-label" for="mag-bouquet-<?= (int) $rBouquet['id']; ?>"><?= (int) $rBouquet['id']; ?></label></td>
                                            <td><label class="form-check-label" for="mag-bouquet-<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></label></td>
                                            <td class="text-center"><?= count(json_decode((string) $rBouquet['bouquet_channels'], true) ?: []); ?></td>
                                            <td class="text-center"><?= count(json_decode((string) $rBouquet['bouquet_movies'], true) ?: []); ?></td>
                                            <td class="text-center"><?= count(json_decode((string) $rBouquet['bouquet_series'], true) ?: []); ?></td>
                                            <td class="text-center"><?= count(json_decode((string) $rBouquet['bouquet_radios'], true) ?: []); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="mag-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var $ = window.jQuery;

        // select2 owner (registered users) + paired-user (all lines) remote search.
        if ($ && $.fn.select2) {
            $('#member_id').select2({
                width: '100%',
                dropdownParent: $('#member_id').closest('.tab-pane'),
                ajax: {
                    url: './api', dataType: 'json', cache: true,
                    data: function(params) { return { search: params.term, action: 'reguserlist', page: params.page }; },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return { results: data.items, pagination: { more: (params.page * 100) < data.total_count } };
                    }
                }
            });
            $('#pair_id').select2({
                width: '100%',
                allowClear: true,
                placeholder: <?= json_encode($language::get('search_user')); ?>,
                dropdownParent: $('#pair_id').closest('.tab-pane'),
                ajax: {
                    url: './api', dataType: 'json', cache: true,
                    data: function(params) { return { search: params.term, action: 'userlist', page: params.page }; },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return { results: data.items, pagination: { more: (params.page * 100) < data.total_count } };
                    }
                }
            });
        }

        var pairEl = document.getElementById('pair_id');
        var isPaired = function() { return !!(pairEl && pairEl.value); };

        // Pairing a user takes over the owner/expiry/advanced/bouquet inputs, so hide
        // and disable them while paired (legacy evaluatePair()).
        var pairedFields = ['exp_date', 'is_trial', 'no_expire', 'is_isplock', 'force_server_id', 'forced_country', 'ip_field', 'allowed_ips', 'member_id'];
        var applyPair = function() {
            var paired = isPaired();
            document.getElementById('linked_info').classList.toggle('d-none', paired);
            document.getElementById('advanced_info').classList.toggle('d-none', paired);
            document.getElementById('bouquets_info').classList.toggle('d-none', paired);
            document.getElementById('advanced_warning').classList.toggle('d-none', !paired);
            document.getElementById('bouquet_warning').classList.toggle('d-none', !paired);
            pairedFields.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) { el.disabled = paired; }
            });
        };
        if ($ && pairEl) {
            $('#pair_id').on('change', applyPair);
        } else if (pairEl) {
            pairEl.addEventListener('change', applyPair);
        }

        // Unpair / clear-owner / clear-isp buttons.
        document.getElementById('unpair-user').addEventListener('click', function() {
            if ($) { $('#pair_id').val(null).trigger('change'); } else { pairEl.value = ''; applyPair(); }
        });
        document.getElementById('clear-owner').addEventListener('click', function() {
            if ($) { $('#member_id').val('').trigger('change'); } else { document.getElementById('member_id').value = ''; }
        });
        document.getElementById('clear-isp').addEventListener('click', function() {
            document.getElementById('isp_clear').value = '';
        });

        // MAC input formatter.
        var macEl = document.getElementById('mac');
        if (macEl) {
            macEl.addEventListener('input', function(e) {
                var re = /([a-f0-9]{2})([a-f0-9]{2})/i,
                    s = e.target.value.replace(/[^a-f0-9]/ig, '');
                while (re.test(s)) { s = s.replace(re, '$1:$2'); }
                e.target.value = s.slice(0, 17).toUpperCase();
            });
        }

        // Clear device-info fields (except sticky username/password).
        var clearDev = document.getElementById('clear-device');
        if (clearDev) {
            clearDev.addEventListener('click', function() {
                document.querySelectorAll('#tab-device input:not(.sticky)').forEach(function(i) { i.value = ''; });
            });
        }

        // Never-expire disables the expiry field.
        var noExpire = document.getElementById('no_expire'),
            expDate = document.getElementById('exp_date');
        var applyExpire = function() {
            if (!isPaired()) { expDate.disabled = noExpire.checked; }
        };
        noExpire.addEventListener('change', applyExpire);

        // Allowed-IP list widget.
        var validIP = function(v) {
            return /^[0-9.]+$/.test(v) || /^[0-9a-fA-F:]+$/.test(v);
        };
        var ipSel = document.getElementById('allowed_ips'),
            ipField = document.getElementById('ip_field');
        document.getElementById('add_ip').addEventListener('click', function() {
            if (isPaired()) { return; }
            var v = ipField.value.trim();
            if (!v || !validIP(v)) { alert('Please enter a valid IP address.'); return; }
            var exists = Array.prototype.some.call(ipSel.options, function(o) { return o.value === v; });
            if (!exists) { ipSel.add(new Option(v, v)); }
            ipField.value = '';
        });
        document.getElementById('remove_ip').addEventListener('click', function() {
            if (isPaired()) { return; }
            Array.prototype.slice.call(ipSel.selectedOptions).forEach(function(o) { o.remove(); });
        });

        // Bouquet toggle-all + collect.
        document.getElementById('bqt-toggle').addEventListener('click', function() {
            var boxes = document.querySelectorAll('.mag-bouquet-cb');
            var allOn = Array.prototype.every.call(boxes, function(c) { return c.checked; });
            boxes.forEach(function(c) { c.checked = !allOn; });
        });
        var collect = function(cls) {
            var ids = [];
            document.querySelectorAll('.' + cls + ':checked').forEach(function(c) { ids.push(c.value); });
            return JSON.stringify(ids);
        };

        applyPair();
        applyExpire();

        // Submit → post.php?action=mag. Re-enable paired-disabled inputs so their
        // values still post, select every allowed-IP option, serialise bouquets.
        document.getElementById('mag-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!macEl.value) { alert('Please enter a valid MAC address.'); return; }
            document.getElementById('bouquets_selected').value = collect('mag-bouquet-cb');
            Array.prototype.forEach.call(ipSel.options, function(o) { o.selected = true; });
            pairedFields.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) { el.disabled = false; }
            });
            var btn = document.getElementById('mag-submit');
            btn.disabled = true;
            fetch('post.php?action=mag', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt;
                    try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) {
                            window.parent.postMessage('xcModalSaved', '*');
                        } else {
                            window.location.href = dt.location || 'mags';
                        }
                        return;
                    }
                    btn.disabled = false;
                    applyPair();
                    alert(errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    applyPair();
                    alert(errText);
                });
        });
    })();
</script>
</body>

</html>
