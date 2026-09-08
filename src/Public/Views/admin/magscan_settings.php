<?php

/**
 * MAG Scan settings (Bootstrap 5). Whitelist / blacklist of MAC addresses and a
 * whitelist of IP addresses for the MAG anti-scan protection. Three tabbed lists
 * with add/remove controls. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Config\SettingsManager;

$rWhiteMacs = $gData['value']['whitelist_macs'] ?? [];
$rBlackMacs = $gData['value']['blacklist_macs'] ?? [];
$rWhiteIps  = $gData['value']['whitelist_ips'] ?? [];
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('magscan_settings'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="magscan-form" method="POST" action="magscan_settings">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-wl-mac"><i class="icon-base ti tabler-shield-check me-1"></i><?= $language::get('whitelist_mac'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bl-mac"><i class="icon-base ti tabler-shield-x me-1"></i><?= $language::get('blacklist_mac'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-wl-ip"><i class="icon-base ti tabler-world-check me-1"></i><?= $language::get('whitelist_ip'); ?></button></li>
            </ul>

            <div class="tab-content p-4 border border-top-0 rounded-bottom">
                <!-- Whitelist MAC -->
                <div class="tab-pane fade show active" id="tab-wl-mac">
                    <h5 class="mb-1"><?= $language::get('whitelist_mac_title'); ?></h5>
                    <p class="text-body-secondary"><?= $language::get('whitelist_mac_help'); ?></p>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="whitelist_mac"><?= $language::get('mac_address'); ?></label>
                        <div class="col-md-9">
                            <div class="d-flex align-items-start gap-2">
                                <input type="text" id="whitelist_mac" class="form-control flex-grow-1" value="" maxlength="17">
                                <button type="button" id="add_mac" class="btn btn-primary flex-shrink-0"><i class="icon-base ti tabler-plus"></i></button>
                                <button type="button" id="remove_mac" class="btn btn-label-danger flex-shrink-0"><i class="icon-base ti tabler-x"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <label class="col-md-3 col-form-label" for="whitelist_macs">&nbsp;</label>
                        <div class="col-md-9">
                            <select id="whitelist_macs" name="whitelist_macs[]" size="10" class="form-select" multiple="multiple">
                                <?php foreach ($rWhiteMacs as $rMac): ?>
                                    <option value="<?= htmlspecialchars((string) $rMac, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rMac, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Blacklist MAC -->
                <div class="tab-pane fade" id="tab-bl-mac">
                    <h5 class="mb-1"><?= $language::get('blacklist_mac_title'); ?></h5>
                    <p class="text-body-secondary"><?= $language::get('blacklist_mac_help'); ?></p>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="blacklist_mac"><?= $language::get('mac_address'); ?></label>
                        <div class="col-md-9">
                            <div class="d-flex align-items-start gap-2">
                                <input type="text" id="blacklist_mac" class="form-control flex-grow-1" value="" maxlength="17">
                                <button type="button" id="add_black_mac" class="btn btn-primary flex-shrink-0"><i class="icon-base ti tabler-plus"></i></button>
                                <button type="button" id="remove_black_mac" class="btn btn-label-danger flex-shrink-0"><i class="icon-base ti tabler-x"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <label class="col-md-3 col-form-label" for="blacklist_macs">&nbsp;</label>
                        <div class="col-md-9">
                            <select id="blacklist_macs" name="blacklist_macs[]" size="10" class="form-select" multiple="multiple">
                                <?php foreach ($rBlackMacs as $rMac): ?>
                                    <option value="<?= htmlspecialchars((string) $rMac, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rMac, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Whitelist IP -->
                <div class="tab-pane fade" id="tab-wl-ip">
                    <h5 class="mb-1"><?= $language::get('whitelist_ip_title'); ?></h5>
                    <p class="text-body-secondary"><?= $language::get('whitelist_ip_help'); ?></p>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="whitelist_ip"><?= $language::get('ip_address'); ?></label>
                        <div class="col-md-9">
                            <div class="d-flex align-items-start gap-2">
                                <input type="text" id="whitelist_ip" class="form-control flex-grow-1" value="">
                                <button type="button" id="add_ip" class="btn btn-primary flex-shrink-0"><i class="icon-base ti tabler-plus"></i></button>
                                <button type="button" id="remove_ip" class="btn btn-label-danger flex-shrink-0"><i class="icon-base ti tabler-x"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <label class="col-md-3 col-form-label" for="whitelist_ips">&nbsp;</label>
                        <div class="col-md-9">
                            <select id="whitelist_ips" name="whitelist_ips[]" size="10" class="form-select" multiple="multiple">
                                <?php foreach ($rWhiteIps as $rIp): ?>
                                    <option value="<?= htmlspecialchars((string) $rIp, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rIp, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-end mt-4"><button type="submit" name="submit_magscan" class="btn btn-primary"><?= $language::get('save_settings'); ?></button></div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var toast = window.xcToast || function() {};
        var lang = {
            validMac: <?= json_encode($language::get('enter_valid_mac')) ?>,
            enterMac: <?= json_encode($language::get('enter_mac')) ?>,
            macWhitelisted: <?= json_encode($language::get('mac_already_whitelisted')) ?>,
            macBlacklisted: <?= json_encode($language::get('mac_already_blacklisted')) ?>,
            validIp: <?= json_encode($language::get('enter_valid_ip')) ?>,
            enterIp: <?= json_encode($language::get('enter_ip')) ?>,
            ipWhitelisted: <?= json_encode($language::get('ip_already_whitelisted')) ?>
        };
        var macRegex = /^([0-9A-Fa-f]{2}[:\-]){5}([0-9A-Fa-f]{2})$/;
        var ipRegex = /^([0-9]{1,3}\.){3}[0-9]{1,3}$/;

        // Live MAC formatter (xx:xx:xx:xx:xx:xx).
        var formatMAC = function(e) {
            var r = /([a-f0-9]{2})([a-f0-9]{2})/i;
            var str = e.target.value.replace(/[^a-f0-9]/ig, '');
            while (r.test(str)) {
                str = str.replace(r, '$1:$2');
            }
            e.target.value = str.slice(0, 17);
        };
        document.getElementById('whitelist_mac').addEventListener('keyup', formatMAC);
        document.getElementById('blacklist_mac').addEventListener('keyup', formatMAC);

        // Add a validated, de-duplicated value into a listbox.
        var addValue = function(inputId, listId, regex, invalidMsg, emptyMsg, dupMsg) {
            var val = $('#' + inputId).val().trim();
            if (!val) {
                toast(emptyMsg, 'error');
                return;
            }
            if (!regex.test(val)) {
                toast(invalidMsg, 'error');
                return;
            }
            var exists = false;
            $('#' + listId + ' option').each(function() {
                if (this.value === val) {
                    exists = true;
                }
            });
            if (exists) {
                toast(dupMsg, 'error');
                return;
            }
            $('#' + listId).append(new Option(val, val));
            $('#' + inputId).val('');
        };
        var removeSelected = function(listId) {
            $('#' + listId + ' option:selected').remove();
        };

        $('#add_mac').on('click', function() {
            addValue('whitelist_mac', 'whitelist_macs', macRegex, lang.validMac, lang.enterMac, lang.macWhitelisted);
        });
        $('#remove_mac').on('click', function() {
            removeSelected('whitelist_macs');
        });
        $('#add_black_mac').on('click', function() {
            addValue('blacklist_mac', 'blacklist_macs', macRegex, lang.validMac, lang.enterMac, lang.macBlacklisted);
        });
        $('#remove_black_mac').on('click', function() {
            removeSelected('blacklist_macs');
        });
        $('#add_ip').on('click', function() {
            addValue('whitelist_ip', 'whitelist_ips', ipRegex, lang.validIp, lang.enterIp, lang.ipWhitelisted);
        });
        $('#remove_ip').on('click', function() {
            removeSelected('whitelist_ips');
        });

        // Select every list entry so the full lists post.
        document.getElementById('magscan-form').addEventListener('submit', function() {
            $('#whitelist_macs option, #blacklist_macs option, #whitelist_ips option').prop('selected', true);
        });
    })();
</script>
</body>

</html>