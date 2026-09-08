<?php

/**
 * Ticket conversation (Bootstrap 5, reseller). Read-only thread of a support
 * ticket: $rTicketInfo['replies'] rendered as a stacked conversation — the
 * reseller's own messages on the left, panel-owner replies aligned right.
 * Reached from the tickets table in the reseller new-UI shell.
 *
 * Reseller parity note: admin-side replies are labelled "Owner" (the panel
 * owner answering the reseller), matching the legacy reseller wording, and an
 * "Add Response" button (-> ticket?id=...) replaces the legacy topbar entry.
 */
?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
            <a href="tickets" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
            <h5 class="card-title mb-0"><?= htmlspecialchars((string) $rTicketInfo['title'], ENT_QUOTES); ?></h5>
        </div>
        <a href="ticket?id=<?= (int) $rTicketInfo['id']; ?>" class="btn btn-primary"><i class="icon-base ti tabler-message-reply me-1"></i><?= $language::get('ticket_response'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($rTicketInfo['replies'])): ?>
            <div class="text-body-secondary text-center py-4">—</div>
        <?php else: ?>
            <?php foreach ($rTicketInfo['replies'] as $rReply): ?>
                <?php $rIsOwner = !empty($rReply['admin_reply']); ?>
                <div class="d-flex mb-4 <?= $rIsOwner ? 'flex-row-reverse' : ''; ?>">
                    <div class="flex-shrink-0">
                        <span class="badge rounded-circle p-2 bg-label-<?= $rIsOwner ? 'primary' : 'secondary'; ?>"><i class="icon-base ti tabler-<?= $rIsOwner ? 'headset' : 'user'; ?>"></i></span>
                    </div>
                    <div class="flex-grow-1 <?= $rIsOwner ? 'me-3 text-end' : 'ms-3'; ?>" style="max-width:80%">
                        <div class="d-flex align-items-center gap-2 mb-1 <?= $rIsOwner ? 'justify-content-end' : ''; ?>">
                            <span class="fw-medium"><?= $rIsOwner ? 'Owner' : htmlspecialchars((string) ($rTicketInfo['user']['username'] ?? ''), ENT_QUOTES); ?></span>
                            <small class="text-body-secondary"><?= date('Y-m-d H:i', (int) $rReply['date']); ?></small>
                        </div>
                        <div class="d-inline-block text-start p-3 rounded bg-label-<?= $rIsOwner ? 'primary' : 'secondary'; ?>"><?= nl2br(htmlspecialchars((string) $rReply['message'], ENT_QUOTES)); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
</body>

</html>
