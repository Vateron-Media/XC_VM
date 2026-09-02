<?php

/**
 * Block User-Agent (Vuexy). Full-page form reached from the useragents table
 * (href="useragent?id=X"). Vuexy vertical layout. Posts to post.php?action=useragent
 * via fetch; on success returns to the useragents list.
 */

$rIsEdit = isset($rUAArr);
?>

<div class="d-flex align-items-center mb-4">
    <a href="useragents" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('block'); ?> User-Agent</h4>
</div>

<div class="card">
    <div class="card-header"><h5 class="mb-0"><?= $language::get('details'); ?></h5></div>
    <div class="card-body">
        <form id="ua-form" autocomplete="off">
            <?php if ($rIsEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rUAArr['id']; ?>">
            <?php endif; ?>
            <div class="mb-6">
                <label class="form-label" for="user_agent"><?= $language::get('user_agent_label'); ?></label>
                <input type="text" class="form-control" id="user_agent" name="user_agent" required value="<?= $rIsEdit ? htmlspecialchars((string) $rUAArr['user_agent'], ENT_QUOTES) : ''; ?>">
            </div>
            <div class="form-check form-switch mb-6">
                <input class="form-check-input" type="checkbox" id="exact_match" name="exact_match" value="1" <?= ($rIsEdit && $rUAArr['exact_match'] == 1) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="exact_match"><?= $language::get('exact_match'); ?></label>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="ua-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('block'); ?></button>
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
        document.getElementById('ua-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('ua-submit');
            btn.disabled = true;
            fetch('post.php?action=useragent', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) { window.location.href = dt.location || 'useragents'; return; }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() { btn.disabled = false; alert(errText); });
        });
    })();
</script>
</body>

</html>
