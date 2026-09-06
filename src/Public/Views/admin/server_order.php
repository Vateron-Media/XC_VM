<?php

/**
 * Server order (Bootstrap 5). Drag-to-reorder list of $rOrderedServers; the order is
 * serialized to a hidden "server_order" field ([{id}, …], the format ServerService
 * expects) and POSTed to post.php?action=server_order. Reordering uses the shared native
 * HTML5 drag-and-drop helper (window.xcSortable) — the legacy jQuery-Nestable is gone.
 * Reached full-page in the new-UI shell.
 */
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('server_order'); ?></h5>
    </div>
    <div class="card-body">
        <p class="text-body-secondary small">
            <i class="icon-base ti tabler-grip-vertical"></i> Drag a server up or down to re-order it, then click <b>Save Changes</b>. Servers appear in this order on the dashboard; offline servers are moved to the end automatically.
        </p>
        <form method="POST" id="server-form">
            <input type="hidden" id="server_order" name="server_order" value="">
            <ol class="list-group xc-sortable mb-4" id="server-list" style="list-style:none;padding-left:0">
                <?php foreach ($rOrderedServers as $rServer): ?>
                    <li class="list-group-item d-flex align-items-center server-<?= (int) $rServer['id']; ?>" data-id="<?= (int) $rServer['id']; ?>" draggable="true">
                        <i class="icon-base ti tabler-grip-vertical text-body-secondary me-3" style="cursor:grab"></i>
                        <span class="flex-grow-1"><span class="text-body-secondary">#<?= (int) $rServer['id']; ?></span> — <?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
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
        var list = document.getElementById('server-list');
        if (window.xcSortable) {
            window.xcSortable(list);
        }
        var form = document.getElementById('server-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var order = [].map.call(list.querySelectorAll('li'), function(li) {
                return {
                    id: parseInt(li.getAttribute('data-id'), 10)
                };
            });
            document.getElementById('server_order').value = JSON.stringify(order);
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            fetch('post.php?action=server_order', {
                    method: 'POST',
                    body: new FormData(form),
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
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(d && d.result !== false ? 'Servers re-ordered.' : errText, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(errText, 'error');
                });
        });
    })();
</script>
</body>

</html>