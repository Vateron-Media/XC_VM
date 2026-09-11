<?php

/**
 * Access code add / edit (Bootstrap 5). Full-page form reached from the codes table
 * (href="code?id=X"). Bootstrap 5 vertical layout — each section is its own card:
 * Details (code + generate, access type, enabled), Groups (per-group checkboxes),
 * Restrictions (allowed-IP whitelist). Posts to post.php?action=code via fetch;
 * on success returns to the codes list.
 */

use XcVm\Core\Auth\AuthRepository;
use XcVm\Domain\User\GroupService;

$rIsEdit = isset($rCode);
$rCodeGroups = ($rIsEdit && !empty($rCode['groups'])) ? (json_decode((string) $rCode['groups'], true) ?: []) : [];
$rWhitelist = ($rIsEdit && !empty($rCode['whitelist'])) ? (json_decode((string) $rCode['whitelist'], true) ?: []) : [];
$rTypes = ['Admin', 'Reseller', 'Ministra', 'Admin API', 'Reseller API', 6 => 'Web Player', 7 => 'Active Code Portal'];
?>

<div class="d-flex align-items-center mb-4">
    <a href="codes" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('access_code'); ?></h4>
</div>

<?php if ($rIsEdit && AuthRepository::getCurrentCode() == $rCode['code']): ?>
    <div class="alert alert-warning" role="alert">
        You are editing the Access Code you're currently using to access the system. Ensure you have set up another access code before disabling or modifying its access rights.
    </div>
<?php endif; ?>

<form id="code-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rCode['id']; ?>">
    <?php endif; ?>

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-groups" role="tab"><i class="icon-base ti tabler-users me-1"></i><?= $language::get('groups'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-restrictions" role="tab"><i class="icon-base ti tabler-shield-lock me-1"></i><?= $language::get('restrictions'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="row mb-6">
                        <div class="col-md-8">
                            <label class="form-label" for="code">Access Code</label>
                            <div class="input-group">
                                <input type="text" maxlength="16" class="form-control" id="code" name="code" required value="<?= $rIsEdit ? htmlspecialchars((string) $rCode['code'], ENT_QUOTES) : ''; ?>">
                                <button class="btn btn-outline-primary" type="button" id="gen-code"><i class="icon-base ti tabler-refresh"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="type">Access Type</label>
                            <select id="type" name="type" class="form-select">
                                <?php foreach ($rTypes as $rTid => $rTname): ?>
                                    <option value="<?= (int) $rTid; ?>" <?= ($rIsEdit && (int) $rCode['type'] === (int) $rTid) ? 'selected' : ''; ?>><?= htmlspecialchars($rTname, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?= (!$rIsEdit || $rCode['enabled'] == 1) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="enabled"><?= $language::get('enabled'); ?></label>
                    </div>

                    <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 mt-4 d-none" id="portal-preview-box">
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mt-1 flex-shrink-0">
                                <i class="icon-base ti tabler-key fs-5" id="preview-box-icon"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="alert-heading fw-bold mb-1" id="preview-box-title">Active Code Portal Direct Access</h6>
                                <p class="mb-2 small text-body-secondary" id="preview-box-desc">
                                    Subscribers can open this URL to input their activation codes, receive Xtream Codes credentials, and download playlists.
                                </p>
                                <div class="d-flex align-items-center gap-2 bg-body p-2 rounded-2 border">
                                    <i class="icon-base ti tabler-link text-primary flex-shrink-0"></i>
                                    <span class="font-monospace small fw-bold text-truncate" id="portal-url-preview"></span>
                                    <button type="button" class="btn btn-xs btn-label-secondary ms-auto flex-shrink-0" id="btn-copy-preview-url" title="Copy URL">
                                        <i class="icon-base ti tabler-copy me-1"></i>Copy URL
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-groups" role="tabpanel">
                    <div class="d-flex justify-content-end mb-4">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-label-secondary" id="grp-all"><?= $language::get('select_all'); ?></button>
                            <button type="button" class="btn btn-label-secondary" id="grp-none"><?= $language::get('deselect_all'); ?></button>
                        </div>
                    </div>
                    <div class="row g-3">
                        <?php foreach (GroupService::getAll() as $rGroup): ?>
                            <div class="col-md-4 col-sm-6">
                                <div class="form-check">
                                    <input class="form-check-input group-checkbox" type="checkbox" name="groups[]" value="<?= (int) $rGroup['group_id']; ?>" id="group-<?= (int) $rGroup['group_id']; ?>" <?= in_array($rGroup['group_id'], $rCodeGroups) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="group-<?= (int) $rGroup['group_id']; ?>"><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-restrictions" role="tabpanel">
                    <label class="form-label" for="ip_field">Allowed IP Addresses</label>
                    <div class="input-group mb-3">
                        <input type="text" id="ip_field" class="form-control" placeholder="0.0.0.0">
                        <button type="button" id="add_ip" class="btn btn-primary"><i class="icon-base ti tabler-plus"></i></button>
                        <button type="button" id="remove_ip" class="btn btn-label-danger"><i class="icon-base ti tabler-trash"></i></button>
                    </div>
                    <select id="whitelist" name="whitelist[]" size="6" class="form-select" multiple>
                        <?php foreach ($rWhitelist as $rIP): ?>
                            <option value="<?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rIP, ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="code-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;

        // Random 16-char hex access code.
        var genCode = function() {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
                out = '';
            for (var i = 0; i < 8; i++) {
                out += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return out;
        };
        document.getElementById('gen-code').addEventListener('click', function() {
            document.getElementById('code').value = genCode();
        });
        <?php if (!$rIsEdit): ?>
            if (!document.getElementById('code').value) {
                document.getElementById('code').value = genCode();
            }
        <?php endif; ?>

        // Dynamic preview for Web Player (6) and Active Code Portal (7)
        var typeSelect = document.getElementById('type');
        var codeInput = document.getElementById('code');
        var previewBox = document.getElementById('portal-preview-box');
        var previewTitle = document.getElementById('preview-box-title');
        var previewDesc = document.getElementById('preview-box-desc');
        var previewUrl = document.getElementById('portal-url-preview');
        var previewIcon = document.getElementById('preview-box-icon');
        var copyPreviewBtn = document.getElementById('btn-copy-preview-url');
        var tabGroupsBtn = document.querySelector('button[data-bs-target="#tab-groups"]');

        function updatePreview() {
            var typeVal = parseInt(typeSelect.value, 10);
            var codeVal = codeInput.value.trim();
            var origin = window.location.origin;
            var fullUrl = origin + '/' + (codeVal ? encodeURIComponent(codeVal) : '...') + '/';

            if (typeVal === 7) {
                previewBox.classList.remove('d-none');
                previewTitle.textContent = 'Active Code Portal (Subscriber Activation)';
                previewDesc.textContent = 'Subscribers open this URL to enter their activation codes, view Xtream Codes credentials, and download playlists.';
                previewIcon.className = 'icon-base ti tabler-key fs-5';
                previewUrl.textContent = fullUrl;
                if (tabGroupsBtn) {
                    tabGroupsBtn.classList.add('disabled', 'opacity-50');
                    tabGroupsBtn.title = 'Groups do not apply to subscriber portals';
                }
            } else if (typeVal === 6) {
                previewBox.classList.remove('d-none');
                previewTitle.textContent = 'Web Player Direct Access';
                previewDesc.textContent = 'Subscribers open this URL to stream channels and VOD directly in their web browser.';
                previewIcon.className = 'icon-base ti tabler-device-tv fs-5';
                previewUrl.textContent = fullUrl;
                if (tabGroupsBtn) {
                    tabGroupsBtn.classList.add('disabled', 'opacity-50');
                    tabGroupsBtn.title = 'Groups do not apply to web players';
                }
            } else {
                previewBox.classList.add('d-none');
                if (tabGroupsBtn) {
                    tabGroupsBtn.classList.remove('disabled', 'opacity-50');
                    tabGroupsBtn.removeAttribute('title');
                }
            }
        }

        typeSelect.addEventListener('change', updatePreview);
        codeInput.addEventListener('input', updatePreview);
        updatePreview();

        if (copyPreviewBtn) {
            copyPreviewBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var txt = previewUrl.textContent;
                if (!txt) return;
                var p = (navigator.clipboard && window.isSecureContext)
                    ? navigator.clipboard.writeText(txt)
                    : new Promise(function(resolve, reject) {
                        try {
                            var textarea = document.createElement('textarea');
                            textarea.value = String(txt);
                            textarea.style.position = 'fixed';
                            textarea.style.left = '-9999px';
                            textarea.style.top = '0';
                            textarea.setAttribute('readonly', '');
                            document.body.appendChild(textarea);
                            textarea.focus();
                            textarea.select();
                            var success = document.execCommand('copy');
                            document.body.removeChild(textarea);
                            success ? resolve() : reject();
                        } catch (err) {
                            reject(err);
                        }
                    });
                p.then(function() {
                    xcToast('Copied portal URL to clipboard!', 'success');
                }).catch(function() {
                    prompt('Copy portal URL:', txt);
                });
            });
        }

        // Group select-all / none.
        document.getElementById('grp-all').addEventListener('click', function() {
            document.querySelectorAll('.group-checkbox').forEach(function(c) {
                c.checked = true;
            });
        });
        document.getElementById('grp-none').addEventListener('click', function() {
            document.querySelectorAll('.group-checkbox').forEach(function(c) {
                c.checked = false;
            });
        });

        // Allowed-IP whitelist add / remove.
        var wl = document.getElementById('whitelist');
        var validIP = function(v) {
            return /^[0-9.]+$/.test(v) || /^[0-9a-fA-F:]+$/.test(v);
        };
        document.getElementById('add_ip').addEventListener('click', function() {
            var f = document.getElementById('ip_field'),
                v = f.value.trim();
            if (!v || !validIP(v)) {
                xcToast('Please enter a valid IP address.', 'warning');
                return;
            }
            var exists = Array.prototype.some.call(wl.options, function(o) {
                return o.value === v;
            });
            if (!exists) {
                wl.add(new Option(v, v));
            }
            f.value = '';
        });
        document.getElementById('remove_ip').addEventListener('click', function() {
            Array.prototype.slice.call(wl.selectedOptions).forEach(function(o) {
                o.remove();
            });
        });

        // Submit → post.php?action=code. Select every whitelist option first so it
        // is included in the FormData (a multi-select only submits selected options).
        document.getElementById('code-form').addEventListener('submit', function(e) {
            e.preventDefault();
            Array.prototype.forEach.call(wl.options, function(o) {
                o.selected = true;
            });
            var btn = document.getElementById('code-submit');
            btn.disabled = true;
            fetch('post.php?action=code', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var dt;
                    try {
                        dt = JSON.parse(txt);
                    } catch (err) {
                        dt = {
                            result: false
                        };
                    }
                    if (dt && dt.result !== false) {
                        window.location.href = dt.location || 'codes';
                        return;
                    }
                    btn.disabled = false;
                    xcToast(errText, 'error');
                })
                .catch(function() {
                    btn.disabled = false;
                    xcToast(errText, 'error');
                });
        });
    })();
</script>
</body>

</html>