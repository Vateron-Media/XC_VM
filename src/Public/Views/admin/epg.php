<?php
use XcVm\Core\Util\LayoutRenderer;


/**
 * EPG source add / edit (Bootstrap 5). Full-page form reached from the epgs table
 * (href="epg?id=X"). Bootstrap 5 vertical layout — each section is its own card with a
 * card-header title, labels stacked above full-width fields. The form posts to
 * post.php?action=epg (the legacy PostController path) via fetch; on success it
 * returns to the epgs list. When editing, the parsed channel list is shown in a
 * second card as a datatables-bs5 table.
 */

$rIsEdit = isset($rEPGArr);
$rMergeConfig = json_decode((string) ($rEPGArr['merge_config'] ?? ''), true) ?: [];
$rMergeStatus = json_decode((string) ($rEPGArr['merge_status'] ?? ''), true) ?: [];
$rMergeSources = array_filter($rMergeSources ?? [], static fn($s) => !empty($s['epg_file']) && empty($s['merge_config']) && (int) $s['id'] !== (int) ($rEPGArr['id'] ?? 0));
$rMergeOptions = '';
foreach ($rMergeSources as $rSource) {
    $rMergeOptions .= '<option value="' . (int) $rSource['id'] . '">' . htmlspecialchars((string) $rSource['epg_name'], ENT_QUOTES) . '</option>';
}
$rEPGData = ($rIsEdit && !empty($rEPGArr['data'])) ? (json_decode((string) $rEPGArr['data'], true) ?: []) : [];
?>

<div class="d-flex align-items-center mb-4">
    <a href="epgs" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('epg'); ?></h4>
</div>

<div class="card mb-6">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?= $language::get('details'); ?></h5>
    </div>
    <div class="card-body">
        <form id="epg-form" autocomplete="off">
            <?php if ($rIsEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rEPGArr['id']; ?>">
            <?php endif; ?>

            <div class="mb-6">
                <label class="form-label" for="epg_name"><?= $language::get('epg_name'); ?></label>
                <input type="text" class="form-control" id="epg_name" name="epg_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEPGArr['epg_name'], ENT_QUOTES) : ''; ?>">
            </div>
            <div class="mb-6">
                <label class="form-label" for="epg_file"><?= $language::get('source'); ?></label>
                <input type="text" class="form-control" id="epg_file" name="epg_file" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEPGArr['epg_file'], ENT_QUOTES) : ''; ?>">
            </div>
            <div class="mb-6">
                <input type="hidden" name="merge_enabled" value="0">
                <label class="form-check-label"><input class="form-check-input me-2" type="checkbox" id="merge-enabled" name="merge_enabled" value="1" <?= $rMergeConfig ? 'checked' : ''; ?>><?= $language::get('epg_merge_enable'); ?></label>
            </div>
            <div id="merge-settings" class="mb-6">
                <p><?= $language::get('epg_merge_help'); ?></p>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th><?= $language::get('source'); ?></th><th><?= $language::get('epg_merge_priority'); ?></th></tr></thead>
                    <tbody><?php foreach ($rMergeSources as $rSource): $rPriority = array_search((int) $rSource['id'], $rMergeConfig['sources'] ?? [], true); ?>
                        <tr><td><label><input class="form-check-input me-2" type="checkbox" name="merge_sources[]" value="<?= (int) $rSource['id']; ?>" <?= $rPriority !== false ? 'checked' : ''; ?>><?= htmlspecialchars((string) $rSource['epg_name'], ENT_QUOTES); ?></label></td>
                        <td><input class="form-control" type="number" min="1" max="999" name="merge_order[<?= (int) $rSource['id']; ?>]" value="<?= $rPriority !== false ? $rPriority + 1 : 99; ?>"></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <h6 class="mt-4"><?= $language::get('epg_merge_aliases'); ?></h6>
                <p><?= $language::get('epg_merge_alias_help'); ?></p>
                <div class="table-responsive"><table class="table"><thead><tr>
                    <th><?= $language::get('epg_merge_target'); ?></th><th><?= $language::get('source'); ?></th><th><?= $language::get('epg_merge_input_channel'); ?></th><th></th>
                </tr></thead><tbody id="merge-aliases"></tbody></table></div>
                <button type="button" id="merge-add-alias" class="btn btn-label-secondary mt-3"><?= $language::get('add'); ?></button>
            </div>
            <div class="row mb-6">
                <div class="col-md-6">
                    <label class="form-label" for="days_keep"><?= $language::get('days_to_keep'); ?></label>
                    <input type="text" inputmode="numeric" class="form-control" id="days_keep" name="days_keep" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEPGArr['days_keep'], ENT_QUOTES) : '7'; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="offset"><?= $language::get('minute_offset'); ?></label>
                    <input type="text" inputmode="numeric" class="form-control" id="offset" name="offset" required value="<?= $rIsEdit ? (int) $rEPGArr['offset'] : '0'; ?>">
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="epg-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($rMergeStatus): ?>
<div class="card mb-6"><div class="card-header"><h5><?= $language::get('epg_merge_coverage'); ?></h5></div><div class="card-body">
    <p><?= $language::get('last_updated'); ?>: <?= htmlspecialchars(date('Y-m-d H:i', (int) $rMergeStatus['built_at']), ENT_QUOTES); ?></p>
    <?php if (!empty($rMergeStatus['failed_sources'])): ?><div class="alert alert-warning"><?= $language::get('epg_merge_failed'); ?> <?= htmlspecialchars(implode(', ', $rMergeStatus['failed_sources']), ENT_QUOTES); ?></div><?php endif; ?>
    <div class="table-responsive"><table class="table"><thead><tr><th><?= $language::get('channel_name'); ?></th><th><?= $language::get('epg_merge_coverage'); ?></th><th><?= $language::get('epg_merge_until'); ?></th></tr></thead><tbody>
    <?php foreach ($rMergeStatus['channels'] ?? [] as $rCoverage): ?><tr>
        <td><?= htmlspecialchars((string) $rCoverage['name'], ENT_QUOTES); ?></td><td><?= (float) $rCoverage['percent']; ?>%</td>
        <td><?= $rCoverage['until'] ? htmlspecialchars(date('Y-m-d H:i', (int) $rCoverage['until']), ENT_QUOTES) : '—'; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</div></div>
<?php endif; ?>

<?php if ($rIsEdit): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= $language::get('view_channels'); ?></h5>
        </div>
        <div class="card-datatable table-responsive">
            <table id="epg-channels-table" class="table" style="width:100%">
                <thead>
                    <tr>
                        <th><?= $language::get('key'); ?></th>
                        <th><?= $language::get('channel_name'); ?></th>
                        <th><?= $language::get('languages'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rEPGData as $rEPGKey => $rEPGRow): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $rEPGKey, ENT_QUOTES); ?></td>
                            <td><?= htmlspecialchars((string) ($rEPGRow['display_name'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?= htmlspecialchars(implode(', ', (array) ($rEPGRow['langs'] ?? [])), ENT_QUOTES); ?></td>
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
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var mergeToggle = document.getElementById('merge-enabled');
        var mergeSettings = document.getElementById('merge-settings');
        var sourceField = document.getElementById('epg_file');
        var offsetField = document.getElementById('offset');
        function toggleMerge() {
            mergeSettings.hidden = !mergeToggle.checked;
            mergeSettings.querySelectorAll('input, select, button').forEach(function(el) { el.disabled = !mergeToggle.checked; });
            sourceField.required = !mergeToggle.checked;
            sourceField.disabled = mergeToggle.checked;
            offsetField.disabled = mergeToggle.checked;
        }
        var aliasIndex = 0;
        function addAlias(alias) {
            var index = aliasIndex++;
            var tr = document.createElement('tr');
            ['target', 'source', 'channel'].forEach(function(key) {
                var td = document.createElement('td');
                var field = document.createElement(key === 'source' ? 'select' : 'input');
                field.className = key === 'source' ? 'form-select' : 'form-control';
                field.name = 'merge_aliases[' + index + '][' + key + ']';
                if (key === 'source') field.innerHTML = <?= json_encode($rMergeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                else field.maxLength = 200;
                if (alias && alias[key] !== undefined) field.value = alias[key];
                td.appendChild(field);
                tr.appendChild(td);
            });
            var td = document.createElement('td');
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-label-danger';
            remove.textContent = <?= json_encode($language::get('delete')); ?>;
            remove.addEventListener('click', function() { tr.remove(); });
            td.appendChild(remove);
            tr.appendChild(td);
            document.getElementById('merge-aliases').appendChild(tr);
        }
        <?= json_encode($rMergeConfig['aliases'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.forEach(addAlias);
        document.getElementById('merge-add-alias').addEventListener('click', function() { addAlias(null); });
        mergeToggle.addEventListener('change', toggleMerge);
        toggleMerge();
        // Numeric-only guards mirroring the legacy inputFilter.
        var digitsOnly = function(el, allowNeg) {
            el.addEventListener('input', function() {
                var re = allowNeg ? /[^0-9-]/g : /[^0-9]/g;
                var v = el.value.replace(re, '');
                if (allowNeg) {
                    v = v.replace(/(?!^)-/g, '');
                }
                el.value = v;
            });
        };
        digitsOnly(document.getElementById('days_keep'), false);
        digitsOnly(document.getElementById('offset'), true);

        <?php if ($rIsEdit): ?>
            jQuery('#epg-channels-table').DataTable({
                paging: true,
                searching: true,
                info: false,
                order: [],
                responsive: true,
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search'
                }
            });
        <?php endif; ?>

        // Submit → post.php?action=epg (legacy PostController path), then back to the list.
        document.getElementById('epg-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('epg-submit');
            btn.disabled = true;
            fetch('post.php?action=epg', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var dt;
                    try {
                        dt = JSON.parse(txt);
                    } catch (err) {
                        dt = {
                            result: false
                        };
                    }
                    if (dt && dt.result !== false) {
                        window.location.href = dt.location || 'epgs';
                        return;
                    }
                    btn.disabled = false;
                    xcToast(errText, 'error');
                })
                .catch(function() {
                    btn.disabled = false;
                    xcToast(errText, 'error');
                });
        });
    })();
</script>
</body>

</html>