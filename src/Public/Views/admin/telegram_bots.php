<?php

/**
 * Telegram Bots Management View (Admin).
 *
 * Provides overview cards for all configured Telegram bots, quick test broadcasts,
 * real-time status toggles, deletion, metrics KPIs, and broadcast logs modal.
 */

$rBots = $bots ?? [];
$rStats = $stats ?? ['total_bots' => 0, 'active_bots' => 0, 'total_sent' => 0, 'last_sent_at' => null];
$rLogs = $recentLogs ?? [];
?>

<div class="container-xxl flex-grow-1 container-p-y">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-brand-telegram text-primary fs-2"></i>
                <span><?= $language::get('telegram_bots'); ?></span>
                <span class="badge bg-label-primary rounded-pill fs-7"><?= count($rBots); ?> <?= $language::get('total_bots'); ?></span>
            </h4>
            <p class="text-muted mb-0">
                <?= $language::get('telegram_bots_desc'); ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#broadcastLogsModal">
                <i class="icon-base ti tabler-history"></i>
                <span><?= $language::get('broadcast_history'); ?></span>
            </button>
            <a href="telegram_bot" class="btn btn-primary d-flex align-items-center gap-1 shadow-sm">
                <i class="icon-base ti tabler-plus"></i>
                <span><?= $language::get('add_telegram_bot'); ?></span>
            </a>
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
                                            <?php if (!empty($b['bot_username'])): ?>
                                                <a href="https://t.me/<?= urlencode((string)$b['bot_username']); ?>" target="_blank" class="text-muted fs-7 text-decoration-none">
                                                    <i class="icon-base ti tabler-at fs-7"></i><?= htmlspecialchars((string)$b['bot_username']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted fs-7">Token ID: #<?= $botId; ?></span>
                                            <?php endif; ?>
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

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
(function() {
    function toast(type, msg) {
        if (window.xcToast) {
            window.xcToast(msg, type);
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: type === 'error' ? 'error' : 'success',
                title: msg,
                timer: 3000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        } else {
            alert(msg);
        }
    }

    // Instant Client-side Search
    var searchInput = document.getElementById('botSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value.toLowerCase().trim();
            var cards = document.querySelectorAll('.bot-card-wrapper');
            cards.forEach(function(card) {
                var name = card.getAttribute('data-name') || '';
                var user = card.getAttribute('data-user') || '';
                var chat = card.getAttribute('data-chat') || '';
                if (name.includes(val) || user.includes(val) || chat.includes(val)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    // Toggle Bot Status Switch
    document.querySelectorAll('.js-toggle-status').forEach(function(el) {
        el.addEventListener('change', function() {
            var id = this.getAttribute('data-id');
            var isChecked = this.checked;

            fetch('./api?action=telegram_bot_toggle&id=' + encodeURIComponent(id), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.result) {
                    toast('success', data.message || 'Status updated');
                } else {
                    el.checked = !isChecked; // revert
                    toast('error', data.message || 'Failed to update status');
                }
            })
            .catch(function() {
                el.checked = !isChecked;
                toast('error', 'Network error');
            });
        });
    });

    // Test Broadcast Action
    document.querySelectorAll('.js-btn-test').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');
            var btn = this;
            var originalHtml = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Sending...';

            fetch('./api?action=telegram_bot_broadcast_test&bot_id=' + encodeURIComponent(id), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                if (data.result) {
                    toast('success', data.message || 'Test broadcast sent successfully!');
                } else {
                    toast('error', data.message || 'Failed to send broadcast');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                toast('error', 'Network connection error');
            });
        });
    });

    // Delete Bot Action
    document.querySelectorAll('.js-btn-delete').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');
            var cardWrapper = this.closest('.bot-card-wrapper');

            var confirmPromise = typeof Swal !== 'undefined'
                ? Swal.fire({
                    title: 'Delete Bot?',
                    text: 'Are you sure you want to delete "' + name + '"? All related broadcast history will be removed.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ea5455',
                    cancelButtonColor: '#82868b',
                    confirmButtonText: 'Yes, delete it!'
                }).then(function(r) { return r.isConfirmed; })
                : Promise.resolve(window.confirm('Delete bot ' + name + '?'));

            confirmPromise.then(function(confirmed) {
                if (!confirmed) return;

                fetch('./api?action=telegram_bot_delete&id=' + encodeURIComponent(id), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.result) {
                        toast('success', data.message || 'Bot deleted');
                        if (cardWrapper) {
                            cardWrapper.remove();
                        }
                    } else {
                        toast('error', data.message || 'Failed to delete bot');
                    }
                })
                .catch(function() {
                    toast('error', 'Network connection error');
                });
            });
        });
    });
})();
</script>
</body>

</html>
