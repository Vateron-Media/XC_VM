<div class="wrapper boxed-layout-ext">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include __DIR__ . '/topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= htmlspecialchars($rTicketInfo['title']) ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="timeline" dir="ltr">
                    <?php foreach ($rTicketInfo['replies'] ?? [] as $rReply): ?>
                    <article class="timeline-item<?php if (!$rReply['admin_reply']) echo ' timeline-item-left'; ?>">
                        <div class="timeline-desk">
                            <div class="timeline-box">
                                <span class="arrow-alt"></span>
                                <span class="timeline-icon"><i class="mdi mdi-adjust"></i></span>
                                <h4 class="mt-0 font-16"><?php if (!$rReply['admin_reply']) { echo htmlspecialchars($rTicketInfo['user']['username']); } else { echo 'Owner'; } ?></h4>
                                <p class="text-muted"><small><?= date('Y-m-d H:i', $rReply['date']) ?></small></p>
                                <p class="mb-0"><?= $rReply['message'] ?></p>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
