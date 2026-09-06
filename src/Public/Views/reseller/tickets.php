<?php

/**
 * Support tickets (Bootstrap 5, reseller). A small client-rendered list: the
 * controller supplies $tickets (TicketRepository::getAll for this reseller) and
 * $statusArray; rows are rendered server-side and a client-side DataTable adds
 * search / sort / paging. Row actions (view / close / reopen) hit
 * ./api?action=ticket. Reached full-page in the reseller new-UI shell.
 *
 * Reseller parity note: the reseller ticket dispatcher only supports close /
 * reopen (no delete), so no delete action is exposed; a "Create Ticket" button
 * replaces the legacy topbar dropdown entry.
 */

// Ticket status -> [Bootstrap label colour].
$rStatusColour = ['secondary', 'warning', 'success', 'warning', 'info', 'primary', 'warning'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h4 class="mb-0"><?= $language::get('tickets'); ?></h4>
    <a href="ticket" class="btn btn-primary"><i class="icon-base ti tabler-plus me-1"></i><?= $language::get('create'); ?></a>
</div>

<div class="card">
    <div class="card-datatable table-responsive">
        <table id="tickets-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('id'); ?></th>
                    <th><?= $language::get('reseller'); ?></th>
                    <th><?= $language::get('title'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th class="text-center"><?= $language::get('created_date'); ?></th>
                    <th class="text-center"><?= $language::get('last_reply'); ?></th>
                    <th class="text-center"><?= $language::get('action'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets ?: [] as $rTicket): ?>
                    <?php $rStatus = (int) $rTicket['status']; ?>
                    <tr id="ticket-<?= (int) $rTicket['id']; ?>">
                        <td class="text-center"><a href="ticket_view?id=<?= (int) $rTicket['id']; ?>" class="text-body"><?= (int) $rTicket['id']; ?></a></td>
                        <td><?= htmlspecialchars((string) $rTicket['username'], ENT_QUOTES); ?></td>
                        <td><a href="ticket_view?id=<?= (int) $rTicket['id']; ?>" class="text-body fw-medium"><?= htmlspecialchars((string) $rTicket['title'], ENT_QUOTES); ?></a></td>
                        <td class="text-center"><span class="badge bg-label-<?= $rStatusColour[$rStatus] ?? 'secondary'; ?>"><?= htmlspecialchars((string) ($statusArray[$rStatus] ?? ''), ENT_QUOTES); ?></span></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rTicket['created'], ENT_QUOTES); ?></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rTicket['last_reply'], ENT_QUOTES); ?></td>
                        <td class="text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item" href="ticket_view?id=<?= (int) $rTicket['id']; ?>"><i class="icon-base ti tabler-eye me-2"></i><?= $language::get('view_ticket'); ?></a>
                                    <?php if ($rStatus > 0): ?>
                                        <a class="dropdown-item js-ticket" href="javascript:void(0);" data-id="<?= (int) $rTicket['id']; ?>" data-sub="close"><i class="icon-base ti tabler-check me-2"></i><?= $language::get('close'); ?></a>
                                    <?php elseif ($rTicket['member_id'] != $rUserInfo['id']): ?>
                                        <a class="dropdown-item js-ticket" href="javascript:void(0);" data-id="<?= (int) $rTicket['id']; ?>" data-sub="reopen"><i class="icon-base ti tabler-refresh me-2"></i><?= $language::get('re_open'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var errText = <?= json_encode($language::get('error_occured')); ?>;

        var table = $('#tickets-table').DataTable({
            order: [[0, 'desc']],
            columnDefs: [{ orderable: false, targets: [6] }],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        $('#tickets-table tbody').on('click', '.js-ticket', function() {
            var id = this.getAttribute('data-id'), sub = this.getAttribute('data-sub');
            fetch('./api?action=ticket&sub=' + encodeURIComponent(sub) + '&ticket_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d || d.result !== true) { throw new Error('fail'); }
                    window.location.reload();
                })
                .catch(function() { xcToast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
