<?php

/**
 * Radio station add / edit (Bootstrap 5). Reached full-page from the radios
 * table ("Add" → radio) inside the new-UI shell, and as an iframe modal
 * ("Edit" → radio?id=X&modal=1) inside the modal shell. Tabs: Details (name,
 * logo, source URL, categories, bouquets, notes), Advanced (direct source +
 * ffmpeg/probe/proxy options), Auto-Restart (days + time) and Servers (the
 * jstree load-balancer tree + on-demand list). Categories and bouquets use
 * select2 tags — freshly typed names are collected into the *_create_list hidden
 * inputs so the backend creates them. Posts to post.php?action=radio via fetch;
 * in the modal it posts xcModalSaved to the parent, full-page it returns to the
 * list. Requires the jstree bundle (declared by RadioController).
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;

$rIsEdit     = isset($rStation);
$rSource     = $rIsEdit ? (json_decode((string) $rStation['stream_source'], true)[0] ?? '') : '';
$rStationCat = $rIsEdit ? (json_decode((string) $rStation['category_id'], true) ?: []) : [];
$rAutoRestart = ($rIsEdit && !empty($rStation['auto_restart'])) ? (json_decode((string) $rStation['auto_restart'], true) ?: []) : [];
$rRestartDays = (array) ($rAutoRestart['days'] ?? []);
$rArg = static function (string $key, $rOptId) use ($rStationOptions, $rStationArguments) {
    if (isset($rStationOptions[$rOptId]['value'])) {
        return (string) $rStationOptions[$rOptId]['value'];
    }
    return (string) ($rStationArguments[$key]['argument_default_value'] ?? '');
};
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="radios" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= $rIsEdit ? htmlspecialchars((string) $rStation['stream_display_name'], ENT_QUOTES) : $language::get('add_radio_station'); ?></h4>
    </div>
<?php endif; ?>

<form id="radio-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rStation['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
    <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="">
    <input type="hidden" name="category_create_list" id="category_create_list" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-restart" role="tab"><i class="icon-base ti tabler-clock me-1"></i><?= $language::get('auto_restart'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-servers" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="stream_display_name"><?= $language::get('station_name'); ?></label>
                        <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rStation['stream_display_name'], ENT_QUOTES) : ''; ?>">
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="stream_icon"><?= $language::get('station_logo'); ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="stream_icon" name="stream_icon" value="<?= $rIsEdit ? htmlspecialchars((string) $rStation['stream_icon'], ENT_QUOTES) : ''; ?>">
                            <button type="button" class="btn btn-label-secondary" id="preview-icon"><i class="icon-base ti tabler-eye"></i></button>
                        </div>
                        <div class="mt-2"><img id="icon-preview" src="" alt="" style="max-height:96px" hidden></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="stream_source"><?= $language::get('station_url'); ?></label>
                        <input type="text" id="stream_source" name="stream_source[]" class="form-control" value="<?= htmlspecialchars((string) $rSource, ENT_QUOTES); ?>">
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="category_id"><?= $language::get('categories'); ?></label>
                        <select name="category_id[]" id="category_id" class="form-select" multiple>
                            <?php foreach (CategoryService::getAllByType('radio') as $rCategory): ?>
                                <option value="<?= (int) $rCategory['id']; ?>" <?= in_array((int) $rCategory['id'], array_map('intval', $rStationCat), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="bouquets"><?= $language::get('bouquets'); ?></label>
                        <select name="bouquets[]" id="bouquets" class="form-select" multiple>
                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                <option value="<?= (int) $rBouquet['id']; ?>" <?= ($rIsEdit && in_array((int) $rStation['id'], array_map('intval', json_decode((string) $rBouquet['bouquet_radios'], true) ?: []), true)) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rStation['notes'], ENT_QUOTES) : ''; ?></textarea>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="form-check form-switch mb-6">
                        <input class="form-check-input" type="checkbox" id="direct_source" name="direct_source" value="1" <?= ($rIsEdit && $rStation['direct_source'] == 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="direct_source"><?= $language::get('direct_source'); ?> <i title="<?= $language::get('dont_run_source_through_xc_vm_just_redirect_instead'); ?>" class="icon-base ti tabler-help-circle text-secondary"></i></label>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="probesize_ondemand"><?= $language::get('on_demand_probesize'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control opt-field" id="probesize_ondemand" name="probesize_ondemand" value="<?= $rIsEdit ? htmlspecialchars((string) $rStation['probesize_ondemand'], ENT_QUOTES) : '128000'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="custom_sid"><?= $language::get('custom_channel_sid'); ?></label>
                            <input type="text" class="form-control opt-field" id="custom_sid" name="custom_sid" value="<?= $rIsEdit ? htmlspecialchars((string) $rStation['custom_sid'], ENT_QUOTES) : ''; ?>">
                        </div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="custom_ffmpeg"><?= $language::get('custom_ffmpeg_command'); ?></label>
                        <input type="text" class="form-control opt-field" id="custom_ffmpeg" name="custom_ffmpeg" value="<?= $rIsEdit ? htmlspecialchars((string) $rStation['custom_ffmpeg'], ENT_QUOTES) : ''; ?>">
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="user_agent"><?= $language::get('user_agent'); ?></label>
                            <input type="text" class="form-control opt-field" id="user_agent" name="user_agent" value="<?= htmlspecialchars($rArg('user_agent', 1), ENT_QUOTES); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="http_proxy"><?= $language::get('http_proxy'); ?></label>
                            <input type="text" class="form-control opt-field" id="http_proxy" name="http_proxy" value="<?= htmlspecialchars($rArg('proxy', 2), ENT_QUOTES); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label" for="cookie"><?= $language::get('cookie'); ?></label>
                            <input type="text" class="form-control opt-field" id="cookie" name="cookie" value="<?= htmlspecialchars($rArg('cookie', 17), ENT_QUOTES); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="headers"><?= $language::get('headers'); ?></label>
                            <input type="text" class="form-control opt-field" id="headers" name="headers" value="<?= htmlspecialchars($rArg('headers', 19), ENT_QUOTES); ?>">
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-restart" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="days_to_restart"><?= $language::get('days_to_restart'); ?></label>
                        <select id="days_to_restart" name="days_to_restart[]" class="form-select opt-field" multiple>
                            <?php
                            $rDaysMap = [
                                $language::get('monday') => 'Monday', $language::get('tuesday') => 'Tuesday',
                                $language::get('wednesday') => 'Wednesday', $language::get('thursday') => 'Thursday',
                                $language::get('friday') => 'Friday', $language::get('saturday') => 'Saturday',
                                $language::get('sunday') => 'Sunday',
                            ];
                            foreach ($rDaysMap as $rDayLabel => $rDayValue): ?>
                                <option value="<?= htmlspecialchars((string) $rDayValue, ENT_QUOTES); ?>" <?= in_array($rDayValue, $rRestartDays, true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rDayLabel, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="time_to_restart"><?= $language::get('time_to_restart'); ?></label>
                        <input id="time_to_restart" name="time_to_restart" type="text" class="form-control opt-field" value="<?= htmlspecialchars((string) ($rAutoRestart['at'] ?? '06:00'), ENT_QUOTES); ?>">
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-servers" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label"><?= $language::get('server_tree'); ?></label>
                        <div id="server_tree" class="border rounded p-2"></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="on_demand"><?= $language::get('on_demand_servers'); ?></label>
                        <select name="on_demand[]" id="on_demand" class="form-select opt-field" multiple>
                            <?php foreach ($rServers as $rServer): ?>
                                <option value="<?= (int) $rServer['id']; ?>" <?= in_array((int) $rServer['id'], array_map('intval', $rOnDemand), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input opt-field" type="checkbox" id="restart_on_edit" name="restart_on_edit" value="1">
                        <label class="form-check-label" for="restart_on_edit"><?= $rIsEdit ? $language::get('restart_on_edit') : $language::get('start_stream_now'); ?></label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="radio-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var nameErr = <?= json_encode($language::get('enter_a_radio_station_name')); ?>;
        var $ = window.jQuery;
        if (!$) { return; }

        // Logo preview.
        document.getElementById('preview-icon').addEventListener('click', function() {
            var url = document.getElementById('stream_icon').value.trim(),
                img = document.getElementById('icon-preview');
            if (!url) { img.hidden = true; return; }
            img.src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(url);
            img.hidden = false;
        });

        // select2 tags for categories/bouquets (freshly typed names are collected
        // into *_create_list on submit); plain select2 for days/on_demand.
        $('#category_id, #bouquets').select2({ width: '100%', tags: true, dropdownParent: $('#tab-details') });
        $('#days_to_restart').select2({ width: '100%', dropdownParent: $('#tab-restart') });
        $('#on_demand').select2({ width: '100%', dropdownParent: $('#tab-servers') });

        // flatpickr time picker (replaces the legacy clockpicker).
        if (window.flatpickr) {
            flatpickr('#time_to_restart', { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true });
        }

        // jstree load-balancer tree: click a server to toggle Online/Offline; drag
        // also works. on_demand options mirror the Online servers.
        function evaluateServers() {
            var cur = $('#on_demand').val();
            $('#on_demand').empty();
            $($('#server_tree').jstree(true).get_json('source', { flat: true })).each(function(i, v) {
                if (v.parent !== '#') { $('#on_demand').append(new Option(v.text, v.id)); }
            });
            $('#on_demand').val(cur).trigger('change');
        }
        $('#server_tree')
            .on('redraw.jstree', function() { evaluateServers(); })
            .on('select_node.jstree', function(e, data) {
                if (data.node.id === 'source' || data.node.id === 'offline') { return; }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') { return false; }
                            if (parent.id === '#') { return false; }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        // Direct source disables the encode/restart/server options.
        var directEl = document.getElementById('direct_source');
        function applyDirect() {
            var off = directEl.checked;
            document.querySelectorAll('.opt-field').forEach(function(el) {
                el.disabled = off;
                if ($(el).hasClass('select2-hidden-accessible')) { $(el).prop('disabled', off).trigger('change.select2'); }
            });
        }
        directEl.addEventListener('change', applyDirect);
        applyDirect();

        // Collect freshly typed select2 tags (non-numeric values) as the create list.
        function collectNew(sel) {
            var vals = $(sel).val() || [], nw = [];
            vals.forEach(function(v) { if (!/^\d+$/.test(v)) { nw.push(v); } });
            return JSON.stringify(nw);
        }

        document.getElementById('radio-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!document.getElementById('stream_display_name').value.trim()) { alert(nameErr); return; }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }));
            document.getElementById('category_create_list').value = collectNew('#category_id');
            document.getElementById('bouquet_create_list').value = collectNew('#bouquets');
            // Re-enable direct-source-disabled fields so their values still post.
            document.querySelectorAll('.opt-field').forEach(function(el) { el.disabled = false; });
            var btn = document.getElementById('radio-submit');
            btn.disabled = true;
            fetch('post.php?action=radio', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt;
                    try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) {
                            window.parent.postMessage('xcModalSaved', '*');
                        } else {
                            window.location.href = dt.location || 'radios';
                        }
                        return;
                    }
                    btn.disabled = false;
                    applyDirect();
                    alert(errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    applyDirect();
                    alert(errText);
                });
        });
    })();
</script>
</body>

</html>
