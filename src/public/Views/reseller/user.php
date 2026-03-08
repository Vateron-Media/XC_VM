<?php
/**
 * User (sub-reseller) — clean view template.
 * Variables from controller: $rUser, $rGroups
 * ViewGlobals: $rUserInfo, $rPermissions, $rSettings, $language, $rRequest
 */
?>
<div class="wrapper boxed-layout">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= isset($rUser) ? 'Edit' : 'Add' ?> User</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($rUser) && !in_array($rUser['id'], $rPermissions['direct_reports'])): ?>
                <?php $rOwner = UserRepository::getRegisteredUserById($rUser['owner_id']); ?>
                <div class="alert alert-info" role="alert">
                    This user does not directly report to you, although you have the right to edit this user you should notify the user's parent <strong><a href="user?id=<?= $rOwner['id'] ?>"><?= htmlspecialchars($rOwner['username']) ?></a></strong> when doing so.
                </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rUser)): ?>
                            <input type="hidden" name="edit" value="<?= intval($rUser['id']) ?>" />
                            <?php endif; ?>
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <?php if (!isset($rUser)): ?>
                                    <li class="nav-item">
                                        <a href="#review-purchase" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-book-open-variant mr-1"></i>
                                            <span class="d-none d-sm-inline">Review Purchase</span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="user-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="username">Username</label>
                                                    <div class="col-md-8">
                                                        <input <?php if (!$rPermissions['allow_change_username'] && isset($rUser)) echo 'disabled '; ?>type="text" class="form-control" id="username" name="username" value="<?php if (isset($rUser)) { echo htmlspecialchars($rUser['username']); } else { echo ($rPermissions['allow_change_username'] ? generateString(10) : ''); } ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <?php if ($rPermissions['allow_change_password'] || !isset($rUser)): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="password"><?php if (isset($rUser)) echo 'Change '; ?>Password</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="password" name="password"<?php if (isset($rUser)) echo ' placeholder="Enter a new password here to change it"'; ?> value="<?= isset($rUser) ? '' : ($rPermissions['allow_change_username'] ? generateString(max(10, SettingsManager::getAll()['pass_length'])) : '') ?>" data-indicator="pwindicator">
                                                        <div id="pwindicator">
                                                            <div class="bar"></div>
                                                            <div class="label"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (count($rPermissions['all_reports']) > 0): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="owner_id">Owner</label>
                                                    <div class="col-md-8">
                                                        <select name="owner_id" id="owner_id" class="form-control select2" data-toggle="select2">
                                                            <optgroup label="Myself">
                                                                <option value="<?= $rUserInfo['id'] ?>"<?= isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserInfo['id'] ? ' selected' : '' ?>><?= htmlspecialchars($rUserInfo['username']) ?></option>
                                                            </optgroup>
                                                            <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                                            <optgroup label="Direct Reports">
                                                                <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                                                <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                                                <option value="<?= $rUserID ?>"<?= isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserID ? ' selected' : '' ?>><?= htmlspecialchars($rRegisteredUser['username']) ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                            <?php endif; ?>
                                                            <?php if (count($rPermissions['direct_reports']) < count($rPermissions['all_reports'])): ?>
                                                            <optgroup label="Indirect Reports">
                                                                <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                                                <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                                                <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                                                <option value="<?= $rUserID ?>"<?= isset($rUser['owner_id']) && $rUser['owner_id'] == $rUserID ? ' selected' : '' ?>><?= htmlspecialchars($rRegisteredUser['username']) ?></option>
                                                                <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (count($rPermissions['subresellers']) > 1): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="member_group_id">Member Group</label>
                                                    <div class="col-md-8">
                                                        <select name="member_group_id" id="member_group_id" class="form-control select2" data-toggle="select2">
                                                            <?php foreach ($rGroups as $rGroup): ?>
                                                            <?php if (in_array($rGroup['group_id'], $rPermissions['subresellers'])): ?>
                                                            <option <?= isset($rUser) && intval($rUser['member_group_id']) == intval($rGroup['group_id']) ? 'selected ' : '' ?>value="<?= intval($rGroup['group_id']) ?>"><?= htmlspecialchars($rGroup['group_name']) ?></option>
                                                            <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="email">Email Address</label>
                                                    <div class="col-md-8">
                                                        <input type="email" id="email" class="form-control" name="email" value="<?= isset($rUser) ? htmlspecialchars($rUser['email']) : '' ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="reseller_dns">Custom DNS</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="reseller_dns" name="reseller_dns" value="<?= isset($rUser) ? htmlspecialchars($rUser['reseller_dns']) : '' ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="notes">Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="notes" name="notes" class="form-control" rows="3" placeholder=""><?= isset($rUser) ? $rUser['notes'] : '' ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <?php if (isset($rUser)): ?>
                                            <li class="list-inline-item float-right">
                                                <input name="submit_user" type="submit" class="btn btn-primary purchase" value="Edit" />
                                            </li>
                                            <?php else: ?>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                    <?php if (!isset($rUser)): ?>
                                    <div class="tab-pane" id="review-purchase">
                                        <div class="row">
                                            <div class="col-12">
                                                <?php if ($rUserInfo['credits'] - $rPermissions['create_sub_resellers_price'] < 0): ?>
                                                <div class="alert alert-danger" role="alert" id="no-credits">
                                                    <i class="mdi mdi-block-helper mr-2"></i> You do not have enough credits to complete this transaction!
                                                </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <table class="table table-striped table-borderless" id="credits-cost">
                                                        <thead>
                                                            <tr>
                                                                <th class="text-center">Total Credits</th>
                                                                <th class="text-center">Purchase Cost</th>
                                                                <th class="text-center">Remaining Credits</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <td class="text-center"><?= number_format($rUserInfo['credits'], 0) ?></td>
                                                                <td class="text-center" id="cost_credits"><?= number_format($rPermissions['create_sub_resellers_price'], 0) ?></td>
                                                                <td class="text-center" id="remaining_credits"><?= number_format($rUserInfo['credits'] - $rPermissions['create_sub_resellers_price'], 0) ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <input <?= $rUserInfo['credits'] - $rPermissions['create_sub_resellers_price'] < 0 ? 'disabled ' : '' ?>name="submit_user" type="submit" class="btn btn-primary purchase" value="Purchase" />
                                            </li>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
