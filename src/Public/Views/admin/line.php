<?php

/**
 * Line add / edit (Bootstrap 5). Reached two ways: full-page from the lines table
 * ("Add Line" → line) inside the normal Bootstrap 5 shell, and as an iframe modal
 * ("Edit" from the lines table → line?id=X&modal=1) inside the Bootstrap 5 modal shell.
 * Bootstrap 5 vertical layout — each former wizard tab becomes its own section card:
 * Details (credentials, owner, expiry, connections, contact, notes), Advanced
 * (forced connection, device/behaviour switches, ISP, access token, forced
 * country, output formats), Restrictions (allowed-IP / allowed-UA whitelists +
 * bypass-UA switch) and Bouquets (bouquet checkbox table). The bouquet checkboxes
 * serialise into the hidden bouquets_selected input (a JSON id array) on submit,
 * exactly as the legacy datatable collector did. Posts to post.php?action=line via
 * fetch; in the iframe modal it posts xcModalSaved to the parent (which closes the
 * modal and reloads the table), full-page it returns to the list.
 */

use XcVm\Core\Reference\GeoReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineRepository;

$rIsEdit         = isset($rLine);
$rLineBouquets   = ($rIsEdit && !empty($rLine['bouquet'])) ? (json_decode((string) $rLine['bouquet'], true) ?: []) : [];
$rLineOutputs    = ($rIsEdit && !empty($rLine['allowed_outputs'])) ? (json_decode((string) $rLine['allowed_outputs'], true) ?: []) : [];
$rLineIPs        = ($rIsEdit && !empty($rLine['allowed_ips'])) ? (json_decode((string) $rLine['allowed_ips'], true) ?: []) : [];
$rLineUAs        = ($rIsEdit && !empty($rLine['allowed_ua'])) ? (json_decode((string) $rLine['allowed_ua'], true) ?: []) : [];
$rOwnerID        = $rIsEdit ? (int) $rLine['member_id'] : (int) ($rUserInfo['id'] ?? 0);
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="lines" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('line') ?: 'Line'; ?></h4>
    </div>
<?php endif; ?>

<form id="line-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rLine['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-restrictions" role="tab"><i class="icon-base ti tabler-shield-lock me-1"></i><?= $language::get('restrictions'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bouquets" role="tab"><i class="icon-base ti tabler-list me-1"></i><?= $language::get('bouquets'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="username"><?= $language::get('username'); ?></label>
                            <input type="text" class="form-control" id="username" name="username" placeholder="<?= $language::get('auto_generate_if_blank'); ?>" value="<?= $rIsEdit ? htmlspecialchars((string) $rLine['username'], ENT_QUOTES) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password"><?= $language::get('password'); ?></label>
                            <input type="text" class="form-control" id="password" name="password" placeholder="<?= $language::get('auto_generate_if_blank'); ?>" value="<?= $rIsEdit ? htmlspecialchars((string) $rLine['password'], ENT_QUOTES) : ''; ?>">
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="member_id"><?= $language::get('owner'); ?></label>
                        <div class="input-group">
                            <select name="member_id" id="member_id" class="form-select">
                                <option value=""></option>
                                <?php foreach ($rRegisteredUsers as $rRegUser): ?>
                                    <?php if (empty($rRegUser['id'])): continue;
                                    endif; ?>
                                    <option value="<?= (int) $rRegUser['id']; ?>" <?= ((int) $rRegUser['id'] === $rOwnerID) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rRegUser['username'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-label-warning" id="clear-owner"><?= $language::get('clear'); ?></button>
                        </div>
                    </div>

                    <div class="row mb-6">
                        <div class="col-md-8">
                            <label class="form-label" for="exp_date"><?= $language::get('expiry'); ?></label>
                            <input type="text" class="form-control" id="exp_date" name="exp_date" value="<?= $rIsEdit ? (is_null($rLine['exp_date']) ? '' : date('Y-m-d H:i:s', (int) $rLine['exp_date'])) : date('Y-m-d H:i:s', time() + 2592000); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="no_expire" name="no_expire" value="1" <?= ($rIsEdit && is_null($rLine['exp_date'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="no_expire"><?= $language::get('never_expire'); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="max_connections"><?= $language::get('max_connections'); ?></label>
                        <input type="text" inputmode="numeric" class="form-control" id="max_connections" name="max_connections" value="<?= $rIsEdit ? htmlspecialchars((string) $rLine['max_connections'], ENT_QUOTES) : '1'; ?>">
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="contact">WhatsApp <i title="<?= $language::get('enter_whatsapp_number_with_country_code_eg_491234567890'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                        <input type="text" class="form-control" id="contact" name="contact" placeholder="+491234567890" value="<?= $rIsEdit ? htmlspecialchars((string) $rLine['contact'], ENT_QUOTES) : ''; ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="admin_notes"><?= $language::get('admin_notes'); ?></label>
                            <textarea id="admin_notes" name="admin_notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rLine['admin_notes'], ENT_QUOTES) : ''; ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="reseller_notes"><?= $language::get('reseller_notes'); ?></label>
                            <textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rLine['reseller_notes'], ENT_QUOTES) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="force_server_id">Forced Connection <i title="<?= $language::get('force_this_user_to_connect_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <select name="force_server_id" id="force_server_id" class="form-select">
                                <option value="0" <?= (!$rIsEdit || (int) $rLine['force_server_id'] === 0) ? 'selected' : ''; ?>><?= $language::get('disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>" <?= ($rIsEdit && (int) $rLine['force_server_id'] === (int) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="forced_country">Forced Country <i title="<?= $language::get('force_user_to_connect_to_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <select name="forced_country" id="forced_country" class="form-select">
                                <?php foreach (GeoReference::countries() as $rCountry): ?>
                                    <option value="<?= htmlspecialchars((string) $rCountry['id'], ENT_QUOTES); ?>" <?= ($rIsEdit && $rLine['forced_country'] == $rCountry['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCountry['name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-6">
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_stalker" name="is_stalker" value="1" <?= ($rIsEdit && $rLine['is_stalker'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_stalker">Ministra Portal <i title="<?= $language::get('select_this_option_if_you_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_restreamer" name="is_restreamer" value="1" <?= ($rIsEdit && $rLine['is_restreamer'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_restreamer">Restreamer <i title="<?= $language::get('if_selected_this_user_will_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_trial" name="is_trial" value="1" <?= ($rIsEdit && $rLine['is_trial'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_trial"><?= $language::get('trial_account'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_isplock" name="is_isplock" value="1" <?= ($rIsEdit && $rLine['is_isplock'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_isplock"><?= $language::get('lock_to_isp'); ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="isp_clear"><?= $language::get('current_isp'); ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="<?= $rIsEdit ? htmlspecialchars((string) $rLine['isp_desc'], ENT_QUOTES) : ''; ?>">
                            <button type="button" class="btn btn-label-danger" id="clear-isp"><i class="icon-base ti tabler-x"></i></button>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="access_token">Access Token <i title="<?= $language::get('generate_an_access_token_that_tooltip'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                        <div class="input-group">
                            <input type="text" readonly class="form-control" id="access_token" name="access_token" value="<?= $rIsEdit ? htmlspecialchars((string) $rLine['access_token'], ENT_QUOTES) : ''; ?>">
                            <button type="button" class="btn btn-label-primary" id="gen-token"><i class="icon-base ti tabler-refresh"></i></button>
                            <button type="button" class="btn btn-label-danger" id="clear-token"><i class="icon-base ti tabler-x"></i></button>
                        </div>
                    </div>

                    <div>
                        <label class="form-label d-block"><?= $language::get('access_output'); ?></label>
                        <?php foreach (LineRepository::getOutputFormats() as $rOutput): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="access_output_<?= (int) $rOutput['access_output_id']; ?>" name="access_output[]" value="<?= (int) $rOutput['access_output_id']; ?>" <?= ($rIsEdit ? in_array($rOutput['access_output_id'], $rLineOutputs) : true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="access_output_<?= (int) $rOutput['access_output_id']; ?>"><?= htmlspecialchars((string) $rOutput['output_name'], ENT_QUOTES); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-restrictions" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="ip_field"><?= $language::get('allowed_ip_addresses'); ?></label>
                        <div class="input-group mb-3">
                            <input type="text" id="ip_field" class="form-control" placeholder="0.0.0.0">
                            <button type="button" id="add_ip" class="btn btn-primary"><i class="icon-base ti tabler-plus"></i></button>
                            <button type="button" id="remove_ip" class="btn btn-label-danger"><i class="icon-base ti tabler-trash"></i></button>
                        </div>
                        <select id="allowed_ips" name="allowed_ips[]" size="6" class="form-select" multiple>
                            <?php foreach ($rLineIPs as $rIP): ?>
                                <option value="<?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="ua_field"><?= $language::get('allowed_user_agents'); ?></label>
                        <div class="input-group mb-3">
                            <input type="text" id="ua_field" class="form-control">
                            <button type="button" id="add_ua" class="btn btn-primary"><i class="icon-base ti tabler-plus"></i></button>
                            <button type="button" id="remove_ua" class="btn btn-label-danger"><i class="icon-base ti tabler-trash"></i></button>
                        </div>
                        <select id="allowed_ua" name="allowed_ua[]" size="6" class="form-select" multiple>
                            <?php foreach ($rLineUAs as $rUA): ?>
                                <option value="<?= htmlspecialchars((string) $rUA, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rUA, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="bypass_ua" name="bypass_ua" value="1" <?= ($rIsEdit && $rLine['bypass_ua'] == 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="bypass_ua"><?= $language::get('bypass_ua_restrictions'); ?></label>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-bouquets" role="tabpanel">
                    <div class="d-flex justify-content-end mb-4">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-label-secondary" id="bqt-all"><?= $language::get('select_all'); ?></button>
                            <button type="button" class="btn btn-label-secondary" id="bqt-none"><?= $language::get('deselect_all'); ?></button>
                        </div>
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
                                                <input class="form-check-input line-bouquet-cb" type="checkbox" value="<?= (int) $rBouquet['id']; ?>" id="line-bouquet-<?= (int) $rBouquet['id']; ?>" <?= in_array($rBouquet['id'], $rLineBouquets) ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td class="text-center"><label class="form-check-label" for="line-bouquet-<?= (int) $rBouquet['id']; ?>"><?= (int) $rBouquet['id']; ?></label></td>
                                        <td><label class="form-check-label" for="line-bouquet-<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></label></td>
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

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="line-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;

        // Numeric-only guard mirroring the legacy inputFilter (/^\d*$/).
        var mc = document.getElementById('max_connections');
        if (mc) {
            mc.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }

        // Owner clear (legacy clearOwner()).
        document.getElementById('clear-owner').addEventListener('click', function() {
            document.getElementById('member_id').value = '';
        });

        // Access-token generate / clear (legacy generateToken() / clearToken()).
        document.getElementById('gen-token').addEventListener('click', function() {
            var chars = 'ABCDEF0123456789',
                out = '';
            for (var i = 0; i < 32; i++) {
                out += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('access_token').value = out;
        });
        document.getElementById('clear-token').addEventListener('click', function() {
            document.getElementById('access_token').value = '';
        });

        // ISP clear (legacy clearISP()).
        document.getElementById('clear-isp').addEventListener('click', function() {
            document.getElementById('isp_clear').value = '';
        });

        // Never-expire disables the expiry field (legacy #no_expire change handler).
        var noExpire = document.getElementById('no_expire'),
            expDate = document.getElementById('exp_date');
        var applyExpire = function() {
            expDate.disabled = noExpire.checked;
        };
        noExpire.addEventListener('change', applyExpire);
        applyExpire();

        // Allowed-IP / allowed-UA list widgets (legacy add_ip / remove_ip / add_ua / remove_ua).
        var validIP = function(v) {
            return /^[0-9.]+$/.test(v) || /^[0-9a-fA-F:]+$/.test(v);
        };
        var bindList = function(fieldId, addId, removeId, selectId, validate) {
            var sel = document.getElementById(selectId),
                field = document.getElementById(fieldId);
            document.getElementById(addId).addEventListener('click', function() {
                var v = field.value.trim();
                if (!v || (validate && !validate(v))) {
                    alert('Please enter a valid value.');
                    return;
                }
                var exists = Array.prototype.some.call(sel.options, function(o) {
                    return o.value === v;
                });
                if (!exists) {
                    sel.add(new Option(v, v));
                }
                field.value = '';
            });
            document.getElementById(removeId).addEventListener('click', function() {
                Array.prototype.slice.call(sel.selectedOptions).forEach(function(o) {
                    o.remove();
                });
            });
        };
        bindList('ip_field', 'add_ip', 'remove_ip', 'allowed_ips', validIP);
        bindList('ua_field', 'add_ua', 'remove_ua', 'allowed_ua', null);

        // Bouquet select-all / deselect-all.
        document.getElementById('bqt-all').addEventListener('click', function() {
            document.querySelectorAll('.line-bouquet-cb').forEach(function(c) {
                c.checked = true;
            });
        });
        document.getElementById('bqt-none').addEventListener('click', function() {
            document.querySelectorAll('.line-bouquet-cb').forEach(function(c) {
                c.checked = false;
            });
        });

        // Collect checked bouquet ids as a JSON string array (legacy pushed the row id
        // text; here the checkbox value carries the id) into the hidden input.
        var collect = function(cls) {
            var ids = [];
            document.querySelectorAll('.' + cls + ':checked').forEach(function(c) {
                ids.push(c.value);
            });
            return JSON.stringify(ids);
        };

        // Submit → post.php?action=line. Select every whitelist option first so the
        // multi-selects are fully submitted (legacy selected all options on submit).
        document.getElementById('line-form').addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('bouquets_selected').value = collect('line-bouquet-cb');
            ['allowed_ips', 'allowed_ua'].forEach(function(id) {
                var sel = document.getElementById(id);
                Array.prototype.forEach.call(sel.options, function(o) {
                    o.selected = true;
                });
            });
            var btn = document.getElementById('line-submit');
            btn.disabled = true;
            fetch('post.php?action=line', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var dt;
                    try {
                        dt = JSON.parse(txt);
                    } catch (err) {
                        dt = {
                            result: false
                        };
                    }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) {
                            window.parent.postMessage('xcModalSaved', '*');
                        } else {
                            window.location.href = dt.location || 'lines';
                        }
                        return;
                    }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    alert(errText);
                });
        });
    })();
</script>
</body>

</html>