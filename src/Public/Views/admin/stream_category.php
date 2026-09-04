<?php

/**
 * Category add / edit (Bootstrap 5). A details tab (type / name / adult switch) plus, when
 * editing, a read-only channels tab listing the category's streams via the existing
 * serverSide *_short table handler. Saves through post.php?action=stream_category. Reached
 * full-page in the new-UI shell.
 */

$rEdit = isset($rCategoryArr);
$rTypeMap = ['live' => 'streams_short', 'movie' => 'movies_short', 'radio' => 'radios_short', 'series' => 'series_short'];
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $rEdit ? 'Edit' : 'Add'; ?> Category</h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="category-form">
            <?php if ($rEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rCategoryArr['id']; ?>">
                <input type="hidden" name="cat_order" value="<?= (int) $rCategoryArr['cat_order']; ?>">
                <input type="hidden" name="category_type" value="<?= htmlspecialchars((string) $rCategoryArr['category_type'], ENT_QUOTES); ?>">
            <?php endif; ?>
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#category-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <?php if ($rEdit): ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#view-channels" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('permission_streams'); ?></button></li>
                <?php endif; ?>
            </ul>
            <div class="tab-content p-4 border rounded">
                <div class="tab-pane fade show active" id="category-details" role="tabpanel">
                    <?php if (!$rEdit): ?>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="category_type">Category Type</label>
                            <div class="col-md-9">
                                <select name="category_type" id="category_type" class="form-select">
                                    <?php foreach (['live' => 'Live TV', 'movie' => 'Movie', 'series' => 'TV Series', 'radio' => 'Radio Station'] as $rGid => $rGname): ?>
                                        <option value="<?= $rGid; ?>"><?= $rGname; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="category_name">Category Name</label>
                        <div class="col-md-9"><input type="text" class="form-control" id="category_name" name="category_name" value="<?= $rEdit ? htmlspecialchars((string) $rCategoryArr['category_name'], ENT_QUOTES) : ''; ?>" required></div>
                    </div>
                    <div class="row mb-4">
                        <label class="col-md-3 col-form-label" for="is_adult">Adult Content</label>
                        <div class="col-md-9">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="is_adult" name="is_adult" value="1" <?= ($rEdit && $rCategoryArr['is_adult'] == 1) ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-primary"><?= $rEdit ? 'Save' : 'Add'; ?></button></div>
                </div>
                <?php if ($rEdit): ?>
                    <div class="tab-pane fade" id="view-channels" role="tabpanel">
                        <div class="card-datatable table-responsive">
                            <table id="channels-table" class="table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('stream_id'); ?></th>
                                        <th><?= $language::get('stream_name'); ?></th>
                                        <th class="text-center"><?= $language::get('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
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
        var edit = <?= $rEdit ? 'true' : 'false'; ?>;

        <?php if ($rEdit): ?>
            // Channels list — reuses the legacy serverSide *_short handler (HTML rows).
            var channelsInit = false;
            document.querySelector('[data-bs-target="#view-channels"]').addEventListener('shown.bs.tab', function() {
                if (channelsInit) { return; }
                channelsInit = true;
                $('#channels-table').DataTable({
                    processing: true, serverSide: true, info: false,
                    ajax: { url: './table', data: function(d) { d.id = <?= json_encode($rTypeMap[$rCategoryArr['category_type']] ?? 'streams_short'); ?>; d.category_id = <?= (int) $rCategoryArr['id']; ?>; } },
                    columns: [{ data: 0, className: 'text-center' }, { data: 1 }, { data: 2, className: 'text-center', orderable: false }],
                    layout: { topStart: 'pageLength', topEnd: 'search' }
                });
            });
        <?php endif; ?>

        document.getElementById('category-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = this.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            fetch('post.php?action=stream_category', { method: 'POST', body: new FormData(this), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (d && d.result !== false) { window.location.href = d.location || 'stream_categories'; return; }
                    if (btn) { btn.disabled = false; }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(<?= json_encode($language::get('error_occured')); ?>, 'error'); });
        });
    })();
</script>
</body>

</html>
