<?php

/**
 * Bouquet channel order (Bootstrap 5). Four tabs (streams / movies / series / radio);
 * each is a drag-to-reorder list of that bouquet's $rOrdered[$type] channels. On save the
 * per-type orders are serialized to a keyed object {stream:[], movie:[], series:[],
 * radio:[]} (the format BouquetService expects) in the hidden "stream_order_array" field,
 * alongside "reorder" = the bouquet id, and POSTed to post.php?action=bouquet_sort.
 * Reordering uses the shared native drag-and-drop helper (window.xcSortable). Reached
 * full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;

$rTypes = [
    'stream' => ['streams', 'tabler-player-play'],
    'movie'  => ['movies', 'tabler-movie'],
    'series' => ['episodes', 'tabler-device-tv'],
    'radio'  => ['stations', 'tabler-radio'],
];
$rBouquetId = (int) RequestManager::get('id');
?>

<div class="d-flex align-items-center mb-4">
    <a href="bouquets" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
            <?php $rFirst = true; foreach ($rTypes as $rType => $rMeta): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link <?= $rFirst ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#order-<?= $rType; ?>" role="tab">
                        <i class="icon-base ti <?= $rMeta[1]; ?> me-1"></i><?= $language::get($rMeta[0]); ?>
                    </button>
                </li>
                <?php $rFirst = false; endforeach; ?>
        </ul>
        <div class="tab-content p-0">
            <?php $rFirst = true; foreach ($rTypes as $rType => $rMeta): ?>
                <div class="tab-pane fade <?= $rFirst ? 'show active' : ''; ?>" id="order-<?= $rType; ?>" role="tabpanel">
                    <p class="text-body-secondary small"><i class="icon-base ti tabler-grip-vertical"></i> Drag to re-order, then click <b>Save Changes</b>.</p>
                    <ol class="list-group xc-sortable mb-3" id="list-<?= $rType; ?>" style="list-style:none;padding-left:0;max-height:60vh;overflow-y:auto">
                        <?php foreach (($rOrdered[$rType] ?? []) as $rItem): ?>
                            <li class="list-group-item d-flex align-items-center" data-id="<?= (int) $rItem['id']; ?>" draggable="true">
                                <i class="icon-base ti tabler-grip-vertical text-body-secondary me-3" style="cursor:grab"></i>
                                <span class="flex-grow-1"><?= htmlspecialchars((string) ($rItem['stream_display_name'] ?? $rItem['title'] ?? ''), ENT_QUOTES); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
                <?php $rFirst = false; endforeach; ?>
        </div>
        <form method="POST" id="order-form">
            <input type="hidden" id="stream_order_array" name="stream_order_array" value="">
            <input type="hidden" name="reorder" value="<?= $rBouquetId; ?>">
            <div class="text-end">
                <button type="submit" class="btn btn-primary"><?= $language::get('save_changes') ?: 'Save Changes'; ?></button>
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
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var toast = window.xcToast || function() {};
        var types = ['stream', 'movie', 'series', 'radio'];
        types.forEach(function(t) {
            var el = document.getElementById('list-' + t);
            if (el && window.xcSortable) { window.xcSortable(el); }
        });
        var form = document.getElementById('order-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var order = { stream: [], movie: [], series: [], radio: [] };
            types.forEach(function(t) {
                var el = document.getElementById('list-' + t);
                if (el) { [].forEach.call(el.querySelectorAll('li'), function(li) { order[t].push(li.getAttribute('data-id')); }); }
            });
            document.getElementById('stream_order_array').value = JSON.stringify(order);
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            fetch('post.php?action=bouquet_sort', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (btn) { btn.disabled = false; }
                    toast(d && d.result !== false ? 'Bouquet order saved.' : errText, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
