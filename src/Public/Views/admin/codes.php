<?php

/**
 * Access codes (Bootstrap 5). Client-side table: AuthRepository::getAllCodes provides
 * the rows, rendered server-side into the DOM, with a client-side datatables-bs5
 * table. Delete via api?action=code&sub=delete.
 */

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'add_code')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

// Access-code type labels (matches the code form's type select).
$rCodeTypes = [0 => 'Admin', 1 => 'Reseller', 2 => 'Ministra', 3 => 'Admin API', 4 => 'Reseller API', 6 => 'Web Player', 7 => 'Active Code Portal'];
$rTypeBadges = [
    0 => 'bg-label-primary',
    1 => 'bg-label-warning',
    2 => 'bg-label-secondary',
    3 => 'bg-label-dark',
    4 => 'bg-label-dark',
    6 => 'bg-label-success',
    7 => 'bg-label-info',
];
?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0"><?= $language::get('access_codes'); ?></h5>
        <a href="code" class="btn btn-sm btn-primary">
            <i class="icon-base ti tabler-plus me-1"></i><?= $language::get('add'); ?> <?= $language::get('access_code'); ?>
        </a>
    </div>
    <div class="card-datatable table-responsive">
        <table id="codes-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('access_code'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('enabled'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (AuthRepository::getAllCodes() as $rCode): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rCode['id']; ?></td>
                        <td class="text-nowrap">
                            <span class="font-monospace fw-semibold"><?= htmlspecialchars((string) $rCode['code'], ENT_QUOTES); ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $rTypeBadges[(int) $rCode['type']] ?? 'bg-label-primary'; ?>">
                                <?= htmlspecialchars($rCodeTypes[(int) $rCode['type']] ?? (string) $rCode['type'], ENT_QUOTES); ?>
                            </span>
                        </td>
                        <td class="text-center" data-order="<?= (int) (bool) $rCode['enabled']; ?>">
                            <i class="icon-base ti tabler-square-filled <?= $rCode['enabled'] ? 'text-success' : 'text-body-secondary'; ?>"></i>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php if ($rCode['enabled']): ?>
                                <a href="/<?= htmlspecialchars((string) $rCode['code'], ENT_QUOTES); ?>/" target="_blank" class="btn btn-sm btn-icon btn-label-info me-1" title="Open Portal / Access Link">
                                    <i class="icon-base ti tabler-external-link"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-icon btn-label-secondary js-copy-link me-1" data-code="<?= htmlspecialchars((string) $rCode['code'], ENT_QUOTES); ?>" title="Copy Full URL">
                                    <i class="icon-base ti tabler-copy"></i>
                                </button>
                            <?php endif; ?>
                            <a href="code?id=<?= (int) $rCode['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('edit_code'); ?>"><i class="icon-base ti tabler-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rCode['id']; ?>" title="<?= $language::get('delete_code'); ?>"><i class="icon-base ti tabler-trash"></i></button>
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
        var errMsg = <?= json_encode($language::get('error_occured')); ?>;
        var delMsg = <?= json_encode($language::get('delete') . '?'); ?>;
        var table = jQuery('#codes-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [1, 'desc']
            ],
            columnDefs: [{
                    targets: 0,
                    orderable: false,
                    searchable: false,
                    className: 'control',
                    responsivePriority: 2
                },
                {
                    targets: 1,
                    visible: false
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });
        jQuery('#codes-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) {
                return;
            }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=code&sub=delete&code_id=' + encodeURIComponent(id), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        if (!d || d.result !== true) {
                            throw new Error('fail');
                        }
                        table.row(row).remove().draw(false);
                    })
                    .catch(function() {
                        xcToast(errMsg, 'error');
                    });
            });
        });

        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function(resolve, reject) {
                try {
                    var textarea = document.createElement('textarea');
                    textarea.value = String(text);
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
        }

        jQuery('#codes-table tbody').on('click', '.js-copy-link', function() {
            var code = this.getAttribute('data-code');
            var fullUrl = window.location.origin + '/' + encodeURIComponent(code) + '/';
            copyToClipboard(fullUrl).then(function() {
                xcToast('Access URL copied to clipboard!', 'success');
            }).catch(function() {
                prompt('Copy Access URL:', fullUrl);
            });
        });
    })();
</script>
</body>

</html>