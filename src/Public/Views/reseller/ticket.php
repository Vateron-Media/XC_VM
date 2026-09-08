<?php

/**
 * Ticket create / respond (Bootstrap 5, reseller). Dual-purpose form driven by
 * $rTicketInfo (nullable, from ResellerTicketController):
 *   - respond mode ($rTicketInfo set): hidden `respond` (ticket id) + `message`.
 *   - create mode  ($rTicketInfo null): `title` (subject) + `message`.
 * Posts to post.php?action=ticket (ResellerPostController -> ResellerAPI::submitTicket);
 * on success it follows the returned location (ticket_view?id=...).
 */

$rIsRespond = isset($rTicketInfo);
?>

<div class="d-flex align-items-center mb-4">
    <a href="<?= $rIsRespond ? 'ticket_view?id=' . (int) $rTicketInfo['id'] : 'tickets'; ?>" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsRespond ? $language::get('ticket_response') : $language::get('create'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="ticket-form" autocomplete="off">
            <?php if ($rIsRespond): ?>
                <input type="hidden" name="respond" value="<?= (int) $rTicketInfo['id']; ?>">
            <?php else: ?>
                <div class="mb-6">
                    <label class="form-label" for="title"><?= $language::get('title'); ?></label>
                    <input type="text" id="title" name="title" class="form-control" required>
                </div>
            <?php endif; ?>
            <div class="mb-6">
                <label class="form-label" for="message"><?= $language::get('message'); ?></label>
                <textarea id="message" name="message" class="form-control" rows="5" required></textarea>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="ticket-submit" name="submit_ticket" value="1"><?= $rIsRespond ? $language::get('send') : $language::get('create'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var form = document.getElementById('ticket-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var msg = document.getElementById('message');
            if (!msg.value.trim()) { return; }
            var titleEl = document.getElementById('title');
            if (titleEl && !titleEl.value.trim()) { return; }
            var btn = document.getElementById('ticket-submit');
            btn.disabled = true;
            var fd = new FormData(form);
            fd.append('submit_ticket', '1');
            fetch('post.php?action=ticket', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (d && d.result === true) { window.location.href = d.location || 'tickets'; return; }
                    btn.disabled = false;
                    xcToast(errText, 'error');
                })
                .catch(function() { btn.disabled = false; xcToast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
