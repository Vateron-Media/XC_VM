<?php

/**
 * The "API tokens" card of the admin and reseller profile pages
 * (Core\Auth\ApiTokens): the account's named tokens, a form to make one, and
 * revoking. A new token is shown once; only its hash is kept. Posts to
 * post.php?action=api_tokens (ApiTokens::manage()). In scope: $language,
 * $rUserInfo; $rTokensAdmin (an admin may give a full token raw SQL).
 */

use XcVm\Core\Auth\ApiTokens;

$rTokens = ApiTokens::forUser((int) $rUserInfo['id']);
$rScopeNames = ['full' => $language::get('api_token_scope_full'), 'read' => $language::get('api_token_scope_read'), 'lines' => $language::get('api_token_scope_lines')];
$rWhen = static fn($rTime): string => $rTime ? date('Y-m-d H:i', (int) $rTime) : '—';
?>
<div class="card mt-6" id="api-tokens-card">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('api_tokens'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('api_tokens_intro'); ?></p>
    </div>
    <div class="card-body">
        <?php if ($rTokens !== []): ?>
            <div class="table-responsive mb-6">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th><?= $language::get('name'); ?></th>
                            <th><?= $language::get('api_token_scope'); ?></th>
                            <th><?= $language::get('api_token'); ?></th>
                            <th><?= $language::get('api_token_ips'); ?></th>
                            <th><?= $language::get('api_token_expires'); ?></th>
                            <th><?= $language::get('api_token_last_used'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rTokens as $rToken): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $rToken['name'], ENT_QUOTES); ?></td>
                                <td><?= htmlspecialchars($rScopeNames[$rToken['scope']] ?? (string) $rToken['scope'], ENT_QUOTES); ?><?= !empty($rToken['allow_sql']) ? ' + SQL' : ''; ?></td>
                                <td><code><?= htmlspecialchars((string) $rToken['prefix'], ENT_QUOTES); ?>…</code></td>
                                <td><?= htmlspecialchars((string) ($rToken['ips'] ?: '—'), ENT_QUOTES); ?></td>
                                <td><?= $rWhen($rToken['expires']); ?></td>
                                <td><?= $rWhen($rToken['last_used']); ?><?= $rToken['last_ip'] ? ' · ' . htmlspecialchars((string) $rToken['last_ip'], ENT_QUOTES) : ''; ?></td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-label-danger" data-api-token-revoke="<?= (int) $rToken['id']; ?>"><?= $language::get('api_token_revoke'); ?></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="alert alert-success d-none" id="api-token-new" role="alert">
            <div class="mb-2"><?= $language::get('api_token_created'); ?></div>
            <code class="fs-6" style="user-select: all; word-break: break-all;"></code>
        </div>

        <form id="api-token-form" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="api-token-name"><?= $language::get('name'); ?></label>
                <input type="text" class="form-control" id="api-token-name" name="name" maxlength="64" required>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="api-token-scope"><?= $language::get('api_token_scope'); ?></label>
                <select class="form-select" id="api-token-scope" name="scope">
                    <?php foreach ($rScopeNames as $rScope => $rScopeName): ?>
                        <option value="<?= $rScope; ?>"><?= htmlspecialchars($rScopeName, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="api-token-ips"><?= $language::get('api_token_ips'); ?></label>
                <input type="text" class="form-control" id="api-token-ips" name="ips" placeholder="<?= htmlspecialchars($language::get('api_token_ips_any'), ENT_QUOTES); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="api-token-days"><?= $language::get('api_token_days'); ?></label>
                <input type="number" class="form-control" id="api-token-days" name="days" min="0" max="3650" value="0">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><?= $language::get('api_token_create'); ?></button>
            </div>
            <?php if (!empty($rTokensAdmin)): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="api-token-sql" name="allow_sql" value="1">
                        <label class="form-check-label" for="api-token-sql"><?= $language::get('api_token_allow_sql'); ?></label>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<script>
    (function() {
        var card = document.getElementById('api-tokens-card');
        if (!card) {
            return;
        }
        var toast = window.xcToast || function(text) {
            window.alert(text);
        };
        var errors = <?= json_encode([
            'name' => $language::get('api_token_error_name'),
            'ips' => $language::get('api_token_error_ips'),
            'limit' => $language::get('api_token_error_limit'),
            'error' => $language::get('error_occured'),
        ]); ?>;

        function post(fd) {
            return fetch('post.php?action=api_tokens', {
                method: 'POST',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(r) {
                return r.json();
            });
        }

        document.getElementById('api-token-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var form = this;
            var btn = form.querySelector('button[type="submit"]');
            var fd = new FormData(form);
            fd.append('sub', 'create');
            btn.disabled = true;
            post(fd).then(function(d) {
                if (!d || d.result !== true) {
                    btn.disabled = false;
                    toast(errors[d && d.error] || errors.error, 'error');
                    return;
                }
                // Shown once: the page keeps only its first characters.
                var box = document.getElementById('api-token-new');
                box.querySelector('code').textContent = d.token;
                box.classList.remove('d-none');
                form.classList.add('d-none');
            }).catch(function() {
                btn.disabled = false;
                toast(errors.error, 'error');
            });
        });

        card.querySelectorAll('[data-api-token-revoke]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!window.confirm(<?= json_encode($language::get('api_token_revoke_confirm')); ?>)) {
                    return;
                }
                var fd = new FormData();
                fd.append('sub', 'revoke');
                fd.append('id', btn.getAttribute('data-api-token-revoke'));
                btn.disabled = true;
                post(fd).then(function(d) {
                    if (d && d.result === true) {
                        window.location.reload();
                        return;
                    }
                    btn.disabled = false;
                    toast(errors.error, 'error');
                }).catch(function() {
                    btn.disabled = false;
                    toast(errors.error, 'error');
                });
            });
        });
    })();
</script>
