<?php

/**
 * Active Code voucher details — modal body partial.
 *
 * Rendered server-side by ActiveCodeAjaxController::details() and injected into
 * #modal-details-body. Receives a single $d array (see the controller for keys).
 * All values are escaped here so the JS side only does an .html() injection.
 */

/** @var array<string, mixed> $d */

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);

$status = (int) ($d['status'] ?? 0);
$statusBadge = $status === 1 ? 'bg-success' : ($status === 2 ? 'bg-primary' : 'bg-danger');
?>
<div class="row g-4">
    <div class="col-12 col-md-6">
        <div class="p-3 bg-light-subtle rounded-3 border">
            <label class="small text-muted text-uppercase fw-semibold d-block"><?= $language::get('ac_activation_code') ?></label>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="fs-4 fw-bold font-monospace text-primary"><?= $e($d['code']); ?></span>
                <button class="btn btn-sm btn-outline-primary btn-copy-code" data-code="<?= $e($d['code']); ?>"><i class="ti tabler-copy me-1"></i><?= $language::get('copy') ?></button>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="p-3 bg-light-subtle rounded-3 border">
            <label class="small text-muted text-uppercase fw-semibold d-block"><?= $language::get('ac_current_status') ?></label>
            <div class="mt-1">
                <span class="badge <?= $statusBadge; ?> fs-6 px-3 py-2"><?= $e($d['status_text']); ?></span>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('package') ?>:</span>
                <span class="fw-semibold"><?= $e($d['package_name']); ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('ac_batch') ?>:</span>
                <span class="font-monospace"><?= $e($d['batch_name']); ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('max_connections') ?>:</span>
                <span class="fw-semibold"><?= $e($d['max_connections']); ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('ac_device_mac') ?>:</span>
                <span class="font-monospace"><?= $e($d['mac']); ?></span>
            </li>
        </ul>
    </div>

    <div class="col-12 col-md-6">
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('ac_countdown_expiry') ?>:</span>
                <span class="fw-semibold <?= $status === 1 ? 'text-info' : 'text-primary'; ?>"><?= $e($d['exp_date']); ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('ac_first_activated') ?>:</span>
                <span><?= $e($d['activated_at']); ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between px-0">
                <span class="text-muted"><?= $language::get('ac_created') ?>:</span>
                <span><?= $e($d['created_at']); ?></span>
            </li>
        </ul>
    </div>

    <div class="col-12">
        <div class="card bg-dark text-white border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-white d-flex align-items-center gap-2 mb-3">
                    <i class="ti tabler-device-tv text-warning"></i><?= $language::get('ac_streaming_xtream_codes_credentials') ?>
                </h6>
                <div class="row g-2 text-start">
                    <div class="col-12 col-md-6">
                        <small class="text-secondary d-block"><?= $language::get('username') ?></small>
                        <div class="font-monospace fw-semibold"><?= $e($d['username']); ?></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <small class="text-secondary d-block"><?= $language::get('password') ?></small>
                        <div class="font-monospace fw-semibold"><?= $e($d['password']); ?></div>
                    </div>
                    <div class="col-12 col-md-8">
                        <small class="text-secondary d-block"><?= $language::get('ac_server_url') ?></small>
                        <div class="font-monospace small"><?= $e($d['portal_url']); ?></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <small class="text-secondary d-block"><?= $language::get('ac_port') ?></small>
                        <div class="font-monospace"><?= $e($d['port']); ?></div>
                    </div>
                </div>
                <hr class="border-secondary my-3">
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= $e($d['m3u_hls']); ?>" class="btn btn-sm btn-outline-light" target="_blank">
                        <i class="ti tabler-download me-1"></i><?= $language::get('ac_download_m3u_hls') ?>
                    </a>
                    <a href="<?= $e($d['m3u_ts']); ?>" class="btn btn-sm btn-outline-light" target="_blank">
                        <i class="ti tabler-download me-1"></i><?= $language::get('ac_download_m3u_ts') ?>
                    </a>
                    <button class="btn btn-sm btn-outline-info btn-copy-code" data-code="<?= $e($d['m3u_hls']); ?>">
                        <i class="ti tabler-copy me-1"></i><?= $language::get('ac_copy_m3u') ?>
                    </button>
                    <?php if (!empty($d['direct_activate_url'])): ?>
                        <button class="btn btn-sm btn-primary btn-copy-code" data-code="<?= $e($d['direct_activate_url']); ?>">
                            <i class="ti tabler-link me-1"></i><?= $language::get('ac_copy_activation_portal_link') ?>
                        </button>
                        <a href="<?= $e($d['direct_activate_url']); ?>" class="btn btn-sm btn-outline-light" target="_blank" title="<?= $language::get('ac_test_subscriber_activation_portal') ?>">
                            <i class="ti tabler-external-link me-1"></i><?= $language::get('ac_open_portal') ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($d['web_player_url'])): ?>
                        <a href="<?= $e($d['web_player_url']); ?>" class="btn btn-sm btn-warning text-dark fw-bold" target="_blank">
                            <i class="ti tabler-player-play me-1"></i><?= $language::get('ac_launch_web_player') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
