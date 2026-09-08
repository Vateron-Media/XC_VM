<?php

/**
 * Ticket conversation (Bootstrap 5). Read-only thread of a support ticket:
 * $rTicketInfo['replies'] rendered as a stacked conversation — the reseller's
 * messages on the left, admin replies aligned right — reached from the tickets
 * table in the new-UI shell.
 */
?>

<div class="card">
    <div class="card-header d-flex align-items-center">
        <a href="tickets" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h5 class="card-title mb-0"><?= htmlspecialchars((string) $rTicketInfo['title'], ENT_QUOTES); ?></h5>
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
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
