<div class="wrapper boxed-layout">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <?php if (isset($rTicketInfo)): ?>
                    <h4 class="page-title">Ticket Response</h4>
                    <?php else: ?>
                    <h4 class="page-title">Create Ticket</h4>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rTicketInfo)): ?>
                            <input type="hidden" name="respond" value="<?= intval($rTicketInfo['id']) ?>" />
                            <?php endif; ?>
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#ticket-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="ticket-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <?php if (!isset($rTicketInfo)): ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="title">Subject</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="title" name="title" value="" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="message">Message</label>
                                                    <div class="col-md-8">
                                                        <textarea id="message" name="message" class="form-control" rows="3" placeholder="" required data-parsley-trigger="change"></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_ticket" type="submit" class="btn btn-primary" value="Create" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
