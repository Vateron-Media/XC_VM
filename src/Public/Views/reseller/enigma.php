<?php

/**
 * Enigma2 device add / edit (Bootstrap 5, reseller). Full-page create/edit form
 * reached from the reseller enigmas table (enigma?id=X) or "Add Enigma" /
 * "Generate Trial" (no id = create; ?trial=1 = trial). Rebuilt from the legacy
 * wizard reseller/enigma.php onto the Bootstrap 5 shell; the POST field contract
 * is preserved byte-for-byte.
 *
 * Posts to post.php?action=enigma (ResellerPostController -> ResellerAPI::processEnigma).
 * ResellerAPI::processData('enigma') keeps ONLY: edit, trial, bouquets_selected,
 * pair_id, mac, member_id, package, modem_mac, local_ip, enigma_version, cpu,
 * lversion, token, reseller_notes, allowed_ips, is_isplock, isp_clear — this form
 * emits exactly those. Package selection calls ./api?action=get_package[_trial]
 * and fills the review table with the package's bouquets as bouquets_selected[]
 * checkboxes (only when allow_change_bouquets), mirroring the legacy footer.php
 * reseller "enigma" script.
 *
 * Variables from controller: $rDevice, $rLine, $rOrigPackage, $rPackages
 * ViewGlobals: $rUserInfo, $rPermissions, $rSettings, $rGenTrials, $language, $rRequest
 */

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\UserRepository;

$rIsEdit  = isset($rDevice['device_id']) && !isset($_STATUS);
$rIsTrial = isset($rRequest['trial']);
$rAllowChange = (bool) $rPermissions['allow_change_bouquets'];

// Pre-computed review rows for the "No Changes" (empty package) branch on edit —
// mirrors the legacy server-side render in footer.php.
$rReviewRows = [];
if (isset($rLine)) {
    $rCheckboxMode = $rAllowChange;
    if ($rAllowChange && $rLine['package_id']) {
        $rPkg    = PackageService::getById($rLine['package_id']);
        $rBqList = json_decode((string) ($rPkg['bouquets'] ?? ''), true);
    } else {
        $rBqList = null;
    }
    if (!is_array($rBqList)) {
        $rBqList       = json_decode((string) ($rLine['bouquet'] ?? ''), true) ?: [];
        $rCheckboxMode = false;
    }
    $rLineBqs = json_decode((string) ($rLine['bouquet'] ?? ''), true) ?: [];
    foreach ($rBqList as $rBqID) {
        if ((string) $rBqID === '') {
            continue;
        }
        $rBqData = BouquetService::getById($rBqID);
        if (!$rBqData) {
            continue;
        }
        $rReviewRows[] = [
            'id'       => (int) $rBqID,
            'name'     => (string) $rBqData['bouquet_name'],
            'channels' => count(json_decode((string) $rBqData['bouquet_channels'], true) ?: []),
            'movies'   => count(json_decode((string) $rBqData['bouquet_movies'], true) ?: []),
            'series'   => count(json_decode((string) $rBqData['bouquet_series'], true) ?: []),
            'radios'   => count(json_decode((string) $rBqData['bouquet_radios'], true) ?: []),
            'checkbox' => $rCheckboxMode,
            'checked'  => in_array($rBqID, $rLineBqs),
        ];
    }
}

// Numeric status -> user message map (mirrors the legacy callbackForm 'enigma' switch).
$rStatusMessages = [
    STATUS_INVALID_TYPE         => 'This package is not supported.',
    STATUS_NO_TRIALS            => 'You cannot generate trials at this time.',
    STATUS_INSUFFICIENT_CREDITS => 'You do not have enough credits to make this purchase.',
    STATUS_INVALID_PACKAGE      => 'Please select a valid package.',
    STATUS_INVALID_MAC          => 'Please enter a valid MAC address.',
    STATUS_EXISTS_MAC           => 'The MAC address you entered is already in use.',
];
?>

<div class="d-flex align-items-center mb-4">
    <a href="enigmas" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? 'Edit' : 'Add'; ?><?= $rIsTrial ? ' Trial' : ''; ?> Enigma Device</h4>
</div>

<?php if (!$rGenTrials && !isset($rLine) && $rIsTrial): ?>
    <div class="alert alert-danger" role="alert">
        <?= $rSettings['disable_trial'] ? 'Trials have been disabled by the administrator. Please try again later.' : 'You have used your allowance of trials for this period. Please try again later.'; ?>
    </div>
<?php else: ?>
    <?php if (isset($rLine) && $rLine['is_trial']): ?>
        <div class="alert alert-info" role="alert">This device is on a trial package. Adding a new package will convert it to an official package.</div>
    <?php endif; ?>
    <?php if (isset($rLine) && !in_array($rLine['member_id'], array_merge([$rUserInfo['id']], $rPermissions['direct_reports']))): ?>
        <?php $rOwner = UserRepository::getRegisteredUserById($rLine['member_id']); ?>
        <div class="alert alert-info" role="alert">
            This device does not belong to you, although you have the right to edit this device you should notify the device's owner <strong><a href="user?id=<?= (int) $rOwner['id']; ?>"><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES); ?></a></strong> when doing so.
        </div>
    <?php endif; ?>

    <form id="enigma-form" autocomplete="off">
        <?php if ($rIsEdit): ?>
            <input type="hidden" name="edit" value="<?= (int) $rDevice['device_id']; ?>">
        <?php elseif ($rIsTrial): ?>
            <input type="hidden" name="trial" value="1">
        <?php endif; ?>

        <div class="card mb-6">
            <div class="card-header px-0 pt-2">
                <div class="nav-align-top">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i>Details</button></li>
                        <?php if ($rIsEdit): ?>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-device" role="tab"><i class="icon-base ti tabler-device-mobile me-1"></i>Device Info</button></li>
                        <?php endif; ?>
                        <?php if ($rPermissions['allow_restrictions']): ?>
                            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-restrictions" role="tab"><i class="icon-base ti tabler-shield-lock me-1"></i>Restrictions</button></li>
                        <?php endif; ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-review" role="tab"><i class="icon-base ti tabler-book me-1"></i>Review Purchase</button></li>
                    </ul>
                </div>
            </div>
            <div class="card-body">
                <div class="tab-content p-0">
                    <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                        <div class="mb-6">
                            <label class="form-label" for="mac">MAC Address</label>
                            <input type="text" class="form-control" id="mac" name="mac" value="<?= isset($rDevice) ? htmlspecialchars((string) $rDevice['mac'], ENT_QUOTES) : ''; ?>">
                        </div>

                        <?php if (count($rPermissions['all_reports']) > 0): ?>
                            <div class="mb-6">
                                <label class="form-label" for="member_id">Owner</label>
                                <select name="member_id" id="member_id" class="form-select select2">
                                    <optgroup label="Myself">
                                        <option value="<?= (int) $rUserInfo['id']; ?>"<?= isset($rLine['member_id']) && $rLine['member_id'] == $rUserInfo['id'] ? ' selected' : ''; ?>><?= htmlspecialchars((string) $rUserInfo['username'], ENT_QUOTES); ?></option>
                                    </optgroup>
                                    <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                        <optgroup label="Direct Reports">
                                            <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                                <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                                <option value="<?= (int) $rUserID; ?>"<?= isset($rLine['member_id']) && $rLine['member_id'] == $rUserID ? ' selected' : ''; ?>><?= htmlspecialchars((string) $rRegisteredUser['username'], ENT_QUOTES); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    <?php if (count($rPermissions['direct_reports']) < count($rPermissions['all_reports'])): ?>
                                        <optgroup label="Indirect Reports">
                                            <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                                <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                                    <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                                    <option value="<?= (int) $rUserID; ?>"<?= isset($rLine['member_id']) && $rLine['member_id'] == $rUserID ? ' selected' : ''; ?>><?= htmlspecialchars((string) $rRegisteredUser['username'], ENT_QUOTES); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($rOrigPackage)): ?>
                            <div class="mb-6">
                                <label class="form-label" for="orig_package">Original Package</label>
                                <input type="text" readonly class="form-control" id="orig_package" name="orig_package" value="<?= htmlspecialchars((string) $rOrigPackage['package_name'], ENT_QUOTES); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="mb-6">
                            <label class="form-label" for="package"><?= isset($rLine) ? 'Add ' : ''; ?>Package</label>
                            <select name="package" id="package" class="form-select select2">
                                <?php if (isset($rLine)): ?>
                                    <option value="">No Changes</option>
                                <?php endif; ?>
                                <?php foreach ($rPackages as $rPackage): ?>
                                    <?php if (($rPackage['is_trial'] && $rIsTrial) || ($rPackage['is_official'] && !$rIsTrial)): ?>
                                        <option value="<?= (int) $rPackage['id']; ?>"><?= htmlspecialchars((string) $rPackage['package_name'], ENT_QUOTES); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="package_info" class="row mb-6" style="display:none;">
                            <div class="col-md-6">
                                <label class="form-label" for="package_cost">Package Cost</label>
                                <input readonly type="text" class="form-control" id="package_cost" name="package_cost" value="">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="package_duration">Duration</label>
                                <input readonly type="text" class="form-control" id="package_duration" name="package_duration" value="">
                            </div>
                        </div>

                        <div class="mb-6" id="package_warning" style="display:none;">
                            <div class="alert alert-warning mb-0" role="alert">
                                The package you have selected is incompatible with the existing package. This could be due to the number of connections or other restrictions.<br><br>You can still upgrade to this package, however the time added will be from today and not from the end of the original package.
                            </div>
                        </div>

                        <div class="mb-6">
                            <label class="form-label" for="exp_date">Expiration Date</label>
                            <input readonly type="text" class="form-control" id="exp_date" name="exp_date" value="<?= isset($rLine) && !is_null($rLine['exp_date']) ? date('Y-m-d H:i', (int) $rLine['exp_date']) : ''; ?>">
                        </div>

                        <div class="mb-2">
                            <label class="form-label" for="reseller_notes">Reseller Notes</label>
                            <textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3"><?= isset($rDevice) ? htmlspecialchars((string) $rLine['reseller_notes'], ENT_QUOTES) : ''; ?></textarea>
                        </div>
                    </div>

                    <?php if ($rIsEdit): ?>
                        <div class="tab-pane fade" id="tab-device" role="tabpanel">
                            <div class="row mb-6">
                                <div class="col-md-6">
                                    <label class="form-label" for="username">Line Username</label>
                                    <input type="text" readonly class="form-control sticky" id="username" value="<?= htmlspecialchars((string) $rLine['username'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="password">Line Password</label>
                                    <input type="text" readonly class="form-control sticky" id="password" value="<?= htmlspecialchars((string) $rLine['password'], ENT_QUOTES); ?>">
                                </div>
                            </div>
                            <div class="row mb-6">
                                <div class="col-md-6">
                                    <label class="form-label" for="modem_mac">Modem MAC</label>
                                    <input type="text" class="form-control" id="modem_mac" name="modem_mac" value="<?= htmlspecialchars((string) $rDevice['modem_mac'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="local_ip">Local IP</label>
                                    <input type="text" class="form-control" id="local_ip" name="local_ip" value="<?= htmlspecialchars((string) $rDevice['local_ip'], ENT_QUOTES); ?>">
                                </div>
                            </div>
                            <div class="row mb-6">
                                <div class="col-md-6">
                                    <label class="form-label" for="enigma_version">Enigma Version</label>
                                    <input type="text" class="form-control" id="enigma_version" name="enigma_version" value="<?= htmlspecialchars((string) $rDevice['enigma_version'], ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="cpu">CPU</label>
                                    <input type="text" class="form-control" id="cpu" name="cpu" value="<?= htmlspecialchars((string) $rDevice['cpu'], ENT_QUOTES); ?>">
                                </div>
                            </div>
                            <div class="mb-6">
                                <label class="form-label" for="lversion">Linux Version</label>
                                <input type="text" class="form-control" id="lversion" name="lversion" value="<?= htmlspecialchars((string) $rDevice['lversion'], ENT_QUOTES); ?>">
                            </div>
                            <div class="mb-4">
                                <label class="form-label" for="token">Token</label>
                                <input type="text" class="form-control" id="token" name="token" value="<?= htmlspecialchars((string) $rDevice['token'], ENT_QUOTES); ?>">
                            </div>
                            <div class="d-flex justify-content-start">
                                <button type="button" id="clear-device" class="btn btn-label-warning">Clear Device Info</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($rPermissions['allow_restrictions']): ?>
                        <div class="tab-pane fade" id="tab-restrictions" role="tabpanel">
                            <div class="mb-6">
                                <label class="form-label" for="ip_field">Allowed IP Addresses</label>
                                <div class="input-group mb-3">
                                    <input type="text" id="ip_field" class="form-control" placeholder="0.0.0.0">
                                    <button type="button" id="add_ip" class="btn btn-primary"><i class="icon-base ti tabler-plus"></i></button>
                                    <button type="button" id="remove_ip" class="btn btn-label-danger"><i class="icon-base ti tabler-x"></i></button>
                                </div>
                                <select id="allowed_ips" name="allowed_ips[]" size="6" class="form-select" multiple>
                                    <?php if (isset($rDevice)): ?>
                                        <?php foreach (json_decode((string) $rLine['allowed_ips'], true) ?: [] as $rIP): ?>
                                            <option value="<?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-check form-switch mb-6">
                                <input class="form-check-input" type="checkbox" id="is_isplock" name="is_isplock" value="1" <?= isset($rLine) && $rLine['is_isplock'] == 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_isplock">Lock to ISP</label>
                            </div>

                            <div class="mb-2">
                                <label class="form-label" for="isp_clear">Current ISP</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="<?= isset($rLine) ? htmlspecialchars((string) $rLine['isp_desc'], ENT_QUOTES) : ''; ?>">
                                    <button type="button" id="clear-isp" class="btn btn-label-danger"><i class="icon-base ti tabler-x"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tab-pane fade" id="tab-review" role="tabpanel">
                        <div class="alert alert-danger d-flex align-items-center" role="alert" style="display:none;" id="no-credits">
                            <i class="icon-base ti tabler-ban me-2"></i> You do not have enough credits to complete this transaction!
                        </div>
                        <div class="table-responsive mb-4">
                            <table class="table table-borderless mb-0" id="credits-cost">
                                <thead>
                                    <tr>
                                        <th class="text-center">Total Credits</th>
                                        <th class="text-center">Purchase Cost</th>
                                        <th class="text-center">Remaining Credits</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="text-center"><?= number_format($rUserInfo['credits'], 0); ?></td>
                                        <td class="text-center" id="cost_credits">0</td>
                                        <td class="text-center" id="remaining_credits"><?= number_format($rUserInfo['credits'], 0); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-datatable table-responsive">
                            <table class="table" id="datatable-review">
                                <thead>
                                    <tr>
                                        <th class="text-center"></th>
                                        <th><?= $language::get('bouquet_name'); ?></th>
                                        <th class="text-center"><?= $language::get('streams'); ?></th>
                                        <th class="text-center"><?= $language::get('movies'); ?></th>
                                        <th class="text-center"><?= $language::get('series'); ?></th>
                                        <th class="text-center"><?= $language::get('stations'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-6">
            <button type="submit" class="btn btn-primary purchase" id="submit_button">Purchase</button>
        </div>
    </form>
<?php endif; ?>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var form = document.getElementById('enigma-form');
        if (!form) { return; }

        var $ = window.jQuery;
        var toast = window.xcToast || function(m) { alert(m); };
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var statusMessages = <?= json_encode($rStatusMessages); ?>;

        var ownerCredits = <?= (int) $rUserInfo['credits']; ?>;
        var allowChangeBouquets = <?= $rAllowChange ? 'true' : 'false'; ?>;
        var userPackage = <?= isset($rLine) ? (intval($rLine['package_id']) ?: 'null') : 'null'; ?>;
        var userBouquet = <?= isset($rLine) ? json_encode(array_map('intval', json_decode((string) $rLine['bouquet'], true) ?: [])) : '[]'; ?>;
        var currentBouquets = <?= json_encode($rReviewRows); ?>;
        <?php if (isset($rLine)): ?>
        var lineExpDate = <?= json_encode(!is_null($rLine['exp_date']) ? date('Y-m-d H:i', (int) $rLine['exp_date']) : ''); ?>;
        <?php endif; ?>

        var pkgTrialSuffix = <?= (!isset($rLine) && $rIsTrial) ? "'_trial'" : "''"; ?>;
        var pkgExtra = '<?= isset($rLine) ? '&user_id=' . (int) $rLine['id'] : ''; ?><?= isset($rOrigPackage) ? '&orig_id=' . (int) $rOrigPackage['id'] : ''; ?>';

        var nf = new Intl.NumberFormat('en-US');
        var reviewBody = form.querySelector('#datatable-review tbody');
        var pkgSelect = document.getElementById('package');

        function setVal(id, v) { var el = document.getElementById(id); if (el) { el.value = v; } }
        function setText(id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } }
        function show(id, on) { var el = document.getElementById(id); if (el) { el.style.display = on ? '' : 'none'; } }

        function firstCell(id, checked) {
            if (allowChangeBouquets) {
                return "<input class='form-check-input' type='checkbox' name='bouquets_selected[]' value='" + id + "'" + (checked ? ' checked' : '') + '>';
            }
            return String(id);
        }

        function addRow(cellHtml, name, ch, mo, se, ra) {
            var tr = document.createElement('tr');
            var td0 = document.createElement('td');
            td0.className = 'text-center';
            td0.innerHTML = cellHtml;
            tr.appendChild(td0);
            var tdName = document.createElement('td');
            tdName.textContent = name;
            tr.appendChild(tdName);
            [ch, mo, se, ra].forEach(function(v) {
                var td = document.createElement('td');
                td.className = 'text-center';
                td.textContent = v;
                tr.appendChild(td);
            });
            reviewBody.appendChild(tr);
        }

        function setSubmitLabel(txt) {
            var btn = document.getElementById('submit_button');
            if (btn) { btn.textContent = txt; }
        }

        function getPackage() {
            reviewBody.innerHTML = '';
            var pid = pkgSelect ? pkgSelect.value : '';
            if (pid && pid.length > 0) {
                fetch('./api?action=get_package' + pkgTrialSuffix + '&package_id=' + encodeURIComponent(pid) + pkgExtra, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.result === true) {
                            setVal('max_connections', data.data.max_connections);
                            setText('cost_credits', nf.format(data.data.cost_credits));
                            setText('remaining_credits', nf.format(ownerCredits - data.data.cost_credits));
                            setVal('exp_date', data.data.exp_date);
                            var enough = (ownerCredits - data.data.cost_credits) >= 0;
                            show('credits-cost', enough);
                            show('no-credits', !enough);
                            form.querySelectorAll('.purchase').forEach(function(b) { b.disabled = !enough; });
                            (data.bouquets || []).forEach(function(bq) {
                                var checked;
                                if (userBouquet.length > 0 && userPackage === parseInt(pid)) {
                                    checked = userBouquet.indexOf(parseInt(bq.id)) !== -1;
                                } else {
                                    checked = true;
                                }
                                addRow(firstCell(bq.id, checked), bq.bouquet_name, bq.bouquet_channels.length, bq.bouquet_movies.length, bq.bouquet_series.length, bq.bouquet_radios.length);
                            });
                            show('package_warning', !data.data.compatible);
                            var isp = document.getElementById('is_isplock');
                            if (isp) { isp.checked = (data.data.is_isplock == 1); }
                            setVal('package_cost', data.data.cost_credits);
                            setVal('package_duration', data.data.duration);
                            show('package_info', true);
                            setSubmitLabel('Purchase');
                        }
                    })
                    .catch(function() { /* leave table cleared */ });
            } else {
                <?php if (isset($rLine)): ?>
                setText('cost_credits', '0');
                setText('remaining_credits', nf.format(ownerCredits));
                setVal('exp_date', lineExpDate);
                setVal('package_cost', '');
                setVal('package_duration', '');
                show('package_info', false);
                show('package_warning', false);
                setSubmitLabel('Save');
                currentBouquets.forEach(function(bq) {
                    addRow(bq.checkbox ? firstCell(bq.id, bq.checked) : String(bq.id), bq.name, bq.channels, bq.movies, bq.series, bq.radios);
                });
                <?php endif; ?>
            }
        }

        // Allowed-IP list widget (legacy add_ip / remove_ip).
        function isValidIP(v) {
            return /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/.test(v);
        }
        var addIp = document.getElementById('add_ip');
        if (addIp) {
            var ipSel = document.getElementById('allowed_ips');
            var ipField = document.getElementById('ip_field');
            addIp.addEventListener('click', function() {
                if (ipField.value && isValidIP(ipField.value)) {
                    var exists = Array.prototype.some.call(ipSel.options, function(o) { return o.value === ipField.value; });
                    if (!exists) { ipSel.add(new Option(ipField.value, ipField.value)); }
                    ipField.value = '';
                } else {
                    toast('Please enter a valid IP address.', 'warning');
                }
            });
            document.getElementById('remove_ip').addEventListener('click', function() {
                var selected = Array.prototype.slice.call(ipSel.selectedOptions);
                if (selected.length > 0) {
                    selected.forEach(function(o) { o.remove(); });
                } else {
                    toast('Please select an IP address to remove.', 'warning');
                }
            });
        }

        var clearIsp = document.getElementById('clear-isp');
        if (clearIsp) {
            clearIsp.addEventListener('click', function() { document.getElementById('isp_clear').value = ''; });
        }

        var clearDevice = document.getElementById('clear-device');
        if (clearDevice) {
            clearDevice.addEventListener('click', function() {
                var pane = document.getElementById('tab-device');
                if (!pane) { return; }
                pane.querySelectorAll('input').forEach(function(inp) {
                    if (!inp.classList.contains('sticky')) { inp.value = ''; }
                });
            });
        }

        // MAC address auto-format (legacy #mac input handler).
        var mac = document.getElementById('mac');
        if (mac) {
            mac.addEventListener('input', function(e) {
                var rRegex = /([a-f0-9]{2})([a-f0-9]{2})/i,
                    rString = e.target.value.replace(/[^a-f0-9]/ig, '');
                while (rRegex.test(rString)) {
                    rString = rString.replace(rRegex, '$1:$2');
                }
                e.target.value = rString.slice(0, 17).toUpperCase();
            });
        }

        if ($ && $.fn.select2) { $('#member_id, #package').select2({ width: '100%' }); }
        if (pkgSelect) {
            if ($ && $.fn.select2) { $(pkgSelect).on('change', getPackage); } else { pkgSelect.addEventListener('change', getPackage); }
        }
        getPackage();

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var sel = document.getElementById('allowed_ips');
            if (sel) { Array.prototype.forEach.call(sel.options, function(o) { o.selected = true; }); }
            var btn = document.getElementById('submit_button');
            btn.disabled = true;
            fetch('post.php?action=enigma', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt;
                    try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        window.location.href = dt.location || 'enigmas';
                        return;
                    }
                    btn.disabled = false;
                    toast(statusMessages[dt.status] || errText, 'error');
                })
                .catch(function() { btn.disabled = false; toast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
