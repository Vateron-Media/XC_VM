<?php

/**
 * HMAC key add / edit (Vuexy). Full-page form reached from the hmacs table
 * (href="hmac?id=X"). Vuexy vertical layout. The key is generated client-side
 * (shown once — it is stored encrypted). Posts to post.php?action=hmac via fetch;
 * on success returns to the hmacs list.
 */

$rIsEdit = isset($rHMAC);
?>

<div class="d-flex align-items-center mb-4">
    <a href="hmacs" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('hmac_key'); ?></h4>
</div>

<div class="alert alert-info" role="alert">
    Use this tool to generate a key you can use to create HMAC tokens that can access a stream or movie.<br>
    <strong>Write down the HMAC key — you will not see it again; it is stored encrypted in the database and cannot be extracted.</strong>
</div>

<div class="card">
    <div class="card-header"><h5 class="mb-0"><?= $language::get('details'); ?></h5></div>
    <div class="card-body">
        <form id="hmac-form" autocomplete="off">
            <?php if ($rIsEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rHMAC['id']; ?>">
            <?php endif; ?>
            <div class="mb-6">
                <label class="form-label" for="notes"><?= $language::get('description'); ?></label>
                <input type="text" class="form-control" id="notes" name="notes" value="<?= $rIsEdit ? htmlspecialchars((string) $rHMAC['notes'], ENT_QUOTES) : ''; ?>">
            </div>
            <div class="row mb-6">
                <div class="col-md-8">
                    <label class="form-label" for="keygen"><?= $language::get('hmac_key'); ?></label>
                    <div class="input-group">
                        <input readonly type="text" maxlength="32" class="form-control" id="keygen" name="keygen" required value="<?= $rIsEdit ? 'HMAC KEY HIDDEN' : ''; ?>">
                        <button class="btn btn-outline-primary" type="button" id="gen-key"><i class="icon-base ti tabler-refresh"></i></button>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?= (!$rIsEdit || $rHMAC['enabled'] == 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enabled"><?= $language::get('enabled'); ?></label>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="hmac-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
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
        // 32-char alphanumeric HMAC key.
        var genKey = function() {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', out = '';
            for (var i = 0; i < 32; i++) { out += chars.charAt(Math.floor(Math.random() * chars.length)); }
            return out;
        };
        document.getElementById('gen-key').addEventListener('click', function() { document.getElementById('keygen').value = genKey(); });
        <?php if (!$rIsEdit): ?>
            document.getElementById('keygen').value = genKey();
        <?php endif; ?>

        document.getElementById('hmac-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('hmac-submit');
            btn.disabled = true;
            fetch('post.php?action=hmac', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) { window.location.href = dt.location || 'hmacs'; return; }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() { btn.disabled = false; alert(errText); });
        });
    })();
</script>
</body>

</html>
