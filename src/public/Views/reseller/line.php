<?php
/**
 * Line — clean view template.
 * Variables from controller: $rLine, $rOrigPackage, $rPackages
 * ViewGlobals: $rUserInfo, $rPermissions, $rSettings, $rGenTrials, $language, $rRequest
 */
?>
<div class="wrapper boxed-layout-ext">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= isset($rLine) ? 'Edit' : 'Add' ?><?php if (isset($rRequest['trial'])) echo ' Trial'; ?> Line</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (!$rGenTrials && !isset($rLine) && isset($rRequest['trial'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $rSettings['disable_trial'] ? 'Trials have been disabled by the administrator. Please try again later.' : 'You have used your allowance of trials for this period. Please try again later.' ?>
                </div>
                <?php else: ?>
                <?php if (isset($rLine) && $rLine['is_trial']): ?>
                <div class="alert alert-info" role="alert">
                    This user is on a trial package. Adding a new package will convert it to an official package.
                </div>
                <?php endif; ?>
                <?php if (isset($rLine) && !in_array($rLine['member_id'], array_merge([$rUserInfo['id']], $rPermissions['direct_reports']))): ?>
                <?php $rOwner = UserRepository::getRegisteredUserById($rLine['member_id']); ?>
                <div class="alert alert-info" role="alert">
                    This line does not belong to you, although you have the right to edit this line you should notify the line's owner <strong><a href="user?id=<?= $rOwner['id'] ?>"><?= htmlspecialchars($rOwner['username']) ?></a></strong> when doing so.
                </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rLine)): ?>
                            <input type="hidden" name="edit" value="<?= intval($rLine['id']) ?>" />
                            <?php elseif (isset($rRequest['trial'])): ?>
                            <input type="hidden" name="trial" value="1" />
                            <?php endif; ?>
                            <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <?php if ($rPermissions['allow_restrictions']): ?>
                                    <li class="nav-item">
                                        <a href="#restrictions" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-hazard-lights mr-1"></i>
                                            <span class="d-none d-sm-inline">Restrictions</span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <li class="nav-item">
                                        <a href="#review-purchase" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-book-open-variant mr-1"></i>
                                            <span class="d-none d-sm-inline">Review Purchase</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="user-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <?php if ($rPermissions['allow_change_username']): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="username">Username</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="username" name="username" placeholder="Auto-generate if blank" value="<?= isset($rLine) ? htmlspecialchars($rLine['username']) : '' ?>" data-indicator="unindicator">
                                                        <div id="unindicator">
                                                            <div class="bar"></div>
                                                            <div class="label"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php elseif (isset($rLine)): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="username">Username</label>
                                                    <div class="col-md-8">
                                                        <input type="text" readonly class="form-control" id="username" value="<?= htmlspecialchars($rLine['username']) ?>">
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($rPermissions['allow_change_password']): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="password">Password</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="password" name="password" placeholder="Auto-generate if blank" value="<?= isset($rLine) ? htmlspecialchars($rLine['password']) : '' ?>" data-indicator="pwindicator">
                                                        <div id="pwindicator">
                                                            <div class="bar"></div>
                                                            <div class="label"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php elseif (isset($rLine)): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="password">Password</label>
                                                    <div class="col-md-8">
                                                        <input type="text" readonly class="form-control" id="password" value="<?= htmlspecialchars($rLine['password']) ?>">
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (count($rPermissions['all_reports']) > 0): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="member_id">Owner</label>
                                                    <div class="col-md-8">
                                                        <select name="member_id" id="member_id" class="form-control select2" data-toggle="select2">
                                                            <optgroup label="Myself">
                                                                <option value="<?= $rUserInfo['id'] ?>"<?= isset($rLine['member_id']) && $rLine['member_id'] == $rUserInfo['id'] ? ' selected' : '' ?>><?= htmlspecialchars($rUserInfo['username']) ?></option>
                                                            </optgroup>
                                                            <?php if (count($rPermissions['direct_reports']) > 0): ?>
                                                            <optgroup label="Direct Reports">
                                                                <?php foreach ($rPermissions['direct_reports'] as $rUserID): ?>
                                                                <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                                                <option value="<?= $rUserID ?>"<?= isset($rLine['member_id']) && $rLine['member_id'] == $rUserID ? ' selected' : '' ?>><?= htmlspecialchars($rRegisteredUser['username']) ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                            <?php endif; ?>
                                                            <?php if (count($rPermissions['direct_reports']) < count($rPermissions['all_reports'])): ?>
                                                            <optgroup label="Indirect Reports">
                                                                <?php foreach ($rPermissions['all_reports'] as $rUserID): ?>
                                                                <?php if (!in_array($rUserID, $rPermissions['direct_reports'])): ?>
                                                                <?php $rRegisteredUser = $rPermissions['users'][$rUserID]; ?>
                                                                <option value="<?= $rUserID ?>"<?= isset($rLine['member_id']) && $rLine['member_id'] == $rUserID ? ' selected' : '' ?>><?= htmlspecialchars($rRegisteredUser['username']) ?></option>
                                                                <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (isset($rOrigPackage)): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="orig_package">Original Package</label>
                                                    <div class="col-md-8">
                                                        <input type="text" readonly class="form-control" id="orig_package" name="orig_package" value="<?= htmlspecialchars($rOrigPackage['package_name']) ?>">
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="package"><?php if (isset($rLine)) echo 'Add '; ?>Package</label>
                                                    <div class="col-md-8">
                                                        <select name="package" id="package" class="form-control select2" data-toggle="select2">
                                                            <?php if (isset($rLine)): ?>
                                                            <option value="">No Changes</option>
                                                            <?php endif; ?>
                                                            <?php foreach ($rPackages as $rPackage): ?>
                                                            <?php if (($rPackage['is_trial'] && isset($rRequest['trial'])) || ($rPackage['is_official'] && !isset($rRequest['trial']))): ?>
                                                            <option value="<?= intval($rPackage['id']) ?>"><?= htmlspecialchars($rPackage['package_name']) ?></option>
                                                            <?php endif; ?>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div id="package_info" style="display: none;">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="package_cost">Package Cost</label>
                                                        <div class="col-md-2">
                                                            <input readonly type="text" class="form-control text-center" id="package_cost" name="package_cost" value="">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="package_duration">Duration</label>
                                                        <div class="col-md-3">
                                                            <input readonly type="text" class="form-control text-center" id="package_duration" name="package_duration" value="">
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4" id="package_warning" style="display:none;">
                                                    <label class="col-md-4 col-form-label" for="max_connections">Warning Notice</label>
                                                    <div class="col-md-8">
                                                        <div class="alert alert-warning" role="alert">
                                                            The package you have selected is incompatible with the existing package. This could be due to the number of connections or other restrictions.<br/><br/>You can still upgrade to this package, however the time added will be from today and not from the end of the original package.
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="max_connections">Max Connections</label>
                                                    <div class="col-md-2">
                                                        <input readonly type="text" class="form-control text-center" id="max_connections" name="max_connections" value="<?= isset($rLine) ? htmlspecialchars($rLine['max_connections']) : '1' ?>">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="exp_date">Expiration Date</label>
                                                    <div class="col-md-3">
                                                        <input readonly type="text" class="form-control text-center date" id="exp_date" name="exp_date" value="<?php if (isset($rLine)) { if (!is_null($rLine['exp_date'])) { echo date('Y-m-d H:i', $rLine['exp_date']); } else { echo '" disabled="disabled'; } } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="contact">WhatsApp <i class="mdi mdi-whatsapp text-success"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="contact" name="contact" placeholder="+491234567890" value="<?= isset($rLine) ? htmlspecialchars($rLine['contact']) : '' ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="reseller_notes">Reseller Notes</label>
                                                    <div class="col-md-8">
                                                        <textarea id="reseller_notes" name="reseller_notes" class="form-control" rows="3" placeholder=""><?= isset($rLine) ? htmlspecialchars($rLine['reseller_notes']) : '' ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <?php if ($rPermissions['allow_restrictions']): ?>
                                    <div class="tab-pane" id="restrictions">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="ip_field">Allowed IP Addresses</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="ip_field" class="form-control" value="">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
                                                            <a href="javascript:void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="allowed_ips">&nbsp;</label>
                                                    <div class="col-md-8">
                                                        <select id="allowed_ips" name="allowed_ips[]" size=6 class="form-control" multiple="multiple">
                                                        <?php if (isset($rLine)): ?>
                                                        <?php foreach (json_decode($rLine['allowed_ips'], true) ?: [] as $rIP): ?>
                                                        <option value="<?= htmlspecialchars($rIP) ?>"><?= htmlspecialchars($rIP) ?></option>
                                                        <?php endforeach; ?>
                                                        <?php endif; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="ua_field">Allowed User-Agents</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="ua_field" class="form-control" value="">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" id="add_ua" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
                                                            <a href="javascript:void(0)" id="remove_ua" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="allowed_ua">&nbsp;</label>
                                                    <div class="col-md-8">
                                                        <select id="allowed_ua" name="allowed_ua[]" size=6 class="form-control" multiple="multiple">
                                                        <?php if (isset($rLine)): ?>
                                                        <?php foreach (json_decode($rLine['allowed_ua'], true) ?: [] as $rUA): ?>
                                                        <option value="<?= htmlspecialchars($rUA) ?>"><?= htmlspecialchars($rUA) ?></option>
                                                        <?php endforeach; ?>
                                                        <?php endif; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="bypass_ua">Bypass UA Restrictions</label>
                                                    <div class="col-md-2">
                                                        <input name="bypass_ua" id="bypass_ua" type="checkbox" <?= isset($rLine) && $rLine['bypass_ua'] == 1 ? 'checked ' : '' ?>data-plugin="switchery" class="js-switch" data-color="#039cfd"/>
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="is_isplock">Lock to ISP</label>
                                                    <div class="col-md-2">
                                                        <input name="is_isplock" id="is_isplock" type="checkbox" <?= isset($rLine) && $rLine['is_isplock'] == 1 ? 'checked ' : '' ?>data-plugin="switchery" class="js-switch" data-color="#039cfd"/>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="isp_clear">Current ISP</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" class="form-control" readonly id="isp_clear" name="isp_clear" value="<?= isset($rLine) ? htmlspecialchars($rLine['isp_desc']) : '' ?>">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" onclick="clearISP()" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                    <div class="tab-pane" id="review-purchase">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="alert alert-danger" role="alert" style="display:none;" id="no-credits">
                                                    <i class="mdi mdi-block-helper mr-2"></i> You do not have enough credits to complete this transaction!
                                                </div>
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
                                                                <td class="text-center" id="cost_credits">0</td>
                                                                <td class="text-center" id="remaining_credits"><?= number_format($rUserInfo['credits'], 0) ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                    <table id="datatable-review" class="table table-striped table-borderless dt-responsive nowrap" style="margin-top:30px;">
                                                        <thead>
                                                            <tr>
                                                                <th class="text-center">ID</th>
                                                                <th><?= $language::get('bouquet_name') ?></th>
                                                                <th class="text-center"><?= $language::get('streams') ?></th>
                                                                <th class="text-center"><?= $language::get('movies') ?></th>
                                                                <th class="text-center"><?= $language::get('series') ?></th>
                                                                <th class="text-center"><?= $language::get('stations') ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="next list-inline-item float-right">
                                                <input name="submit_line" id="submit_button" type="submit" class="btn btn-primary purchase" value="Purchase" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
