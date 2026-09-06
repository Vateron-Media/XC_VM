<?php

/**
 * Servers management table (Bootstrap 5). Client-rendered: ServerRepository::getAll(true)
 * is echoed as <tbody> and a plain client-side DataTable adds search / sort / paging.
 * Live watchdog metrics (cpu / mem / network) are shown as compact progress bars, and
 * per-server actions (start / stop / restart / kill / proxy / rollback / delete, plus the
 * Server Tools modal) run through ./api?action=server&sub=… . Reached full-page in the
 * new-UI shell.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;

$rCanEdit = Authorization::check('adv', 'edit_server');
$rCanAdd = Authorization::check('adv', 'add_server');
$rCanConns = Authorization::check('adv', 'live_connections');
$rRedis = (bool) SettingsManager::get('redis_handler');
$rMainVer = $rServers[SERVER_ID]['xc_vm_version'] ?? '';

/** Compact coloured progress bar for a 0-100 percentage (cpu / mem). */
$rBar = static function (int $pct): string {
    $rColour = $pct <= 34 ? 'success' : ($pct <= 67 ? 'warning' : 'danger');
    return '<div class="d-flex align-items-center gap-2" style="min-width:70px">'
        . '<div class="progress w-100" style="height:6px"><div class="progress-bar bg-' . $rColour . '" style="width:' . $pct . '%"></div></div>'
        . '<small class="text-body-secondary">' . $pct . '%</small></div>';
};
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('servers'); ?></h5>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($rCanEdit): ?>
                <div id="bulk-bar" class="d-none align-items-center gap-2">
                    <span class="text-body-secondary small" id="bulk-count">0</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-label-success" data-bulk="start"><?= $language::get('start'); ?></button>
                        <button type="button" class="btn btn-label-secondary" data-bulk="stop"><?= $language::get('stop'); ?></button>
                        <button type="button" class="btn btn-label-info" data-bulk="restart"><?= $language::get('restart'); ?></button>
                        <button type="button" class="btn btn-label-dark" data-bulk="purge"><?= $language::get('kill'); ?></button>
                        <button type="button" class="btn btn-label-danger" data-bulk="delete"><?= $language::get('delete'); ?></button>
                    </div>
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-label-secondary dropdown-toggle" data-bs-toggle="dropdown">More</button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <button type="button" class="dropdown-item" data-bulk="enable"><?= $language::get('enable_server'); ?></button>
                            <button type="button" class="dropdown-item" data-bulk="disable"><?= $language::get('disable_server'); ?></button>
                            <div class="dropdown-divider"></div>
                            <button type="button" class="dropdown-item" data-bulk="enable_proxy"><?= $language::get('enable_proxy'); ?></button>
                            <button type="button" class="dropdown-item" data-bulk="disable_proxy"><?= $language::get('disable_proxy'); ?></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-icon btn-label-secondary" id="bulk-clear" title="Clear selection"><i class="icon-base ti tabler-x"></i></button>
                </div>
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-label-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="icon-base ti tabler-tool me-1"></i>Bulk</button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <button type="button" class="dropdown-item" id="op-update-all"><i class="icon-base ti tabler-download me-2"></i>Update All Servers</button>
                        <button type="button" class="dropdown-item" id="op-restart-services"><i class="icon-base ti tabler-refresh me-2"></i>Restart All Services</button>
                        <button type="button" class="dropdown-item" id="op-update-binaries"><i class="icon-base ti tabler-package me-2"></i>Update All Binaries</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($rCanAdd): ?>
                <a href="server_install" class="btn btn-sm btn-primary"><i class="icon-base ti tabler-plus me-1"></i>Add Server</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="servers-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <?php if ($rCanEdit): ?><th class="text-center" style="width:1%"><input type="checkbox" class="form-check-input" id="check-all"></th><?php endif; ?>
                    <th class="text-center"><?= $language::get('order'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th class="text-center"><?= $language::get('proxied'); ?></th>
                    <th><?= $language::get('server_name'); ?></th>
                    <th class="text-center"><?= $language::get('server_ip'); ?></th>
                    <th class="text-center"><?= $language::get('connections'); ?></th>
                    <th class="text-center"><?= $language::get('network'); ?></th>
                    <th class="text-center"><?= $language::get('cpu_header'); ?></th>
                    <th class="text-center"><?= $language::get('mem'); ?></th>
                    <th class="text-center"><?= $language::get('ping'); ?></th>
                    <th class="text-center"><?= $language::get('version'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rServers as $rServer): ?>
                    <?php if ($rServer['server_type'] != 0) {
                        continue;
                    } ?>
                    <?php
                    $rWatchDog = json_decode((string) $rServer['watchdog_data'], true);
                    if (!is_array($rWatchDog)) {
                        $rWatchDog = [];
                    }
                    $rWatchDog += ['total_mem_used_percent' => 0, 'cpu' => 0, 'bytes_sent' => 0, 'bytes_received' => 0];
                    if (empty($rServers[$rServer['id']]['server_online'])) {
                        $rWatchDog['cpu'] = $rWatchDog['total_mem_used_percent'] = $rWatchDog['bytes_sent'] = $rWatchDog['bytes_received'] = 0;
                    }
                    $rIsMain = ((int) $rServer['is_main'] === 1);
                    $rUpdatable = ($rServer['xc_vm_version'] && $rServer['xc_vm_version'] != $rMainVer);
                    $rClients = $rRedis ? (int) $rServer['connections'] : (int) ConnectionTracker::getLiveConnections($rServer['id']);
                    ?>
                    <tr id="server-<?= (int) $rServer['id']; ?>">
                        <?php if ($rCanEdit): ?>
                            <td class="text-center"><input type="checkbox" class="form-check-input row-check" data-id="<?= (int) $rServer['id']; ?>"></td>
                        <?php endif; ?>
                        <td class="text-center"><a href="server_view?id=<?= (int) $rServer['id']; ?>" class="fw-medium"><?= (int) ($rServer['order'] ?: $rServer['id']); ?></a></td>
                        <td class="text-center">
                            <?php if (!$rServer['enabled']): ?>
                                <i class="icon-base ti tabler-circle-filled text-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disabled'); ?>"></i>
                            <?php elseif ($rServer['server_online']): ?>
                                <?php if ($rUpdatable): ?>
                                    <a href="javascript:void(0)" class="js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="update" data-bs-toggle="tooltip" title="Update available: v<?= htmlspecialchars((string) $rMainVer, ENT_QUOTES); ?>"><i class="icon-base ti tabler-circle-arrow-down-filled text-success"></i></a>
                                <?php else: ?>
                                    <i class="icon-base ti tabler-circle-filled text-success" data-bs-toggle="tooltip" title="<?= $language::get('online'); ?>"></i>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php
                                $rPing = $rServer['last_check_ago'] > 0 ? date($rSettings['datetime_format'], $rServer['last_check_ago']) : 'Never';
                                if ($rServer['status'] == 3) {
                                    $rSt = ['info', $language::get('installing')];
                                } elseif ($rServer['status'] == 4) {
                                    $rSt = ['warning', $language::get('installation_failed')];
                                } elseif ($rServer['status'] == 5) {
                                    $rSt = ['info', $language::get('updating')];
                                } elseif (!$rServer['remote_status']) {
                                    $rSt = ['danger', "Can't connect on " . htmlentities((string) $rServer['server_ip']) . ':' . (int) $rServer['http_broadcast_port'] . ' — Last Ping: ' . $rPing];
                                } else {
                                    $rSt = ['danger', 'Last Ping: ' . $rPing];
                                }
                                ?>
                                <i class="icon-base ti tabler-circle-filled text-<?= $rSt[0]; ?>" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $rSt[1], ENT_QUOTES); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><i class="icon-base ti tabler-circle-filled text-<?= $rServer['enable_proxy'] ? 'success' : 'secondary'; ?>"></i></td>
                        <td>
                            <a href="server_view?id=<?= (int) $rServer['id']; ?>" class="fw-medium"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></a>
                            <?php if (!empty($rServer['domain_name'])): ?>
                                <br><small class="text-body-secondary"><?= htmlspecialchars(explode(',', (string) $rServer['domain_name'])[0], ENT_QUOTES); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?= htmlspecialchars((string) $rServer['server_ip'], ENT_QUOTES); ?>
                            <?php if (!empty($rServer['private_ip'])): ?>
                                <br><small class="text-body-secondary">private: <?= htmlspecialchars((string) $rServer['private_ip'], ENT_QUOTES); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center" data-order="<?= $rClients; ?>">
                            <?php if ($rCanConns): ?>
                                <a href="./live_connections?server=<?= (int) $rServer['id']; ?>" class="btn btn-xs btn-label-secondary"><?= number_format($rClients); ?></a>
                            <?php else: ?>
                                <span class="badge bg-label-secondary"><?= number_format($rClients); ?></span>
                            <?php endif; ?>
                            <br><small class="text-body-secondary">of <?= number_format((int) $rServer['total_clients']); ?></small>
                        </td>
                        <td class="text-center" style="min-width:120px">
                            <span class="text-success"><i class="icon-base ti tabler-arrow-up"></i> <?= number_format($rWatchDog['bytes_sent'] / 125000, 0); ?></span>
                            <span class="text-primary ms-2"><i class="icon-base ti tabler-arrow-down"></i> <?= number_format($rWatchDog['bytes_received'] / 125000, 0); ?></span>
                            <br><small class="text-body-secondary"><?= number_format((int) $rServer['network_guaranteed_speed']); ?> Mbps</small>
                        </td>
                        <td class="text-center" data-order="<?= (int) $rWatchDog['cpu']; ?>"><?= $rBar((int) $rWatchDog['cpu']); ?></td>
                        <td class="text-center" data-order="<?= (int) $rWatchDog['total_mem_used_percent']; ?>"><?= $rBar((int) $rWatchDog['total_mem_used_percent']); ?></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= number_format(($rServer['server_online'] ? $rServer['ping'] : 0), 0); ?> ms</span></td>
                        <td class="text-center"><span class="badge bg-label-<?= $rUpdatable ? 'warning' : 'secondary'; ?>"><?= $rServer['xc_vm_version'] ? htmlspecialchars((string) $rServer['xc_vm_version'], ENT_QUOTES) : 'N/A'; ?></span></td>
                        <td class="text-center">
                            <?php if ($rCanEdit): ?>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <button type="button" class="dropdown-item js-tools" data-id="<?= (int) $rServer['id']; ?>"><i class="icon-base ti tabler-tool me-2"></i><?= $language::get('server_tools'); ?></button>
                                        <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="restart"><i class="icon-base ti tabler-refresh me-2"></i><?= $language::get('restart_live_streams'); ?></button>
                                        <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="start"><i class="icon-base ti tabler-player-play me-2"></i><?= $language::get('start_all_streams'); ?></button>
                                        <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="stop"><i class="icon-base ti tabler-player-stop me-2"></i><?= $language::get('stop_all_streams'); ?></button>
                                        <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="kill"><i class="icon-base ti tabler-hammer me-2"></i><?= $language::get('kill_all_connections'); ?></button>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="./server?id=<?= (int) $rServer['id']; ?>"><i class="icon-base ti tabler-pencil me-2"></i><?= $language::get('permission_edit_server'); ?></a>
                                        <button type="button" class="dropdown-item js-rollback" data-id="<?= (int) $rServer['id']; ?>" data-version="<?= htmlspecialchars((string) $rServer['xc_vm_version'], ENT_QUOTES); ?>"><i class="icon-base ti tabler-history me-2"></i>Rollback Version</button>
                                        <?php if ($rServer['enable_proxy']): ?>
                                            <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="disable_proxy"><i class="icon-base ti tabler-shield-off me-2"></i><?= $language::get('disable_proxy'); ?></button>
                                        <?php else: ?>
                                            <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="enable_proxy"><i class="icon-base ti tabler-shield-check me-2"></i><?= $language::get('enable_proxy'); ?></button>
                                        <?php endif; ?>
                                        <?php if (!$rIsMain): ?>
                                            <div class="dropdown-divider"></div>
                                            <?php if ($rServer['enabled']): ?>
                                                <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="disable"><i class="icon-base ti tabler-plug-off me-2"></i><?= $language::get('disable_server'); ?></button>
                                            <?php else: ?>
                                                <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="enable"><i class="icon-base ti tabler-plug-connected me-2"></i><?= $language::get('enable_server'); ?></button>
                                            <?php endif; ?>
                                            <button type="button" class="dropdown-item text-danger js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="delete"><i class="icon-base ti tabler-trash me-2"></i><?= $language::get('delete_server'); ?></button>
                                        <?php endif; ?>
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

<?php if ($rCanEdit): ?>
    <div class="modal fade" id="serverToolsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $language::get('server_tools'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><button type="button" class="btn btn-label-primary w-100 js-tool" data-tool="reinstall">Reinstall Server</button></div>
                        <div class="col-md-4"><button type="button" class="btn btn-label-secondary w-100 js-tool" data-tool="restart_services">Restart Services</button></div>
                        <div class="col-md-4"><button type="button" class="btn btn-label-secondary w-100 js-tool" data-tool="reboot_server">Reboot Server</button></div>
                        <div class="col-md-6"><button type="button" class="btn btn-label-secondary w-100 js-tool" data-tool="update_binaries">Update Binaries</button></div>
                        <div class="col-md-6"><button type="button" class="btn btn-label-secondary w-100 js-tool" data-tool="update">Update Server</button></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>;

        var toast = window.xcToast || function() {};

        function confirmSwal(text) {
            if (window.Swal) {
                return Swal.fire({
                    text: text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'OK',
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-label-secondary ms-2'
                    },
                    buttonsStyling: false
                }).then(function(r) {
                    return r.isConfirmed;
                });
            }
            return Promise.resolve(window.confirm(text));
        }

        function getJSON(url) {
            return fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(r) {
                return r.json();
            });
        }

        var table = $('#servers-table').DataTable({
            order: [
                [canEdit ? 1 : 0, 'asc']
            ],
            columnDefs: [{
                orderable: false,
                targets: canEdit ? [0, 12] : [11]
            }],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            },
            drawCallback: function() {
                if (window.bootstrap) {
                    document.querySelectorAll('#servers-table [data-bs-toggle="tooltip"]').forEach(function(el) {
                        if (!el._tt) {
                            el._tt = new bootstrap.Tooltip(el);
                        }
                    });
                }
            }
        });

        // --- single-server actions ------------------------------------------------
        var confirmMsgs = {
            'delete': 'Delete this server and its accompanying streams?',
            'kill': 'Kill all connections to this server?',
            'restart': 'Restart all running streams on this server?',
            'start': 'Start ALL streams on this server?',
            'stop': 'Stop all streams on this server?',
            'disable': 'Disable this server?',
            'enable': null,
            'disable_proxy': 'Disable all proxies on this server? Traffic will route through the original IP.',
            'enable_proxy': 'Enable all proxies on this server? Traffic will route through the proxy servers.',
            'update': 'Update this server? It will go offline until the update completes.'
        };

        function runApi(id, sub) {
            getJSON('./api?action=server&sub=' + encodeURIComponent(sub) + '&server_id=' + encodeURIComponent(id)).then(function(d) {
                if (!d || d.result !== true) {
                    toast(errText, 'error');
                    return;
                }
                if (sub === 'delete') {
                    table.row($('#server-' + id)).remove().draw(false);
                    toast('Server deleted.');
                } else if (sub === 'enable' || sub === 'disable' || sub === 'enable_proxy' || sub === 'disable_proxy') {
                    setTimeout(function() {
                        location.reload();
                    }, 600);
                    toast('Done.');
                } else {
                    toast('Done.');
                }
            }).catch(function() {
                toast(errText, 'error');
            });
        }

        $('#servers-table tbody').on('click', '.js-api', function() {
            var id = this.getAttribute('data-id'),
                sub = this.getAttribute('data-sub');
            var msg = confirmMsgs.hasOwnProperty(sub) ? confirmMsgs[sub] : null;
            if (!msg) {
                runApi(id, sub);
                return;
            }
            confirmSwal(msg).then(function(ok) {
                if (ok) {
                    runApi(id, sub);
                }
            });
        });

        // --- rollback -------------------------------------------------------------
        $('#servers-table tbody').on('click', '.js-rollback', function() {
            var id = this.getAttribute('data-id'),
                version = this.getAttribute('data-version') || '';
            getJSON('./api?action=rollback_versions&version=' + encodeURIComponent(version)).then(function(d) {
                if (!d || !d.result || !d.versions || !d.versions.length) {
                    toast('No earlier versions available for this server.', 'info');
                    return;
                }
                var opts = {};
                d.versions.forEach(function(v) {
                    opts[v.version] = 'v' + v.version + (v.beta ? ' (beta)' : '');
                });
                if (!window.Swal) {
                    return;
                }
                Swal.fire({
                    title: 'Rollback server',
                    html: 'Downgrading does <b>not</b> undo database migrations — use only as a recovery step. The server restarts during rollback.',
                    input: 'select',
                    inputOptions: opts,
                    showCancelButton: true,
                    confirmButtonText: 'Rollback',
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-label-secondary ms-2'
                    },
                    buttonsStyling: false
                }).then(function(r) {
                    if (!r.isConfirmed || !r.value) {
                        return;
                    }
                    getJSON('./api?action=server&sub=rollback&server_id=' + encodeURIComponent(id) + '&version=' + encodeURIComponent(r.value)).then(function(res) {
                        toast(res && res.result === true ? 'Rollback to v' + r.value + ' started…' : 'Rollback request failed.', res && res.result === true ? 'success' : 'error');
                    });
                });
            });
        });

        // --- server tools modal ---------------------------------------------------
        if (canEdit) {
            var toolsModal = window.bootstrap ? new bootstrap.Modal(document.getElementById('serverToolsModal')) : null;
            var toolsId = null;
            $('#servers-table tbody').on('click', '.js-tools', function() {
                toolsId = this.getAttribute('data-id');
                if (toolsModal) {
                    toolsModal.show();
                }
            });
            $('#serverToolsModal').on('click', '.js-tool', function() {
                var tool = this.getAttribute('data-tool');
                if (toolsModal) {
                    toolsModal.hide();
                }
                if (!toolsId) {
                    return;
                }
                var url;
                if (tool === 'reinstall') {
                    window.location.href = './server_install?id=' + toolsId;
                    return;
                }
                if (tool === 'restart_services') {
                    url = './api?action=restart_services&server_id=' + toolsId;
                } else if (tool === 'reboot_server') {
                    url = './api?action=reboot_server&server_id=' + toolsId;
                } else if (tool === 'update_binaries') {
                    url = './api?action=update_binaries&server_id=' + toolsId;
                } else {
                    url = './api?action=server&sub=update&server_id=' + toolsId;
                }
                getJSON(url).then(function(d) {
                    toast(d && d.result === true ? 'Task started in the background…' : errText, d && d.result === true ? 'success' : 'error');
                });
            });

            // --- global bulk operations ------------------------------------------
            $('#op-update-all').on('click', function() {
                confirmSwal('Update ALL running servers?').then(function(ok) {
                    if (ok) {
                        getJSON('./api?action=update_all_servers').then(function() {
                            toast('Servers are being updated in the background…');
                        });
                    }
                });
            });
            $('#op-restart-services').on('click', function() {
                confirmSwal('Restart services on ALL running servers?').then(function(ok) {
                    if (ok) {
                        getJSON('./api?action=restart_all_services').then(function() {
                            toast('Services will be restarted shortly…');
                        });
                    }
                });
            });
            $('#op-update-binaries').on('click', function() {
                confirmSwal('Update binaries on ALL running servers?').then(function(ok) {
                    if (ok) {
                        getJSON('./api?action=update_all_binaries').then(function() {
                            toast('Binaries are being updated in the background…');
                        });
                    }
                });
            });

            // --- multi-select bulk actions ---------------------------------------
            function selectedIds() {
                return $('.row-check:checked').map(function() {
                    return parseInt(this.getAttribute('data-id'), 10);
                }).get();
            }

            function syncBar() {
                var ids = selectedIds();
                $('#bulk-count').text(ids.length + ' selected');
                $('#bulk-bar').toggleClass('d-none', ids.length === 0).toggleClass('d-flex', ids.length > 0);
            }
            $('#check-all').on('change', function() {
                $('.row-check').prop('checked', this.checked);
                syncBar();
            });
            $('#servers-table tbody').on('change', '.row-check', syncBar);
            $('#bulk-clear').on('click', function() {
                $('.row-check, #check-all').prop('checked', false);
                syncBar();
            });

            var bulkConfirm = {
                'delete': 'Delete the selected servers?',
                'purge': 'Kill all connections on the selected servers?',
                'start': 'Start all streams on the selected servers?',
                'stop': 'Stop all streams on the selected servers?',
                'restart': 'Restart all streams on the selected servers?',
                'enable': 'Enable the selected servers?',
                'disable': 'Disable the selected servers?',
                'enable_proxy': 'Enable proxy on the selected servers?',
                'disable_proxy': 'Disable proxy on the selected servers?'
            };
            $('#bulk-bar').on('click', '[data-bulk]', function() {
                var sub = this.getAttribute('data-bulk'),
                    ids = selectedIds();
                if (!ids.length) {
                    return;
                }
                confirmSwal((bulkConfirm[sub] || sub) + ' (' + ids.length + ')').then(function(ok) {
                    if (!ok) {
                        return;
                    }
                    getJSON('./api?action=multi&type=server&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids))).then(function(d) {
                        if (!d || d.result !== true) {
                            toast(errText, 'error');
                            return;
                        }
                        toast('Done.');
                        setTimeout(function() {
                            location.reload();
                        }, 600);
                    }).catch(function() {
                        toast(errText, 'error');
                    });
                });
            });
        }
    })();
</script>
</body>

</html>