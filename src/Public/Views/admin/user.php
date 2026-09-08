<?php

/**
 * Reseller/user add / edit (Bootstrap 5). Reached full-page from the users table
 * ("Add" → user) inside the new-UI shell, and as an iframe modal
 * ("Edit" → user?id=X&modal=1) inside the modal shell. Two tabs: Details
 * (credentials, group, email, owner, credits + adjustment reason, reseller DNS,
 * notes) and Overrides (per-package credit overrides — edit only). Posts to
 * post.php?action=user via fetch; in the modal it posts xcModalSaved to the
 * parent (which closes the modal and reloads the table), full-page it returns to
 * the list.
 */

use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;

$rIsEdit    = (bool) ($rUser ?? null);
$rOverrides = ($rIsEdit && !empty($rUser['override_packages'])) ? (json_decode((string) $rUser['override_packages'], true) ?: []) : [];
$rMinPwLen  = max(10, (int) ($rPermissions['minimum_password_length'] ?? 10));
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="users" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('user'); ?></h4>
    </div>
<?php endif; ?>

<form id="user-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rUser['id']; ?>">
    <?php endif; ?>

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if ($rIsEdit): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-override" role="tab"><i class="icon-base ti tabler-pencil-plus me-1"></i><?= $language::get('overrides'); ?></button></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="username"><?= $language::get('username'); ?></label>
                            <input type="text" class="form-control" id="username" name="username" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['username'], ENT_QUOTES) : htmlspecialchars(AdminHelpers::generateString(10), ENT_QUOTES); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password"><?= $rIsEdit ? ($language::get('change') ?: 'Change') . ' ' : ''; ?><?= $language::get('password'); ?></label>
                            <input type="text" class="form-control" id="password" name="password" <?= $rIsEdit ? 'placeholder="' . htmlspecialchars((string) $language::get('enter_a_new_password_here_to_change_it'), ENT_QUOTES) . '"' : ''; ?> value="<?= $rIsEdit ? '' : htmlspecialchars(AdminHelpers::generateString($rMinPwLen), ENT_QUOTES); ?>">
                        </div>
                    </div>

                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="member_group_id"><?= $language::get('member_group'); ?></label>
                            <select name="member_group_id" id="member_group_id" class="form-select">
                                <?php foreach (GroupService::getAll() as $rGroup): ?>
                                    <option value="<?= (int) $rGroup['group_id']; ?>" <?= ($rIsEdit && (int) $rUser['member_group_id'] === (int) $rGroup['group_id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email"><?= $language::get('email_address'); ?></label>
                            <input type="email" id="email" class="form-control" name="email" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['email'], ENT_QUOTES) : ''; ?>">
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="owner_id"><?= $language::get('owner'); ?></label>
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1">
                                <select name="owner_id" id="owner_id" class="form-select">
                                    <option value="0"></option>
                                    <?php if ($rIsEdit && (int) $rUser['owner_id'] > 0):
                                        $rOwnerRow = UserRepository::getRegisteredUserById((int) $rUser['owner_id']); ?>
                                        <?php if ($rOwnerRow): ?>
                                            <option value="<?= (int) $rOwnerRow['id']; ?>" selected><?= htmlspecialchars((string) $rOwnerRow['username'], ENT_QUOTES); ?></option>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <button type="button" class="btn btn-label-warning flex-shrink-0" id="clear-owner"><?= $language::get('clear'); ?></button>
                        </div>
                    </div>

                    <div class="row mb-6">
                        <div class="col-md-4">
                            <label class="form-label" for="credits"><?= $language::get('credits'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control" id="credits" name="credits" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['credits'], ENT_QUOTES) : '0'; ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="credits_reason"><?= $language::get('reason_for_adjustment') ?: 'Reason for Adjustment'; ?></label>
                            <input type="text" class="form-control" id="credits_reason" name="credits_reason" value="">
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="form-label" for="reseller_dns"><?= $language::get('reseller_dns') ?: 'Reseller DNS'; ?></label>
                        <input type="text" class="form-control" id="reseller_dns" name="reseller_dns" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['reseller_dns'], ENT_QUOTES) : ''; ?>">
                    </div>

                    <div>
                        <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rUser['notes'], ENT_QUOTES) : ''; ?></textarea>
                    </div>
                </div>

                <?php if ($rIsEdit): ?>
                    <div class="tab-pane fade" id="tab-override" role="tabpanel">
                        <?php if (count($rPackages) > 0): ?>
                            <p class="text-muted small mb-3"><?= $language::get('leave_the_override_cell_blank_to_disable_package_override_for_the_selected_package') ?: 'Leave the override cell blank to disable package override for the selected package.'; ?></p>
                            <div class="card-datatable table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th class="text-center">#</th>
                                            <th><?= $language::get('package'); ?></th>
                                            <th class="text-center"><?= $language::get('credits'); ?></th>
                                            <th class="text-center"><?= $language::get('override'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rPackages as $rPackage): ?>
                                            <?php if (empty($rPackage['is_official'])) {
                                                continue;
                                            } ?>
                                            <tr>
                                                <td class="text-center"><?= (int) $rPackage['id']; ?></td>
                                                <td><?= htmlspecialchars((string) $rPackage['package_name'], ENT_QUOTES); ?></td>
                                                <td class="text-center"><?= (int) $rPackage['official_credits']; ?></td>
                                                <td class="text-center">
                                                    <input class="form-control form-control-sm text-center mx-auto" style="max-width:120px" inputmode="numeric" name="override_<?= (int) $rPackage['id']; ?>" type="text" value="<?= isset($rOverrides[$rPackage['id']]) ? (int) $rOverrides[$rPackage['id']]['official_credits'] : ''; ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0" role="alert"><?= $language::get('no_packages_have_been_allocated_to_this_user_group_you_can_modify_the_package_or_group_settings') ?: 'No packages have been allocated to this user group. You can modify the package or group settings.'; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="user-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('user'); ?></button>
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

        // Searchable owner picker (registered users, remote search); 0 = no owner.
        if ($ && $.fn.select2) {
            $('#owner_id').select2({
                width: '100%',
                dropdownParent: $('#owner_id').closest('.tab-pane'),
                ajax: {
                    url: './api',
                    dataType: 'json',
                    cache: true,
                    data: function(params) {
                        return {
                            search: params.term || '',
                            action: 'reguserlist',
                            page: params.page
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.items,
                            pagination: {
                                more: (params.page * 100) < data.total_count
                            }
                        };
                    }
                }
            });
        }

        // Numeric-only guard for the credits/override fields.
        document.querySelectorAll('#credits, input[name^="override_"]').forEach(function(el) {
            el.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        });

        // Clear owner → back to 0 (no owner); trigger change so select2 repaints.
        document.getElementById('clear-owner').addEventListener('click', function() {
            if ($) {
                $('#owner_id').val('0').trigger('change');
            } else {
                document.getElementById('owner_id').value = '0';
            }
        });

        document.getElementById('user-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('user-submit');
            btn.disabled = true;
            fetch('post.php?action=user', {
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
                            window.location.href = dt.location || 'users';
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