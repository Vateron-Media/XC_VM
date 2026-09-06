<?php

/**
 * Proxy servers management table (Bootstrap 5). Client-rendered: the server_type==1
 * rows of ServerRepository::getAll(true) are echoed as <tbody> and a plain client-side
 * DataTable adds search / sort / paging. Live watchdog cpu / mem are compact progress
 * bars; per-proxy actions (kill / enable / disable / delete, plus the Proxy Tools modal)
 * run through ./api?action=proxy&sub=… . Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;

$rCanEdit = Authorization::check('adv', 'edit_server');
$rCanAdd = Authorization::check('adv', 'add_server');
$rCanConns = Authorization::check('adv', 'live_connections');

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
        <h5 class="card-title mb-0"><?= $language::get('proxy_servers'); ?></h5>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($rCanEdit): ?>
                <div id="bulk-bar" class="d-none align-items-center gap-2">
                    <span class="text-body-secondary small" id="bulk-count">0</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-label-dark" data-bulk="purge"><?= $language::get('kill'); ?></button>
                        <button type="button" class="btn btn-label-success" data-bulk="enable"><?= $language::get('enable'); ?></button>
                        <button type="button" class="btn btn-label-secondary" data-bulk="disable"><?= $language::get('disable'); ?></button>
                        <button type="button" class="btn btn-label-danger" data-bulk="delete"><?= $language::get('delete'); ?></button>
                    </div>
                    <button type="button" class="btn btn-sm btn-icon btn-label-secondary" id="bulk-clear" title="Clear selection"><i class="icon-base ti tabler-x"></i></button>
                </div>
            <?php endif; ?>
            <?php if ($rCanAdd): ?>
                <a href="server_install?proxy=1" class="btn btn-sm btn-primary"><i class="icon-base ti tabler-plus me-1"></i>Add Proxy</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="proxies-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <?php if ($rCanEdit): ?><th class="text-center" style="width:1%"><input type="checkbox" class="form-check-input" id="check-all"></th><?php endif; ?>
                    <th class="text-center"><?= $language::get('id'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th><?= $language::get('proxy_name'); ?></th>
                    <th><?= $language::get('proxied_server'); ?></th>
                    <th class="text-center"><?= $language::get('proxy_ip'); ?></th>
                    <th class="text-center"><?= $language::get('network'); ?></th>
                    <th class="text-center"><?= $language::get('connections'); ?></th>
                    <th class="text-center"><?= $language::get('cpu_header'); ?></th>
                    <th class="text-center"><?= $language::get('mem'); ?></th>
                    <th class="text-center"><?= $language::get('ping'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rServers as $rServer): ?>
                    <?php if ($rServer['server_type'] != 1) {
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
                    $rParents = (array) $rServer['parent_id'];
                    $rParent0 = $rParents[0] ?? null;
                    $rClients = (int) ConnectionTracker::getLiveConnections($rServer['id'], true);
                    ?>
                    <tr id="server-<?= (int) $rServer['id']; ?>">
                        <?php if ($rCanEdit): ?>
                            <td class="text-center"><input type="checkbox" class="form-check-input row-check" data-id="<?= (int) $rServer['id']; ?>"></td>
                        <?php endif; ?>
                        <td class="text-center fw-medium"><?= (int) $rServer['id']; ?></td>
                        <td class="text-center">
                            <?php if (!$rServer['enabled']): ?>
                                <i class="icon-base ti tabler-circle-filled text-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disabled'); ?>"></i>
                            <?php elseif ($rServer['server_online']): ?>
                                <i class="icon-base ti tabler-circle-filled text-success" data-bs-toggle="tooltip" title="<?= $language::get('online'); ?>"></i>
                            <?php else: ?>
                                <?php
                                $rPing = $rServer['last_check_ago'] > 0 ? date($rSettings['datetime_format'], $rServer['last_check_ago']) : 'Never';
                                if ($rServer['status'] == 3) {
                                    $rSt = ['info', $language::get('installing')];
                                } elseif ($rServer['status'] == 4) {
                                    $rSt = ['warning', $language::get('installation_failed')];
                                } elseif ($rServer['status'] == 5) {
                                    $rSt = ['info', $language::get('updating')];
                                } else {
                                    $rSt = ['danger', 'Last Ping: ' . $rPing];
                                }
                                ?>
                                <i class="icon-base ti tabler-circle-filled text-<?= $rSt[0]; ?>" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $rSt[1], ENT_QUOTES); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="server_view?id=<?= (int) $rServer['id']; ?>" class="fw-medium"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></a>
                            <?php if (!empty($rServer['domain_name'])): ?>
                                <br><small class="text-body-secondary"><?= htmlspecialchars(explode(',', (string) $rServer['domain_name'])[0], ENT_QUOTES); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rParent0 !== null): ?>
                                <a href="server_view?id=<?= (int) $rParent0; ?>"><?= htmlspecialchars((string) ($rServers[$rParent0]['server_name'] ?? $rParent0), ENT_QUOTES); ?></a>
                                <?php if (count($rParents) > 1): ?>
                                    <?php $rNames = array_map(static fn($p) => (string) ($rServers[$p]['server_name'] ?? $p), $rParents); ?>
                                    <span class="badge bg-label-info ms-1" data-bs-toggle="tooltip" title="<?= htmlspecialchars(implode(', ', $rNames), ENT_QUOTES); ?>">+<?= count($rParents) - 1; ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= htmlspecialchars((string) $rServer['server_ip'], ENT_QUOTES); ?></td>
                        <td class="text-center" style="min-width:120px">
                            <span class="text-success"><i class="icon-base ti tabler-arrow-up"></i> <?= number_format($rWatchDog['bytes_sent'] / 125000, 0); ?></span>
                            <span class="text-primary ms-2"><i class="icon-base ti tabler-arrow-down"></i> <?= number_format($rWatchDog['bytes_received'] / 125000, 0); ?></span>
                            <br><small class="text-body-secondary"><?= number_format((int) $rServer['network_guaranteed_speed']); ?> Mbps</small>
                        </td>
                        <td class="text-center" data-order="<?= $rClients; ?>">
                            <?php if ($rCanConns): ?>
                                <a href="./live_connections?server=<?= (int) $rServer['id']; ?>" class="btn btn-xs btn-label-secondary"><?= number_format($rClients); ?></a>
                            <?php else: ?>
                                <span class="badge bg-label-secondary"><?= number_format($rClients); ?></span>
                            <?php endif; ?>
                            <br><small class="text-body-secondary">of <?= number_format((int) $rServer['total_clients']); ?></small>
                        </td>
                        <td class="text-center" data-order="<?= (int) $rWatchDog['cpu']; ?>"><?= $rBar((int) $rWatchDog['cpu']); ?></td>
                        <td class="text-center" data-order="<?= (int) $rWatchDog['total_mem_used_percent']; ?>"><?= $rBar((int) $rWatchDog['total_mem_used_percent']); ?></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= number_format(($rServer['server_online'] ? $rServer['ping'] : 0), 0); ?> ms</span></td>
                        <td class="text-center">
                            <?php if ($rCanEdit): ?>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <button type="button" class="dropdown-item js-tools" data-id="<?= (int) $rServer['id']; ?>"><i class="icon-base ti tabler-tool me-2"></i><?= $language::get('proxy_tools'); ?></button>
                                        <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="kill"><i class="icon-base ti tabler-hammer me-2"></i><?= $language::get('kill_all_connections'); ?></button>
                                        <a class="dropdown-item" href="./proxy?id=<?= (int) $rServer['id']; ?>"><i class="icon-base ti tabler-pencil me-2"></i><?= $language::get('edit_proxy'); ?></a>
                                        <div class="dropdown-divider"></div>
                                        <?php if ($rServer['enabled']): ?>
                                            <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="disable"><i class="icon-base ti tabler-plug-off me-2"></i><?= $language::get('disable_proxy'); ?></button>
                                        <?php else: ?>
                                            <button type="button" class="dropdown-item js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="enable"><i class="icon-base ti tabler-plug-connected me-2"></i><?= $language::get('enable_proxy'); ?></button>
                                        <?php endif; ?>
                                        <button type="button" class="dropdown-item text-danger js-api" data-id="<?= (int) $rServer['id']; ?>" data-sub="delete"><i class="icon-base ti tabler-trash me-2"></i><?= $language::get('delete_proxy'); ?></button>
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
    <div class="modal fade" id="proxyToolsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $language::get('proxy_tools'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4"><button type="button" class="btn btn-label-primary w-100 js-tool" data-tool="reinstall">Reinstall Proxy</button></div>
                        <div class="col-md-4"><button type="button" class="btn btn-label-secondary w-100 js-tool" data-tool="restart_services">Restart Services</button></div>
                        <div class="col-md-4"><button type="button" class="btn btn-label-secondary w-100 js-tool" data-tool="reboot_server">Reboot Server</button></div>
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
            if (window.xcConfirm) {
                return window.xcConfirm(text);
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

        var table = $('#proxies-table').DataTable({
            order: [
                [canEdit ? 1 : 0, 'asc']
            ],
            columnDefs: [{
                orderable: false,
                targets: canEdit ? [0, 11] : [10]
            }],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            },
            drawCallback: function() {
                if (window.bootstrap) {
                    document.querySelectorAll('#proxies-table [data-bs-toggle="tooltip"]').forEach(function(el) {
                        if (!el._tt) {
                            el._tt = new bootstrap.Tooltip(el);
                        }
                    });
                }
            }
        });

        // --- single-proxy actions -------------------------------------------------
        var confirmMsgs = {
            'delete': 'Delete this proxy server?',
            'kill': 'Kill all connections to this proxy?',
            'disable': 'Disable this proxy?',
            'enable': null
        };

        function runApi(id, sub) {
            getJSON('./api?action=proxy&sub=' + encodeURIComponent(sub) + '&server_id=' + encodeURIComponent(id)).then(function(d) {
                if (!d || d.result !== true) {
                    toast(errText, 'error');
                    return;
                }
                if (sub === 'delete') {
                    table.row($('#server-' + id)).remove().draw(false);
                    toast('Proxy deleted.');
                } else if (sub === 'enable' || sub === 'disable') {
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

        $('#proxies-table tbody').on('click', '.js-api', function() {
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

        // --- proxy tools modal ----------------------------------------------------
        if (canEdit) {
            var toolsModal = window.bootstrap ? new bootstrap.Modal(document.getElementById('proxyToolsModal')) : null;
            var toolsId = null;
            $('#proxies-table tbody').on('click', '.js-tools', function() {
                toolsId = this.getAttribute('data-id');
                if (toolsModal) {
                    toolsModal.show();
                }
            });
            $('#proxyToolsModal').on('click', '.js-tool', function() {
                var tool = this.getAttribute('data-tool');
                if (toolsModal) {
                    toolsModal.hide();
                }
                if (!toolsId) {
                    return;
                }
                if (tool === 'reinstall') {
                    window.location.href = './server_install?id=' + toolsId + '&proxy=1';
                    return;
                }
                var url = tool === 'restart_services' ? './api?action=restart_services&server_id=' + toolsId : './api?action=reboot_server&server_id=' + toolsId;
                getJSON(url).then(function(d) {
                    toast(d && d.result === true ? 'Task started…' : errText, d && d.result === true ? 'success' : 'error');
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
            $('#proxies-table tbody').on('change', '.row-check', syncBar);
            $('#bulk-clear').on('click', function() {
                $('.row-check, #check-all').prop('checked', false);
                syncBar();
            });

            var bulkConfirm = {
                'delete': 'Delete the selected proxies?',
                'purge': 'Kill all connections on the selected proxies?',
                'enable': 'Enable the selected proxies?',
                'disable': 'Disable the selected proxies?'
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
                    getJSON('./api?action=multi&type=proxy&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids))).then(function(d) {
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