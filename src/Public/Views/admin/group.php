<?php

/**
 * Member group add / edit (Bootstrap 5). Full-page form reached from the groups table
 * (href="group?id=X"). Bootstrap 5 vertical layout — each former wizard tab becomes its
 * own section card: Details (name + admin/reseller switches), Packages (package
 * checkboxes), Permissions (reseller limits + capability switches), Subresellers
 * (create switch + sub-reseller group checkboxes), Dashboard notice (textarea) and
 * Admin Permissions (advanced permission matrix). The Packages / Subresellers /
 * Admin-permission checkbox tables serialise into the hidden packages_selected /
 * groups_selected / permissions_selected inputs (JSON id arrays) on submit, exactly
 * as the legacy datatable collectors did; the notice textarea posts as notice_html.
 * Card visibility follows the is_admin / is_reseller switches (legacy
 * validatePermissions). Posts to post.php?action=group via fetch; on success returns
 * to the list.
 */

use XcVm\Core\Reference\PermissionReference;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\GroupService;

$rIsEdit       = isset($rGroup);
$rAllowedPages = ($rIsEdit && !empty($rGroup['allowed_pages'])) ? (json_decode((string) $rGroup['allowed_pages'], true) ?: []) : [];
// Groups that can never be modified keep their admin/reseller flags locked.
$rCanDelete    = !$rIsEdit || !empty($rGroup['can_delete']);
$rAdminCard    = !$rIsEdit || !empty($rGroup['can_delete']);
?>

<div class="d-flex align-items-center mb-4">
    <a href="groups" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit_group') : $language::get('add_group'); ?></h4>
</div>

<form id="group-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rGroup['group_id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="permissions_selected" id="permissions_selected" value="">
    <input type="hidden" name="packages_selected" id="packages_selected" value="">
    <input type="hidden" name="groups_selected" id="groups_selected" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" id="tabli-details"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <li class="nav-item" id="tabli-packages"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-packages" role="tab"><i class="icon-base ti tabler-package me-1"></i><?= $language::get('packages'); ?></button></li>
                    <li class="nav-item" id="tabli-permissions"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-permissions" role="tab"><i class="icon-base ti tabler-shield-lock me-1"></i><?= $language::get('permissions'); ?></button></li>
                    <li class="nav-item" id="tabli-subresellers"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-subresellers" role="tab"><i class="icon-base ti tabler-users me-1"></i><?= $language::get('subresellers'); ?></button></li>
                    <li class="nav-item" id="tabli-notice"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-notice" role="tab"><i class="icon-base ti tabler-layout-dashboard me-1"></i><?= $language::get('dashboard'); ?></button></li>
                    <?php if ($rAdminCard): ?>
                        <li class="nav-item" id="tabli-admin"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-admin" role="tab"><i class="icon-base ti tabler-shield-check me-1"></i><?= $language::get('admin_permissions'); ?></button></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="group_name"><?= $language::get('group_name'); ?></label>
                        <input type="text" class="form-control" id="group_name" name="group_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES) : ''; ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" value="1" <?= ($rIsEdit && $rGroup['is_admin']) ? 'checked' : ''; ?> <?= $rCanDelete ? '' : 'disabled'; ?>>
                                <label class="form-check-label" for="is_admin"><?= $language::get('is_admin'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_reseller" name="is_reseller" value="1" <?= ($rIsEdit && $rGroup['is_reseller']) ? 'checked' : ''; ?> <?= $rCanDelete ? '' : 'disabled'; ?>>
                                <label class="form-check-label" for="is_reseller"><?= $language::get('is_reseller'); ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-packages" role="tabpanel">
                    <div class="d-flex justify-content-end mb-4">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-label-secondary" id="pkg-all"><?= $language::get('select_all'); ?></button>
                            <button type="button" class="btn btn-label-secondary" id="pkg-none"><?= $language::get('deselect_all'); ?></button>
                        </div>
                    </div>
                    <div class="card-datatable table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:1%"></th>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th><?= $language::get('package_name'); ?></th>
                                    <th class="text-center"><?= $language::get('trial'); ?></th>
                                    <th class="text-center"><?= $language::get('official'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (PackageService::getAll() as $rPackage): ?>
                                    <tr>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input group-package-cb" type="checkbox" value="<?= (int) $rPackage['id']; ?>" id="grp-package-<?= (int) $rPackage['id']; ?>" <?= (is_array($rPackageIDs) && in_array($rPackage['id'], $rPackageIDs)) ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td class="text-center"><label class="form-check-label" for="grp-package-<?= (int) $rPackage['id']; ?>"><?= (int) $rPackage['id']; ?></label></td>
                                        <td><label class="form-check-label" for="grp-package-<?= (int) $rPackage['id']; ?>"><?= htmlspecialchars((string) $rPackage['package_name'], ENT_QUOTES); ?></label></td>
                                        <td class="text-center"><i class="icon-base ti tabler-circle-filled <?= $rPackage['is_trial'] ? 'text-success' : 'text-secondary'; ?>"></i></td>
                                        <td class="text-center"><i class="icon-base ti tabler-circle-filled <?= $rPackage['is_official'] ? 'text-success' : 'text-secondary'; ?>"></i></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-permissions" role="tabpanel">
                    <p class="text-body-secondary"><?= $language::get('permissions_info'); ?></p>

                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="total_allowed_gen_trials"><?= $language::get('allowed_trials'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="total_allowed_gen_trials" name="total_allowed_gen_trials" required value="<?= $rIsEdit ? (int) $rGroup['total_allowed_gen_trials'] : '0'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="total_allowed_gen_in"><?= $language::get('allowed_trials_in'); ?></label>
                            <select name="total_allowed_gen_in" id="total_allowed_gen_in" class="form-select">
                                <?php foreach (['Day', 'Month'] as $rOption): ?>
                                    <option value="<?= strtolower($rOption); ?>" <?= ($rIsEdit && $rGroup['total_allowed_gen_in'] == strtolower($rOption)) ? 'selected' : ''; ?>><?= $rOption; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="minimum_trial_credits"><?= $language::get('minimum_credit_for_trials'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="minimum_trial_credits" name="minimum_trial_credits" required value="<?= $rIsEdit ? (int) $rGroup['minimum_trial_credits'] : '0'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="create_sub_resellers_price"><?= $language::get('subreseller_price'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="create_sub_resellers_price" name="create_sub_resellers_price" required value="<?= $rIsEdit ? htmlspecialchars((string) $rGroup['create_sub_resellers_price'], ENT_QUOTES) : '0'; ?>">
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="minimum_username_length"><?= $language::get('minimum_username_length'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="minimum_username_length" name="minimum_username_length" required value="<?= $rIsEdit ? (int) $rGroup['minimum_username_length'] : '8'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="minimum_password_length"><?= $language::get('minimum_password_length'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="minimum_password_length" name="minimum_password_length" required value="<?= $rIsEdit ? htmlspecialchars((string) $rGroup['minimum_password_length'], ENT_QUOTES) : '8'; ?>">
                        </div>
                    </div>

                    <div class="row g-3">
                        <?php
                        // Reseller capability switches. Every switch except allow_change_bouquets
                        // defaults to checked when adding (matching the legacy Switchery defaults).
                        $rGroupSwitches = [
                            'allow_restrictions'              => ['label' => $language::get('allow_line_restrictions'), 'default' => true],
                            'allow_change_bouquets'           => ['label' => $language::get('allow_bouquet_editing'),   'default' => false],
                            'delete_users'                    => ['label' => $language::get('can_delete_users'),        'default' => true],
                            'allow_download'                  => ['label' => $language::get('show_m3u_download'),        'default' => true],
                            'can_view_vod'                    => ['label' => $language::get('can_view_vod_streams'),     'default' => true],
                            'reseller_client_connection_logs' => ['label' => $language::get('can_view_live_connections'), 'default' => true],
                            'allow_change_username'           => ['label' => $language::get('change_usernames'),        'default' => true],
                            'allow_change_password'           => ['label' => $language::get('change_passwords'),        'default' => true],
                        ];
                        foreach ($rGroupSwitches as $rKey => $rInfo):
                            $rChecked = $rIsEdit ? (bool) $rGroup[$rKey] : $rInfo['default'];
                        ?>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="<?= $rKey; ?>" name="<?= $rKey; ?>" value="1" <?= $rChecked ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="<?= $rKey; ?>"><?= htmlspecialchars((string) $rInfo['label'], ENT_QUOTES); ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-subresellers" role="tabpanel">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="create_sub_resellers" name="create_sub_resellers" value="1" <?= ($rIsEdit && $rGroup['create_sub_resellers']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="create_sub_resellers"><?= $language::get('allow_subreseller_creation'); ?></label>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:1%"></th>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th><?= $language::get('group_name'); ?></th>
                                    <th class="text-center"><?= $language::get('allowed_subresellers'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (GroupService::getAll() as $rSubGroup): ?>
                                    <?php if ($rSubGroup['is_reseller'] && !($rIsEdit && $rGroup['group_id'] == $rSubGroup['group_id'])): ?>
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input group-subreseller-cb" type="checkbox" value="<?= (int) $rSubGroup['group_id']; ?>" id="grp-sub-<?= (int) $rSubGroup['group_id']; ?>" <?= (is_array($rGroupIDs) && in_array($rSubGroup['group_id'], $rGroupIDs)) ? 'checked' : ''; ?>>
                                                </div>
                                            </td>
                                            <td class="text-center"><label class="form-check-label" for="grp-sub-<?= (int) $rSubGroup['group_id']; ?>"><?= (int) $rSubGroup['group_id']; ?></label></td>
                                            <td><label class="form-check-label" for="grp-sub-<?= (int) $rSubGroup['group_id']; ?>"><?= htmlspecialchars((string) $rSubGroup['group_name'], ENT_QUOTES); ?></label></td>
                                            <td class="text-center"><i class="icon-base ti tabler-circle-filled <?= $rSubGroup['create_sub_resellers'] ? 'text-success' : 'text-secondary'; ?>"></i></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-notice" role="tabpanel">
                    <p class="text-body-secondary">Display a notice for this group when they've logged into the Reseller Dashboard.</p>
                    <textarea class="form-control" id="notice_html" name="notice_html" rows="8"><?= $rIsEdit ? htmlspecialchars((string) $rNotice, ENT_QUOTES) : ''; ?></textarea>
                </div>
                <?php if ($rAdminCard): ?>
                <div class="tab-pane fade" id="tab-admin" role="tabpanel">
                    <div class="d-flex justify-content-end mb-4">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-label-secondary" id="perm-all"><?= $language::get('select_all'); ?></button>
                            <button type="button" class="btn btn-label-secondary" id="perm-none"><?= $language::get('deselect_all'); ?></button>
                        </div>
                    </div>
                    <p class="text-body-secondary"><?= $language::get('advanced_permissions_info'); ?></p>
                    <div class="card-datatable table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= $language::get('permission'); ?></th>
                                    <th><?= $language::get('description'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (PermissionReference::advanced() as $rPermission): ?>
                                    <tr>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input group-permission-cb" type="checkbox" value="<?= htmlspecialchars((string) $rPermission[0], ENT_QUOTES); ?>" id="grp-perm-<?= htmlspecialchars((string) $rPermission[0], ENT_QUOTES); ?>" <?= (is_array($rAllowedPages) && in_array($rPermission[0], $rAllowedPages)) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="grp-perm-<?= htmlspecialchars((string) $rPermission[0], ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rPermission[1], ENT_QUOTES); ?></label>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars((string) $rPermission[2], ENT_QUOTES); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="group-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
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
        ['total_allowed_gen_trials', 'minimum_trial_credits', 'create_sub_resellers_price', 'minimum_username_length', 'minimum_password_length'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
        });

        // Select-all / deselect-all button pairs for each checkbox table.
        var bindToggle = function(allId, noneId, cls, guard) {
            var all = document.getElementById(allId),
                none = document.getElementById(noneId);
            var set = function(state) {
                if (guard && !guard()) {
                    return;
                }
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
        bindToggle('pkg-all', 'pkg-none', 'group-package-cb');
        bindToggle('perm-all', 'perm-none', 'group-permission-cb');

        // Sub-reseller selection is only permitted while create_sub_resellers is on;
        // turning it off clears the current selection (legacy deselectGroups()).
        var subSwitch = document.getElementById('create_sub_resellers');
        var subCheckboxes = function() {
            return document.querySelectorAll('.group-subreseller-cb');
        };
        var applySubState = function() {
            var on = subSwitch.checked;
            subCheckboxes().forEach(function(c) {
                c.disabled = !on;
                if (!on) {
                    c.checked = false;
                }
            });
        };
        subSwitch.addEventListener('change', applySubState);
        applySubState();

        // Tab visibility mirrors the legacy validatePermissions() tab logic: each
        // toggled section is now a nav <li> (#tabli-*) + pane (#tab-*) instead of a
        // card, so hide/show both. If the hidden tab was the active one, fall back to
        // the first still-visible tab.
        var isAdmin = document.getElementById('is_admin');
        var isReseller = document.getElementById('is_reseller');
        var showTab = function(suffix, visible) {
            var li = document.getElementById('tabli-' + suffix);
            var pane = document.getElementById('tab-' + suffix);
            if (li) {
                li.hidden = !visible;
            }
            if (pane) {
                pane.hidden = !visible;
            }
        };
        var activateFirstVisibleTab = function() {
            var lis = document.querySelectorAll('#group-form .nav-tabs .nav-item');
            var activeHidden = false,
                firstBtn = null;
            lis.forEach(function(li) {
                var btn = li.querySelector('.nav-link');
                if (!btn) {
                    return;
                }
                if (!li.hidden && !firstBtn) {
                    firstBtn = btn;
                }
                if (li.hidden && btn.classList.contains('active')) {
                    activeHidden = true;
                }
            });
            if (activeHidden && firstBtn) {
                firstBtn.click();
            }
        };
        var validatePermissions = function() {
            showTab('admin', isAdmin.checked);
            var res = isReseller.checked;
            showTab('permissions', res);
            showTab('packages', res);
            showTab('notice', res);
            // Subresellers tab is always visible (legacy keeps subreseller_tab shown).
            if (!res) {
                subCheckboxes().forEach(function(c) {
                    c.checked = false;
                });
            }
            activateFirstVisibleTab();
        };
        isAdmin.addEventListener('change', validatePermissions);
        isReseller.addEventListener('change', validatePermissions);
        validatePermissions();

        // Collect checked ids as a JSON string array into the matching hidden input.
        var collect = function(cls) {
            var ids = [];
            document.querySelectorAll('.' + cls + ':checked').forEach(function(c) {
                ids.push(c.value);
            });
            return JSON.stringify(ids);
        };

        // Submit → post.php?action=group. notice_html posts directly via the textarea.
        document.getElementById('group-form').addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('permissions_selected').value = collect('group-permission-cb');
            document.getElementById('packages_selected').value = collect('group-package-cb');
            document.getElementById('groups_selected').value = collect('group-subreseller-cb');
            var btn = document.getElementById('group-submit');
            btn.disabled = true;
            fetch('post.php?action=group', {
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
                        window.location.href = dt.location || 'groups';
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