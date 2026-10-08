<?php

/**
 * Cluster nodes (Bootstrap 5), in the Manage Servers page's format: one card
 * whose header carries the title and the page's actions (Bulk, Enrol by code),
 * a client-side DataTable of the load balancers enrolled in the cluster API,
 * with liveness from their heartbeats, each node's flows as one cell of
 * toggles and its actions in a row menu; then the secondary cards (MAIN's data
 * plane, figures, audit, viewer record proof), each a title and a line of help
 * with its action on the right. Every action POSTs `cluster_action` back to
 * this page (ClusterNodesController → Domain\Cluster\ClusterAdmin).
 */

use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterCutover;
use XcVm\Domain\Cluster\ClusterOverview;

$rTone = static fn(string $rState): string => match ($rState) {
	'ok', 'active' => 'success',
	'suspect', 'enrolling' => 'warning',
	'offline', 'suspended', 'revoked', 'quarantined' => 'danger',
	default => 'secondary',
};
$rWhen = static fn(?int $rTs): string => $rTs ? gmdate('Y-m-d H:i:s', $rTs) . ' UTC' : '—';
// A table cell's time: short, the full one in its tooltip.
$rShort = static fn(?int $rTs): string => $rTs ? '<span class="text-nowrap" title="' . $rWhen($rTs) . '">' . gmdate('m-d H:i', $rTs) . '</span>' : '—';
$rCutAny = false;
?>

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
    <?php // Code enrolments waiting for the SAS their node shows: above the table, as Manage Servers puts its rolling update. ?>
    <div class="card mb-4 border border-warning">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="card-title mb-0"><i class="icon-base ti tabler-user-check me-1"></i><?= $language::get('cluster_pending_enrolments'); ?></h5>
                <small class="text-body-secondary"><?= $language::get('cluster_sas_help'); ?></small>
            </div>
            <span class="badge bg-label-warning"><?= count($clusterPending); ?></span>
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($clusterPending as $rReq): ?>
                <li class="list-group-item">
                    <form method="POST" class="row g-2 align-items-center">
                        <input type="hidden" name="server_id" value="<?= (int) $rReq['server_id']; ?>">
                        <div class="col-md-4">
                            <span class="fw-medium"><?= htmlspecialchars($rReq['server_name'], ENT_QUOTES); ?></span>
                            <br><small class="text-body-secondary"><span class="font-monospace"><?= htmlspecialchars((string) $rReq['node_uuid'], ENT_QUOTES); ?></span> · <?= $rWhen((int) $rReq['created_at']); ?></small>
                        </div>
                        <div class="col-md-5"><input type="text" name="sas" class="form-control form-control-sm font-monospace" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" aria-label="SAS"></div>
                        <div class="col-md-3 d-flex gap-2 justify-content-md-end">
                            <button type="submit" name="cluster_action" value="approve" class="btn btn-sm btn-success"><i class="icon-base ti tabler-check me-1"></i><?= $language::get('cluster_approve'); ?></button>
                            <button type="submit" name="cluster_action" value="reject" class="btn btn-sm btn-label-danger"><?= $language::get('cluster_reject'); ?></button>
                        </div>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="card-title mb-0"><?= $language::get('cluster_nodes'); ?></h5>
            <?php if (!empty($clusterPanelFp)): ?>
                <small class="text-body-secondary" title="<?= htmlspecialchars($language::get('cluster_panel_fp_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_panel_fp'); ?>: <code class="user-select-all"><?= htmlspecialchars($clusterPanelFp, ENT_QUOTES); ?></code></small>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if (!empty($clusterEnabled) && !empty($clusterNodes)): ?>
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-label-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-tool me-1"></i>Bulk</button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <form method="POST">
                            <button type="submit" name="cluster_action" value="rotate_all" class="dropdown-item" title="<?= htmlspecialchars($language::get('cluster_rotate_all_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-refresh me-2"></i><?= $language::get('cluster_rotate_all'); ?></button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#cluster-enrol" aria-expanded="false" aria-controls="cluster-enrol"><i class="icon-base ti tabler-key me-1"></i><?= $language::get('cluster_enrol_by_code'); ?></button>
        </div>
    </div>

    <?php // Enrol by code, opened from the header as Manage Servers' Add Server leads to its form. ?>
    <div class="collapse" id="cluster-enrol">
        <div class="card-body border-bottom pt-0">
            <p class="small text-body-secondary mb-2"><?= $language::get('cluster_code_help'); ?></p>
            <form method="POST" class="row g-2">
                <div class="col-md-4">
                    <select name="server_id" class="form-select form-select-sm" required aria-label="<?= htmlspecialchars($language::get('server_name'), ENT_QUOTES); ?>">
                        <?php foreach ($clusterLbs as $rID => $rName): ?>
                            <option value="<?= (int) $rID; ?>"><?= htmlspecialchars($rName, ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5"><input type="text" name="url" class="form-control form-control-sm" placeholder="<?= $language::get('cluster_code_url_placeholder'); ?>" aria-label="MAIN URL"></div>
                <div class="col-md-3"><button type="submit" name="cluster_action" value="code" class="btn btn-sm btn-primary w-100"<?= empty($clusterLbs) ? ' disabled' : ''; ?>><?= $language::get('cluster_issue_code'); ?></button></div>
            </form>
        </div>
    </div>

    <div class="card-datatable table-responsive">
        <table id="cluster-nodes-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th><?= $language::get('server_name'); ?></th>
                    <th class="text-center"><?= $language::get('cluster_mode'); ?></th>
                    <th class="text-center"><?= $language::get('cluster_flows'); ?></th>
                    <th class="text-center"><?= $language::get('cluster_root_pin'); ?></th>
                    <th class="text-center"><?= $language::get('cluster_epoch'); ?></th>
                    <th class="text-center" title="<?= htmlspecialchars($language::get('cluster_fence_window_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_fence_window'); ?></th>
                    <th class="text-center" title="<?= htmlspecialchars($language::get('cluster_queue_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_queue'); ?></th>
                    <th class="text-center"><?= $language::get('cluster_last_seen'); ?></th>
                    <th class="text-center"><?= $language::get('cluster_agent'); ?></th>
                    <th class="text-center" title="<?= htmlspecialchars($language::get('cluster_settings_misses_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_settings_misses'); ?></th>
                    <th class="text-center" title="<?= htmlspecialchars($language::get('cluster_main_connects_tip'), ENT_QUOTES); ?>"><?= $language::get('cluster_main_connects'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clusterNodes as $rNode): ?>
                    <?php
                    $rSid = (int) $rNode['server_id'];
                    $rLive = in_array($rNode['state'], ['active', 'quarantined'], true);
                    $rHealth = (string) $rNode['health'];
                    // The guided cutover (ClusterCutover): every flow, watched, up to mode 1.
                    $rCut = $rNode['state'] !== 'revoked' ? ClusterCutover::state($rSid) : null;
                    $rCutRunning = $rCut !== null && ClusterCutover::running($rSid, $rCut);
                    $rCutAny = $rCutAny || $rCutRunning || ($rCut['status'] ?? '') === 'starting';
                    $rStatusTip = $rHealth . ($rHealth !== (string) $rNode['state'] ? ' (' . $rNode['state'] . ')' : '') . (!empty($rNode['quarantine_reason']) ? ': ' . $rNode['quarantine_reason'] : '');
                    ?>
                    <tr id="node-<?= (int) $rNode['server_id']; ?>">
                        <td class="text-center" data-col="health" data-health="<?= htmlspecialchars($rHealth, ENT_QUOTES); ?>" data-order="<?= htmlspecialchars($rHealth, ENT_QUOTES); ?>">
                            <i class="icon-base ti tabler-circle-filled text-<?= $rTone($rHealth); ?>" data-bs-toggle="tooltip" title="<?= htmlspecialchars($rStatusTip, ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($rStatusTip, ENT_QUOTES); ?>"></i>
                        </td>
                        <td data-col="name">
                            <a href="server_view?id=<?= $rSid; ?>" class="fw-medium"><?= htmlspecialchars($rNode['server_name'], ENT_QUOTES); ?></a>
                            <?php foreach (ClusterOverview::nodeBadges($rNode) as $rNodeBadge): ?>
                                <span class="badge bg-label-<?= $rNodeBadge['tone']; ?>" title="<?= htmlspecialchars($language::get($rNodeBadge['help'], $rNodeBadge['vars']), ENT_QUOTES); ?>"><?= htmlspecialchars($language::get($rNodeBadge['key'], $rNodeBadge['vars']), ENT_QUOTES); ?></span>
                            <?php endforeach; ?>
                            <?php if ((int) $rNode['mode'] === 2 && $rNode['streams_local'] === false): ?>
                                <span class="badge bg-label-danger" title="<?= htmlspecialchars($language::get('cluster_streams_not_local_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_streams_not_local'); ?></span>
                            <?php endif; ?>
                            <?php if ($rNode['db_revoked_at'] !== null): ?>
                                <span class="badge bg-label-success" title="<?= htmlspecialchars($language::get('cluster_db_revoked_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_db_revoked'); ?> <?= $rWhen((int) $rNode['db_revoked_at']); ?></span>
                            <?php endif; ?>
                            <br><small class="text-body-secondary font-monospace"><?= htmlspecialchars((string) $rNode['node_uuid'], ENT_QUOTES); ?></small>
                            <?php if (!empty($rNode['quarantine_reason'])): ?>
                                <br><small class="text-danger"><?= htmlspecialchars((string) $rNode['quarantine_reason'], ENT_QUOTES); ?></small>
                            <?php endif; ?>
                            <?php if ($rCut !== null): ?>
                                <?php $rCutTone = ['starting' => 'info', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary', 'waiting' => 'secondary', 'seeding' => 'info', 'switching' => 'info', 'watching' => 'warning', 'on' => 'success', 'skipped' => 'secondary']; ?>
                                <?php $rCutStatus = ['starting' => $language::get('cluster_cutover_starting'), 'running' => $language::get('cluster_cutover_running'), 'done' => $language::get('cluster_cutover_done'), 'failed' => $language::get('cluster_cutover_failed'), 'cancelled' => $language::get('cluster_cutover_cancelled')]; ?>
                                <?php $rCutStep = ['waiting' => $language::get('cluster_cutover_step_waiting'), 'seeding' => $language::get('cluster_cutover_step_seeding'), 'switching' => $language::get('cluster_cutover_step_switching'), 'watching' => $language::get('cluster_cutover_step_watching'), 'on' => $language::get('cluster_cutover_step_on'), 'failed' => $language::get('cluster_cutover_step_failed'), 'skipped' => $language::get('cluster_cutover_step_skipped')]; ?>
                                <div class="mt-1 d-flex flex-wrap gap-1 align-items-center" style="max-width:340px">
                                    <span class="badge bg-label-<?= $rCutTone[$rCut['status']] ?? 'secondary'; ?>"><?= $language::get('cluster_cutover'); ?>: <?= $rCutStatus[$rCut['status']] ?? htmlspecialchars((string) $rCut['status'], ENT_QUOTES); ?></span>
                                    <?php foreach ($rCut['steps'] ?? [] as $rCutFlow): ?>
                                        <span class="badge bg-label-<?= $rCutTone[$rCutFlow['state']] ?? 'secondary'; ?>" title="<?= htmlspecialchars(($rCutStep[$rCutFlow['state']] ?? (string) $rCutFlow['state']) . ($rCutFlow['note'] !== '' ? ': ' . $rCutFlow['note'] : ''), ENT_QUOTES); ?>"><?= $language::get('cluster_' . $rCutFlow['flow'] . '_flow'); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (($rCut['note'] ?? '') !== ''): ?>
                                    <small class="text-body-secondary"><?= htmlspecialchars((string) $rCut['note'], ENT_QUOTES); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap" data-col="mode" data-order="<?= (int) $rNode['mode']; ?>">
                            <span class="badge bg-label-<?= (int) $rNode['mode'] === 2 ? 'primary' : 'secondary'; ?>"><?= (int) $rNode['mode']; ?></span>
                            <?php if ($rLive): ?>
                                <?php if ((int) $rNode['mode'] < 2): ?>
                                    <?php $rToTwo = (int) $rNode['mode'] === 1; // the move that ends the node's use of MAIN's database: asked first ?>
                                    <form method="POST" class="d-inline<?= $rToTwo ? ' js-cluster-confirm' : ''; ?>"<?php if ($rToTwo): ?> data-confirm="<?= htmlspecialchars($language::get('cluster_mode_two_warning'), ENT_QUOTES); ?>"<?php endif; ?>>
                                        <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                        <input type="hidden" name="mode" value="<?= (int) $rNode['mode']; ?>">
                                        <button type="submit" name="cluster_action" value="mode_up" class="btn btn-xs btn-icon btn-label-<?= $rToTwo ? 'warning' : 'secondary'; ?>" title="<?= htmlspecialchars($language::get('cluster_mode_up_tip'), ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($language::get('cluster_mode_up'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-arrow-up"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int) $rNode['mode'] > 0): ?>
                                    <?php $rNoDb = $rNode['db_revoked_at'] !== null; // its credentials are gone: mode 1 would have no database ?>
                                    <form method="POST" class="d-inline<?= $rNoDb ? ' js-cluster-confirm' : ''; ?>"<?php if ($rNoDb): ?> data-confirm="<?= htmlspecialchars($language::get('cluster_mode_down_revoked_confirm'), ENT_QUOTES); ?>"<?php endif; ?>>
                                        <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                        <input type="hidden" name="mode" value="<?= (int) $rNode['mode']; ?>">
                                        <button type="submit" name="cluster_action" value="mode_down" class="btn btn-xs btn-icon btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_mode_down_help'), ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($language::get('cluster_mode_down'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-arrow-down"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td data-col="flows">
                            <?php // Each flow a toggle named after it: filled when on, plain when off. ?>
                            <div class="d-flex flex-wrap gap-1 justify-content-center" style="min-width:240px;max-width:360px">
                                <?php foreach (ClusterAdmin::FLOW_BITS as $rFlowName => $rFlowBit): ?>
                                    <?php $rOn = ((int) $rNode['flows'] & $rFlowBit) === $rFlowBit; ?>
                                    <?php $rFlowLabel = $language::get('cluster_' . $rFlowName . '_flow'); ?>
                                    <?php $rFlowState = $language::get($rOn ? 'cluster_flow_on' : 'cluster_flow_off'); ?>
                                    <?php if (!$rOn && $rFlowName === 'dataplane' && empty($rNode['relay']) && $rLive): ?>
                                        <span class="btn btn-xs btn-label-secondary disabled" title="<?= htmlspecialchars($language::get('cluster_dataplane_needs_relay'), ENT_QUOTES); ?>"><?= $rFlowLabel; ?></span>
                                    <?php elseif ($rLive): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                            <button type="submit" name="cluster_action" value="<?= $rFlowName . ($rOn ? '_off' : '_on'); ?>" class="btn btn-xs <?= $rOn ? 'btn-label-success' : 'btn-outline-secondary'; ?>" aria-pressed="<?= $rOn ? 'true' : 'false'; ?>" title="<?= htmlspecialchars($rFlowLabel . ': ' . $rFlowState . ' — ' . $language::get('cluster_' . $rFlowName . '_help'), ENT_QUOTES); ?>"><?php if ($rOn): ?><i class="icon-base ti tabler-check me-1"></i><?php endif; ?><?= $rFlowLabel; ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-label-secondary" title="<?= htmlspecialchars($rFlowLabel . ': ' . $rFlowState, ENT_QUOTES); ?>"><?= $rFlowLabel; ?></span>
                                    <?php endif; ?>
                                    <?php if ($rFlowName === 'dataplane' && $rNode['relay_down_since'] !== null): ?>
                                        <span class="badge bg-label-danger" title="<?= htmlspecialchars($language::get('cluster_relay_unbound_help') . ' ' . $rNode['relay_error'], ENT_QUOTES); ?>"><?= $language::get('cluster_relay_unbound'); ?> <?= $rWhen((int) $rNode['relay_down_since']); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php if (!empty($rNode['root_ready'])): ?>
                                <span class="badge bg-label-success" title="<?= htmlspecialchars($language::get('cluster_root_ready_help'), ENT_QUOTES); ?>">root</span>
                            <?php else: ?>
                                <span class="text-body-secondary" title="<?= htmlspecialchars($language::get('cluster_root_missing_help'), ENT_QUOTES); ?>">—</span>
                            <?php endif; ?>
                            <?php if (!empty($rNode['core_pinned'])): ?>
                                <span class="badge bg-label-success" title="<?= htmlspecialchars($language::get('cluster_core_pinned_help'), ENT_QUOTES); ?>">core</span>
                            <?php elseif (!empty($rNode['root_ready']) && $rNode['state'] === 'active'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                    <button type="submit" name="cluster_action" value="pin_core" class="btn btn-xs btn-label-secondary" title="<?= htmlspecialchars($language::get('cluster_pin_core_help'), ENT_QUOTES); ?>"><?= $language::get('cluster_pin_core'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap" data-col="epoch" data-order="<?= (int) $rNode['epoch']; ?>">
                            <?= (int) $rNode['epoch']; ?> <small class="text-body-secondary">(gen <?= (int) $rNode['gen']; ?>)</small>
                            <?php if ($rNode['token_exp'] !== null): ?>
                                <br><small class="text-body-secondary text-nowrap" title="<?= htmlspecialchars($language::get('cluster_token_expires') . ': ' . $rWhen((int) $rNode['token_exp']), ENT_QUOTES); ?>"><?= gmdate('m-d H:i', (int) $rNode['token_exp']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php $rFence = $clusterFences[$rSid] ?? null; ?>
                            <?php if ($rFence === null || $rFence['lease_until'] === null || $rNode['state'] === 'revoked'): ?>
                                <span class="text-body-secondary">—</span>
                            <?php else: ?>
                                <?= $rShort($rFence['lease_until']); ?>
                                <br><small class="text-body-secondary text-nowrap" title="<?= $rWhen((int) $rFence['drain_until']); ?>"><?= htmlspecialchars($language::get('cluster_fence_drain', ['{TIME}' => gmdate('m-d H:i', (int) $rFence['drain_until'])]), ENT_QUOTES); ?></small>
                                <br><span class="badge bg-label-<?= $rFence['fence_on'] ? 'warning' : 'secondary'; ?>"><?= $language::get($rFence['fence_on'] ? 'cluster_fence_on' : 'cluster_fence_off'); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php $rDepth = (int) ($clusterMetrics['commands']['per_node'][$rSid] ?? 0); ?>
                        <td class="text-center" data-col="queue" data-queue="<?= $rDepth; ?>" data-order="<?= $rDepth; ?>">
                            <span class="badge bg-label-<?= $rDepth > 0 ? 'warning' : 'secondary'; ?>"><?= $rDepth; ?></span>
                        </td>
                        <td class="text-center text-nowrap" data-col="last-seen" data-order="<?= (int) ($rNode['last_seen_at'] ?? 0); ?>">
                            <?= $rWhen($rNode['last_seen_at'] === null ? null : intdiv((int) $rNode['last_seen_at'], 1000)); ?>
                            <?php if (($rClock = ClusterOverview::clockBadge($rNode['clock_offset_ms'] ?? null)) !== null): ?>
                                <br><span class="badge bg-label-<?= $rClock['tone']; ?>" title="<?= htmlspecialchars($language::get('cluster_clock_help'), ENT_QUOTES); ?>"><?= htmlspecialchars($language::get($rClock['key'], $rClock['vars']), ENT_QUOTES); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-label-secondary"><?= htmlspecialchars((string) ($rNode['agent_version'] ?? '—'), ENT_QUOTES); ?></span>
                            <?php if (!empty($rNode['arch'])): ?>
                                <br><small class="text-body-secondary"><?= htmlspecialchars((string) $rNode['arch'], ENT_QUOTES); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ((int) $rNode['mode'] < 1 || !is_array($rNode['settings_misses'] ?? null)): ?>
                                <span class="text-body-secondary">—</span>
                            <?php elseif ($rNode['settings_misses'] === []): ?>
                                <span class="badge bg-label-success">0</span>
                            <?php else: ?>
                                <details>
                                    <summary class="text-nowrap"><span class="badge bg-label-warning"><?= count($rNode['settings_misses']); ?></span></summary>
                                    <ul class="list-unstyled small font-monospace mb-0 text-start">
                                        <?php foreach ($rNode['settings_misses'] as $rKey => $rCount): ?>
                                            <li><?= htmlspecialchars((string) $rKey, ENT_QUOTES); ?> × <?= (int) $rCount; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php $rConnects = $rNode['connects'] ?? null; ?>
                            <?php if ((int) $rNode['mode'] < 1 || !is_array($rConnects)): ?>
                                <span class="text-body-secondary">—</span>
                            <?php else: ?>
                                <?php $rNone = $rConnects['sql_connects'] === 0 && $rConnects['redis_connects'] === 0; ?>
                                <details>
                                    <summary class="text-nowrap"><span class="badge bg-label-<?= $rNone ? 'success' : 'warning'; ?>">SQL <?= (int) $rConnects['sql_connects']; ?> · Redis <?= (int) $rConnects['redis_connects']; ?></span></summary>
                                    <?php if ($rConnects['connects_since'] !== null): ?>
                                        <small class="text-body-secondary"><?= $language::get('cluster_connects_since'); ?> <?= $rWhen($rConnects['connects_since']); ?></small>
                                    <?php endif; ?>
                                    <ul class="list-unstyled small font-monospace mb-0 text-start">
                                        <?php foreach ($rConnects['sites'] as $rSite => $rCount): ?>
                                            <li><?= htmlspecialchars((string) $rSite, ENT_QUOTES); ?> × <?= (int) $rCount; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($rNode['state'] !== 'revoked'): ?>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= htmlspecialchars($language::get('actions'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-dots-vertical"></i></button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <form method="POST">
                                            <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                            <button type="submit" name="cluster_action" value="rotate_now" class="dropdown-item" title="<?= htmlspecialchars($language::get('cluster_rotate_now_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-refresh me-2"></i><?= $language::get('cluster_rotate_now'); ?></button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                            <button type="submit" name="cluster_action" value="resync" class="dropdown-item" title="<?= htmlspecialchars($language::get('cluster_resync_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-database-import me-2"></i><?= $language::get('cluster_resync'); ?></button>
                                        </form>
                                        <?php if ($rNode['state'] === 'active' && (int) $rNode['mode'] < 2 && !$rCutRunning && ((int) $rNode['flows'] & ClusterAdmin::MODE2_FLOWS) !== ClusterAdmin::MODE2_FLOWS): ?>
                                            <form method="POST" class="js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get('cluster_cutover_confirm'), ENT_QUOTES); ?>">
                                                <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                                <button type="submit" name="cluster_action" value="cutover_start" class="dropdown-item" title="<?= htmlspecialchars($language::get('cluster_cutover_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-route me-2"></i><?= $language::get('cluster_cutover'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($rCutRunning): ?>
                                            <form method="POST">
                                                <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                                <button type="submit" name="cluster_action" value="cutover_cancel" class="dropdown-item text-danger"><i class="icon-base ti tabler-player-stop me-2"></i><?= $language::get('cluster_cutover_cancel'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <div class="dropdown-divider"></div>
                                        <form method="POST" class="js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get('cluster_fence_confirm'), ENT_QUOTES); ?>">
                                            <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                            <button type="submit" name="cluster_action" value="fence" class="dropdown-item" title="<?= htmlspecialchars($language::get('cluster_fence_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-fence me-2"></i><?= $language::get('cluster_fence'); ?></button>
                                            <button type="submit" name="cluster_action" value="unfence" class="dropdown-item" formnovalidate title="<?= htmlspecialchars($language::get('cluster_unfence_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-fence-off me-2"></i><?= $language::get('cluster_unfence'); ?></button>
                                        </form>
                                        <?php if ($rNode['state'] === 'quarantined'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                                <button type="submit" name="cluster_action" value="trust" class="dropdown-item text-success" title="<?= htmlspecialchars($language::get('cluster_trust_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-shield-check me-2"></i><?= $language::get('cluster_trust'); ?></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get('cluster_quarantine_confirm'), ENT_QUOTES); ?>">
                                                <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                                <button type="submit" name="cluster_action" value="quarantine" class="dropdown-item text-warning" title="<?= htmlspecialchars($language::get('cluster_quarantine_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-shield-x me-2"></i><?= $language::get('cluster_quarantine'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($rNode['db_revoked_at'] === null && (int) $rNode['mode'] === 2 && $rNode['state'] === 'active'): ?>
                                            <form method="POST" class="js-cluster-strip">
                                                <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                                <button type="submit" name="cluster_action" value="strip_credentials" class="dropdown-item text-warning" title="<?= htmlspecialchars($language::get('cluster_strip_credentials_help'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-database-off me-2"></i><?= $language::get('cluster_strip_credentials'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                        <div class="dropdown-divider"></div>
                                        <form method="POST" class="js-cluster-revoke">
                                            <input type="hidden" name="server_id" value="<?= $rSid; ?>">
                                            <button type="submit" name="cluster_action" value="revoke" class="dropdown-item text-danger"><i class="icon-base ti tabler-trash me-2"></i><?= $language::get('cluster_revoke'); ?></button>
                                        </form>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($clusterEnabled)): ?>
    <?php // MAIN's own data plane, and what still keeps the load balancers' legacy /api open (ClusterOverview::legacyApiOpenBy). ?>
    <?php $rOpenBy = $clusterLegacyApiOpenBy ?? []; ?>
    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div style="flex:1 1 0;min-width:16rem">
                <h5 class="card-title mb-0"><i class="icon-base ti tabler-shield-lock me-1"></i><?= $language::get('cluster_main_dataplane'); ?></h5>
                <small class="text-body-secondary"><?= $language::get('cluster_main_dataplane_help'); ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-label-<?= $clusterMainDataPlane ? 'success' : 'secondary'; ?>"><?= $language::get($clusterMainDataPlane ? 'cluster_main_dataplane_state_on' : 'cluster_main_dataplane_state_off'); ?></span>
                <form method="POST" class="d-inline js-cluster-confirm" data-confirm="<?= htmlspecialchars($language::get($clusterMainDataPlane ? 'cluster_main_dataplane_confirm_off' : 'cluster_main_dataplane_confirm_on'), ENT_QUOTES); ?>">
                    <button type="submit" name="cluster_action" value="<?= $clusterMainDataPlane ? 'main_dataplane_off' : 'main_dataplane_on'; ?>" class="btn btn-sm btn-label-<?= $clusterMainDataPlane ? 'secondary' : 'primary'; ?>"><?= $language::get($clusterMainDataPlane ? 'cluster_main_dataplane_off' : 'cluster_main_dataplane_on'); ?></button>
                </form>
            </div>
        </div>
        <div class="card-body pt-0">
            <small class="text-body-secondary"><?= $language::get('cluster_legacy_api'); ?>:</small>
            <?php if ($rOpenBy === []): ?>
                <span class="text-success"><?= htmlspecialchars($language::get('cluster_legacy_api_closed'), ENT_QUOTES); ?></span>
            <?php else: ?>
                <span class="text-warning"><?= htmlspecialchars($language::get('cluster_legacy_api_open', ['{SERVERS}' => implode(', ', array_map(static fn(int $rSid): string => ($clusterNames[$rSid] ?? '') ?: '#' . $rSid, $rOpenBy))]), ENT_QUOTES); ?></span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($clusterMetrics)): ?>
    <?php
    $rCmd = $clusterMetrics['commands'];
    $rSat = $clusterMetrics['saturation'];
    $rSec = static fn(?int $rValue): string => $rValue === null ? '—' : $rValue . ' s';
    ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-chart-dots me-1"></i><?= $language::get('cluster_metrics'); ?></h5>
        </div>
        <div class="card-body pt-0">
            <div class="row g-4">
                <div class="col-sm-6 col-xl">
                    <small class="text-body-secondary"><?= $language::get('cluster_metric_queue'); ?></small>
                    <div class="fs-5 fw-medium"><?= (int) $rCmd['depth']; ?></div>
                    <small class="text-body-secondary"><?= htmlspecialchars($language::get('cluster_metric_oldest', ['{AGE}' => $rCmd['oldest'] === null ? '—' : (time() - (int) $rCmd['oldest']) . ' s']), ENT_QUOTES); ?></small>
                </div>
                <div class="col-sm-6 col-xl" title="<?= htmlspecialchars($language::get('cluster_metric_latency_help'), ENT_QUOTES); ?>">
                    <small class="text-body-secondary"><?= $language::get('cluster_metric_delivery'); ?></small>
                    <div class="fs-5 fw-medium">p50 <?= $rSec($rCmd['deliver_p50']); ?> · p99 <?= $rSec($rCmd['deliver_p99']); ?></div>
                    <small class="text-body-secondary"><?= $language::get('cluster_metric_ack'); ?>: p50 <?= $rSec($rCmd['ack_p50']); ?> · p99 <?= $rSec($rCmd['ack_p99']); ?> (<?= (int) $rCmd['samples']; ?>)</small>
                </div>
                <div class="col-sm-6 col-xl" title="<?= htmlspecialchars($language::get('cluster_metric_ingest_help'), ENT_QUOTES); ?>">
                    <small class="text-body-secondary"><?= $language::get('cluster_metric_ingest'); ?></small>
                    <?php if ($rSat['ingest'] === null): ?>
                        <div class="fs-5 fw-medium text-body-secondary">—</div>
                        <small class="text-body-secondary"><?= $language::get('cluster_metric_no_bus'); ?></small>
                    <?php else: ?>
                        <div class="fs-5 fw-medium">P0 <?= (int) $rSat['ingest']['p0']; ?>/<?= (int) $rSat['ingest']['permits']['p0']; ?> · bulk <?= (int) $rSat['ingest']['bulk']; ?>/<?= (int) $rSat['ingest']['permits']['bulk']; ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-sm-6 col-xl" title="<?= htmlspecialchars($language::get('cluster_metric_ctl_help'), ENT_QUOTES); ?>">
                    <small class="text-body-secondary"><?= $language::get('cluster_metric_ctl'); ?></small>
                    <div class="fs-5 fw-medium <?= $rSat['ctl_queue_ms'] === null ? '' : 'text-warning'; ?>"><?= $rSat['ctl_queue_ms'] === null ? htmlspecialchars($language::get('cluster_metric_ctl_none'), ENT_QUOTES) : number_format($rSat['ctl_queue_ms'] / 1000, 1) . ' s'; ?></div>
                </div>
                <div class="col-sm-6 col-xl" title="<?= htmlspecialchars($language::get('cluster_metric_digest_n1_help'), ENT_QUOTES); ?>">
                    <small class="text-body-secondary"><?= $language::get('cluster_metric_digest_n1'); ?></small>
                    <?php if ($clusterDigestN1['owners'] === []): ?>
                        <div class="fs-5 fw-medium"><?= $language::get('cluster_metric_digest_n1_none'); ?></div>
                    <?php else: ?>
                        <div class="fs-5 fw-medium text-warning"><?= htmlspecialchars(implode(', ', array_map(static fn(int $rSid): string => ($clusterNames[$rSid] ?? '') ?: '#' . $rSid, $clusterDigestN1['owners'])), ENT_QUOTES); ?></div>
                    <?php endif; ?>
                    <?php if ($clusterDigestN1['silent'] > 0): ?>
                        <small class="text-body-secondary"><?= htmlspecialchars($language::get('cluster_metric_digest_n1_silent', ['{COUNT}' => (string) $clusterDigestN1['silent']]), ENT_QUOTES); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('cluster_audit_recent'); ?></h5>
        </div>
        <div class="card-datatable table-responsive">
            <table id="cluster-audit-table" class="table table-sm" style="width:100%">
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
                    <?php foreach ($clusterMetrics['audit'] as $rRow): ?>
                        <tr>
                            <td class="text-nowrap" data-order="<?= (int) $rRow['time']; ?>"><?= $rWhen($rRow['time']); ?></td>
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

<?php if (!empty($clusterBinding)): ?>
    <?php // Viewer record proof (cluster_conn_binding): which nodes prove, and whether enforce is safe yet (ClusterOverview::binding). ?>
    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div style="flex:1 1 0;min-width:16rem">
                <h5 class="card-title mb-0"><i class="icon-base ti tabler-certificate me-1"></i><?= $language::get('cluster_binding'); ?></h5>
                <small class="text-body-secondary"><?= $language::get('cluster_binding_help'); ?></small>
            </div>
            <span class="d-flex align-items-center gap-2"><small class="text-body-secondary"><?= $language::get('cluster_binding_mode'); ?></small><span class="badge bg-label-<?= $clusterBinding['mode'] === 'enforce' ? 'primary' : 'secondary'; ?>"><?= htmlspecialchars($clusterBinding['mode'], ENT_QUOTES); ?></span></span>
        </div>
        <div class="card-body pt-0 pb-2">
            <?php if (!$clusterBinding['held']): ?>
                <small class="text-body-secondary"><?= htmlspecialchars($language::get('cluster_binding_none_held'), ENT_QUOTES); ?></small>
            <?php elseif ($clusterBinding['ready']): ?>
                <small class="text-success"><?= htmlspecialchars($language::get('cluster_binding_ready'), ENT_QUOTES); ?></small>
            <?php else: ?>
                <small class="text-warning"><?= htmlspecialchars($language::get('cluster_binding_not_ready'), ENT_QUOTES); ?></small>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><?= $language::get('server_name'); ?></th>
                        <th class="text-center"><?= $language::get('cluster_binding_proves'); ?></th>
                        <th class="text-center"><?= $language::get('cluster_binding_held'); ?></th>
                        <th><?= $language::get('cluster_binding_today'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clusterBinding['nodes'] as $rRow): ?>
                        <?php $rCounts = $rRow['counts']; ?>
                        <tr>
                            <td class="fw-medium"><?= htmlspecialchars($rRow['server_name'] !== '' ? $rRow['server_name'] : '#' . $rRow['server_id'], ENT_QUOTES); ?></td>
                            <td class="text-center"><span class="badge bg-label-<?= $rRow['proves'] ? 'success' : 'secondary'; ?>"><?= $language::get($rRow['proves'] ? 'label_yes' : 'label_no'); ?></span></td>
                            <td class="text-center"><?= $language::get($rRow['held'] ? 'label_yes' : 'label_no'); ?></td>
                            <td class="small"><?= $rCounts === null ? htmlspecialchars($language::get('cluster_binding_none'), ENT_QUOTES) : htmlspecialchars($language::get('cluster_binding_counts', ['{UNPROVEN}' => (string) (int) $rCounts['unproven'], '{ADMIT}' => (string) (int) $rCounts['admit_unproven'], '{PROVEN}' => (string) (int) $rCounts['proven']]), ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        // The confirmations, delegated: the row menus' forms are moved by DataTables' paging.
        var rConfirms = {
            strip: <?= json_encode($language::get('cluster_strip_credentials_confirm')); ?>,
            revoke: <?= json_encode($language::get('cluster_revoke_confirm')); ?>
        };
        document.addEventListener('submit', function(e) {
            var f = e.target;
            var rText = null;
            if (f.classList.contains('js-cluster-confirm')) {
                if (e.submitter && e.submitter.value === 'unfence') {
                    return;
                }
                rText = f.getAttribute('data-confirm');
            } else if (f.classList.contains('js-cluster-strip')) {
                rText = rConfirms.strip;
            } else if (f.classList.contains('js-cluster-revoke')) {
                rText = rConfirms.revoke;
            }
            if (rText !== null && !confirm(rText)) {
                e.preventDefault();
            }
        });

        var $ = window.jQuery;
        if ($ && $.fn.DataTable) {
            var rTooltips = function(rTable) {
                if (window.bootstrap) {
                    document.querySelectorAll(rTable + ' [data-bs-toggle="tooltip"]').forEach(function(el) {
                        if (!el._tt) {
                            el._tt = new bootstrap.Tooltip(el);
                        }
                    });
                }
            };
            $('#cluster-nodes-table').DataTable({
                order: [[1, 'asc']],
                columnDefs: [{ orderable: false, targets: [3, 4, 10, 11, 12] }],
                layout: { topStart: 'pageLength', topEnd: 'search' },
                language: { emptyTable: <?= json_encode($language::get('cluster_no_nodes')); ?> },
                drawCallback: function() {
                    rTooltips('#cluster-nodes-table');
                }
            });
            if ($('#cluster-audit-table').length) {
                $('#cluster-audit-table').DataTable({
                    order: [[0, 'desc']],
                    pageLength: 10,
                    layout: { topStart: 'pageLength', topEnd: 'search' },
                    language: { emptyTable: <?= json_encode($language::get('cluster_audit_none')); ?> }
                });
            }
        }
        <?php if ($rCutAny): ?>
        // A guided cutover is running: show its progress again shortly (a GET, never the last POST again).
        setTimeout(function() {
            window.location.href = window.location.pathname + window.location.search;
        }, 15000);
        <?php endif; ?>
    })();
</script>
