<?php

/**
 * Telegram Bots Management View (Admin).
 *
 * Provides overview cards for all configured Telegram bots, quick test broadcasts,
 * real-time status toggles, deletion, metrics KPIs, and broadcast logs modal.
 */

$language = (!empty($language) && class_exists($language)) ? $language : \XcVm\Core\Localization\Translator::class;
$rBots = $bots ?? [];
$rStats = $stats ?? ['total_bots' => 0, 'active_bots' => 0, 'total_sent' => 0, 'last_sent_at' => null];
$rLogs = $recentLogs ?? [];
?>

<style>
.telegram-hero-card {
    background: linear-gradient(135deg, #0e73b9 0%, #229ed9 60%, #43b9f8 100%);
    color: #fff;
    border-radius: 0.75rem;
    position: relative;
    overflow: hidden;
}
.telegram-hero-card::after {
    content: '';
    position: absolute;
    right: -40px;
    bottom: -50px;
    width: 220px;
    height: 220px;
    background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
}
.bot-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    border: 1px solid rgba(0,0,0,0.06) !important;
}
.bot-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(34, 158, 217, 0.18) !important;
    border-color: rgba(34, 158, 217, 0.35) !important;
}
.status-pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
    background: #28c76f;
    box-shadow: 0 0 0 0 rgba(40, 199, 111, 0.7);
    animation: tg-pulse 2s infinite;
}
@keyframes tg-pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(40, 199, 111, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(40, 199, 111, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(40, 199, 111, 0); }
}
</style>

<div class="container-xxl flex-grow-1 container-p-y">
    <!-- Premium Hero Header -->
    <div class="card telegram-hero-card border-0 shadow-sm mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="avatar avatar-xl bg-white text-primary rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width: 58px; height: 58px;">
                    <i class="icon-base ti tabler-brand-telegram fs-1" style="color: #229ed9 !important;"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h4 class="fw-bold mb-0 text-white"><?= $language::get('telegram_bots'); ?></h4>
                        <span class="badge bg-white bg-opacity-25 text-black rounded-pill fs-8">Module v1.0.0</span>
                    </div>
                    <p class="mb-0 text-white-50 fs-7">
                        <?= $language::get('telegram_bots_desc'); ?>
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-light d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#broadcastLogsModal">
                    <i class="icon-base ti tabler-history text-primary"></i>
                    <span class="fw-semibold text-dark"><?= $language::get('broadcast_history'); ?></span>
                </button>
                <a href="telegram_bot" class="btn btn-dark d-flex align-items-center gap-2 shadow-sm">
                    <i class="icon-base ti tabler-plus"></i>
                    <span class="fw-semibold"><?= $language::get('add_telegram_bot'); ?></span>
                </a>
            </div>
        </div>
    </div>

    <!-- Stats KPI Cards -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="avatar avatar-md bg-label-primary rounded-3 me-3 d-flex align-items-center justify-content-center">
                        <i class="icon-base ti tabler-robot fs-3"></i>
                    </div>
                    <div>
                        <span class="text-muted fs-7 d-block"><?= $language::get('total_bots'); ?></span>
                        <h4 class="mb-0 fw-bold"><?= (int)$rStats['total_bots']; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="avatar avatar-md bg-label-success rounded-3 me-3 d-flex align-items-center justify-content-center">
                        <i class="icon-base ti tabler-activity fs-3"></i>
                    </div>
                    <div>
                        <span class="text-muted fs-7 d-block"><?= $language::get('active_bots'); ?></span>
                        <h4 class="mb-0 fw-bold text-success"><?= (int)$rStats['active_bots']; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="avatar avatar-md bg-label-info rounded-3 me-3 d-flex align-items-center justify-content-center">
                        <i class="icon-base ti tabler-send fs-3"></i>
                    </div>
                    <div>
                        <span class="text-muted fs-7 d-block"><?= $language::get('broadcasts_sent'); ?></span>
                        <h4 class="mb-0 fw-bold"><?= number_format((int)$rStats['total_sent']); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="avatar avatar-md bg-label-warning rounded-3 me-3 d-flex align-items-center justify-content-center">
                        <i class="icon-base ti tabler-clock fs-3"></i>
                    </div>
                    <div>
                        <span class="text-muted fs-7 d-block"><?= $language::get('last_broadcast'); ?></span>
                        <span class="fw-semibold text-truncate d-block fs-7">
                            <?= !empty($rStats['last_sent_at']) ? date('M d, H:i', (int)$rStats['last_sent_at']) : 'Never'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bots Search & Filter Toolbar -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-6">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text"><i class="icon-base ti tabler-search"></i></span>
                        <input type="text" id="botSearchInput" class="form-control" placeholder="Search bots by name, username, or channel...">
                    </div>
                </div>
                <div class="col-12 col-md-6 text-md-end">
                    <span class="text-muted fs-7">
                        <i class="icon-base ti tabler-info-circle text-primary me-1"></i>
                        Movies broadcast automatically when downloads finish with <code>stream_status = 0</code>.
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Bots List -->
    <?php if (empty($rBots)): ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body py-5">
                <div class="avatar avatar-xl bg-label-primary mx-auto mb-3" style="width:72px;height:72px;">
                    <i class="icon-base ti tabler-brand-telegram fs-1 text-primary"></i>
                </div>
                <h5 class="mb-1 text-muted"><?= $language::get('no_bots_found'); ?></h5>
                <p class="text-body-secondary mb-4"><?= $language::get('no_bots_desc'); ?></p>
                <a href="telegram_bot" class="btn btn-primary shadow-sm">
                    <i class="icon-base ti tabler-plus me-1"></i> <?= $language::get('add_first_bot'); ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4" id="botsContainer">
            <?php foreach ($rBots as $b): ?>
                <?php
                $botId = (int)$b['id'];
                $isActive = (int)$b['status'] === 1;
                $types = $b['content_types_array'] ?? [];
                $catArray = $b['categories_array'] ?? [];
                $hasCats = !empty($catArray);
                ?>
                <div class="col-12 col-lg-6 col-xl-4 bot-card-wrapper" data-name="<?= strtolower(htmlspecialchars((string)$b['name'], ENT_QUOTES)); ?>" data-user="<?= strtolower(htmlspecialchars((string)($b['bot_username'] ?? ''), ENT_QUOTES)); ?>" data-chat="<?= strtolower(htmlspecialchars((string)$b['chat_id'], ENT_QUOTES)); ?>">
                    <div class="card h-100 border-0 shadow-sm bot-card transition-all">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <!-- Top section: Header & Status -->
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar avatar-md bg-label-primary rounded-3 flex-shrink-0 d-flex align-items-center justify-content-center">
                                            <i class="icon-base ti tabler-brand-telegram fs-2 text-primary"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0 fw-bold text-truncate" style="max-width: 190px;" title="<?= htmlspecialchars((string)$b['name']); ?>">
                                                <?= htmlspecialchars((string)$b['name']); ?>
                                            </h5>
                                            <div class="d-flex align-items-center gap-1">
                                                <?php if ($isActive): ?>
                                                    <span class="status-pulse-dot" title="Active"></span>
                                                <?php else: ?>
                                                    <span class="badge bg-label-secondary rounded-pill fs-8">Paused</span>
                                                <?php endif; ?>
                                                <?php if (!empty($b['bot_username'])): ?>
                                                    <a href="https://t.me/<?= urlencode((string)$b['bot_username']); ?>" target="_blank" class="text-muted fs-7 text-decoration-none">
                                                        <i class="icon-base ti tabler-at fs-7"></i><?= htmlspecialchars((string)$b['bot_username']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted fs-7">Token ID: #<?= $botId; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch m-0" title="<?= $isActive ? 'Bot is Active' : 'Bot is Paused'; ?>">
                                        <input class="form-check-input js-toggle-status" type="checkbox" role="switch" data-id="<?= $botId; ?>" <?= $isActive ? 'checked' : ''; ?>>
                                    </div>
                                </div>

                                <!-- Target Channel Badge -->
                                <div class="mb-3 p-2 bg-label-secondary rounded-2 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2 overflow-hidden">
                                        <i class="icon-base ti tabler-broadcast text-primary fs-5 flex-shrink-0"></i>
                                        <span class="text-truncate fw-semibold fs-7" title="<?= htmlspecialchars((string)$b['chat_id']); ?>">
                                            <?= htmlspecialchars((string)$b['chat_id']); ?>
                                        </span>
                                    </div>
                                    <button type="button" class="btn btn-xs btn-outline-secondary p-1 border-0" onclick="navigator.clipboard.writeText('<?= addslashes((string)$b['chat_id']); ?>'); toast('success', 'Chat ID copied!');" title="Copy Chat ID">
                                        <i class="icon-base ti tabler-copy fs-6"></i>
                                    </button>
                                </div>

                                <!-- Content Tags & Rules -->
                                <div class="mb-3">
                                    <span class="text-muted fs-8 text-uppercase fw-semibold d-block mb-1">Broadcasting:</span>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if (in_array('movies', $types, true) || (int)$b['notify_on_movie_complete'] === 1): ?>
                                            <span class="badge bg-label-primary rounded-pill fs-8">
                                                <i class="icon-base ti tabler-movie me-1"></i> Movies (VOD)
                                            </span>
                                        <?php endif; ?>
                                        <?php if (in_array('episodes', $types, true) || (int)$b['notify_on_episode'] === 1): ?>
                                            <span class="badge bg-label-info rounded-pill fs-8">
                                                <i class="icon-base ti tabler-device-tv me-1"></i> TV Episodes
                                            </span>
                                        <?php endif; ?>
                                        <?php if (in_array('live', $types, true) || (int)$b['notify_on_live'] === 1): ?>
                                            <span class="badge bg-label-success rounded-pill fs-8">
                                                <i class="icon-base ti tabler-live-photo me-1"></i> Live TV
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Category & Media Mode -->
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <div class="p-2 border rounded-2 bg-light-subtle">
                                            <span class="text-muted fs-8 d-block">Scope:</span>
                                            <span class="fw-semibold fs-7">
                                                <?= $hasCats ? count($catArray) . ' Categories' : 'All Categories'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="p-2 border rounded-2 bg-light-subtle">
                                            <span class="text-muted fs-8 d-block">Media:</span>
                                            <span class="fw-semibold fs-7 text-capitalize">
                                                <?= htmlspecialchars((string)$b['image_type']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($b['last_error'])): ?>
                                    <div class="alert alert-danger p-2 mb-3 fs-8 d-flex align-items-center gap-2">
                                        <i class="icon-base ti tabler-alert-triangle flex-shrink-0"></i>
                                        <span class="text-truncate" title="<?= htmlspecialchars((string)$b['last_error']); ?>">
                                            <?= htmlspecialchars((string)$b['last_error']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Footer section: Stats & Actions -->
                            <div class="pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center mb-3 fs-8 text-muted">
                                    <span>Sent: <b class="text-body"><?= number_format((int)$b['total_sent']); ?></b></span>
                                    <span>Last: <b class="text-body"><?= !empty($b['last_sent_at']) ? date('M d, H:i', (int)$b['last_sent_at']) : 'Never'; ?></b></span>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-label-primary flex-grow-1 js-btn-test" data-id="<?= $botId; ?>" data-name="<?= htmlspecialchars((string)$b['name'], ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-send me-1"></i> <?= $language::get('broadcast_test'); ?>
                                    </button>
                                    <a href="telegram_bot?id=<?= $botId; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="Edit Bot (Wizard)">
                                        <i class="icon-base ti tabler-edit"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-icon btn-label-danger js-btn-delete" data-id="<?= $botId; ?>" data-name="<?= htmlspecialchars((string)$b['name'], ENT_QUOTES); ?>" title="Delete Bot">
                                        <i class="icon-base ti tabler-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Broadcast Logs Modal -->
<div class="modal fade" id="broadcastLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-history text-primary"></i>
                    <span>Broadcast History Logs</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 fs-7">
                        <thead class="table-light">
                            <tr>
                                <th>Status</th>
                                <th>Bot</th>
                                <th>Media Title</th>
                                <th>Chat ID</th>
                                <th>Type</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <?php if (empty($rLogs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No broadcasts recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rLogs as $log): ?>
                                    <?php
                                    $isSent = $log['status'] === 'sent';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($isSent): ?>
                                                <span class="badge bg-label-success">Sent</span>
                                            <?php else: ?>
                                                <span class="badge bg-label-danger" title="<?= htmlspecialchars((string)$log['details']); ?>">Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold text-truncate" style="max-width:140px;"><?= htmlspecialchars((string)($log['bot_name'] ?? 'Bot #' . $log['bot_id'])); ?></td>
                                        <td class="text-truncate fw-bold" style="max-width:180px;"><?= htmlspecialchars((string)$log['title']); ?></td>
                                        <td class="text-muted"><?= htmlspecialchars((string)$log['chat_id']); ?></td>
                                        <td><span class="badge bg-label-secondary text-uppercase fs-8"><?= htmlspecialchars((string)$log['content_type']); ?></span></td>
                                        <td class="text-muted fs-8"><?= htmlspecialchars((string)$log['sent_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

