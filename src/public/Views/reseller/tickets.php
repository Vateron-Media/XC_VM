<div class="wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Tickets</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card-box">
                    <table class="table table-striped table-borderless dt-responsive nowrap w-100" id="tickets-table">
                        <thead>
                            <tr>
                                <th class="text-center">ID</th>
                                <th>Reseller</th>
                                <th>Subject</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Created Date</th>
                                <th class="text-center">Last Reply</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets ?: [] as $rTicket): ?>
                            <tr id="ticket-<?= intval($rTicket['id']) ?>">
                                <td class="text-center"><a href="./ticket_view?id=<?= intval($rTicket['id']) ?>"><?= intval($rTicket['id']) ?></a></td>
                                <td><?= $rTicket['username'] ?></td>
                                <td><?= $rTicket['title'] ?></td>
                                <td class="text-center"><span class="badge badge-<?= ['secondary', 'warning', 'success', 'warning', 'info', 'purple', 'warning'][$rTicket['status']] ?>"><?= $statusArray[$rTicket['status']] ?></span></td>
                                <td class="text-center"><?= $rTicket['created'] ?></td>
                                <td class="text-center"><?= $rTicket['last_reply'] ?></td>
                                <td class="text-center">
                                    <div class="btn-group dropdown">
                                        <a href="javascript: void(0);" class="table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm" data-toggle="dropdown" aria-expanded="false"><i class="mdi mdi-dots-horizontal"></i></a>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a class="dropdown-item" href="./ticket_view?id=<?= intval($rTicket['id']) ?>"><i class="mdi mdi-eye mr-2 text-muted font-18 vertical-middle"></i>View Ticket</a>
                                            <?php if ($rTicket['status'] > 0): ?>
                                            <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= intval($rTicket['id']) ?>, 'close');"><i class="mdi mdi-check-all mr-2 text-muted font-18 vertical-middle"></i>Close</a>
                                            <?php elseif ($rTicket['member_id'] != $rUserInfo['id']): ?>
                                            <a class="dropdown-item" href="javascript:void(0);" onClick="api(<?= intval($rTicket['id']) ?>, 'reopen');"><i class="mdi mdi-check-all mr-2 text-muted font-18 vertical-middle"></i>Re-Open</a>
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
        </div>
    </div>
</div>
