<?php

/**
 * Channel order (Bootstrap 5). Four tabs (streams / movies / series / radio); each is a
 * drag-to-reorder list of $rOrdered[$type]. On save the four lists are concatenated into
 * a single flat [id, …] array (the format ChannelService::setOrder expects), written to
 * the hidden "stream_order_array" field and POSTed to post.php?action=channel_order.
 * Reordering uses the shared native drag-and-drop helper (window.xcSortable); the legacy
 * dual-listbox is gone. Reached full-page in the new-UI shell.
 */

$rTypes = [
    'stream' => ['streams', 'tabler-player-play'],
    'movie'  => ['movies', 'tabler-movie'],
    'series' => ['episodes', 'tabler-device-tv'],
    'radio'  => ['stations', 'tabler-radio'],
];
$rBlocked = (50000 < $rCount && empty($rOverride));
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('channel_order'); ?></h4>
</div>

<?php if ($rBlocked): ?>
    <div class="alert alert-danger" role="alert">
        You have <?= number_format($rCount, 0); ?> streams in your database — far too many to order manually on this page (it would crash your browser). Manual channel ordering has been disabled.
        <div class="mt-3"><a href="channel_order?override=1" class="btn btn-danger"><?= $language::get('continue_anyway'); ?></a></div>
    </div>
<?php else: ?>
    <?php if (($rSettings['channel_number_type'] ?? '') !== 'manual'): ?>
        <div class="alert alert-warning" role="alert"><?= $language::get('channel_order_info'); ?></div>
    <?php endif; ?>
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
                <div class="text-end">
                    <button type="submit" class="btn btn-primary"><?= $language::get('save_changes') ?: 'Save Changes'; ?></button>
                </div>
            </form>
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
        var toast = window.xcToast || function() {};
        var types = ['stream', 'movie', 'series', 'radio'];
        types.forEach(function(t) {
            var el = document.getElementById('list-' + t);
            if (el && window.xcSortable) { window.xcSortable(el); }
        });
        var form = document.getElementById('order-form');
        if (!form) { return; }
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var order = [];
            types.forEach(function(t) {
                var el = document.getElementById('list-' + t);
                if (el) { [].forEach.call(el.querySelectorAll('li'), function(li) { order.push(parseInt(li.getAttribute('data-id'), 10)); }); }
            });
            document.getElementById('stream_order_array').value = JSON.stringify(order);
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            fetch('post.php?action=channel_order', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (btn) { btn.disabled = false; }
                    toast(d && d.result !== false ? 'Channel order saved.' : errText, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
