<?php

/**
 * Ticket response (Bootstrap 5). A single-message reply form for a support
 * ticket, reached from the ticket view in the new-UI shell. Posts respond
 * (ticket id) + message to post.php?action=ticket (UserService::process, the
 * respond branch); on success it returns to the ticket conversation.
 */
?>

<div class="d-flex align-items-center mb-4">
    <a href="ticket_view?id=<?= (int) $rTicket['id']; ?>" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $language::get('ticket_response'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="ticket-form" autocomplete="off">
            <input type="hidden" name="respond" value="<?= (int) $rTicket['id']; ?>">
            <div class="mb-6">
                <label class="form-label" for="message"><?= $language::get('message'); ?></label>
                <textarea id="message" name="message" class="form-control" rows="5" required></textarea>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="ticket-submit"><?= $language::get('send') ?: 'Send'; ?></button>
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
        var ticketId = <?= (int) $rTicket['id']; ?>;
        document.getElementById('ticket-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!document.getElementById('message').value.trim()) { return; }
            var btn = document.getElementById('ticket-submit');
            btn.disabled = true;
            fetch('post.php?action=ticket', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) { window.location.href = 'ticket_view?id=' + ticketId; return; }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() { btn.disabled = false; alert(errText); });
        });
    })();
</script>
</body>

</html>
