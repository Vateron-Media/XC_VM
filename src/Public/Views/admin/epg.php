<?php

/**
 * EPG source add / edit (Vuexy). Full-page form reached from the epgs table
 * (href="epg?id=X"). Two tabs: Details (name / source / retention / offset) and,
 * when editing, a read-only channel listing decoded from the stored EPG data.
 * The form posts to post.php?action=epg (the legacy PostController path) via
 * fetch; on success it returns to the epgs list.
 */

$rIsEdit = isset($rEPGArr);
$rEPGData = ($rIsEdit && !empty($rEPGArr['data'])) ? (json_decode((string) $rEPGArr['data'], true) ?: []) : [];
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex align-items-center mb-4">
            <a href="epgs" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
            <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('epg'); ?></h4>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-pills flex-column flex-md-row mb-4" role="tablist">
            <li class="nav-item">
                <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab">
                    <i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?>
                </button>
            </li>
            <?php if ($rIsEdit): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-channels" role="tab">
                        <i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('view_channels'); ?>
                    </button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content p-0">
            <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                <form id="epg-form" autocomplete="off">
                    <?php if ($rIsEdit): ?>
                        <input type="hidden" name="edit" value="<?= (int) $rEPGArr['id']; ?>">
                    <?php endif; ?>
                    <div class="row g-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="epg_name"><?= $language::get('epg_name'); ?></label>
                            <input type="text" class="form-control" id="epg_name" name="epg_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEPGArr['epg_name'], ENT_QUOTES) : ''; ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="epg_file"><?= $language::get('source'); ?></label>
                            <input type="text" class="form-control" id="epg_file" name="epg_file" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEPGArr['epg_file'], ENT_QUOTES) : ''; ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="days_keep"><?= $language::get('days_to_keep'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control text-center" id="days_keep" name="days_keep" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEPGArr['days_keep'], ENT_QUOTES) : '7'; ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="offset"><?= $language::get('minute_offset'); ?></label>
                            <input type="text" inputmode="numeric" class="form-control text-center" id="offset" name="offset" required value="<?= $rIsEdit ? (int) $rEPGArr['offset'] : '0'; ?>">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary" id="epg-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
                    </div>
                </form>
            </div>

            <?php if ($rIsEdit): ?>
                <div class="tab-pane fade" id="tab-channels" role="tabpanel">
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
        </div>
    </div>
</div>

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
