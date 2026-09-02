<?php

/**
 * Access code add / edit (Vuexy). Full-page form reached from the codes table
 * (href="code?id=X"). Vuexy vertical layout — each section is its own card:
 * Details (code + generate, access type, enabled), Groups (per-group checkboxes),
 * Restrictions (allowed-IP whitelist). Posts to post.php?action=code via fetch;
 * on success returns to the codes list.
 */

use XcVm\Core\Auth\AuthRepository;
use XcVm\Domain\User\GroupService;

$rIsEdit = isset($rCode);
$rCodeGroups = ($rIsEdit && !empty($rCode['groups'])) ? (json_decode((string) $rCode['groups'], true) ?: []) : [];
$rWhitelist = ($rIsEdit && !empty($rCode['whitelist'])) ? (json_decode((string) $rCode['whitelist'], true) ?: []) : [];
$rTypes = ['Admin', 'Reseller', 'Ministra', 'Admin API', 'Reseller API', 6 => 'Web Player'];
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
        <div class="card-header">
            <h5 class="mb-0"><?= $language::get('details'); ?></h5>
        </div>
        <div class="card-body">
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
        </div>
    </div>

    <div class="card mb-6">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= $language::get('groups'); ?></h5>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-label-secondary" id="grp-all"><?= $language::get('select_all'); ?></button>
                <button type="button" class="btn btn-label-secondary" id="grp-none"><?= $language::get('deselect_all'); ?></button>
            </div>
        </div>
        <div class="card-body">
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
    </div>

    <div class="card mb-6">
        <div class="card-header">
            <h5 class="mb-0"><?= $language::get('restrictions'); ?></h5>
        </div>
        <div class="card-body">
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
                alert('Please enter a valid IP address.');
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
                    alert(errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    alert(errText);
                });
        });
    })();
</script>
</body>

</html>