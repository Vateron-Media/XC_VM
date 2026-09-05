<?php

/**
 * Edit Server (Bootstrap 5). Full server (MAIN or LB node): details/IP/max clients,
 * the domain/IP rotation list, broadcast ports, advanced networking + GeoIP,
 * performance (PHP services, rate limits, CPU governor, sysctl) and SSL/certbot.
 * Saves via post.php?action=server. Reached full-page in the new-UI shell from the
 * servers table.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Reference\GeoReference;

?>

<style>
    /* Center the selected value of the PHP-services select2 (single digit reads better centered). */
    #total_services + .select2-container .select2-selection__rendered {
        text-align: center;
        padding-right: 20px;
    }
</style>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('permission_edit_server'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="server-form">
            <input type="hidden" name="edit" value="<?= (int) $rServerArr['id']; ?>">
            <input type="hidden" id="regenerate_ssl" name="regenerate_ssl" value="0">

            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details"><i class="icon-base ti tabler-id me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ips"><i class="icon-base ti tabler-world me-1"></i><?= !$rServerArr['is_main'] ? $language::get('domains_and_ips_span') : $language::get('domains'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced"><i class="icon-base ti tabler-adjustments-alt me-1"></i><?= $language::get('advanced'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-performance"><i class="icon-base ti tabler-bolt me-1"></i><?= $language::get('performance'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ssl"><i class="icon-base ti tabler-certificate me-1"></i><?= $language::get('ssl_certificate'); ?></button></li>
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
                        <label class="col-md-4 col-form-label" for="private_ip"><?= $language::get('private_ip'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('enter_a_private_ip_to_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8"><input type="text" class="form-control" id="private_ip" name="private_ip" value="<?= htmlspecialchars((string) ($rServerArr['private_ip'] ?? ''), ENT_QUOTES); ?>"></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="total_clients"><?= $language::get('max_clients'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('maximum_number_of_simultaneous_connections_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="total_clients" name="total_clients" value="<?= htmlspecialchars((string) $rServerArr['total_clients'], ENT_QUOTES); ?>" required></div>
                        <label class="col-md-4 col-form-label" for="timeshift_only"><?= $language::get('timeshift_only'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('dont_allow_connections_to_this_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="timeshift_only" name="timeshift_only" value="1" <?= $rServerArr['timeshift_only'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-1">
                        <label class="col-md-4 col-form-label" for="enabled"><?= $language::get('enabled'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('utilise_this_server_for_connections_and_streams'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" <?= $rServerArr['enabled'] == 1 ? 'checked' : ''; ?> <?= $rServerArr['is_main'] ? 'onclick="return false;"' : ''; ?>>
                            </div>
                        </div>
                        <label class="col-md-4 col-form-label" for="enable_proxy"><?= $language::get('proxied'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('route_connections_through_allocated_proxies'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_proxy" name="enable_proxy" value="1" <?= $rServerArr['enable_proxy'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Domains & IPs -->
                <div class="tab-pane fade" id="tab-ips">
                    <?php if (!$rServerArr['is_main']): ?>
                        <div class="alert alert-info" role="alert"><?= $language::get('server_domains_help'); ?></div>
                    <?php endif; ?>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="ip_field"><?= !$rServerArr['is_main'] ? $language::get('domains_and_ips') : $language::get('domain_names'); ?></label>
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
                    <?php if (!$rServerArr['is_main']): ?>
                        <div class="row mb-1">
                            <label class="col-md-4 col-form-label" for="random_ip"><?= $language::get('serve_random_ip_domain'); ?></label>
                            <div class="col-md-8">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="random_ip" name="random_ip" value="1" <?= $rServerArr['random_ip'] == 1 ? 'checked' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Advanced -->
                <div class="tab-pane fade" id="tab-advanced">
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="http_broadcast_ports"><?= $language::get('http_ports'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('enter_one_or_more_port_numbers_between_80_and_65535'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8">
                            <select name="http_broadcast_ports[]" id="http_broadcast_ports" class="form-select" multiple="multiple" data-placeholder="<?= htmlspecialchars((string) $language::get('choose_placeholder'), ENT_QUOTES); ?>">
                                <?php if (is_numeric($rServerArr['http_broadcast_port']) && $rServerArr['http_broadcast_port'] >= 80 && $rServerArr['http_broadcast_port'] <= 65535): ?>
                                    <option selected value="<?= (int) $rServerArr['http_broadcast_port']; ?>"><?= (int) $rServerArr['http_broadcast_port']; ?></option>
                                <?php endif; ?>
                                <?php foreach (explode(',', (string) $rServerArr['http_ports_add']) as $rPort): ?>
                                    <?php if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535): ?>
                                        <option selected value="<?= (int) $rPort; ?>"><?= (int) $rPort; ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="https_broadcast_ports"><?= $language::get('https_ports'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('enter_one_or_more_port_numbers_between_80_and_65535'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8">
                            <select name="https_broadcast_ports[]" id="https_broadcast_ports" class="form-select" multiple="multiple" data-placeholder="<?= htmlspecialchars((string) $language::get('choose_placeholder'), ENT_QUOTES); ?>">
                                <?php if (is_numeric($rServerArr['https_broadcast_port']) && $rServerArr['https_broadcast_port'] >= 80 && $rServerArr['https_broadcast_port'] <= 65535): ?>
                                    <option selected value="<?= (int) $rServerArr['https_broadcast_port']; ?>"><?= (int) $rServerArr['https_broadcast_port']; ?></option>
                                <?php endif; ?>
                                <?php foreach (explode(',', (string) $rServerArr['https_ports_add']) as $rPort): ?>
                                    <?php if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535): ?>
                                        <option selected value="<?= (int) $rPort; ?>"><?= (int) $rPort; ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="rtmp_port"><?= $language::get('rtmp_port'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('enter_the_port_to_run_the_rtmp_server_on'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="rtmp_port" name="rtmp_port" value="<?= htmlspecialchars((string) $rServerArr['rtmp_port'], ENT_QUOTES); ?>" required></div>
                        <label class="col-md-4 col-form-label" for="disable_ramdisk"><?= $language::get('disable_ramdisk'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('if_you_have_a_fast_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="disable_ramdisk" name="disable_ramdisk" value="1" <?= !$rMounted ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="network_interface"><?= $language::get('network_interface'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('which_network_interface_to_use_for_statistics'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <select name="network_interface" id="network_interface" class="form-select">
                                <?php foreach (array_merge(['auto'], $rInterfaces) as $rInterface): ?>
                                    <option value="<?= htmlspecialchars((string) $rInterface, ENT_QUOTES); ?>" <?= $rServerArr['network_interface'] == $rInterface ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rInterface, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="col-md-4 col-form-label" for="network_guaranteed_speed"><?= $language::get('network_speed'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('port_speed_to_consider_when_connecting_clients'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="network_guaranteed_speed" name="network_guaranteed_speed" value="<?= htmlspecialchars((string) $rServerArr['network_guaranteed_speed'], ENT_QUOTES); ?>" required></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="geoip_type"><?= $language::get('geoip_priority'); ?></label>
                        <div class="col-md-8">
                            <select name="geoip_type" id="geoip_type" class="form-select">
                                <?php foreach (['high_priority' => $language::get('high_priority'), 'low_priority' => $language::get('low_priority'), 'strict' => $language::get('strict')] as $rType => $rText): ?>
                                    <option value="<?= $rType; ?>" <?= $rServerArr['geoip_type'] == $rType ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rText, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="geoip_countries"><?= $language::get('geoip_countries'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('select_which_countries_should_be_prioritised_to_this_server'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-8">
                            <select name="geoip_countries[]" id="geoip_countries" class="form-select" multiple="multiple" data-placeholder="<?= htmlspecialchars((string) $language::get('choose_placeholder'), ENT_QUOTES); ?>">
                                <?php $rSelected = json_decode($rServerArr['geoip_countries'] ?? '[]', true) ?: []; ?>
                                <?php foreach (GeoReference::countries() as $rCountry): ?>
                                    <option value="<?= htmlspecialchars((string) $rCountry['id'], ENT_QUOTES); ?>" <?= in_array($rCountry['id'], $rSelected) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCountry['name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-1">
                        <label class="col-md-4 col-form-label" for="enable_geoip"><?= $language::get('geoip_load_balancing'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('route_connections_to_the_nearest_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_geoip" name="enable_geoip" value="1" <?= $rServerArr['enable_geoip'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance -->
                <div class="tab-pane fade" id="tab-performance">
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="total_services"><?= $language::get('php_services'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('how_many_phpfpm_daemons_to_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <select name="total_services" id="total_services" class="form-select">
                                <?php foreach (range(1, $rServiceMax) as $rInt): ?>
                                    <option value="<?= $rInt; ?>" <?= ($rServerArr['total_services'] == $rInt || $rInt == 4) ? 'selected' : ''; ?>><?= $rInt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($rServerArr['is_main']): ?>
                            <label class="col-md-4 col-form-label" for="enable_gzip"><?= $language::get('gzip_compression'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('compressing_server_output_on_your_tooltip'), ENT_QUOTES); ?>"></i></label>
                            <div class="col-md-2">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="enable_gzip" name="enable_gzip" value="1" <?= $rServerArr['enable_gzip'] == 1 ? 'checked' : ''; ?>>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="limit_requests"><?= $language::get('rate_limit_per_second'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('limit_requests_per_second_this_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="limit_requests" name="limit_requests" value="<?= htmlspecialchars((string) $rServerArr['limit_requests'], ENT_QUOTES); ?>" required></div>
                        <label class="col-md-4 col-form-label" for="limit_burst"><?= $language::get('rate_limit_burst_queue'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('when_the_request_limit_is_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2"><input type="text" class="form-control text-center" id="limit_burst" name="limit_burst" value="<?= htmlspecialchars((string) $rServerArr['limit_burst'], ENT_QUOTES); ?>" required></div>
                    </div>
                    <?php if (!empty($rServerArr['governors']) && count(json_decode($rServerArr['governors'], true) ?: []) > 0):
                        $rCurrentGovernor = json_decode($rServerArr['governor'], true);
                        if (!is_array($rCurrentGovernor)) {
                            $rCurrentGovernor = [];
                        }
                        $rGovernorMin = floatval($rCurrentGovernor[0] ?? 0);
                        $rGovernorMax = floatval($rCurrentGovernor[1] ?? 0);
                        $rCurrentGovernor[2] = ($rCurrentGovernor[2] ?? '');
                        $rCurrentGovernor[3] = '* ' . $rCurrentGovernor[2] . ' - Freq: ' . round($rGovernorMin / 1000000, 1) . 'GHz - ' . round($rGovernorMax / 1000000, 1) . 'GHz'; ?>
                        <div class="row mb-3">
                            <label class="col-md-4 col-form-label" for="governor"><?= $language::get('cpu_governor'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('change_default_cpu_governor_for_tooltip'), ENT_QUOTES); ?>"></i></label>
                            <div class="col-md-8">
                                <select name="governor" id="governor" class="form-select">
                                    <option selected value="<?= htmlspecialchars((string) $rCurrentGovernor[2], ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rCurrentGovernor[3], ENT_QUOTES); ?></option>
                                    <?php foreach (json_decode($rServerArr['governors'], true) as $rGovernor): ?>
                                        <?php if ($rGovernor != $rCurrentGovernor[2]): ?>
                                            <option value="<?= htmlspecialchars((string) $rGovernor, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rGovernor, ENT_QUOTES); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="row mb-1">
                        <label class="col-md-4 col-form-label" for="sysctl"><?= $language::get('custom_sysctl'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('write_a_custom_sysctlconf_to_tooltip'), ENT_QUOTES); ?>"></i>
                            <button type="button" id="sysctl_default" class="btn btn-label-secondary btn-sm mt-2"><?= $language::get('default'); ?></button>
                        </label>
                        <div class="col-md-8">
                            <textarea class="form-control" id="sysctl" name="sysctl" rows="16"><?= htmlspecialchars((string) $rServerArr['sysctl'], ENT_QUOTES); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- SSL Certificate -->
                <div class="tab-pane fade" id="tab-ssl">
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="expiration_date"><?= $language::get('expiration_date'); ?></label>
                        <div class="col-md-8"><input type="text" class="form-control" id="expiration_date" value="<?= htmlspecialchars((string) $rExpiration, ENT_QUOTES); ?>" readonly></div>
                    </div>
                    <?php if ($rCertValid): ?>
                        <div class="row mb-3">
                            <label class="col-md-4 col-form-label" for="cert_serial"><?= $language::get('certificate_serial'); ?></label>
                            <div class="col-md-8"><input type="text" class="form-control" id="cert_serial" value="<?= htmlspecialchars((string) ($rCertificate['serial'] ?? ''), ENT_QUOTES); ?>" readonly></div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-4 col-form-label" for="cert_subject"><?= $language::get('certificate_subject'); ?></label>
                            <div class="col-md-8"><input type="text" class="form-control" id="cert_subject" value="<?= htmlspecialchars((string) ($rCertificate['subject'] ?? ''), ENT_QUOTES); ?>" readonly></div>
                        </div>
                    <?php endif; ?>
                    <div class="row mb-3">
                        <label class="col-md-4 col-form-label" for="enable_https"><?= $language::get('enable_https'); ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= htmlspecialchars((string) $language::get('allow_ssl_connections_to_this_tooltip'), ENT_QUOTES); ?>"></i></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="enable_https" name="enable_https" value="1" <?= $rServerArr['enable_https'] == 1 ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <?php if (!$rCertValid): ?>
                        <?php if (!empty($rSSLLog['output'])): ?>
                            <div class="row mb-3">
                                <label class="col-md-4 col-form-label" for="error_log"><?= $language::get('error_log'); ?></label>
                                <div class="col-md-8"><textarea rows="10" id="error_log" class="form-control" readonly><?= htmlspecialchars(implode("\n", $rSSLLog['output']), ENT_QUOTES); ?></textarea></div>
                            </div>
                        <?php endif; ?>
                        <div class="alert alert-info" role="alert"><?= $language::get('server_ssl_certbot_help'); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <?php if (!$rCertValid && count(explode(',', (string) $rServerArr['domain_name'])) > 0): ?>
                    <button type="button" id="submit_server_ssl" class="btn btn-info"><?= $language::get('generate_ssl'); ?></button>
                <?php elseif ($rCertValid): ?>
                    <button type="button" id="submit_server_ssl" class="btn btn-info"><?= $language::get('force_update_ssl'); ?></button>
                <?php endif; ?>
                <button type="submit" id="submit_button" class="btn btn-primary" name="submit_server" value="1"><?= $language::get('save'); ?></button>
            </div>
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
        if (!$) { return; }
        var toast = window.xcToast || function() {};

        // select2 dropdowns.
        if ($.fn.select2) {
            $('#network_interface, #geoip_type, #geoip_countries, #total_services, #governor').select2({ width: '100%' });
            var portCreateTag = function(params) {
                if (!$.isNumeric(params.term) || params.term < 80 || params.term > 65535) { return null; }
                return { id: params.term, text: params.term };
            };
            $('#http_broadcast_ports').select2({ width: '100%', tags: true, createTag: portCreateTag });
            $('#https_broadcast_ports').select2({ width: '100%', tags: true, createTag: portCreateTag });
        }

        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { new bootstrap.Tooltip(el); });
        }

        // Numeric-only input filters.
        var numericOnly = function(el, max) {
            if (!el) { return; }
            el.addEventListener('input', function() {
                var v = this.value.replace(/[^\d]/g, '');
                if (max && v !== '' && parseInt(v, 10) > max) { v = this.value.slice(0, -1).replace(/[^\d]/g, ''); }
                this.value = v;
            });
        };
        numericOnly(document.getElementById('total_clients'));
        numericOnly(document.getElementById('rtmp_port'), 65535);
        numericOnly(document.getElementById('network_guaranteed_speed'));
        numericOnly(document.getElementById('limit_requests'));
        numericOnly(document.getElementById('limit_burst'));

        var isValidIP = function(v) { return /^(\d{1,3}\.){3}\d{1,3}$/.test(v); };
        var isValidDomain = function(v) { return /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i.test(v); };

        var domainSelect = document.getElementById('domain_name');
        var addBtn = document.getElementById('add_ip');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                var field = document.getElementById('ip_field');
                var val = field.value.trim();
                if (val && (isValidIP(val) || isValidDomain(val))) {
                    domainSelect.add(new Option(val, val));
                    field.value = '';
                } else {
                    toast(<?= json_encode($language::get('please_enter_a_valid_ip_or_domain')); ?>, 'error');
                }
            });
        }
        var removeBtn = document.getElementById('remove_ip');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                for (var i = domainSelect.options.length - 1; i >= 0; i--) {
                    if (domainSelect.options[i].selected) { domainSelect.remove(i); }
                }
            });
        }
        var moveUpBtn = document.getElementById('move_up');
        if (moveUpBtn) {
            moveUpBtn.addEventListener('click', function() {
                var opts = domainSelect.options;
                for (var i = 1; i < opts.length; i++) {
                    if (opts[i].selected && !opts[i - 1].selected) {
                        domainSelect.insertBefore(opts[i], opts[i - 1]);
                    }
                }
            });
        }
        var moveDownBtn = document.getElementById('move_down');
        if (moveDownBtn) {
            moveDownBtn.addEventListener('click', function() {
                var opts = domainSelect.options;
                for (var i = opts.length - 2; i >= 0; i--) {
                    if (opts[i].selected && !opts[i + 1].selected) {
                        domainSelect.insertBefore(opts[i + 1], opts[i]);
                    }
                }
            });
        }

        // Restore the default sysctl.conf.
        var sysctlDefault = document.getElementById('sysctl_default');
        if (sysctlDefault) {
            sysctlDefault.addEventListener('click', function() {
                document.getElementById('sysctl').value = "# XC_VM\n\nnet.ipv4.tcp_congestion_control = bbr\nnet.core.default_qdisc = fq\nnet.ipv4.tcp_rmem = 8192 87380 134217728\nnet.ipv4.udp_rmem_min = 16384\nnet.core.rmem_default = 262144\nnet.core.rmem_max = 268435456\nnet.ipv4.tcp_wmem = 8192 65536 134217728\nnet.ipv4.udp_wmem_min = 16384\nnet.core.wmem_default = 262144\nnet.core.wmem_max = 268435456\nnet.core.somaxconn = 1000000\nnet.core.netdev_max_backlog = 250000\nnet.core.optmem_max = 65535\nnet.ipv4.tcp_max_tw_buckets = 1440000\nnet.ipv4.tcp_max_orphans = 16384\nnet.ipv4.ip_local_port_range = 2000 65000\nnet.ipv4.tcp_no_metrics_save = 1\nnet.ipv4.tcp_slow_start_after_idle = 0\nnet.ipv4.tcp_fin_timeout = 15\nnet.ipv4.tcp_keepalive_time = 300\nnet.ipv4.tcp_keepalive_probes = 5\nnet.ipv4.tcp_keepalive_intvl = 15\nfs.file-max=20970800\nfs.nr_open=20970800\nfs.aio-max-nr=20970800\nnet.ipv4.tcp_timestamps = 1\nnet.ipv4.tcp_window_scaling = 1\nnet.ipv4.tcp_mtu_probing = 1\nnet.ipv4.route.flush = 1\nnet.ipv6.route.flush = 1";
            });
        }

        var form = document.getElementById('server-form');

        // Trigger a certbot (re)generation: flag it and submit the form.
        var sslBtn = document.getElementById('submit_server_ssl');
        if (sslBtn) {
            sslBtn.addEventListener('click', function() {
                document.getElementById('regenerate_ssl').value = '1';
                if (form.requestSubmit) { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
            });
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            // Select every entry so the whole domain_name[] list is posted, order preserved.
            for (var i = 0; i < domainSelect.options.length; i++) { domainSelect.options[i].selected = true; }
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            var fd = new FormData(form);
            fetch('post.php?action=server&referer=', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (d && d.location) { window.location = d.location; return; }
                    if (btn) { btn.disabled = false; }
                    document.getElementById('regenerate_ssl').value = '0';
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() {
                    if (btn) { btn.disabled = false; }
                    document.getElementById('regenerate_ssl').value = '0';
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                });
        });

        <?php if (SettingsManager::get('enable_search')): ?>
        if (typeof initSearch === 'function') { initSearch(); }
        <?php endif; ?>
    })();
</script>
</body>

</html>
