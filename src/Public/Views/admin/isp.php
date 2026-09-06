<?php

/**
 * Block ISP (Bootstrap 5). Full-page form reached from the isps table (href="isp?id=X").
 * Bootstrap 5 vertical layout. Posts to post.php?action=isp via fetch; on success
 * returns to the isps list.
 */

$rIsEdit = isset($rISPArr);
?>

<div class="d-flex align-items-center mb-4">
    <a href="isps" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('block'); ?> ISP</h4>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?= $language::get('details'); ?></h5>
    </div>
    <div class="card-body">
        <form id="isp-form" autocomplete="off">
            <?php if ($rIsEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rISPArr['id']; ?>">
            <?php endif; ?>
            <div class="mb-6">
                <label class="form-label" for="isp">ISP</label>
                <input type="text" class="form-control" id="isp" name="isp" required value="<?= $rIsEdit ? htmlspecialchars((string) $rISPArr['isp'], ENT_QUOTES) : ''; ?>">
            </div>
            <div class="form-check form-switch mb-6">
                <input class="form-check-input" type="checkbox" id="blocked" name="blocked" value="1" <?= ($rIsEdit && $rISPArr['blocked'] == 1) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="blocked"><?= $language::get('blocked'); ?></label>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="isp-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('block'); ?></button>
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
        document.getElementById('isp-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('isp-submit');
            btn.disabled = true;
            fetch('post.php?action=isp', {
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
                        window.location.href = dt.location || 'isps';
                        return;
                    }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    alert(errText);
                });
        });
    })();
</script>
</body>

</html>