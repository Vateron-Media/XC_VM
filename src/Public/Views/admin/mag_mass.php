<?php

/**
 * Mass edit MAG devices (Bootstrap 5). Four tabs: a serverSide selection table
 * (mags handler, click a row to select), a details tab whose fields are each
 * gated by an "activate" checkbox, an events tab (send message / play channel /
 * reboot), and a bouquets-override table. On submit the selected ids
 * (devices_selected JSON) plus the selected bouquets (bouquets_selected JSON)
 * and the activated fields POST to post.php?action=mag_mass. Reached full-page
 * in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Reference\GeoReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\User\UserRepository;

$rOwner = (RequestManager::has('owner') && ($rO = UserRepository::getRegisteredUserById((int) RequestManager::get('owner')))) ? $rO : null;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_devices'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="devices_selected" id="devices_selected" value="">
            <input type="hidden" name="bouquets_selected" id="bouquets_selected" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#user-selection" role="tab"><i class="icon-base ti tabler-devices me-1"></i><?= $language::get('devices'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#user-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#events" role="tab"><i class="icon-base ti tabler-mail me-1"></i><?= $language::get('events'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#bouquets" role="tab"><i class="icon-base ti tabler-flower me-1"></i><?= $language::get('bouquets'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="user-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="user_search" placeholder="<?= $language::get('search_devices_placeholder'); ?>"></div>
                        <div class="col-md-3">
                            <select id="reseller_search" class="form-select">
                                <?php if ($rOwner): ?><option value="<?= (int) $rOwner['id']; ?>" selected><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES); ?></option><?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-label-secondary w-100" onclick="clearOwner()"><?= $language::get('clear_btn'); ?></button></div>
                        <div class="col-md-2">
                            <select id="filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter'); ?></option>
                                <option value="1"><?= $language::get('active'); ?></option>
                                <option value="2"><?= $language::get('disabled'); ?></option>
                                <option value="3"><?= $language::get('banned'); ?></option>
                                <option value="4"><?= $language::get('expired'); ?></option>
                                <option value="5"><?= $language::get('trial'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-2 col-8">
                            <select id="show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?><option value="<?= $rShow; ?>" <?= $rSettings['default_entries'] == $rShow ? 'selected' : ''; ?>><?= $rShow; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-2"><button type="button" class="btn btn-info w-100" onclick="toggleUsers()" title="<?= $language::get('select'); ?>"><i class="icon-base ti tabler-select-all"></i></button></div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-mass" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th><?= $language::get('username'); ?></th>
                                    <th class="text-center"><?= $language::get('mac_address'); ?></th>
                                    <th class="text-center"><?= $language::get('device'); ?></th>
                                    <th><?= $language::get('owner'); ?></th>
                                    <th class="text-center"><?= $language::get('status'); ?></th>
                                    <th class="text-center"><?= $language::get('online'); ?></th>
                                    <th class="text-center"><?= $language::get('trial'); ?></th>
                                    <th class="text-center"><?= $language::get('expiration'); ?></th>
                                    <th class="text-center"><?= $language::get('actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <!-- Details -->
                <div class="tab-pane fade" id="user-details" role="tabpanel">
                    <p class="text-body-secondary"><?= $language::get('to_mass_edit_any_of_the_below'); ?></p>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="member_id" name="c_member_id"></div>
                        <label class="col-md-3 col-form-label" for="member_id"><?= $language::get('owner'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="member_id" id="member_id" class="form-select">
                                <?php foreach (UserRepository::getRegisteredUsers() as $rRegisteredUser): ?><option value="<?= (int) $rRegisteredUser['id']; ?>"><?= htmlspecialchars((string) $rRegisteredUser['username'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="parent_password" name="c_parent_password"></div>
                        <label class="col-md-3 col-form-label" for="parent_password"><?= $language::get('adult_pin'); ?></label>
                        <div class="col-md-8"><input disabled type="text" class="form-control text-center" id="parent_password" name="parent_password" value="0000"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="exp_date" name="c_exp_date"></div>
                        <label class="col-md-3 col-form-label" for="exp_date"><?= $language::get('expiry'); ?></label>
                        <div class="col-md-6"><input disabled type="text" class="form-control" id="exp_date" name="exp_date" value="" autocomplete="off"></div>
                        <div class="col-md-2">
                            <div class="form-check mt-1"><input disabled type="checkbox" class="form-check-input" id="no_expire" name="no_expire"><label class="form-check-label" for="no_expire"><?= $language::get('never'); ?></label></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="admin_notes" name="c_admin_notes"></div>
                        <label class="col-md-3 col-form-label" for="admin_notes"><?= $language::get('admin_notes'); ?></label>
                        <div class="col-md-8"><textarea disabled id="admin_notes" name="admin_notes" class="form-control" rows="3"></textarea></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="reseller_notes" name="c_reseller_notes"></div>
                        <label class="col-md-3 col-form-label" for="reseller_notes"><?= $language::get('reseller_notes'); ?></label>
                        <div class="col-md-8"><textarea disabled id="reseller_notes" name="reseller_notes" class="form-control" rows="3"></textarea></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="force_server_id" name="c_force_server_id"></div>
                        <label class="col-md-3 col-form-label" for="force_server_id"><?= $language::get('forced_connection'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="force_server_id" id="force_server_id" class="form-select">
                                <option selected value="0"><?= $language::get('disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?><option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="forced_country" name="c_forced_country"></div>
                        <label class="col-md-3 col-form-label" for="forced_country"><?= $language::get('forced_country'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="forced_country" id="forced_country" class="form-select">
                                <?php foreach (GeoReference::countries() as $rCountry): ?><option value="<?= htmlspecialchars((string) $rCountry['id'], ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rCountry['name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="is_isplock" name="c_is_isplock"></div>
                        <label class="col-md-3 col-form-label" for="is_isplock"><?= $language::get('lock_to_isp'); ?></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch"><input disabled name="is_isplock" id="is_isplock" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                        <label class="col-md-5 col-form-label" for="reset_isp_lock"><?= $language::get('reset_current_isp'); ?></label>
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input" name="reset_isp_lock"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="lock_device" name="c_lock_device"></div>
                        <label class="col-md-3 col-form-label" for="lock_device"><?= $language::get('device_lock'); ?></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch"><input disabled name="lock_device" id="lock_device" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                        <label class="col-md-5 col-form-label" for="reset_device_lock"><?= $language::get('reset_device_lock'); ?></label>
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input" name="reset_device_lock"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="is_trial" name="c_is_trial"></div>
                        <label class="col-md-3 col-form-label" for="is_trial"><?= $language::get('trial_device'); ?></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch"><input disabled name="is_trial" id="is_trial" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                        <label class="col-md-3 col-form-label" for="modern_theme"><?= $language::get('modern_theme'); ?></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch"><input disabled name="modern_theme" id="modern_theme" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="modern_theme" name="c_modern_theme"></div>
                    </div>
                </div>
                <!-- Events -->
                <div class="tab-pane fade" id="events" role="tabpanel">
                    <div class="row mb-3 align-items-center">
                        <label class="col-md-3 col-form-label" for="m_message_type"><?= $language::get('event_type'); ?></label>
                        <div class="col-md-9">
                            <select id="m_message_type" name="message_type" class="form-select">
                                <option value="" selected><?= $language::get('select_an_event'); ?>:</option>
                                <optgroup label="">
                                    <option value="play_channel"><?= $language::get('play_channel'); ?></option>
                                    <option value="reload_portal"><?= $language::get('reload_portal'); ?></option>
                                    <option value="reboot"><?= $language::get('reboot_device'); ?></option>
                                    <option value="send_msg"><?= $language::get('send_message'); ?></option>
                                    <option value="cut_off"><?= $language::get('close_portal'); ?></option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center" id="m_send_msg_form">
                        <label class="col-md-3 col-form-label" for="m_message"><?= $language::get('message'); ?></label>
                        <div class="col-md-9"><textarea id="m_message" name="message" class="form-control" rows="3" placeholder="<?= $language::get('enter_a_custom_message'); ?>..."></textarea></div>
                    </div>
                    <div class="row mb-3 align-items-center" id="m_play_channel_form">
                        <label class="col-md-3 col-form-label" for="m_selected_channel"><?= $language::get('channel'); ?></label>
                        <div class="col-md-9"><select id="m_selected_channel" name="selected_channel" class="form-select" style="width:100%;"></select></div>
                    </div>
                    <div class="row mb-3 align-items-center" id="m_reboot_portal_form">
                        <label class="col-md-9 col-form-label" for="m_reboot_portal"><?= $language::get('reboot_on_confirmation'); ?></label>
                        <div class="col-md-3">
                            <div class="form-check form-switch"><input name="reboot_portal" id="m_reboot_portal" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                </div>
                <!-- Bouquets -->
                <div class="tab-pane fade" id="bouquets" role="tabpanel">
                    <div class="table-responsive">
                        <table id="datatable-bouquets" class="table mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th><?= $language::get('bouquet_name'); ?></th>
                                    <th class="text-center"><?= $language::get('streams'); ?></th>
                                    <th class="text-center"><?= $language::get('movies'); ?></th>
                                    <th class="text-center"><?= $language::get('series'); ?></th>
                                    <th class="text-center"><?= $language::get('stations'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                    <tr>
                                        <td class="text-center"><?= (int) $rBouquet['id']; ?></td>
                                        <td><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></td>
                                        <td class="text-center"><?= count(json_decode($rBouquet['bouquet_channels'], true) ?: []); ?></td>
                                        <td class="text-center"><?= count(json_decode($rBouquet['bouquet_movies'], true) ?: []); ?></td>
                                        <td class="text-center"><?= count(json_decode($rBouquet['bouquet_series'], true) ?: []); ?></td>
                                        <td class="text-center"><?= count(json_decode($rBouquet['bouquet_radios'], true) ?: []); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mt-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="c_bouquets" data-name="bouquets" name="c_bouquets">
                            <label class="form-check-label" for="c_bouquets"><?= $language::get('apply_bouquets_devices_hint'); ?></label>
                        </div>
                        <button type="button" class="btn btn-info" onclick="toggleBouquets()"><?= $language::get('toggle_bouquets'); ?></button>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_device" value="1"><?= $language::get('mass_edit') ?: 'Mass Edit'; ?></button></div>
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
        var selected = [];
        var bouquetsSel = [];

        function updateCount() {
            document.getElementById('selected_count').textContent = selected.length ? '— ' + selected.length + ' selected' : '';
        }
        window.getReseller = function() {
            return document.getElementById('reseller_search').value;
        };
        window.getFilter = function() {
            return document.getElementById('filter').value;
        };
        window.clearOwner = function() {
            $('#reseller_search').val('').trigger('change');
        };
        window.toggleUsers = function() {
            var allSelected = true;
            $('#datatable-mass tbody tr').each(function() {
                if (!$(this).hasClass('table-active')) {
                    allSelected = false;
                }
            });
            $('#datatable-mass tbody tr').each(function() {
                var id = $(this).find('td:eq(0)').text().trim();
                if (!id) {
                    return;
                }
                if (allSelected) {
                    $(this).removeClass('table-active');
                    var i = selected.indexOf(id);
                    if (i > -1) {
                        selected.splice(i, 1);
                    }
                } else if (!$(this).hasClass('table-active')) {
                    $(this).addClass('table-active');
                    if (selected.indexOf(id) === -1) {
                        selected.push(id);
                    }
                }
            });
            updateCount();
        };
        window.toggleBouquets = function() {
            var allSelected = true;
            $('#datatable-bouquets tbody tr').each(function() {
                if (!$(this).hasClass('table-active')) {
                    allSelected = false;
                }
            });
            $('#datatable-bouquets tbody tr').each(function() {
                var id = $(this).find('td:eq(0)').text().trim();
                if (!id) {
                    return;
                }
                if (allSelected) {
                    $(this).removeClass('table-active');
                    var i = bouquetsSel.indexOf(id);
                    if (i > -1) {
                        bouquetsSel.splice(i, 1);
                    }
                } else if (!$(this).hasClass('table-active')) {
                    $(this).addClass('table-active');
                    if (bouquetsSel.indexOf(id) === -1) {
                        bouquetsSel.push(id);
                    }
                }
            });
            document.getElementById('c_bouquets').checked = true;
        };

        if ($.fn.select2) {
            $('#filter, #show_entries, #m_message_type').select2({
                width: '100%'
            });
            $('#reseller_search').select2({
                width: '100%',
                placeholder: 'Search for an owner…',
                allowClear: true,
                ajax: {
                    url: './api',
                    dataType: 'json',
                    delay: 250,
                    data: function(p) {
                        return {
                            search: p.term,
                            action: 'reguserlist',
                            page: p.page
                        };
                    },
                    processResults: function(d, p) {
                        p.page = p.page || 1;
                        return {
                            results: d.items,
                            pagination: {
                                more: (p.page * 100) < d.total_count
                            }
                        };
                    },
                    cache: true
                }
            });
            $('#m_selected_channel').select2({
                width: '100%',
                placeholder: <?= json_encode($language::get('start_typing') . '...'); ?>,
                ajax: {
                    url: './api',
                    dataType: 'json',
                    delay: 250,
                    data: function(p) {
                        return {
                            search: p.term,
                            action: 'streamlist',
                            page: p.page
                        };
                    },
                    processResults: function(d, p) {
                        p.page = p.page || 1;
                        return {
                            results: d.items,
                            pagination: {
                                more: (p.page * 100) < d.total_count
                            }
                        };
                    },
                    cache: true
                }
            });
        }

        if (window.flatpickr) {
            flatpickr('#exp_date', {
                enableTime: true,
                time_24hr: true,
                dateFormat: 'Y-m-d H:i'
            });
        }
        $('#no_expire').on('change', function() {
            var ed = document.getElementById('exp_date');
            if (this.checked) {
                ed.disabled = true;
            } else {
                ed.disabled = false;
            }
        });
        $('#parent_password').on('keyup', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        $('.activate').on('change', function() {
            var name = this.getAttribute('data-name');
            var on = this.checked;
            if (name === 'exp_date') {
                var ne = document.getElementById('no_expire');
                if (ne) {
                    ne.disabled = !on;
                }
                var ed = document.getElementById('exp_date');
                if (ed) {
                    ed.disabled = !on || (ne && ne.checked);
                }
                return;
            }
            var t = document.getElementById(name);
            if (t) {
                t.disabled = !on;
            }
        });

        // Events tab: show only the sub-form relevant to the chosen event type.
        $('#m_message_type').on('change', function() {
            var v = this.value;
            document.getElementById('m_send_msg_form').hidden = (v !== 'send_msg');
            document.getElementById('m_reboot_portal_form').hidden = (v !== 'send_msg');
            document.getElementById('m_play_channel_form').hidden = (v !== 'play_channel');
        }).trigger('change');

        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        };
        var pad = function(n) {
            return (n < 10 ? '0' : '') + n;
        };
        var yn = function(d) {
            return d ? '<i class="icon-base ti tabler-check text-success"></i>' : '<span class="text-body-secondary">—</span>';
        };
        var dash = function(d) {
            return d ? esc(d) : '<span class="text-body-secondary">—</span>';
        };

        function statusBadge(row) {
            var s = 'active',
                cls = 'success';
            if (!row.admin_enabled) {
                s = 'banned';
                cls = 'danger';
            } else if (!row.enabled) {
                s = 'disabled';
                cls = 'secondary';
            } else if (row.exp_date && row.exp_date < (Date.now() / 1000)) {
                s = 'expired';
                cls = 'warning';
            }
            return '<span class="badge bg-label-' + cls + '">' + s + '</span>';
        }

        function fmtDate(d) {
            if (!d) {
                return '<span class="text-body-secondary">—</span>';
            }
            var dt = new Date(d * 1000);
            return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()) + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
        }

        // mags is a clean-JSON handler (objects): map fields to the 10 columns.
        var rTable = $('#datatable-mass').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ordering: false,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'mags';
                    d.filter = getFilter();
                    d.reseller = getReseller();
                }
            },
            columns: [{
                    data: 'mag_id',
                    className: 'text-center'
                },
                {
                    data: 'username',
                    visible: false
                },
                {
                    data: 'mac',
                    className: 'text-center'
                },
                {
                    data: 'stb_type',
                    visible: false
                },
                {
                    data: 'owner_name',
                    render: dash
                },
                {
                    data: 'admin_enabled',
                    className: 'text-center',
                    render: function(d, t, row) {
                        return statusBadge(row);
                    }
                },
                {
                    data: 'active_connections',
                    className: 'text-center',
                    visible: false,
                    render: function(d) {
                        return d > 0 ? '<i class="icon-base ti tabler-circle-filled text-success"></i>' : '<i class="icon-base ti tabler-circle-filled text-secondary"></i>';
                    }
                },
                {
                    data: 'is_trial',
                    className: 'text-center',
                    render: yn
                },
                {
                    data: 'exp_date',
                    className: 'text-center',
                    render: fmtDate
                },
                {
                    data: null,
                    className: 'text-center',
                    visible: false,
                    defaultContent: ''
                }
            ],
            rowCallback: function(row, data) {
                if (selected.indexOf(String(data.mag_id)) !== -1) {
                    $(row).addClass('table-active');
                }
            },
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        $('#datatable-bouquets').DataTable({
            paging: false,
            searching: false,
            info: false,
            ordering: false
        });

        $('#datatable-mass tbody').on('click', 'tr', function() {
            var id = $(this).find('td:eq(0)').text().trim();
            if (!id) {
                return;
            }
            if ($(this).hasClass('table-active')) {
                $(this).removeClass('table-active');
                var i = selected.indexOf(id);
                if (i > -1) {
                    selected.splice(i, 1);
                }
            } else {
                $(this).addClass('table-active');
                if (selected.indexOf(id) === -1) {
                    selected.push(id);
                }
            }
            updateCount();
        });
        $('#datatable-bouquets tbody').on('click', 'tr', function() {
            var id = $(this).find('td:eq(0)').text().trim();
            if (!id) {
                return;
            }
            if ($(this).hasClass('table-active')) {
                $(this).removeClass('table-active');
                var i = bouquetsSel.indexOf(id);
                if (i > -1) {
                    bouquetsSel.splice(i, 1);
                }
            } else {
                $(this).addClass('table-active');
                if (bouquetsSel.indexOf(id) === -1) {
                    bouquetsSel.push(id);
                }
            }
            document.getElementById('c_bouquets').checked = true;
        });

        $('#user_search').on('keyup', function() {
            rTable.search(this.value).draw();
        });
        $('#show_entries').on('change', function() {
            rTable.page.len(parseInt(this.value, 10)).draw();
        });
        $('#reseller_search, #filter').on('change', function() {
            rTable.ajax.reload(null, false);
        });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) {
                toast('Select at least one device to edit.', 'warning');
                return;
            }
            document.getElementById('devices_selected').value = JSON.stringify(selected);
            document.getElementById('bouquets_selected').value = JSON.stringify(bouquetsSel);
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fd.append('submit_device', '1');
            fetch('post.php?action=mag_mass', {
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
                    if (d && d.result !== false) {
                        toast('Mass edit applied.', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 800);
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
    })();
</script>
</body>

</html>