<?php

/**
 * Edit Proxy (Bootstrap 5). Proxy load-balancer server: name/IP/max clients, the
 * domain/IP rotation list, broadcast ports, SSL and GeoIP load-balancing. Saves via
 * post.php?action=proxy. Reached full-page in the new-UI shell from the proxies table.
 */

use XcVm\Core\Config\SettingsManager;

?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('edit_proxy'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="proxy-form">
            <input type="hidden" name="edit" value="<?= (int) $rServerArr['id']; ?>">

            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details"><i class="icon-base ti tabler-id me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ips"><i class="icon-base ti tabler-world me-1"></i><?= $language::get('domains_and_ips'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced"><i class="icon-base ti tabler-adjustments-alt me-1"></i><?= $language::get('advanced'); ?></button></li>
            </ul>

            <div class="tab-content p-4 border border-top-0 rounded-bottom">
                <!-- Details -->
                <div class="tab-pane fade show active" id="tab-details">
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="server_name"><?= $language::get('server_name'); ?></label>
                        <div class="col-md-8"><input type="text" class="form-control" id="server_name" name="server_name" value="<?= htmlspecialchars((string) $rServerArr['server_name'], ENT_QUOTES); ?>" required></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="server_ip"><?= $language::get('server_ip'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('this_ip_will_be_used_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8"><input type="text" class="form-control" id="server_ip" name="server_ip" value="<?= htmlspecialchars((string) $rServerArr['server_ip'], ENT_QUOTES); ?>" required></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="total_clients"><?= $language::get('max_clients'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('maximum_number_of_simultaneous_connections_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8"><input type="text" class="form-control" id="total_clients" name="total_clients" value="<?= htmlspecialchars((string) $rServerArr['total_clients'], ENT_QUOTES); ?>" required></div>
                    </div>
                    <div class="row mb-1">
                        <label class="col-md-4 col-form-label" for="enabled"><?= $language::get('enabled'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('utilise_this_server_for_connections_and_streams'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= $rServerArr['enabled'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Domains & IPs -->
                <div class="tab-pane fade" id="tab-ips">
                    <div class="alert alert-info" role="alert"><?= $language::get('proxy_domains_help'); ?></div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="ip_field"><?= $language::get('domains_and_ips'); ?></label>
                        <div class="col-md-8">
                            <div class="d-flex align-items-start gap-2">
                                <input type="text" id="ip_field" class="form-control flex-grow-1" value="">
                                <button type="button" id="add_ip" class="btn btn-primary flex-shrink-0"><i class="icon-base ti tabler-plus"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="domain_name">&nbsp;</label>
                        <div class="col-md-8">
                            <select id="domain_name" name="domain_name[]" size="6" class="form-select" multiple="multiple">
                                <?php foreach (explode(',', (string) $rServerArr['domain_name']) as $rIP): ?>
                                    <?php if (strlen($rIP) > 0): ?>
                                        <option value="<?= htmlspecialchars($rIP, ENT_QUOTES); ?>"><?= htmlspecialchars($rIP, ENT_QUOTES); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-2 d-flex gap-1">
                                <button type="button" id="move_up" class="btn btn-sm btn-label-secondary"><i class="icon-base ti tabler-chevron-up"></i></button>
                                <button type="button" id="move_down" class="btn btn-sm btn-label-secondary"><i class="icon-base ti tabler-chevron-down"></i></button>
                                <button type="button" id="remove_ip" class="btn btn-sm btn-label-danger"><i class="icon-base ti tabler-x"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-1">
                        <label class="col-md-4 col-form-label" for="random_ip"><?= $language::get('serve_random_ip_domain'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="random_ip" name="random_ip" value="1" <?= $rServerArr['random_ip'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Advanced -->
                <div class="tab-pane fade" id="tab-advanced">
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="http_broadcast_port"><?= $language::get('http_port'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('modifying_this_will_not_change_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="http_broadcast_port" name="http_broadcast_port" value="<?= htmlspecialchars((string) $rServerArr['http_broadcast_port'], ENT_QUOTES); ?>" required></div>
                        <label class="col-md-4 col-form-label" for="https_broadcast_port"><?= $language::get('https_ports'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('modifying_this_will_not_change_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="https_broadcast_port" name="https_broadcast_port" value="<?= htmlspecialchars((string) $rServerArr['https_broadcast_port'], ENT_QUOTES); ?>" required></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="network_guaranteed_speed"><?= $language::get('network_speed'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('port_speed_to_consider_when_connecting_clients'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="network_guaranteed_speed" name="network_guaranteed_speed" value="<?= htmlspecialchars((string) $rServerArr['network_guaranteed_speed'], ENT_QUOTES); ?>" required></div>
                        <label class="col-md-4 col-form-label" for="enable_https"><?= $language::get('enable_ssl'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('allow_https_connections_you_will_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_https" name="enable_https" value="1" <?= $rServerArr['enable_https'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="enable_geoip"><?= $language::get('geoip_load_balancing'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('route_connections_to_the_nearest_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_geoip" name="enable_geoip" value="1" <?= $rServerArr['enable_geoip'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <select name="geoip_type" id="geoip_type" class="form-select">
                                <?php foreach (['high_priority' => $language::get('high_priority'), 'low_priority' => $language::get('low_priority'), 'strict' => $language::get('strict')] as $rType => $rText): ?>
                                    <option value="<?= $rType; ?>" <?= $rServerArr['geoip_type'] == $rType ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rText, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-1">
                        <label class="col-md-4 col-form-label" for="geoip_countries"><?= $language::get('geoip_countries'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('select_which_countries_should_be_prioritised_to_this_server'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8">
                            <select name="geoip_countries[]" id="geoip_countries" class="form-select" multiple="multiple" data-placeholder="<?= htmlspecialchars((string) $language::get('choose_placeholder'), ENT_QUOTES); ?>">
                                <?php $selectedCountries = json_decode($rServerArr['geoip_countries'] ?? '[]', true) ?: []; ?>
                                <?php foreach ($rCountries as $country): ?>
                                    <option value="<?= htmlspecialchars((string) $country['id'], ENT_QUOTES); ?>" <?= in_array($country['id'], $selectedCountries) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $country['name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-end mt-4"><button type="submit" class="btn btn-primary" name="submit_server" value="1"><?= $language::get('save'); ?></button></div>
        </form>
    </div>
</div>

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

        if ($.fn.select2) {
            $('#geoip_type, #geoip_countries').select2({
                width: '100%'
            });
        }
        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
        }

        // Numeric-only input filters.
        var numericOnly = function(el, max) {
            if (!el) {
                return;
            }
            el.addEventListener('input', function() {
                var v = this.value.replace(/[^\d]/g, '');
                if (max && v !== '' && parseInt(v, 10) > max) {
                    v = this.value.slice(0, -1).replace(/[^\d]/g, '');
                }
                this.value = v;
            });
        };
        numericOnly(document.getElementById('total_clients'));
        numericOnly(document.getElementById('http_broadcast_port'), 65535);
        numericOnly(document.getElementById('https_broadcast_port'), 65535);
        numericOnly(document.getElementById('network_guaranteed_speed'));

        var isValidIP = function(v) {
            return /^(\d{1,3}\.){3}\d{1,3}$/.test(v);
        };
        var isValidDomain = function(v) {
            return /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i.test(v);
        };

        var domainSelect = document.getElementById('domain_name');
        document.getElementById('add_ip').addEventListener('click', function() {
            var field = document.getElementById('ip_field');
            var val = field.value.trim();
            if (val && (isValidIP(val) || isValidDomain(val))) {
                domainSelect.add(new Option(val, val));
                field.value = '';
            } else {
                toast(<?= json_encode($language::get('please_enter_a_valid_ip_or_domain')); ?>, 'error');
            }
        });
        document.getElementById('remove_ip').addEventListener('click', function() {
            for (var i = domainSelect.options.length - 1; i >= 0; i--) {
                if (domainSelect.options[i].selected) {
                    domainSelect.remove(i);
                }
            }
        });
        document.getElementById('move_up').addEventListener('click', function() {
            var opts = domainSelect.options;
            for (var i = 1; i < opts.length; i++) {
                if (opts[i].selected && !opts[i - 1].selected) {
                    domainSelect.insertBefore(opts[i], opts[i - 1]);
                }
            }
        });
        document.getElementById('move_down').addEventListener('click', function() {
            var opts = domainSelect.options;
            for (var i = opts.length - 2; i >= 0; i--) {
                if (opts[i].selected && !opts[i + 1].selected) {
                    domainSelect.insertBefore(opts[i + 1], opts[i]);
                }
            }
        });

        document.getElementById('proxy-form').addEventListener('submit', function(e) {
            e.preventDefault();
            // Select every entry so the whole domain_name[] list is posted, order preserved.
            for (var i = 0; i < domainSelect.options.length; i++) {
                domainSelect.options[i].selected = true;
            }
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fetch('post.php?action=proxy&referer=', {
                    method: 'POST',
                    body: fd,
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
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
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