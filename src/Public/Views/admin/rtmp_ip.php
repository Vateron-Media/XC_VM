<?php

/**
 * RTMP IP add / edit (Bootstrap 5). Full-page form reached from the rtmp_ips table
 * (href="rtmp_ip?id=X"). Bootstrap 5 vertical layout. Posts to post.php?action=rtmp_ip
 * via fetch; on success returns to the rtmp_ips list.
 */

$rIsEdit = isset($rIPArr);
?>

<div class="d-flex align-items-center mb-4">
    <a href="rtmp_ips" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('rtmp_ip'); ?></h4>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?= $language::get('details'); ?></h5>
    </div>
    <div class="card-body">
        <form id="rtmp-form" autocomplete="off">
            <?php if ($rIsEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rIPArr['id']; ?>">
            <?php endif; ?>
            <div class="row mb-6">
                <div class="col-md-6">
                    <label class="form-label" for="ip"><?= $language::get('ip_address'); ?></label>
                    <input type="text" class="form-control" id="ip" name="ip" required value="<?= $rIsEdit ? htmlspecialchars((string) $rIPArr['ip'], ENT_QUOTES) : ''; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password"><?= $language::get('password'); ?></label>
                    <input type="text" class="form-control" id="password" name="password" placeholder="<?= htmlspecialchars((string) $language::get('auto_generate_if_blank'), ENT_QUOTES); ?>" value="<?= $rIsEdit ? htmlspecialchars((string) $rIPArr['password'], ENT_QUOTES) : ''; ?>">
                </div>
            </div>
            <div class="mb-6">
                <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                <textarea class="form-control" id="notes" name="notes" rows="2"><?= $rIsEdit ? htmlspecialchars((string) $rIPArr['notes'], ENT_QUOTES) : ''; ?></textarea>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="push" name="push" value="1" <?= ($rIsEdit && $rIPArr['push'] == 1) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="push"><?= $language::get('allow_this_ip_to_publish_rtmp_streams_to_your_service'); ?></label>
            </div>
            <div class="form-check form-switch mb-6">
                <input class="form-check-input" type="checkbox" id="pull" name="pull" value="1" <?= ($rIsEdit && $rIPArr['pull'] == 1) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="pull"><?= $language::get('allow_this_ip_to_request_rtmp_streams_from_your_service'); ?></label>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="rtmp-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
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
        document.getElementById('rtmp-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('rtmp-submit');
            btn.disabled = true;
            fetch('post.php?action=rtmp_ip', {
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
                        window.location.href = dt.location || 'rtmp_ips';
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