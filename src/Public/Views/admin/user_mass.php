<?php

/**
 * Mass edit users (Bootstrap 5). Three tabs: a serverSide selection table (reg_users,
 * click a row to select), a details tab whose fields are each gated by an "activate"
 * checkbox (only ticked fields are applied), and a package-override table. On submit the
 * selected ids (users_selected JSON) plus the activated fields POST to
 * post.php?action=user_mass. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;

$rOwner = (RequestManager::has('owner') && ($rO = UserRepository::getRegisteredUserById((int) RequestManager::get('owner')))) ? $rO : null;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_users'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="users_selected" id="users_selected" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#user-selection" role="tab"><i class="icon-base ti tabler-users me-1"></i><?= $language::get('users'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#user-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#package-override" role="tab"><i class="icon-base ti tabler-flower me-1"></i><?= $language::get('package_override'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="user-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="user_search" placeholder="<?= $language::get('search_users'); ?>"></div>
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
                                    <th><?= $language::get('owner'); ?></th>
                                    <th class="text-center"><?= $language::get('ip'); ?></th>
                                    <th class="text-center"><?= $language::get('status'); ?></th>
                                    <th class="text-center"><?= $language::get('type'); ?></th>
                                    <th class="text-center"><?= $language::get('credits'); ?></th>
                                    <th class="text-center"><?= $language::get('users'); ?></th>
                                    <th class="text-center"><?= $language::get('lines'); ?></th>
                                    <th class="text-center"><?= $language::get('mags'); ?></th>
                                    <th class="text-center"><?= $language::get('enigmas'); ?></th>
                                    <th class="text-center"><?= $language::get('last_login'); ?></th>
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
                    <?php
                    // [label, id/name, control-type, options?]
                    ?>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="owner_id" name="c_owner_id"></div>
                        <label class="col-md-3 col-form-label" for="owner_id"><?= $language::get('owner'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="owner_id" id="owner_id" class="form-select">
                                <option value="0"><?= $language::get('no_owner'); ?></option>
                                <?php foreach (UserRepository::getRegisteredUsers() as $rRu): ?><option value="<?= (int) $rRu['id']; ?>"><?= htmlspecialchars((string) $rRu['username'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="member_group_id" name="c_member_group_id"></div>
                        <label class="col-md-3 col-form-label" for="member_group_id"><?= $language::get('member_group'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="member_group_id" id="member_group_id" class="form-select">
                                <?php foreach (GroupService::getAll() as $rGroup): ?><option value="<?= (int) $rGroup['group_id']; ?>"><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="reseller_dns" name="c_reseller_dns"></div>
                        <label class="col-md-3 col-form-label" for="reseller_dns"><?= $language::get('reseller_dns'); ?></label>
                        <div class="col-md-8"><input disabled type="text" class="form-control" id="reseller_dns" name="reseller_dns" value=""></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="status" name="c_status"></div>
                        <label class="col-md-3 col-form-label" for="status"><?= $language::get('enabled'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled name="status" id="status" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                </div>
                <!-- Package override -->
                <div class="tab-pane fade" id="package-override" role="tabpanel">
                    <div class="alert alert-info"><?= $language::get('leave_the_override_cell_blank_to'); ?></div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center">#</th>
                                    <th><?= $language::get('package'); ?></th>
                                    <th class="text-center"><?= $language::get('credits'); ?></th>
                                    <th class="text-center"><?= $language::get('override'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (PackageService::getAll() as $rPackage): ?>
                                    <?php if (!$rPackage['is_official']) {
                                        continue;
                                    } ?>
                                    <tr>
                                        <td class="text-center"><?= (int) $rPackage['id']; ?></td>
                                        <td><?= htmlspecialchars((string) $rPackage['package_name'], ENT_QUOTES); ?></td>
                                        <td class="text-center"><?= (int) $rPackage['official_credits']; ?></td>
                                        <td class="text-center"><input class="form-control text-center orinput d-inline" name="override_<?= (int) $rPackage['id']; ?>" type="text" value="" style="width:100px"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-check mt-3 text-center">
                        <input type="checkbox" class="form-check-input" id="c_override" name="c_override">
                        <label class="form-check-label" for="c_override"><?= $language::get('apply_package_override_hint'); ?></label>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_user" value="1"><?= $language::get('mass_edit') ?: 'Mass Edit'; ?></button></div>
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

        // Selection filters (select2).
        if ($.fn.select2) {
            $('#filter, #show_entries').select2({
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
        }

        // Activate checkboxes enable/disable their field (switch is just a checkbox now).
        $('.activate').on('change', function() {
            var t = document.getElementById(this.getAttribute('data-name'));
            if (t) {
                t.disabled = !this.checked;
            }
        });

        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        };
        var statusMap = {
            0: ['secondary', 'Disabled'],
            1: ['success', 'Active'],
            2: ['danger', 'Banned']
        };
        // reg_users is a clean-JSON handler (objects), so map fields to the 13 columns.
        var rTable = $('#datatable-mass').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'reg_users';
                    d.filter = getFilter();
                    d.reseller = getReseller();
                }
            },
            columns: [{
                    data: 'id',
                    className: 'text-center'
                },
                {
                    data: 'username'
                },
                {
                    data: 'owner_username',
                    orderable: false,
                    render: function(d) {
                        return d ? esc(d) : '<span class="text-body-secondary">—</span>';
                    }
                },
                {
                    data: 'ip',
                    className: 'text-center',
                    visible: false
                },
                {
                    data: 'status',
                    className: 'text-center',
                    render: function(d) {
                        var s = statusMap[d] || ['secondary', ''];
                        return '<span class="badge bg-label-' + s[0] + '">' + esc(s[1]) + '</span>';
                    }
                },
                {
                    data: 'is_reseller',
                    className: 'text-center',
                    render: function(d) {
                        return d ? 'Reseller' : 'User';
                    }
                },
                {
                    data: 'credits',
                    className: 'text-center'
                },
                {
                    data: 'user_count',
                    className: 'text-center'
                },
                {
                    data: 'user_lines',
                    className: 'text-center',
                    visible: false
                },
                {
                    data: 'mag_lines',
                    className: 'text-center',
                    visible: false
                },
                {
                    data: 'e2_lines',
                    className: 'text-center',
                    visible: false
                },
                {
                    data: 'last_login',
                    className: 'text-center',
                    visible: false
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    defaultContent: '',
                    visible: false
                }
            ],
            rowCallback: function(row, data) {
                if (selected.indexOf(String(data.id)) !== -1) {
                    $(row).addClass('table-active');
                }
            },
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // Row click toggles selection.
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

        $('#user_search').on('keyup', function() {
            rTable.search(this.value).draw();
        });
        $('#show_entries').on('change', function() {
            rTable.page.len(parseInt(this.value, 10)).draw();
        });
        $('#reseller_search, #filter').on('change', function() {
            rTable.ajax.reload(null, false);
        });

        // Auto-tick override apply when a cell is filled.
        $(document).on('keyup', '.orinput', function() {
            if (this.value.length > 0 && !document.getElementById('c_override').checked) {
                document.getElementById('c_override').checked = true;
            }
        });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) {
                toast('Select at least one user to edit.', 'warning');
                return;
            }
            document.getElementById('users_selected').value = JSON.stringify(selected);
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fd.append('submit_user', '1');
            fetch('post.php?action=user_mass', {
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