<?php

/**
 * Sub-reseller add / edit (Bootstrap 5, reseller). Full-page create/edit form for
 * a sub-user reached from the reseller users table (user?id=X) or "Add User"
 * (no id = create). Rebuilt from the legacy wizard reseller/user.php onto the
 * Bootstrap 5 shell; the POST field contract is preserved byte-for-byte.
 *
 * Posts to post.php?action=user (ResellerPostController -> ResellerAPI::processUser).
 * ResellerAPI::processData('user') keeps ONLY: edit, username, password, owner_id,
 * email, reseller_dns, notes, member_group_id — so this form emits exactly those
 * (the legacy credits / credits_reason inputs are dropped: they are not in the
 * reseller POST contract and were stripped server-side, i.e. non-functional).
 *
 * Variables from controller: $rUser, $rGroups
 * ViewGlobals: $rUserInfo, $rPermissions, $rSettings, $language, $rRequest
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\User\UserRepository;

$rIsEdit  = isset($rUser);
$rCost    = intval($rPermissions['create_sub_resellers_price']);
$rNoFunds = !$rIsEdit && ($rUserInfo['credits'] - $rCost < 0);

// Numeric status -> user message map (mirrors the legacy callbackForm 'user' switch).
$rStatusMessages = [
    STATUS_INVALID_PASSWORD     => 'Password is too short! It must be at least ' . intval($rPermissions['minimum_password_length']) . ' characters long.',
    STATUS_INVALID_USERNAME     => 'Username is too short! It must be at least ' . intval($rPermissions['minimum_username_length']) . ' characters long.',
    STATUS_INSUFFICIENT_CREDITS => 'You do not have enough credits to make this purchase.',
    STATUS_INVALID_SUBRESELLER  => 'You are not set up to create subresellers. Please open a ticket.',
    STATUS_EXISTS_USERNAME      => 'The username you selected already exists. Please use another.',
];
?>

<div class="d-flex align-items-center mb-4">
    <a href="users" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? 'Edit' : 'Add'; ?> User</h4>
</div>

<?php if ($rIsEdit && !in_array($rUser['id'], $rPermissions['direct_reports'])): ?>
    <?php $rOwner = UserRepository::getRegisteredUserById($rUser['owner_id']); ?>
    <div class="alert alert-info" role="alert">
        This user does not directly report to you, although you have the right to edit this user you should notify the user's parent <strong><a href="user?id=<?= (int) $rOwner['id']; ?>"><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES); ?></a></strong> when doing so.
    </div>
<?php endif; ?>

<form id="user-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rUser['id']; ?>">
    <?php endif; ?>

    <div class="card mb-6">
        <div class="card-body">
            <div class="mb-6">
                <label class="form-label" for="username">Username</label>
                <input <?= (!$rPermissions['allow_change_username'] && $rIsEdit) ? 'disabled ' : ''; ?>type="text" class="form-control" id="username" name="username" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['username'], ENT_QUOTES) : ($rPermissions['allow_change_username'] ? htmlspecialchars(AdminHelpers::generateString(10), ENT_QUOTES) : ''); ?>" required>
            </div>

            <?php if ($rPermissions['allow_change_password'] || !$rIsEdit): ?>
                <div class="mb-6">
                    <label class="form-label" for="password"><?= $rIsEdit ? 'Change ' : ''; ?>Password</label>
                    <input type="text" class="form-control" id="password" name="password"<?= $rIsEdit ? ' placeholder="Enter a new password here to change it"' : ''; ?> value="<?= $rIsEdit ? '' : ($rPermissions['allow_change_username'] ? htmlspecialchars(AdminHelpers::generateString(max(10, (int) SettingsManager::get('pass_length'))), ENT_QUOTES) : ''); ?>">
                </div>
            <?php endif; ?>

            <?php if (count($rPermissions['all_reports']) > 0): ?>
                <div class="mb-6">
                    <label class="form-label" for="owner_id">Owner</label>
                    <select name="owner_id" id="owner_id" class="form-select select2">
                        <optgroup label="Myself">
                            <option value="<?= (int) $rUserInfo['id']; ?>"<?= isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserInfo['id'] ? ' selected' : ''; ?>><?= htmlspecialchars((string) $rUserInfo['username'], ENT_QUOTES); ?></option>
                        </optgroup>
                        <?php if (count($rPermissions['direct_reports']) > 0): ?>
                            <optgroup label="Direct Reports">
                                <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                    <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                    <option value="<?= (int) $rUserID; ?>"<?= isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserID ? ' selected' : ''; ?>><?= htmlspecialchars((string) $rRegisteredUser['username'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (count($rPermissions['direct_reports']) < count($rPermissions['all_reports'])): ?>
                            <optgroup label="Indirect Reports">
                                <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                    <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                        <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                        <option value="<?= (int) $rUserID; ?>"<?= isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserID ? ' selected' : ''; ?>><?= htmlspecialchars((string) $rRegisteredUser['username'], ENT_QUOTES); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if (count($rPermissions['subresellers']) > 1): ?>
                <div class="mb-6">
                    <label class="form-label" for="member_group_id">Member Group</label>
                    <select name="member_group_id" id="member_group_id" class="form-select select2">
                        <?php foreach ($rGroups as $rGroup): ?>
                            <?php if (in_array($rGroup['group_id'], $rPermissions['subresellers'])): ?>
                                <option <?= $rIsEdit && (int) $rUser['member_group_id'] === (int) $rGroup['group_id'] ? 'selected ' : ''; ?>value="<?= (int) $rGroup['group_id']; ?>"><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="row mb-6">
                <div class="col-md-6">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" class="form-control" name="email" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['email'], ENT_QUOTES) : ''; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="reseller_dns">Reseller DNS</label>
                    <input type="text" class="form-control" id="reseller_dns" name="reseller_dns" value="<?= $rIsEdit ? htmlspecialchars((string) $rUser['reseller_dns'], ENT_QUOTES) : ''; ?>">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rUser['notes'], ENT_QUOTES) : ''; ?></textarea>
            </div>
        </div>
    </div>

    <?php if (!$rIsEdit): ?>
        <div class="card mb-6">
            <div class="card-body">
                <?php if ($rNoFunds): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert" id="no-credits">
                        <i class="icon-base ti tabler-ban me-2"></i> You do not have enough credits to complete this transaction!
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
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
                                <td class="text-center" id="cost_credits"><?= number_format($rCost, 0); ?></td>
                                <td class="text-center" id="remaining_credits"><?= number_format($rUserInfo['credits'] - $rCost, 0); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-end mb-6">
        <button <?= $rNoFunds ? 'disabled ' : ''; ?>type="submit" class="btn btn-primary" id="user-submit"><?= $rIsEdit ? 'Edit' : 'Purchase'; ?></button>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var $ = window.jQuery;
        var toast = window.xcToast || function(m) { alert(m); };
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var statusMessages = <?= json_encode($rStatusMessages); ?>;

        if ($ && $.fn.select2) {
            $('#owner_id, #member_group_id').select2({ width: '100%' });
        }

        document.getElementById('user-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('user-submit');
            btn.disabled = true;
            fetch('post.php?action=user', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt;
                    try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        window.location.href = dt.location || 'users';
                        return;
                    }
                    btn.disabled = false;
                    toast(statusMessages[dt.status] || errText, 'error');
                })
                .catch(function() {
                    btn.disabled = false;
                    toast(errText, 'error');
                });
        });
    })();
</script>
</body>

</html>
