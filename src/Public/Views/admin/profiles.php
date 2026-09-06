<?php

/**
 * Transcoding profiles (Bootstrap 5). A small client-rendered list:
 * StreamConfigRepository::getTranscodeProfiles() is rendered server-side and a
 * client-side DataTable adds search / sort / paging. Each row shows the codec
 * summary (gpu / video / audio / resolution / logo) and edit / delete actions
 * (delete via ./api?action=profile). Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Stream\StreamConfigRepository;

$rCanEdit = Authorization::check('adv', 'edit_tprofile');
$rFlag = static fn(bool $on): string => $on
    ? '<i class="icon-base ti tabler-circle-check-filled text-success"></i>'
    : '<i class="icon-base ti tabler-minus text-body-secondary"></i>';
?>

<div class="card">
    <div class="card-datatable table-responsive">
        <table id="profiles-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th><?= $language::get('profile_name'); ?></th>
                    <th class="text-center"><?= $language::get('gpu'); ?></th>
                    <th class="text-center"><?= $language::get('video'); ?></th>
                    <th class="text-center"><?= $language::get('audio'); ?></th>
                    <th class="text-center"><?= $language::get('resolution'); ?></th>
                    <th class="text-center"><?= $language::get('logo'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile): ?>
                    <?php
                    $rOpt = json_decode((string) $rProfile['profile_options'], true) ?: [];
                    $rGpu = isset($rOpt['gpu']);
                    $rRes = $rGpu ? (($rOpt['gpu']['resize'] ?? '') ?: str_replace(':', 'x', (string) ($rOpt[9]['val'] ?? ''))) : str_replace(':', 'x', (string) ($rOpt[9]['val'] ?? ''));
                    ?>
                    <tr id="profile-<?= (int) $rProfile['profile_id']; ?>">
                        <td class="fw-medium"><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></td>
                        <td class="text-center"><?= $rFlag($rGpu); ?></td>
                        <td class="text-center"><?= htmlspecialchars((string) (($rOpt['-vcodec'] ?? '') ?: 'None'), ENT_QUOTES); ?></td>
                        <td class="text-center"><?= htmlspecialchars((string) (($rOpt['-acodec'] ?? '') ?: 'None'), ENT_QUOTES); ?></td>
                        <td class="text-center"><?= htmlspecialchars((string) $rRes, ENT_QUOTES); ?></td>
                        <td class="text-center"><?= $rFlag(isset($rOpt[16])); ?></td>
                        <td class="text-center">
                            <?php if ($rCanEdit): ?>
                                <div class="btn-group">
                                    <a href="profile?id=<?= (int) $rProfile['profile_id']; ?>" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                                    <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rProfile['profile_id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
                                </div>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
                            <?php endif; ?>
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
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var delText = <?= json_encode($language::get('profile_delete_confirm') ?: $language::get('delete')); ?>;

        var table = $('#profiles-table').DataTable({
            order: [
                [0, 'asc']
            ],
            columnDefs: [{
                orderable: false,
                targets: [6]
            }],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        function confirmSwal(text) {
            if (window.Swal) {
                return Swal.fire({
                    text: text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'OK',
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-label-secondary ms-2'
                    },
                    buttonsStyling: false
                }).then(function(r) {
                    return r.isConfirmed;
                });
            }
            return Promise.resolve(window.confirm(text));
        }

        $('#profiles-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            confirmSwal(delText).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=profile&sub=delete&profile_id=' + encodeURIComponent(id), {
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
                        table.row($('#profile-' + id)).remove().draw(false);
                    })
                    .catch(function() {
                        xcToast(errText, 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>