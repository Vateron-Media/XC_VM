<?php

/**
 * Reseller Dashboard (Bootstrap 5). Content-only markup rendered inside the
 * reseller new-UI shell (reseller/header.newui.php + footer.newui.php).
 *
 * Live tiles keep the legacy data contract: ./api?action=dashboard (1s) feeds
 * the .active-connections / .online-users / .active-accounts / .credits tiles
 * by their unchanged CSS classes, so the endpoint needs no edits. Recent
 * Activity and Expiring Lines are server-rendered from data prepared by
 * ResellerDashboardController.
 */

$xmCreditsAssigned = count($rRegisteredUsers) > 1;

// Live stat tiles: [wrapClass, icon, accent, label, link|null].
$xmTiles = [
    ['active-connections', 'ti tabler-plug-connected', 'primary', $language::get('connections'),           !empty($rPermissions['reseller_client_connection_logs']) ? 'live_connections' : null],
    ['online-users',       'ti tabler-users',          'success', $language::get('lines_online'),           !empty($rPermissions['reseller_client_connection_logs']) ? 'live_connections' : null],
    ['active-accounts',    'ti tabler-circle-check',   'info',    $language::get('dashboard_active_lines'), null],
    ['credits',            'ti tabler-coin',           'warning', $xmCreditsAssigned ? $language::get('assigned_credits') : $language::get('total_credits'), !empty($rPermissions['create_sub_resellers']) ? 'users' : null],
];
?>

<!-- Page header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h4 class="mb-0"><?= htmlspecialchars($language::get('welcome') . ' ' . $rUserInfo['username']); ?></h4>
</div>

<?php if (!empty($rNotice)): ?>
    <div class="card mb-4">
        <div class="card-body"><?= $rNotice; ?></div>
    </div>
<?php endif; ?>

<!-- Stat tiles -->
<div class="row g-4 mb-4">
    <?php foreach ($xmTiles as [$rWrap, $rIcon, $rAccent, $rLabel, $rLink]): ?>
        <div class="col-sm-6 col-xl-3">
            <?php if ($rLink): ?><a href="<?= htmlspecialchars($rLink, ENT_QUOTES); ?>" class="text-body text-decoration-none"><?php endif; ?>
            <div class="card h-100">
                <div class="card-body d-flex justify-content-between align-items-center <?= $rWrap; ?>">
                    <div class="card-title mb-0">
                        <h5 class="mb-1 me-2"><span class="entry">0</span></h5>
                        <p class="mb-0"><?= htmlspecialchars($rLabel); ?></p>
                    </div>
                    <div class="card-icon">
                        <span class="badge bg-label-<?= $rAccent; ?> rounded p-2">
                            <i class="icon-base <?= $rIcon; ?> icon-26px"></i>
                        </span>
                    </div>
                </div>
            </div>
            <?php if ($rLink): ?></a><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <!-- Recent Activity -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0"><a href="user_logs" class="text-body"><?= htmlspecialchars($language::get('recent_activity')); ?></a></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-activity-scroll" style="max-height: 350px; overflow-y: auto;">
                    <table class="table table-striped table-borderless mb-0">
                        <thead>
                            <tr>
                                <th class="text-center"><?= htmlspecialchars($language::get('reseller')); ?></th>
                                <th class="text-center"><?= htmlspecialchars($language::get('line_user')); ?></th>
                                <th><?= htmlspecialchars($language::get('action')); ?></th>
                                <th class="text-center"><?= htmlspecialchars($language::get('date')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rActivityRows as $rRow): ?>
                                <tr>
                                    <td class="text-center"><a class="text-body" href="user?id=<?= intval($rRow['owner_id']); ?>"><?= htmlspecialchars($rRow['username']); ?></a></td>
                                    <td class="text-center"><?= $rRow['target_html'] ?? '<span class="text-body-secondary">-</span>'; ?></td>
                                    <td><?= $rRow['text']; ?></td>
                                    <td class="text-center"><?= date($rSettings['date_format'] . ' H:i', $rRow['date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Expiring Lines -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0"><a href="lines" class="text-body"><?= htmlspecialchars($language::get('expiring_lines')); ?></a></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-expiring-scroll" style="max-height: 350px; overflow-y: auto;">
                    <table class="table table-striped table-borderless mb-0">
                        <thead>
                            <tr>
                                <th class="text-center"><?= htmlspecialchars($language::get('type')); ?></th>
                                <th class="text-center"><?= htmlspecialchars($language::get('identity')); ?></th>
                                <th class="text-center"><?= htmlspecialchars($language::get('owner')); ?></th>
                                <th class="text-center"><?= htmlspecialchars($language::get('expires')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rExpiringLines as $rUser): ?>
                                <tr>
                                    <?php if ($rUser['is_mag']): ?>
                                        <td class="text-center"><?= htmlspecialchars($language::get('mag_device')); ?></td>
                                        <td class="text-center"><a class="text-body" href="mag?id=<?= intval($rUser['mag_id']); ?>"><?= htmlspecialchars($rUser['mag_mac']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                    <?php elseif ($rUser['is_e2']): ?>
                                        <td class="text-center"><?= htmlspecialchars($language::get('enigma_device')); ?></td>
                                        <td class="text-center"><a class="text-body" href="enigma?id=<?= intval($rUser['e2_id']); ?>"><?= htmlspecialchars($rUser['e2_mac']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                    <?php else: ?>
                                        <td class="text-center"><?= htmlspecialchars($language::get('line')); ?></td>
                                        <td class="text-center"><a class="text-body" href="line?id=<?= intval($rUser['line_id']); ?>"><?= htmlspecialchars($rUser['username']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                    <?php endif; ?>
                                    <td class="text-center"><a class="text-body" href="user?id=<?= intval($rUser['member_id']); ?>"><?= htmlspecialchars($rRegisteredUsers[$rUser['member_id']]['username'] ?? ''); ?></a></td>
                                    <td class="text-center"><?= date($rSettings['date_format'] . ' H:i', $rUser['exp_date']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    // Live reseller stat tiles — poll ./api?action=dashboard (legacy contract:
    // open_connections / online_users / active_accounts / credits(_assigned)).
    (function () {
        var nf = new Intl.NumberFormat('en-US');
        var creditsAssigned = <?= $xmCreditsAssigned ? 'true' : 'false'; ?>;
        function setTile(cls, value) {
            var e = document.querySelector('.' + cls + ' .entry');
            if (e) { e.textContent = nf.format(value || 0); }
        }
        function poll() {
            var start = Date.now();
            fetch('./api?action=dashboard', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    setTile('active-connections', d.open_connections);
                    setTile('online-users', d.online_users);
                    setTile('active-accounts', d.active_accounts);
                    setTile('credits', creditsAssigned ? d.credits_assigned : d.credits);
                })
                .catch(function () { /* keep last values */ })
                .finally(function () {
                    var wait = Math.max(0, 1000 - (Date.now() - start));
                    setTimeout(poll, wait);
                });
        }
        poll();
    })();
</script>
</body>

</html>
