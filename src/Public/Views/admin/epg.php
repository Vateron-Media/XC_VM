<?php

/**
 * EPG source add / edit (Vuexy). Full-page form reached from the epgs table
 * (href="epg?id=X"). Vuexy vertical layout — each section is its own card with a
 * card-header title, labels stacked above full-width fields. The form posts to
 * post.php?action=epg (the legacy PostController path) via fetch; on success it
 * returns to the epgs list. When editing, the parsed channel list is shown in a
 * second card as a datatables-bs5 table.
 */

$rIsEdit = isset($rEPGArr);
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
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        // Numeric-only guards mirroring the legacy inputFilter.
        var digitsOnly = function(el, allowNeg) {
            el.addEventListener('input', function() {
                var re = allowNeg ? /[^0-9-]/g : /[^0-9]/g;
                var v = el.value.replace(re, '');
                if (allowNeg) { v = v.replace(/(?!^)-/g, ''); }
                el.value = v;
            });
        };
        digitsOnly(document.getElementById('days_keep'), false);
        digitsOnly(document.getElementById('offset'), true);

        <?php if ($rIsEdit): ?>
            jQuery('#epg-channels-table').DataTable({ paging: true, searching: true, info: false, order: [], responsive: true, layout: { topStart: 'pageLength', topEnd: 'search' } });
        <?php endif; ?>

        // Submit → post.php?action=epg (legacy PostController path), then back to the list.
        document.getElementById('epg-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('epg-submit');
            btn.disabled = true;
            fetch('post.php?action=epg', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) { window.location.href = dt.location || 'epgs'; return; }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() { btn.disabled = false; alert(errText); });
        });
    })();
</script>
</body>

</html>
