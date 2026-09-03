<?php

/**
 * Package add / edit (Bootstrap 5). Full-page form reached from the packages table
 * (href="package?id=X"). Bootstrap 5 vertical layout — each former wizard tab becomes
 * its own section card: Details (name + trial/standard pricing), Options (device
 * flags, forced connection, output formats, forced country), Groups (reseller
 * group checkboxes) and Bouquets (bouquet checkboxes). The Groups / Bouquets
 * checkbox tables are serialised into the hidden groups_selected / bouquets_selected
 * inputs (JSON id arrays) on submit, exactly as the legacy datatable collectors did.
 * Posts to post.php?action=package via fetch; on success returns to the list.
 */

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineRepository;
use XcVm\Domain\User\GroupService;

$rIsEdit         = isset($rPackage);
$rPackageGroups  = ($rIsEdit && !empty($rPackage['groups'])) ? (json_decode((string) $rPackage['groups'], true) ?: []) : [];
$rPackageBqts    = ($rIsEdit && !empty($rPackage['bouquets'])) ? (json_decode((string) $rPackage['bouquets'], true) ?: []) : [];
$rPackageOutputs = ($rIsEdit && !empty($rPackage['output_formats'])) ? (json_decode((string) $rPackage['output_formats'], true) ?: []) : [];
?>

<div class="d-flex align-items-center mb-4">
    <a href="packages" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit_package') : $language::get('add_package'); ?></h4>
</div>

<form id="package-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rPackage['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="">
    <input type="hidden" name="groups_selected" id="groups_selected" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-options" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('options'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-groups" role="tab"><i class="icon-base ti tabler-users me-1"></i><?= $language::get('groups'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bouquets" role="tab"><i class="icon-base ti tabler-list me-1"></i><?= $language::get('bouquets'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="package_name"><?= $language::get('package_name'); ?></label>
                        <input type="text" class="form-control" id="package_name" name="package_name" value="<?= $rIsEdit ? htmlspecialchars((string) $rPackage['package_name'], ENT_QUOTES) : ''; ?>">
                    </div>

                    <h6 class="mb-3">Trial Package</h6>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="is_trial" name="is_trial" value="1" <?= ($rIsEdit && $rPackage['is_trial'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_trial"><?= $language::get('enabled'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="trial_credits"><?= $language::get('credit_cost'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="trial_credits" name="trial_credits" value="<?= $rIsEdit ? htmlspecialchars((string) $rPackage['trial_credits'], ENT_QUOTES) : '0'; ?>">
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="trial_duration"><?= $language::get('duration'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="trial_duration" name="trial_duration" value="<?= $rIsEdit ? htmlspecialchars((string) $rPackage['trial_duration'], ENT_QUOTES) : '0'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="trial_duration_in">&nbsp;</label>
                            <select name="trial_duration_in" id="trial_duration_in" class="form-select">
                                <?php foreach ([$language::get('hours') => 'hours', $language::get('days') => 'days'] as $rText => $rOption): ?>
                                    <option value="<?= htmlspecialchars((string) $rOption, ENT_QUOTES); ?>" <?= ($rIsEdit && $rPackage['trial_duration_in'] == $rOption) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rText, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h6 class="mb-3">Standard Package</h6>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="is_official" name="is_official" value="1" <?= ($rIsEdit && $rPackage['is_official'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_official"><?= $language::get('enabled'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="official_credits"><?= $language::get('credit_cost'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="official_credits" name="official_credits" value="<?= $rIsEdit ? htmlspecialchars((string) $rPackage['official_credits'], ENT_QUOTES) : '0'; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="official_duration"><?= $language::get('duration'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="official_duration" name="official_duration" value="<?= $rIsEdit ? htmlspecialchars((string) $rPackage['official_duration'], ENT_QUOTES) : '0'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="official_duration_in">&nbsp;</label>
                            <select name="official_duration_in" id="official_duration_in" class="form-select">
                                <?php foreach ([$language::get('hours') => 'hours', $language::get('days') => 'days', $language::get('months') => 'months', $language::get('years') => 'years'] as $rText => $rOption): ?>
                                    <option value="<?= htmlspecialchars((string) $rOption, ENT_QUOTES); ?>" <?= ($rIsEdit && $rPackage['official_duration_in'] == $rOption) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rText, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-options" role="tabpanel">
                    <div class="row g-3 mb-6">
                        <?php
                        // Device / behaviour switches. is_line and check_compatible default to
                        // checked when adding (matching the legacy Switchery defaults).
                        $rSwitches = [
                            'is_mag'           => ['label' => $language::get('mag_device'),          'default' => false],
                            'is_e2'            => ['label' => $language::get('enigma_device'),       'default' => false],
                            'is_line'          => ['label' => $language::get('standard_line'),       'default' => true],
                            'is_isplock'       => ['label' => $language::get('lock_to_isp'),         'default' => false],
                            'is_restreamer'    => ['label' => $language::get('restreamer'),          'default' => false],
                            'check_compatible' => ['label' => $language::get('verify_compatibility'), 'default' => true],
                        ];
                        foreach ($rSwitches as $rKey => $rInfo):
                            $rChecked = $rIsEdit ? ($rPackage[$rKey] == 1) : $rInfo['default'];
                        ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="<?= $rKey; ?>" name="<?= $rKey; ?>" value="1" <?= $rChecked ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="<?= $rKey; ?>"><?= htmlspecialchars((string) $rInfo['label'], ENT_QUOTES); ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="force_server_id"><?= $language::get('forced_connection'); ?></label>
                            <select name="force_server_id" id="force_server_id" class="form-select">
                                <option value="0" <?= (!$rIsEdit || (int) $rPackage['force_server_id'] === 0) ? 'selected' : ''; ?>><?= $language::get('disabled'); ?></option>
                                    <?php foreach ($rServers as $rServer): ?>
                                <option value="<?= htmlspecialchars((string) $rServer['id'], ENT_QUOTES); ?>" <?= ($rIsEdit && (int) $rPackage['force_server_id'] === (int) $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="forced_country"><?= $language::get('forced_country'); ?></label>
                            <select name="forced_country" id="forced_country" class="form-select">
                                <?php foreach ($rCountries as $rCountry): ?>
                                    <option value="<?= htmlspecialchars((string) $rCountry['id'], ENT_QUOTES); ?>" <?= ($rIsEdit && $rPackage['forced_country'] == $rCountry['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCountry['name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="max_connections"><?= $language::get('max_connections'); ?></label>
                        <input type="text" inputmode="numeric" class="form-control" id="max_connections" name="max_connections" value="<?= $rIsEdit ? htmlspecialchars((string) $rPackage['max_connections'], ENT_QUOTES) : '1'; ?>">
                    </div>

                    <div>
                        <label class="form-label d-block"><?= $language::get('access_output'); ?></label>
                        <?php foreach (LineRepository::getOutputFormats() as $rOutput): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" id="output_formats_<?= (int) $rOutput['access_output_id']; ?>" name="output_formats[]" value="<?= (int) $rOutput['access_output_id']; ?>" <?= in_array($rOutput['access_output_id'], $rPackageOutputs) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="output_formats_<?= (int) $rOutput['access_output_id']; ?>"><?= htmlspecialchars((string) $rOutput['output_name'], ENT_QUOTES); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-groups" role="tabpanel">
                    <div class="d-flex justify-content-end mb-4">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-label-secondary" id="grp-all"><?= $language::get('select_all'); ?></button>
                            <button type="button" class="btn btn-label-secondary" id="grp-none"><?= $language::get('deselect_all'); ?></button>
                        </div>
                    </div>
                    <div class="card-datatable table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:1%"></th>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th><?= $language::get('group_name'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (GroupService::getAll() as $rGroup): ?>
                                    <?php if ($rGroup['is_reseller']): ?>
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input package-group-cb" type="checkbox" value="<?= (int) $rGroup['group_id']; ?>" id="pkg-group-<?= (int) $rGroup['group_id']; ?>" <?= in_array($rGroup['group_id'], $rPackageGroups) ? 'checked' : ''; ?>>
                                                </div>
                                            </td>
                                            <td class="text-center"><label class="form-check-label" for="pkg-group-<?= (int) $rGroup['group_id']; ?>"><?= (int) $rGroup['group_id']; ?></label></td>
                                            <td><label class="form-check-label" for="pkg-group-<?= (int) $rGroup['group_id']; ?>"><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></label></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                                                <input class="form-check-input package-bouquet-cb" type="checkbox" value="<?= (int) $rBouquet['id']; ?>" id="pkg-bouquet-<?= (int) $rBouquet['id']; ?>" <?= in_array($rBouquet['id'], $rPackageBqts) ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td class="text-center"><label class="form-check-label" for="pkg-bouquet-<?= (int) $rBouquet['id']; ?>"><?= (int) $rBouquet['id']; ?></label></td>
                                        <td><label class="form-check-label" for="pkg-bouquet-<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></label></td>
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
        <button type="submit" class="btn btn-primary" id="package-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;

        // Numeric-only guards mirroring the legacy inputFilter (/^\d*$/).
        ['max_connections', 'trial_duration', 'official_duration', 'trial_credits', 'official_credits'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
        });

        // Select-all / deselect-all button pairs for each checkbox table.
        var bindToggle = function(allId, noneId, cls) {
            var all = document.getElementById(allId),
                none = document.getElementById(noneId);
            var set = function(state) {
                document.querySelectorAll('.' + cls).forEach(function(c) {
                    c.checked = state;
                });
            };
            if (all) {
                all.addEventListener('click', function() {
                    set(true);
                });
            }
            if (none) {
                none.addEventListener('click', function() {
                    set(false);
                });
            }
        };
        bindToggle('grp-all', 'grp-none', 'package-group-cb');
        bindToggle('bqt-all', 'bqt-none', 'package-bouquet-cb');

        // Collect checked ids as a JSON string array (legacy pushed td text; here the
        // checkbox value carries the id) into the matching hidden input.
        var collect = function(cls) {
            var ids = [];
            document.querySelectorAll('.' + cls + ':checked').forEach(function(c) {
                ids.push(c.value);
            });
            return JSON.stringify(ids);
        };

        // Submit → post.php?action=package.
        document.getElementById('package-form').addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('groups_selected').value = collect('package-group-cb');
            document.getElementById('bouquets_selected').value = collect('package-bouquet-cb');
            var btn = document.getElementById('package-submit');
            btn.disabled = true;
            fetch('post.php?action=package', {
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
                        window.location.href = dt.location || 'packages';
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