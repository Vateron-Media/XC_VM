<?php

/**
 * Bouquet order (Bootstrap 5). Drag-to-reorder list of BouquetService::getAllSimple();
 * the order is serialized to a hidden "bouquet_order_array" field (a flat [id, …] array,
 * the format BouquetService expects) and POSTed to post.php?action=bouquet_order, with an
 * optional confirmReplace checkbox to apply the new order retroactively to existing users.
 * Reordering uses the shared native drag-and-drop helper (window.xcSortable). Reached
 * full-page in the new-UI shell.
 */

use XcVm\Domain\Bouquet\BouquetService;
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('bouquet_order'); ?></h5>
    </div>
    <div class="card-body">
        <p class="text-body-secondary small">
            <i class="icon-base ti tabler-grip-vertical"></i> <?= $language::get('bouquet_sort_text'); ?>
        </p>
        <form method="POST" id="bouquet-form">
            <input type="hidden" id="bouquet_order_array" name="bouquet_order_array" value="">
            <ol class="list-group xc-sortable mb-3" id="bouquet-list" style="list-style:none;padding-left:0">
                <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                    <li class="list-group-item d-flex align-items-center" data-id="<?= (int) $rBouquet['id']; ?>" draggable="true">
                        <i class="icon-base ti tabler-grip-vertical text-body-secondary me-3" style="cursor:grab"></i>
                        <span class="flex-grow-1"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="form-check mb-4">
                <input type="checkbox" class="form-check-input" name="confirmReplace" id="confirmReplace">
                <label class="form-check-label" for="confirmReplace"><?= $language::get('bouquet_order_retroactive_hint'); ?></label>
            </div>
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
        var list = document.getElementById('bouquet-list');
        if (window.xcSortable) { window.xcSortable(list); }
        var form = document.getElementById('bouquet-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var order = [].map.call(list.querySelectorAll('li'), function(li) { return parseInt(li.getAttribute('data-id'), 10); });
            document.getElementById('bouquet_order_array').value = JSON.stringify(order);
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            fetch('post.php?action=bouquet_order', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (btn) { btn.disabled = false; }
                    toast(d && d.result !== false ? 'Bouquets re-ordered.' : errText, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
