<?php

/**
 * Cluster nodes (Bootstrap 5): the load balancers enrolled in the cluster API,
 * with liveness from their heartbeats; code enrolments waiting for the SAS
 * the node shows; enrolment codes for nodes MAIN cannot reach over SSH; and
 * revocation. Every action POSTs `cluster_action` back to this page
 * (ClusterNodesController → Domain\Cluster\ClusterAdmin).
 */

use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterCutover;
use XcVm\Domain\Cluster\ClusterOverview;

$rBadge = static fn(string $rState): string => match ($rState) {
	'ok', 'active' => 'success',
	'suspect', 'enrolling' => 'warning',
	'offline', 'suspended', 'revoked', 'quarantined' => 'danger',
	default => 'secondary',
};
$rWhen = static fn(?int $rTs): string => $rTs ? gmdate('Y-m-d H:i:s', $rTs) . ' UTC' : '—';
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('cluster_nodes'); ?></h4>
    <?php if (!empty($clusterPanelFp)): ?>
        <span class="ms-auto small text-body-secondary" title="<?= $language::get('cluster_panel_fp_help'); ?>"><?= $language::get('cluster_panel_fp'); ?>: <code class="user-select-all"><?= htmlspecialchars($clusterPanelFp, ENT_QUOTES); ?></code></span>
    <?php endif; ?>
</div>

<?php if (!empty($clusterFlash)): ?>
    <div class="alert alert-<?= htmlspecialchars($clusterFlash['type'], ENT_QUOTES); ?> alert-dismissible" role="alert">
        <?= htmlspecialchars($language::get($clusterFlash['message'], $clusterFlash['vars'] ?? []), ENT_QUOTES); ?>
        <?php if (!empty($clusterFlash['code'])): ?>
            <div class="mt-3">
                <code class="d-block fs-5 text-break user-select-all js-cluster-code"><?= htmlspecialchars($clusterFlash['code'], ENT_QUOTES); ?></code>
                <div class="mt-2 small"><?= $language::get('cluster_code_run_on_node'); ?></div>
                <code class="d-block text-break user-select-all small">sudo -u xc_vm /home/xc_vm/bin/xc_agent/xc_agent enrol -state /home/xc_vm/config/cluster/agent.json <?= htmlspecialchars($clusterFlash['code'], ENT_QUOTES); ?></code>
            </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (empty($clusterEnabled)): ?>
    <div class="alert alert-info" role="alert"><?= $language::get('cluster_api_off'); ?> <a href="settings#cluster"><?= $language::get('cluster'); ?></a></div>
<?php endif; ?>

<?php foreach (ClusterHealth::read()['reasons'] as $rReason): ?>
    <div class="alert alert-danger" role="alert"><i class="icon-base ti tabler-alert-triangle me-1"></i><?= $language::get($rReason === ClusterHealth::GUARD_CTL_QUEUE ? 'cluster_ctl_queue' : 'cluster_fleet_silence'); ?></div>
<?php endforeach; ?>

<?php foreach ($clusterBanners ?? [] as $rBanner): ?>
    <div class="alert alert-<?= htmlspecialchars($rBanner['type'], ENT_QUOTES); ?>" role="alert"><i class="icon-base ti tabler-<?= $rBanner['type'] === 'danger' ? 'alert-octagon' : 'alert-triangle'; ?> me-1"></i><?= htmlspecialchars($language::get($rBanner['key'], $rBanner['vars']), ENT_QUOTES); ?></div>
<?php endforeach; ?>

<?php if (!empty($clusterPending)): ?>
    <div class="card mb-4 border-warning">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-user-check me-1"></i><?= $language::get('cluster_pending_enrolments'); ?></h5>
        </div>
        <div class="card-body">
            <p class="text-body-secondary"><?= $language::get('cluster_sas_help'); ?></p>
            <?php foreach ($clusterPending as $rReq): ?>
                <form method="POST" class="row g-2 align-items-center mb-2">
                    <input type="hidden" name="server_id" value="<?= (int) $rReq['server_id']; ?>">
                    <div class="col-md-4">
                        <strong><?= htmlspecialchars($rReq['server_name'], ENT_QUOTES); ?></strong>
                        <div class="small text-body-secondary"><?= htmlspecialchars((string) $rReq['node_uuid'], ENT_QUOTES); ?> · <?= $rWhen((int) $rReq['created_at']); ?></div>
                    </div>
                    <div class="col-md-4"><input type="text" name="sas" class="form-control font-monospace" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"></div>
                    <div class="col-md-4 text-nowrap">
                        <button type="submit" name="cluster_action" value="approve" class="btn btn-success"><i class="icon-base ti tabler-check me-1"></i><?= $language::get('cluster_approve'); ?></button>
                        <button type="submit" name="cluster_action" value="reject" class="btn btn-label-danger"><?= $language::get('cluster_reject'); ?></button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex align-items-center">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-topology-star-3 me-1"></i><?= $language::get('cluster_nodes'); ?></h5>
            <?php if (!empty($clusterEnabled) && !empty($clusterNodes)): ?>
                <form method="POST" class="ms-auto">
                    <button type="submit" name="cluster_action" value="rotate_all" class="btn btn-sm btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_rotate_all_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('cluster_rotate_all'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table class="table" style="width:100%">
            <thead>
                <tr>
                    <th><?= $language::get('server_name'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('cluster_mode'); ?></th>
                    <th><?= $language::get('cluster_telemetry_flow'); ?></th>
                    <th><?= $language::get('cluster_commands_flow'); ?></th>
                    <th><?= $language::get('cluster_logs_flow'); ?></th>
                    <th><?= $language::get('cluster_streams_flow'); ?></th>
                    <th><?= $language::get('cluster_content_flow'); ?></th>
                    <th><?= $language::get('cluster_config_flow'); ?></th>
                    <th><?= $language::get('cluster_connections_flow'); ?></th>
                    <th><?= $language::get('cluster_dataplane_flow'); ?></th>
                    <th><?= $language::get('cluster_root_pin'); ?></th>
                    <th><?= $language::get('cluster_epoch'); ?></th>
                    <th><?= $language::get('cluster_token_expires'); ?></th>
                    <th title="<?= htmlspecialchars($language::get('cluster_fence_window_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_fence_window'); ?></th>
                    <th title="<?= htmlspecialchars($language::get('cluster_queue_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_queue'); ?></th>
                    <th><?= $language::get('cluster_last_seen'); ?></th>
                    <th><?= $language::get('cluster_agent'); ?></th>
                    <th title="<?= $language::get('cluster_settings_misses_help'); ?>"><?= $language::get('cluster_settings_misses'); ?></th>
                    <th title="<?= htmlspecialchars($language::get('cluster_main_connects_tip'), ENT_QUOTES); ?>"><?= $language::get('cluster_main_connects'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clusterNodes)): ?>
                    <tr><td colspan="20" class="text-center text-body-secondary"><?= $language::get('cluster_no_nodes'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($clusterNodes as $rNode): ?>
                    <tr id="node-<?= (int) $rNode['server_id']; ?>">
                        <td>
                            <?= htmlspecialchars($rNode['server_name'], ENT_QUOTES); ?>
                            <div class="small text-body-secondary font-monospace"><?= htmlspecialchars((string) $rNode['node_uuid'], ENT_QUOTES); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-label-<?= $rBadge((string) $rNode['health']); ?>"><?= htmlspecialchars((string) $rNode['health'], ENT_QUOTES); ?></span>
                            <?php if (!empty($rNode['quarantine_reason'])): ?>
                                <div class="small text-body-secondary"><?= htmlspecialchars((string) $rNode['quarantine_reason'], ENT_QUOTES); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= (int) $rNode['mode']; ?>
                            <?php if ((int) $rNode['mode'] === 2 && $rNode['streams_local'] === false): ?>
                                <span class="badge bg-label-danger" title="<?= htmlspecialchars($language::get('cluster_streams_not_local_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_streams_not_local'); ?></span>
                            <?php endif; ?>
                            <?php if (in_array($rNode['state'], ['active', 'quarantined'], true)): ?>
                                <?php if ((int) $rNode['mode'] < 2): ?>
                                    <?php $rToTwo = (int) $rNode['mode'] === 1; // the move that ends the node's use of MAIN's database: asked first ?>
                                    <form method="POST" class="d-inline<?= $rToTwo ? ' js-cluster-confirm' : ''; ?>"<?php if ($rToTwo): ?> data-confirm="<?= htmlspecialchars($language::get('cluster_mode_two_warning'), ENT_QUOTES); ?>"<?php endif; ?>>
                                        <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                        <input type="hidden" name="mode" value="<?= (int) $rNode['mode']; ?>">
                                        <button type="submit" name="cluster_action" value="mode_up" class="btn btn-sm btn-label-<?= $rToTwo ? 'warning' : 'secondary'; ?>" title="<?= htmlspecialchars($language::get('cluster_mode_up_tip'), ENT_QUOTES); ?>">+</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int) $rNode['mode'] > 0): ?>
                                    <?php $rNoDb = $rNode['db_revoked_at'] !== null; // its credentials are gone: mode 1 would have no database ?>
                                    <form method="POST" class="d-inline<?= $rNoDb ? ' js-cluster-confirm' : ''; ?>"<?php if ($rNoDb): ?> data-confirm="<?= htmlspecialchars($language::get('cluster_mode_down_revoked_confirm'), ENT_QUOTES); ?>"<?php endif; ?>>
                                        <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                        <input type="hidden" name="mode" value="<?= (int) $rNode['mode']; ?>">
                                        <button type="submit" name="cluster_action" value="mode_down" class="btn btn-sm btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_mode_down_help'), ENT_QUOTES); ?>">&minus;</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <?php foreach (\XcVm\Domain\Cluster\ClusterAdmin::FLOW_BITS as $rFlowName => $rFlowBit): ?>
                        <td>
                            <?php $rOn = ((int) $rNode['flows'] & $rFlowBit) === $rFlowBit; ?>
                            <?php if (!$rOn && $rFlowName === 'dataplane' && empty($rNode['relay']) && in_array($rNode['state'], ['active', 'quarantined'], true)): ?>
                                <span class="btn btn-sm btn-label-secondary disabled" title="<?= $language::get('cluster_dataplane_needs_relay'); ?>"><?= $language::get('cluster_flow_off'); ?></span>
                            <?php elseif (in_array($rNode['state'], ['active', 'quarantined'], true)): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="<?= $rFlowName . ($rOn ? '_off' : '_on'); ?>" class="btn btn-sm <?= $rOn ? 'btn-label-success' : 'btn-label-secondary'; ?>" title="<?= $language::get('cluster_' . $rFlowName . '_help'); ?>"><?= $language::get($rOn ? 'cluster_flow_on' : 'cluster_flow_off'); ?></button>
                                </form>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
                            <?php if ($rFlowName === 'dataplane' && $rNode['relay_down_since'] !== null): ?>
                                <span class="badge bg-label-danger" title="<?= htmlspecialchars($language::get('cluster_relay_unbound_help') . ' ' . $rNode['relay_error'], ENT_QUOTES); ?>"><?= $language::get('cluster_relay_unbound'); ?> <?= $rWhen((int) $rNode['relay_down_since']); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <?php if (!empty($rNode['root_ready'])): ?>
                                <span class="badge bg-label-success" title="<?= $language::get('cluster_root_ready_help'); ?>">root</span>
                            <?php else: ?>
                                <span class="text-body-secondary" title="<?= $language::get('cluster_root_missing_help'); ?>">—</span>
                            <?php endif; ?>
                            <?php if (!empty($rNode['core_pinned'])): ?>
                                <span class="badge bg-label-success" title="<?= htmlspecialchars($language::get('cluster_core_pinned_help'), ENT_QUOTES); ?>">core</span>
                            <?php elseif (!empty($rNode['root_ready']) && $rNode['state'] === 'active'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="pin_core" class="btn btn-sm btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_pin_core_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_pin_core'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $rNode['epoch']; ?> <span class="small text-body-secondary">(gen <?= (int) $rNode['gen']; ?>)</span></td>
                        <td><?= $rWhen($rNode['token_exp'] === null ? null : (int) $rNode['token_exp']); ?></td>
                        <td>
                            <?php $rFence = $clusterFences[(int) $rNode['server_id']] ?? null; ?>
                            <?php if ($rFence === null || $rFence['lease_until'] === null || $rNode['state'] === 'revoked'): ?>
                                <span class="text-body-secondary">—</span>
                            <?php else: ?>
                                <?= $rWhen($rFence['lease_until']); ?>
                                <div class="small text-body-secondary">
                                    <?= htmlspecialchars($language::get('cluster_fence_drain', ['{TIME}' => $rWhen((int) $rFence['drain_until'])]), ENT_QUOTES); ?>
                                    · <span class="badge bg-label-<?= $rFence['fence_on'] ? 'warning' : 'secondary'; ?>"><?= $language::get($rFence['fence_on'] ? 'cluster_fence_on' : 'cluster_fence_off'); ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $rDepth = (int) ($clusterMetrics['commands']['per_node'][(int) $rNode['server_id']] ?? 0); ?>
                            <span class="badge bg-label-<?= $rDepth > 0 ? 'warning' : 'secondary'; ?>"><?= $rDepth; ?></span>
                        </td>
                        <td>
                            <?= $rWhen($rNode['last_seen_at'] === null ? null : intdiv((int) $rNode['last_seen_at'], 1000)); ?>
                            <?php if (($rClock = ClusterOverview::clockBadge($rNode['clock_offset_ms'] ?? null)) !== null): ?>
                                <span class="badge bg-label-<?= $rClock['tone']; ?>" title="<?= htmlspecialchars($language::get('cluster_clock_help'), ENT_QUOTES); ?>"><?= htmlspecialchars($language::get($rClock['key'], $rClock['vars']), ENT_QUOTES); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars((string) ($rNode['agent_version'] ?? '—'), ENT_QUOTES); ?>
                            <?php if (!empty($rNode['arch'])): ?>
                                <span class="small text-body-secondary">(<?= htmlspecialchars((string) $rNode['arch'], ENT_QUOTES); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $rNode['mode'] < 1 || !is_array($rNode['settings_misses'] ?? null)): ?>
                                <span class="text-body-secondary">—</span>
                            <?php elseif ($rNode['settings_misses'] === []): ?>
                                <span class="badge bg-label-success">0</span>
                            <?php else: ?>
                                <details>
                                    <summary><span class="badge bg-label-warning"><?= count($rNode['settings_misses']); ?></span></summary>
                                    <ul class="list-unstyled small font-monospace mb-0">
                                        <?php foreach ($rNode['settings_misses'] as $rKey => $rCount): ?>
                                            <li><?= htmlspecialchars((string) $rKey, ENT_QUOTES); ?> × <?= (int) $rCount; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $rConnects = $rNode['connects'] ?? null; ?>
                            <?php if ((int) $rNode['mode'] < 1 || !is_array($rConnects)): ?>
                                <span class="text-body-secondary">—</span>
                            <?php else: ?>
                                <?php $rNone = $rConnects['sql_connects'] === 0 && $rConnects['redis_connects'] === 0; ?>
                                <details>
                                    <summary><span class="badge bg-label-<?= $rNone ? 'success' : 'warning'; ?>">SQL <?= (int) $rConnects['sql_connects']; ?> · Redis <?= (int) $rConnects['redis_connects']; ?></span></summary>
                                    <?php if ($rConnects['connects_since'] !== null): ?>
                                        <div class="small text-body-secondary"><?= $language::get('cluster_connects_since'); ?> <?= $rWhen($rConnects['connects_since']); ?></div>
                                    <?php endif; ?>
                                    <ul class="list-unstyled small font-monospace mb-0">
                                        <?php foreach ($rConnects['sites'] as $rSite => $rCount): ?>
                                            <li><?= htmlspecialchars((string) $rSite, ENT_QUOTES); ?> × <?= (int) $rCount; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($rNode['state'] !== 'revoked'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="rotate_now" class="btn btn-sm btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_rotate_now_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_rotate_now'); ?></button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="resync" class="btn btn-sm btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_resync_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_resync'); ?></button>
                                </form>
                                <form method="POST" class="d-inline js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get('cluster_fence_confirm'), ENT_QUOTES); ?>">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="fence" class="btn btn-sm btn-label-warning" title="<?= htmlspecialchars($language::get('cluster_fence_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_fence'); ?></button>
                                    <button type="submit" name="cluster_action" value="unfence" class="btn btn-sm btn-label-secondary" formnovalidate title="<?= htmlspecialchars($language::get('cluster_unfence_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_unfence'); ?></button>
                                </form>
                                <?php if ($rNode['state'] === 'quarantined'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                        <button type="submit" name="cluster_action" value="trust" class="btn btn-sm btn-label-success" title="<?= htmlspecialchars($language::get('cluster_trust_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_trust'); ?></button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="d-inline js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get('cluster_quarantine_confirm'), ENT_QUOTES); ?>">
                                        <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                        <button type="submit" name="cluster_action" value="quarantine" class="btn btn-sm btn-label-warning" title="<?= htmlspecialchars($language::get('cluster_quarantine_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_quarantine'); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($rNode['db_revoked_at'] !== null): ?>
                                    <span class="badge bg-label-success" title="<?= htmlspecialchars($language::get('cluster_db_revoked_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_db_revoked'); ?> <?= $rWhen((int) $rNode['db_revoked_at']); ?></span>
                                <?php elseif ((int) $rNode['mode'] === 2 && $rNode['state'] === 'active'): ?>
                                    <form method="POST" class="d-inline js-cluster-strip">
                                        <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                        <button type="submit" name="cluster_action" value="strip_credentials" class="btn btn-sm btn-label-warning" title="<?= htmlspecialchars($language::get('cluster_strip_credentials_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_strip_credentials'); ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" class="d-inline js-cluster-revoke">
                                    <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                    <button type="submit" name="cluster_action" value="revoke" class="btn btn-sm btn-label-danger"><?= $language::get('cluster_revoke'); ?></button>
                                </form>
                                <?php // The guided cutover (ClusterCutover): every flow, watched, up to mode 1. ?>
                                <?php $rCut = ClusterCutover::state((int) $rNode['server_id']); ?>
                                <?php $rCutRunning = $rCut !== null && ClusterCutover::running((int) $rNode['server_id'], $rCut); ?>
                                <?php $rCutAny = $rCutAny ?? false; $rCutAny = $rCutAny || $rCutRunning || ($rCut['status'] ?? '') === 'starting'; ?>
                                <?php if ($rNode['state'] === 'active' && (int) $rNode['mode'] < 2 && !$rCutRunning && ((int) $rNode['flows'] & ClusterAdmin::MODE2_FLOWS) !== ClusterAdmin::MODE2_FLOWS): ?>
                                    <form method="POST" class="d-inline js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get('cluster_cutover_confirm'), ENT_QUOTES); ?>">
                                        <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                        <button type="submit" name="cluster_action" value="cutover_start" class="btn btn-sm btn-label-primary" title="<?= htmlspecialchars($language::get('cluster_cutover_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_cutover'); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($rCut !== null): ?>
                                    <?php $rCutTone = ['starting' => 'info', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary', 'waiting' => 'secondary', 'seeding' => 'info', 'switching' => 'info', 'watching' => 'warning', 'on' => 'success', 'skipped' => 'secondary']; ?>
                                    <?php $rCutStatus = ['starting' => $language::get('cluster_cutover_starting'), 'running' => $language::get('cluster_cutover_running'), 'done' => $language::get('cluster_cutover_done'), 'failed' => $language::get('cluster_cutover_failed'), 'cancelled' => $language::get('cluster_cutover_cancelled')]; ?>
                                    <?php $rCutStep = ['waiting' => $language::get('cluster_cutover_step_waiting'), 'seeding' => $language::get('cluster_cutover_step_seeding'), 'switching' => $language::get('cluster_cutover_step_switching'), 'watching' => $language::get('cluster_cutover_step_watching'), 'on' => $language::get('cluster_cutover_step_on'), 'failed' => $language::get('cluster_cutover_step_failed'), 'skipped' => $language::get('cluster_cutover_step_skipped')]; ?>
                                    <div class="mt-2 small text-wrap" style="min-width:260px">
                                        <span class="badge bg-label-<?= $rCutTone[$rCut['status']] ?? 'secondary'; ?>"><?= $language::get('cluster_cutover'); ?>: <?= $rCutStatus[$rCut['status']] ?? htmlspecialchars((string) $rCut['status'], ENT_QUOTES); ?></span>
                                        <?php foreach ($rCut['steps'] ?? [] as $rCutFlow): ?>
                                            <span class="badge bg-label-<?= $rCutTone[$rCutFlow['state']] ?? 'secondary'; ?>" title="<?= htmlspecialchars(($rCutStep[$rCutFlow['state']] ?? (string) $rCutFlow['state']) . ($rCutFlow['note'] !== '' ? ': ' . $rCutFlow['note'] : ''), ENT_QUOTES); ?>"><?= $language::get('cluster_' . $rCutFlow['flow'] . '_flow'); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (($rCut['note'] ?? '') !== ''): ?>
                                            <div class="text-body-secondary mt-1"><?= htmlspecialchars((string) $rCut['note'], ENT_QUOTES); ?></div>
                                        <?php endif; ?>
                                        <?php if ($rCutRunning): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="server_id" value="<?= (int) $rNode['server_id']; ?>">
                                                <button type="submit" name="cluster_action" value="cutover_cancel" class="btn btn-sm btn-label-danger mt-1"><?= $language::get('cluster_cutover_cancel'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($clusterMetrics)): ?>
    <?php
    $rCmd = $clusterMetrics['commands'];
    $rSat = $clusterMetrics['saturation'];
    $rSec = static fn(?int $rValue): string => $rValue === null ? '—' : $rValue . ' s';
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-chart-dots me-1"></i><?= $language::get('cluster_metrics'); ?></h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="small text-body-secondary"><?= $language::get('cluster_metric_queue'); ?></div>
                    <div class="fs-5"><?= (int) $rCmd['depth']; ?></div>
                    <div class="small text-body-secondary"><?= htmlspecialchars($language::get('cluster_metric_oldest', ['{AGE}' => $rCmd['oldest'] === null ? '—' : (time() - (int) $rCmd['oldest']) . ' s']), ENT_QUOTES); ?></div>
                </div>
                <div class="col-md-3" title="<?= htmlspecialchars($language::get('cluster_metric_latency_help'), ENT_QUOTES); ?>">
                    <div class="small text-body-secondary"><?= $language::get('cluster_metric_delivery'); ?></div>
                    <div class="fs-5">p50 <?= $rSec($rCmd['deliver_p50']); ?> · p99 <?= $rSec($rCmd['deliver_p99']); ?></div>
                    <div class="small text-body-secondary"><?= $language::get('cluster_metric_ack'); ?>: p50 <?= $rSec($rCmd['ack_p50']); ?> · p99 <?= $rSec($rCmd['ack_p99']); ?> (<?= (int) $rCmd['samples']; ?>)</div>
                </div>
                <div class="col-md-3" title="<?= htmlspecialchars($language::get('cluster_metric_ingest_help'), ENT_QUOTES); ?>">
                    <div class="small text-body-secondary"><?= $language::get('cluster_metric_ingest'); ?></div>
                    <?php if ($rSat['ingest'] === null): ?>
                        <div class="fs-5 text-body-secondary">—</div>
                        <div class="small text-body-secondary"><?= $language::get('cluster_metric_no_bus'); ?></div>
                    <?php else: ?>
                        <div class="fs-5">P0 <?= (int) $rSat['ingest']['p0']; ?>/<?= (int) $rSat['ingest']['permits']['p0']; ?> · bulk <?= (int) $rSat['ingest']['bulk']; ?>/<?= (int) $rSat['ingest']['permits']['bulk']; ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-3" title="<?= htmlspecialchars($language::get('cluster_metric_ctl_help'), ENT_QUOTES); ?>">
                    <div class="small text-body-secondary"><?= $language::get('cluster_metric_ctl'); ?></div>
                    <div class="fs-5 <?= $rSat['ctl_queue_ms'] === null ? '' : 'text-warning'; ?>"><?= $rSat['ctl_queue_ms'] === null ? htmlspecialchars($language::get('cluster_metric_ctl_none'), ENT_QUOTES) : number_format($rSat['ctl_queue_ms'] / 1000, 1) . ' s'; ?></div>
                </div>
                <div class="col-md-3 mt-3" title="<?= htmlspecialchars($language::get('cluster_metric_digest_n1_help'), ENT_QUOTES); ?>">
                    <div class="small text-body-secondary"><?= $language::get('cluster_metric_digest_n1'); ?></div>
                    <?php if ($clusterDigestN1['owners'] === []): ?>
                        <div class="fs-5"><?= $language::get('cluster_metric_digest_n1_none'); ?></div>
                    <?php else: ?>
                        <div class="fs-5 text-warning"><?= htmlspecialchars(implode(', ', array_map(static fn(int $rSid): string => ($clusterNames[$rSid] ?? '') ?: '#' . $rSid, $clusterDigestN1['owners'])), ENT_QUOTES); ?></div>
                    <?php endif; ?>
                    <?php if ($clusterDigestN1['silent'] > 0): ?>
                        <div class="small text-body-secondary"><?= htmlspecialchars($language::get('cluster_metric_digest_n1_silent', ['{COUNT}' => (string) $clusterDigestN1['silent']]), ENT_QUOTES); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('cluster_audit_recent'); ?></h5>
        </div>
        <div class="card-datatable table-responsive">
            <table class="table table-sm" style="width:100%">
                <thead>
                    <tr>
                        <th><?= $language::get('date'); ?></th>
                        <th><?= $language::get('server_name'); ?></th>
                        <th><?= $language::get('cluster_audit_event'); ?></th>
                        <th><?= $language::get('cluster_audit_actor'); ?></th>
                        <th><?= $language::get('cluster_audit_detail'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clusterMetrics['audit'])): ?>
                        <tr><td colspan="5" class="text-center text-body-secondary"><?= $language::get('cluster_audit_none'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($clusterMetrics['audit'] as $rRow): ?>
                        <tr>
                            <td class="text-nowrap"><?= $rWhen($rRow['time']); ?></td>
                            <td><?= $rRow['server_id'] === null ? '—' : htmlspecialchars((string) ($clusterNames[$rRow['server_id']] ?? ('#' . $rRow['server_id'])), ENT_QUOTES); ?></td>
                            <td><code><?= htmlspecialchars($rRow['event'], ENT_QUOTES); ?></code></td>
                            <td><?= htmlspecialchars((string) ($rRow['actor'] ?? '—'), ENT_QUOTES); ?></td>
                            <td class="small font-monospace text-break"><?= htmlspecialchars(mb_strimwidth($rRow['detail'], 0, 200, '…'), ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="icon-base ti tabler-key me-1"></i><?= $language::get('cluster_enrol_by_code'); ?></h5>
    </div>
    <div class="card-body">
        <p class="text-body-secondary"><?= $language::get('cluster_code_help'); ?></p>
        <form method="POST" class="row g-2">
            <div class="col-md-4">
                <select name="server_id" class="form-select" required>
                    <?php foreach ($clusterLbs as $rID => $rName): ?>
                        <option value="<?= (int) $rID; ?>"><?= htmlspecialchars($rName, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5"><input type="text" name="url" class="form-control" placeholder="<?= $language::get('cluster_code_url_placeholder'); ?>"></div>
            <div class="col-md-3"><button type="submit" name="cluster_action" value="code" class="btn btn-primary w-100"<?= empty($clusterLbs) ? ' disabled' : ''; ?>><?= $language::get('cluster_issue_code'); ?></button></div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.js-cluster-confirm').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        var rButton = e.submitter;
        if (rButton && rButton.value === 'unfence') {
            return;
        }
        if (!confirm(f.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });
});
document.querySelectorAll('.js-cluster-strip').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(<?= json_encode($language::get('cluster_strip_credentials_confirm')); ?>)) {
            e.preventDefault();
        }
    });
});
document.querySelectorAll('.js-cluster-revoke').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        if (!confirm(<?= json_encode($language::get('cluster_revoke_confirm')); ?>)) {
            e.preventDefault();
        }
    });
});
<?php if (!empty($rCutAny)): ?>
// A guided cutover is running: show its progress again shortly (a GET, never the last POST again).
setTimeout(function () {
    window.location.href = window.location.pathname + window.location.search;
}, 15000);
<?php endif; ?>
</script>

<?php
LayoutRenderer::renderFooter('admin');
?>
