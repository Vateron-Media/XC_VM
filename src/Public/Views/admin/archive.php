<?php

/**
 * Recordings / TV Archive (Bootstrap 5). Client-rendered: either the scheduled/completed
 * $rRecordings list or, for a single stream, its $rArchive timeshift segments is echoed as
 * <tbody> with a plain client-side DataTable. Playback opens in a modal iframe; a recording
 * is cancelled via ./api?action=delete_recording, an archive segment is (re)recorded via the
 * record page. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;

$rIsRecordings = !is_null($rRecordings);
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <?php if ($rIsRecordings): ?>
                <?= $language::get('recordings'); ?>
            <?php else: ?>
                <?= htmlspecialchars((string) $rStream['stream_display_name'], ENT_QUOTES); ?> <small class="text-body-secondary">— TV Archive</small>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="archive-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('id'); ?></th>
                    <th class="text-center"><?= $language::get('date'); ?></th>
                    <th class="text-center"><?= $language::get('duration'); ?></th>
                    <th><?= $language::get('title'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th class="text-center"><?= $language::get('player'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rIsRecordings): ?>
                    <?php foreach ($rRecordings as $rItem): ?>
                        <?php
                        $rDuration = $rItem['end'] - $rItem['start'];
                        if ($rItem['status'] == 0 && !$rItem['archive'] && $rItem['end'] < time()) {
                            $rItem['status'] = 3;
                        }
                        $rStatus = [0 => ['secondary', $language::get('waiting')], 1 => ['info', $language::get('recording')], 2 => ['success', $language::get('complete')], 3 => ['danger', $language::get('failed')]][$rItem['status']] ?? ['secondary', ''];
                        ?>
                        <tr id="rec-<?= (int) $rItem['id']; ?>">
                            <td class="text-center"><?= (int) $rItem['id']; ?></td>
                            <td class="text-center text-nowrap"><?= date($rSettings['date_format'] . ' H:i', $rItem['start']); ?></td>
                            <td class="text-center text-nowrap"><?= sprintf('%02dh %02dm', $rDuration / 3600, ($rDuration / 60) % 60); ?></td>
                            <td class="fw-medium"><?= htmlspecialchars((string) $rItem['title'], ENT_QUOTES); ?></td>
                            <td class="text-center"><span class="badge bg-label-<?= $rStatus[0]; ?>"><?= $rStatus[1]; ?></span></td>
                            <td class="text-center">
                                <?php if ($rItem['created_id']): ?>
                                    <button type="button" class="btn btn-sm btn-icon btn-label-info js-play" data-url="./player?type=movie&id=<?= (int) $rItem['created_id']; ?>&container=mp4"><i class="icon-base ti tabler-player-play"></i></button>
                                <?php else: ?>
                                    <button disabled type="button" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-player-play"></i></button>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <?php if ($rItem['created_id']): ?>
                                        <a href="stream_view?id=<?= (int) $rItem['created_id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('view_movie'); ?>"><i class="icon-base ti tabler-movie"></i></a>
                                    <?php else: ?>
                                        <button disabled type="button" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-movie"></i></button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rItem['id']; ?>" title="<?= $language::get('delete_recording'); ?>"><i class="icon-base ti tabler-x"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($rArchive as $rItem): ?>
                        <?php
                        $rDuration = $rItem['end'] - $rItem['start'];
                        $rItem['stream_id'] = RequestManager::get('id');
                        if ($rItem['in_progress']) {
                            $rStatus = ['info', $language::get('in_progress')];
                        } elseif ($rItem['complete']) {
                            $rStatus = ['success', $language::get('complete')];
                        } else {
                            $rStatus = ['warning', $language::get('incomplete')];
                        }
                        ?>
                        <tr>
                            <td class="text-center"><?= (int) $rItem['id']; ?></td>
                            <td class="text-center text-nowrap"><?= date($rSettings['date_format'] . ' H:i', $rItem['start']); ?></td>
                            <td class="text-center text-nowrap"><?= sprintf('%02dh %02dm', $rDuration / 3600, ($rDuration / 60) % 60); ?></td>
                            <td class="fw-medium"><?= htmlspecialchars((string) $rItem['title'], ENT_QUOTES); ?></td>
                            <td class="text-center"><span class="badge bg-label-<?= $rStatus[0]; ?>"><?= $rStatus[1]; ?></span></td>
                            <td class="text-center"><button type="button" class="btn btn-sm btn-icon btn-label-info js-play" data-url="./player?type=timeshift&id=<?= (int) $rStream['id']; ?>&start=<?= (int) $rItem['start']; ?>&duration=<?= (int) ($rDuration / 60); ?>"><i class="icon-base ti tabler-player-play"></i></button></td>
                            <td class="text-center">
                                <?php if (!$rItem['in_progress']): ?>
                                    <a href="record?archive=<?= urlencode(base64_encode(json_encode($rItem))); ?>" class="btn btn-sm btn-icon btn-label-danger" title="<?= $language::get('record') ?: 'Record'; ?>"><i class="icon-base ti tabler-player-record"></i></a>
                                <?php else: ?>
                                    <button disabled type="button" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-player-record"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="playerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $language::get('player'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0"><iframe id="player-frame" src="about:blank" style="width:100%;height:60vh;border:0"></iframe></div>
        </div>
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
        var toast = window.xcToast || function() {};

        var table = $('#archive-table').DataTable({
            order: [
                [1, 'desc']
            ],
            columnDefs: [{
                visible: false,
                targets: [0]
            }, {
                orderable: false,
                targets: [5, 6]
            }],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        var playerModal = document.getElementById('playerModal');
        $('#archive-table tbody').on('click', '.js-play', function() {
            document.getElementById('player-frame').src = this.getAttribute('data-url');
            if (window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(playerModal).show();
            }
        });
        playerModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('player-frame').src = 'about:blank';
        });

        $('#archive-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            (window.xcConfirm ? window.xcConfirm('Cancel and delete this recording?') : Promise.resolve(confirm('Delete this recording?'))).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=delete_recording&id=' + encodeURIComponent(id), {
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
                        table.row($('#rec-' + id)).remove().draw(false);
                        toast('Recording deleted.');
                    })
                    .catch(function() {
                        toast(errText, 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>