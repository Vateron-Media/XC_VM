<?php

/**
 * Support tickets (Bootstrap 5). A small client-rendered list (not serverSide):
 * TicketRepository::getAll() is rendered server-side into the table body and a
 * client-side DataTable adds search / sort / paging. Row actions (view / close /
 * reopen / delete) hit ./api?action=ticket. Reached full-page from the tickets
 * table in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\User\TicketRepository;

$rCanTicket = Authorization::check('adv', 'ticket');
// Ticket status → [Bootstrap label colour].
$rStatusColour = ['secondary', 'warning', 'success', 'warning', 'info', 'primary', 'warning'];
?>

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
                <?php foreach (TicketRepository::getAll($rUserInfo['id'], true) as $rTicket): ?>
                    <?php $rStatus = (int) $rTicket['status']; ?>
                    <tr id="ticket-<?= (int) $rTicket['id']; ?>">
                        <td class="text-center"><a href="ticket_view?id=<?= (int) $rTicket['id']; ?>" class="text-body"><?= (int) $rTicket['id']; ?></a></td>
                        <td><?= htmlspecialchars((string) $rTicket['username'], ENT_QUOTES); ?></td>
                        <td><a href="ticket_view?id=<?= (int) $rTicket['id']; ?>" class="text-body fw-medium"><?= htmlspecialchars((string) $rTicket['title'], ENT_QUOTES); ?></a></td>
                        <td class="text-center"><span class="badge bg-label-<?= $rStatusColour[$rStatus] ?? 'secondary'; ?>"><?= htmlspecialchars((string) ($rStatusArray[$rStatus] ?? ''), ENT_QUOTES); ?></span></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rTicket['created'], ENT_QUOTES); ?></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rTicket['last_reply'], ENT_QUOTES); ?></td>
                        <td class="text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item" href="ticket_view?id=<?= (int) $rTicket['id']; ?>"><i class="icon-base ti tabler-eye me-2"></i><?= $language::get('view_ticket') ?: 'View Ticket'; ?></a>
                                    <?php if ($rCanTicket): ?>
                                        <?php if ($rStatus > 0): ?>
                                            <a class="dropdown-item js-ticket" href="javascript:void(0);" data-id="<?= (int) $rTicket['id']; ?>" data-sub="close"><i class="icon-base ti tabler-check me-2"></i><?= $language::get('close') ?: 'Close'; ?></a>
                                        <?php else: ?>
                                            <a class="dropdown-item js-ticket" href="javascript:void(0);" data-id="<?= (int) $rTicket['id']; ?>" data-sub="reopen"><i class="icon-base ti tabler-refresh me-2"></i><?= $language::get('re_open') ?: 'Re-Open'; ?></a>
                                        <?php endif; ?>
                                        <a class="dropdown-item text-danger js-ticket" href="javascript:void(0);" data-id="<?= (int) $rTicket['id']; ?>" data-sub="delete"><i class="icon-base ti tabler-trash me-2"></i><?= $language::get('delete'); ?></a>
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
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var delText = <?= json_encode($language::get('delete')); ?>;

        var table = $('#tickets-table').DataTable({
            order: [[0, 'desc']],
            columnDefs: [{ orderable: false, targets: [6] }],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        function confirmSwal(text) {
            if (window.Swal) { return Swal.fire({ text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'OK', customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-label-secondary ms-2' }, buttonsStyling: false }).then(function(r) { return r.isConfirmed; }); }
            return Promise.resolve(window.confirm(text));
        }

        $('#tickets-table tbody').on('click', '.js-ticket', function() {
            var id = this.getAttribute('data-id'), sub = this.getAttribute('data-sub');
            var go = function() {
                fetch('./api?action=ticket&sub=' + encodeURIComponent(sub) + '&ticket_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || d.result !== true) { throw new Error('fail'); }
                        if (sub === 'delete') { table.row($('#ticket-' + id)).remove().draw(false); }
                        else { window.location.reload(); }
                    })
                    .catch(function() { xcToast(errText, 'error'); });
            };
            if (sub === 'delete') { confirmSwal(delText + '?').then(function(ok) { if (ok) { go(); } }); }
            else { go(); }
        });
    })();
</script>
</body>

</html>
