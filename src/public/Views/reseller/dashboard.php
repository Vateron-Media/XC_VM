<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <h4 class="page-title">Welcome <?= htmlspecialchars($rUserInfo['username']) ?></h4>
                </div>
                <?php if (!empty($rNotice)): ?>
                <div class="card" style="padding: 1em 1em 0 1em;">
                    <?= $rNotice ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 col-xl-3">
                <a href="<?= $rPermissions['reseller_client_connection_logs'] ? 'live_connections' : 'javascript: void(0);' ?>">
                    <div class="card cta-box <?php if ($rUserInfo['theme'] == 0) echo 'bg-purple'; ?> text-white rounded-2">
                        <div class="card-body active-connections">
                            <div class="media align-items-center">
                                <div class="col-3">
                                    <div class="avatar-sm bg-light">
                                        <i class="fe-zap avatar-title font-22 <?= $rUserInfo['theme'] == 1 ? 'text-white' : 'text-purple' ?>"></i>
                                    </div>
                                </div>
                                <div class="col-9">
                                    <div class="text-right">
                                        <h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
                                        <p class="text-white mb-1 text-truncate">Connections</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-6 col-xl-3">
                <a href="<?= $rPermissions['reseller_client_connection_logs'] ? 'live_connections' : 'javascript: void(0);' ?>">
                    <div class="card cta-box <?php if ($rUserInfo['theme'] == 0) echo 'bg-success'; ?> text-white rounded-2">
                        <div class="card-body online-users">
                            <div class="media align-items-center">
                                <div class="col-3">
                                    <div class="avatar-sm bg-light">
                                        <i class="fe-users avatar-title font-22 <?= $rUserInfo['theme'] == 1 ? 'text-white' : 'text-success' ?>"></i>
                                    </div>
                                </div>
                                <div class="col-9">
                                    <div class="text-right">
                                        <h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
                                        <p class="text-white mb-1 text-truncate">Lines Online</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-6 col-xl-3">
                <a href="javascript:void(0);" id="manage_lines">
                    <div class="card cta-box <?php if ($rUserInfo['theme'] == 0) echo 'bg-pink'; ?> text-white rounded-2">
                        <div class="card-body active-accounts">
                            <div class="media align-items-center">
                                <div class="col-3">
                                    <div class="avatar-sm bg-light">
                                        <i class="fe-check-circle avatar-title font-22 <?= $rUserInfo['theme'] == 1 ? 'text-white' : 'text-pink' ?>"></i>
                                    </div>
                                </div>
                                <div class="col-9">
                                    <div class="text-right">
                                        <h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
                                        <p class="text-white mb-1 text-truncate">Active Lines</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-6 col-xl-3">
                <a href="<?= $rPermissions['create_sub_resellers'] ? 'users' : 'javascript: void(0);' ?>">
                    <div class="card cta-box <?php if ($rUserInfo['theme'] == 0) echo 'bg-info'; ?> text-white rounded-2">
                        <div class="card-body credits">
                            <div class="media align-items-center">
                                <div class="col-3">
                                    <div class="avatar-sm bg-light">
                                        <i class="fe-dollar-sign avatar-title font-22 <?= $rUserInfo['theme'] == 1 ? 'text-white' : 'text-info' ?>"></i>
                                    </div>
                                </div>
                                <div class="col-9">
                                    <div class="text-right">
                                        <h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
                                        <p class="text-white mb-1 text-truncate"><?= count($rRegisteredUsers) > 1 ? 'Assigned Credits' : 'Total Credits' ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <a href="user_logs"><h4 class="header-title mb-4">Recent Activity</h4></a>
                        <div style="height: 350px; overflow-y: auto;">
                            <table class="table table-striped table-borderless m-0 table-centered dt-responsive nowrap w-100" id="users-table">
                                <thead>
                                    <tr>
                                        <th class="text-center">Reseller</th>
                                        <th class="text-center">Line / User</th>
                                        <th>Action</th>
                                        <th class="text-center">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rActivityRows as $rRow): ?>
                                    <tr>
                                        <td class="text-center"><a class="text-dark" href="user?id=<?= intval($rRow['owner_id']) ?>"><?= htmlspecialchars($rRow['username']) ?></a></td>
                                        <td class="text-center"></td>
                                        <td><?= $rRow['text'] ?></td>
                                        <td class="text-center"><?= date($rSettings['date_format'] . ' H:i', $rRow['date']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <a href="lines"><h4 class="header-title mb-4">Expiring Lines</h4></a>
                        <div style="height: 350px; overflow-y: auto;">
                            <table class="table table-striped table-borderless m-0 table-centered dt-responsive nowrap w-100">
                                <thead>
                                    <tr>
                                        <th class="text-center">Type</th>
                                        <th class="text-center">Identity</th>
                                        <th class="text-center">Owner</th>
                                        <th class="text-center">Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rExpiringLines as $rUser): ?>
                                    <tr>
                                        <?php if ($rUser['is_mag']): ?>
                                        <td class="text-center">MAG Device</td>
                                        <td class="text-center"><a class="text-dark" href="mag?id=<?= intval($rUser['mag_id']) ?>"><?= htmlspecialchars($rUser['mag_mac']) ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . htmlspecialchars($rUser['reseller_notes']) . '"><i class="mdi mdi-note"></i></button>' : '' ?></a></td>
                                        <?php elseif ($rUser['is_e2']): ?>
                                        <td class="text-center">Enigma2 Device</td>
                                        <td class="text-center"><a class="text-dark" href="enigma?id=<?= intval($rUser['e2_id']) ?>"><?= htmlspecialchars($rUser['e2_mac']) ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . htmlspecialchars($rUser['reseller_notes']) . '"><i class="mdi mdi-note"></i></button>' : '' ?></a></td>
                                        <?php else: ?>
                                        <td class="text-center">User Line</td>
                                        <td class="text-center"><a class="text-dark" href="line?id=<?= intval($rUser['line_id']) ?>"><?= htmlspecialchars($rUser['username']) ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . htmlspecialchars($rUser['reseller_notes']) . '"><i class="mdi mdi-note"></i></button>' : '' ?></a></td>
                                        <?php endif; ?>
                                        <td class="text-center"><a class="text-dark" href="user?id=<?= intval($rUser['member_id']) ?>"><?= htmlspecialchars($rRegisteredUsers[$rUser['member_id']]['username'] ?? '') ?></td>
                                        <td class="text-center"><?= date($rSettings['date_format'] . ' H:i', $rUser['exp_date']) ?></td>
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
</div>
