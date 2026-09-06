<?php

/**
 * Install / Reinstall / Update Server or Proxy (Bootstrap 5). A wizard reached full-page
 * from the servers/proxies tables: Details tab (SSH + ports) plus, for a proxy (type 1), a
 * Server Coverage tab picking which main servers it fronts. Saves via
 * post.php?action=server_install.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;

$rIsProxy = ($rType == 1);
$rIsEdit = isset($rServerArr);
$rIsUpdate = $rIsEdit && RequestManager::has('update');

if ($rIsProxy) {
    $rTitle = $rIsEdit ? ($rIsUpdate ? $language::get('update_proxy') : $language::get('reinstall_proxy')) : $language::get('proxy_installation');
} else {
    $rTitle = $rIsEdit ? ($rIsUpdate ? $language::get('update_server') : $language::get('reinstall_server')) : $language::get('server_installation');
}
$rCoverage = $rIsEdit ? (ServerRepository::getAll()[$rServerArr['id']]['parent_id'] ?? []) : [];
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= htmlspecialchars((string) $rTitle, ENT_QUOTES); ?></h4>
</div>

<?php if ($rIsEdit && $rServerArr['is_main'] == 1): ?>
    <div class="alert alert-danger" role="alert"><?= $language::get('server_install_main_error'); ?></div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <form id="install-form">
                <?php if ($rIsEdit): ?><input type="hidden" name="edit" value="<?= (int) $rServerArr['id']; ?>"><?php endif; ?>
                <input type="hidden" id="parent_id" name="parent_id" value="">
                <input type="hidden" name="type" value="<?= (int) $rType; ?>">

                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details"><i class="icon-base ti tabler-settings me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if ($rIsProxy): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-coverage"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('server_coverage'); ?></button></li>
                    <?php endif; ?>
                </ul>

                <div class="tab-content p-4 border border-top-0 rounded-bottom">
                    <!-- Details -->
                    <div class="tab-pane fade show active" id="tab-details">
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="server_name"><?= $language::get('server_name'); ?></label>
                            <div class="col-md-9"><input type="text" class="form-control" id="server_name" name="server_name" <?= $rIsEdit ? 'readonly' : ''; ?> value="<?= $rIsEdit ? htmlspecialchars((string) $rServerArr['server_name'], ENT_QUOTES) : ''; ?>" required></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="server_ip"><?= $language::get('server_ip'); ?></label>
                            <div class="col-md-3"><input type="text" class="form-control" id="server_ip" name="server_ip" <?= $rIsEdit ? 'readonly' : ''; ?> value="<?= $rIsEdit ? htmlspecialchars((string) $rServerArr['server_ip'], ENT_QUOTES) : ''; ?>" required></div>
                            <label class="col-md-3 col-form-label" for="ssh_port"><?= $language::get('ssh_port'); ?></label>
                            <div class="col-md-3"><input type="text" class="form-control text-center" id="ssh_port" name="ssh_port" value="22" required></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="root_username"><?= $language::get('ssh_username'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('this_needs_to_be_either_tooltip'), ENT_QUOTES); ?>"></i></label>
                            <div class="col-md-3"><input type="text" class="form-control" id="root_username" name="root_username" value="root" required></div>
                            <label class="col-md-3 col-form-label" for="root_password"><?= $language::get('ssh_password'); ?></label>
                            <div class="col-md-3"><input type="text" class="form-control" id="root_password" name="root_password" value="" required></div>
                        </div>
                        <?php if ($rIsProxy): ?>
                            <div class="row mb-3">
                                <label class="col-md-3 col-form-label" for="http_broadcast_port"><?= $language::get('http_port'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('install_port_tooltip'), ENT_QUOTES); ?>"></i></label>
                                <div class="col-md-3"><input type="text" class="form-control text-center" id="http_broadcast_port" name="http_broadcast_port" value="80" required></div>
                                <label class="col-md-3 col-form-label" for="https_broadcast_port"><?= $language::get('https_port'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('install_port_tooltip'), ENT_QUOTES); ?>"></i></label>
                                <div class="col-md-3"><input type="text" class="form-control text-center" id="https_broadcast_port" name="https_broadcast_port" value="443" required></div>
                            </div>
                        <?php endif; ?>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="update_sysctl"><?= $language::get('update_sysctl_conf'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('update_sysctl_tooltip'), ENT_QUOTES); ?>"></i></label>
                            <div class="col-md-9">
                                <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="update_sysctl" name="update_sysctl" value="1" checked></div>
                            </div>
                        </div>
                        <?php if ($rIsProxy): ?>
                            <div class="row mb-3">
                                <label class="col-md-3 col-form-label" for="use_private_ip"><?= $language::get('use_private_ip'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('use_private_ip_tooltip'), ENT_QUOTES); ?>"></i></label>
                                <div class="col-md-9">
                                    <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="use_private_ip" name="use_private_ip" value="1"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($rIsUpdate): ?>
                            <div class="alert alert-info" role="alert"><?= $language::get('server_install_update_notice', [':from' => htmlspecialchars((string) $rServerArr['xc_vm_version'], ENT_QUOTES), ':to' => htmlspecialchars((string) ($allServers[SERVER_ID]['xc_vm_version'] ?? ''), ENT_QUOTES)]); ?></div>
                        <?php else: ?>
                            <div class="alert alert-warning" role="alert">
                                <?= $rIsProxy ? $language::get('server_install_proxy_notice') : $language::get('server_install_server_notice'); ?>
                                <br><br><?= $rIsEdit ? $language::get('server_install_reinstall_notice') : $language::get('server_install_new_notice'); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$rIsProxy): ?>
                            <div class="text-end"><button type="submit" name="submit_server" class="btn btn-primary"><?= $language::get('install_server'); ?></button></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($rIsProxy): ?>
                        <!-- Server Coverage -->
                        <div class="tab-pane fade" id="tab-coverage">
                            <div class="table-responsive mb-3">
                                <table id="coverage-table" class="table" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th class="text-center"><?= $language::get('id'); ?></th>
                                            <th><?= $language::get('server_name'); ?></th>
                                            <th class="text-center"><?= $language::get('server_ip'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (ServerRepository::getAll() as $rServer): ?>
                                            <?php if ($rServer['server_type'] != 0) {
                                                continue;
                                            } ?>
                                            <tr class="<?= in_array($rServer['id'], (array) $rCoverage) ? 'selected table-active' : ''; ?>" style="cursor:pointer">
                                                <td class="text-center"><?= (int) $rServer['id']; ?></td>
                                                <td><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></td>
                                                <td class="text-center"><?= htmlspecialchars((string) $rServer['server_ip'], ENT_QUOTES); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end"><button type="submit" name="submit_server" class="btn btn-primary"><?= $language::get('install_server'); ?></button></div>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
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
        var toast = window.xcToast || function() {};
        var form = document.getElementById('install-form');
        if (!form) {
            return;
        }

        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
        }

        var numericOnly = function(id, max) {
            var el = document.getElementById(id);
            if (!el) {
                return;
            }
            el.addEventListener('input', function() {
                var v = this.value.replace(/[^\d]/g, '');
                if (max && v !== '' && parseInt(v, 10) > max) {
                    v = String(max);
                }
                this.value = v;
            });
        };
        numericOnly('ssh_port', 65535);
        numericOnly('http_broadcast_port', 65535);
        numericOnly('https_broadcast_port', 65535);

        var isProxy = <?= $rIsProxy ? 'true' : 'false'; ?>;
        if (isProxy) {
            // Click a coverage row to toggle whether this proxy fronts that server.
            $('#coverage-table tbody').on('click', 'tr', function() {
                $(this).toggleClass('selected table-active');
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (isProxy) {
                var rServers = [];
                $('#coverage-table tbody tr.selected').each(function() {
                    rServers.push($(this).find('td:eq(0)').text());
                });
                if (rServers.length === 0) {
                    toast(<?= json_encode($language::get('select_at_least_one_server')) ?>, 'error');
                    return;
                }
                document.getElementById('parent_id').value = '[' + rServers.join(',') + ']';
            }
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            fetch('post.php?action=server_install&referer=', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var d;
                    try {
                        d = JSON.parse(txt);
                    } catch (err) {
                        d = {
                            result: false
                        };
                    }
                    if (d && d.location) {
                        window.location = d.location;
                        return;
                    }
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')) ?>, 'error');
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')) ?>, 'error');
                });
        });

        <?php if (SettingsManager::get('enable_search')): ?>
            if (typeof initSearch === 'function') {
                initSearch();
            }
        <?php endif; ?>
    })();
</script>
</body>

</html>