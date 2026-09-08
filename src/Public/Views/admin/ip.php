<?php

/**
 * Block IP (Bootstrap 5). Full-page form reached from the ips table (href="ip").
 * Bootstrap 5 vertical layout: a single card with stacked fields. Posts to
 * post.php?action=ip via fetch; on success returns to the ips list.
 */
?>

<div class="d-flex align-items-center mb-4">
    <a href="ips" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $language::get('block_ip'); ?></h4>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?= $language::get('details'); ?></h5>
    </div>
    <div class="card-body">
        <form id="ip-form" autocomplete="off">
            <div class="mb-6">
                <label class="form-label" for="ip"><?= $language::get('ip_address'); ?></label>
                <input type="text" class="form-control" id="ip" name="ip" required>
            </div>
            <div class="mb-6">
                <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                <textarea class="form-control" id="notes" name="notes" rows="3" required></textarea>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="ip-submit"><?= $language::get('block'); ?></button>
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
        document.getElementById('ip-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('ip-submit');
            btn.disabled = true;
            fetch('post.php?action=ip', {
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
                        window.location.href = dt.location || 'ips';
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