<?php

/**
 * Ticket conversation (Bootstrap 5). Read-only thread of a support ticket:
 * $rTicketInfo['replies'] rendered as a stacked conversation — the reseller's
 * messages on the left, admin replies aligned right — reached from the tickets
 * table in the new-UI shell.
 */
?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center">
            <a href="tickets" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
            <div>
                <h5 class="card-title mb-0"><?= htmlspecialchars((string) $rTicketInfo['title'], ENT_QUOTES); ?></h5>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ((int) ($rTicketInfo['status'] ?? 1) > 0): ?>
                <button type="button" class="btn btn-label-danger js-ticket-close" data-id="<?= (int) $rTicketInfo['id']; ?>" data-sub="close">
                    <i class="icon-base ti tabler-lock me-1"></i><?= $language::get('close') ?: 'Close Ticket'; ?>
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-label-success js-ticket-close" data-id="<?= (int) $rTicketInfo['id']; ?>" data-sub="reopen">
                    <i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('re_open') ?: 'Re-Open'; ?>
                </button>
            <?php endif; ?>
            <a href="ticket?id=<?= (int) $rTicketInfo['id']; ?>" class="btn btn-primary"><i class="icon-base ti tabler-message-reply me-1"></i><?= $language::get('ticket_response') ?: 'Add Response'; ?></a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($rTicketInfo['replies'])): ?>
            <div class="text-body-secondary text-center py-4">—</div>
        <?php else: ?>
            <?php foreach ($rTicketInfo['replies'] as $rReply): ?>
                <?php $rIsAdmin = !empty($rReply['admin_reply']); ?>
                <div class="d-flex mb-4 <?= $rIsAdmin ? 'flex-row-reverse' : ''; ?>">
                    <div class="flex-shrink-0">
                        <span class="badge rounded-circle p-2 bg-label-<?= $rIsAdmin ? 'primary' : 'secondary'; ?>"><i class="icon-base ti tabler-<?= $rIsAdmin ? 'headset' : 'user'; ?>"></i></span>
                    </div>
                    <div class="flex-grow-1 <?= $rIsAdmin ? 'me-3 text-end' : 'ms-3'; ?>" style="max-width:80%">
                        <div class="d-flex align-items-center gap-2 mb-1 <?= $rIsAdmin ? 'justify-content-end' : ''; ?>">
                            <span class="fw-medium"><?= $rIsAdmin ? 'Admin' : htmlspecialchars((string) ($rTicketInfo['user']['username'] ?? ''), ENT_QUOTES); ?></span>
                            <small class="text-body-secondary"><?= date('Y-m-d H:i', (int) $rReply['date']); ?></small>
                        </div>
                        <div class="d-inline-block text-start p-3 rounded bg-label-<?= $rIsAdmin ? 'primary' : 'secondary'; ?>"><?= nl2br(htmlspecialchars((string) $rReply['message'], ENT_QUOTES)); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr class="my-4">
        <form id="admin-reply-form" autocomplete="off">
            <input type="hidden" name="respond" value="<?= (int) $rTicketInfo['id']; ?>">
            <div class="mb-3">
                <label class="form-label fw-medium" for="quick-message"><?= $language::get('ticket_response') ?: 'Quick Response'; ?></label>
                <textarea id="quick-message" name="message" class="form-control" rows="3" placeholder="<?= $language::get('message') ?: 'Type your response here...'; ?>" required></textarea>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="reply-submit">
                    <i class="icon-base ti tabler-send me-1"></i><?= $language::get('send') ?: 'Send'; ?>
                </button>
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
        var errText = <?= json_encode($language::get('error_occured') ?: 'An error occurred'); ?>;
        var form = document.getElementById('admin-reply-form');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var msg = document.getElementById('quick-message');
            if (!msg || !msg.value.trim()) return;
            var btn = document.getElementById('reply-submit');
            btn.disabled = true;
            fetch('post.php?action=ticket', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(dt) {
                if (dt && dt.result !== false) {
                    window.location.reload();
                    return;
                }
                btn.disabled = false;
                if (typeof xcToast === 'function') {
                    xcToast(errText, 'error');
                } else {
                    alert(errText);
                }
            })
            .catch(function() {
                btn.disabled = false;
                if (typeof xcToast === 'function') {
                    xcToast(errText, 'error');
                } else {
                    alert(errText);
                }
            });
        });

        var closeBtn = document.querySelector('.js-ticket-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                var sub = this.getAttribute('data-sub');
                var confirmMsg = sub === 'close'
                    ? <?= json_encode($language::get('close_ticket') ?: 'Are you sure you want to close this ticket?'); ?>
                    : <?= json_encode($language::get('reopen_ticket') ?: 'Are you sure you want to reopen this ticket?'); ?>;

                var doToggle = function() {
                    closeBtn.disabled = true;
                    fetch('./api?action=ticket&sub=' + encodeURIComponent(sub) + '&ticket_id=' + encodeURIComponent(id), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d && d.result === true) {
                            window.location.reload();
                        } else {
                            throw new Error('fail');
                        }
                    })
                    .catch(function() {
                        closeBtn.disabled = false;
                        if (typeof xcToast === 'function') {
                            xcToast(errText, 'error');
                        } else {
                            alert(errText);
                        }
                    });
                };

                if (window.Swal) {
                    Swal.fire({
                        text: confirmMsg,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'OK',
                        customClass: {
                            confirmButton: 'btn btn-primary',
                            cancelButton: 'btn btn-label-secondary ms-2'
                        },
                        buttonsStyling: false
                    }).then(function(r) {
                        if (r.isConfirmed) {
                            doToggle();
                        }
                    });
                } else if (confirm(confirmMsg)) {
                    doToggle();
                }
            });
        }
    })();
</script>
</body>

</html>
