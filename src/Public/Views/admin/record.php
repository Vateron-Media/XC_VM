<?php

/**
 * Record an event (Bootstrap 5). Two stages: without a resolved stream, pick a channel
 * (select2 ajax streamlist), a start datetime and a duration in minutes and POST to the
 * record page; with a stream + programme, fill in the event details (title, description,
 * poster, categories, bouquets, recording server) and POST to post.php?action=record.
 * Reached full-page in the new-UI shell.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0">Record an Event</h4>
</div>

<?php if (!$rStream): ?>
    <!-- Stage 1: choose channel / start / duration -->
    <div class="card">
        <div class="card-body">
            <form action="record" method="POST" id="record-pick">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="stream_id">Channel</label>
                        <select id="stream_id" name="stream_id" class="form-select"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="start_date">Start</label>
                        <input type="text" class="form-control" id="start_date" name="start_date" value="" autocomplete="off">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="duration">Minutes</label>
                        <input type="text" class="form-control" id="duration" name="duration" value="0">
                    </div>
                </div>
                <div class="text-end mt-4"><button type="submit" class="btn btn-primary">Continue</button></div>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- Stage 2: event details -->
    <?php if (empty($rProgramme['archive']) && $rProgramme['start'] <= time()): ?>
        <div class="alert alert-warning text-center" role="alert">The programme you are intending to record has already started!</div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-datatable table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th class="text-center">Start</th>
                        <th class="text-center">Finish</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-medium"><?= htmlspecialchars((string) $rStream['stream_display_name'], ENT_QUOTES); ?></td>
                        <td class="text-center"><?= date(SettingsManager::get('date_format'), $rProgramme['start']); ?><br><small class="text-body-secondary"><?= date('H:i', $rProgramme['start']); ?></small></td>
                        <td class="text-center"><?= date(SettingsManager::get('date_format'), $rProgramme['end']); ?><br><small class="text-body-secondary"><?= date('H:i', $rProgramme['end']); ?></small></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <form id="record-form">
                <input type="hidden" name="stream_id" value="<?= (int) $rStream['id']; ?>">
                <input type="hidden" name="start" value="<?= (int) $rProgramme['start']; ?>">
                <input type="hidden" name="end" value="<?= (int) $rProgramme['end']; ?>">
                <input type="hidden" name="archive" value="<?= isset($rProgramme['archive']) ? 1 : 0; ?>">
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="title">Event Title</label>
                    <div class="col-md-9"><input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars((string) $rProgramme['title'], ENT_QUOTES); ?>" required></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="description">Event Description</label>
                    <div class="col-md-9"><textarea rows="5" class="form-control" id="description" name="description"><?= htmlspecialchars((string) $rProgramme['description'], ENT_QUOTES); ?></textarea></div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="stream_icon">Poster URL</label>
                    <div class="col-md-9">
                        <div class="input-group">
                            <input type="text" class="form-control" id="stream_icon" name="stream_icon" value="">
                            <button type="button" class="btn btn-outline-secondary" id="poster-preview"><i class="icon-base ti tabler-eye"></i></button>
                        </div>
                    </div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="category_id">Categories</label>
                    <div class="col-md-9">
                        <select name="category_id[]" id="category_id" class="form-select" multiple data-placeholder="Choose…">
                            <?php foreach (CategoryService::getAllByType('movie') as $rCategory): ?><option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="bouquets">Bouquets</label>
                    <div class="col-md-9">
                        <select name="bouquets[]" id="bouquets" class="form-select" multiple data-placeholder="Choose…">
                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?><option value="<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-4">
                    <label class="col-md-3 col-form-label" for="source_id">Recording Server</label>
                    <div class="col-md-9">
                        <select name="source_id" id="source_id" class="form-select">
                            <?php foreach ((is_array($rAvailableServers ?? null) ? $rAvailableServers : []) as $rServerID): ?>
                                <?php $rSrv = ServerRepository::getAll()[$rServerID] ?? null; ?>
                                <?php if ($rSrv): ?><option value="<?= (int) $rSrv['id']; ?>"><?= htmlspecialchars((string) $rSrv['server_name'], ENT_QUOTES); ?> - <?= htmlspecialchars((string) $rSrv['server_ip'], ENT_QUOTES); ?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="text-end"><button type="submit" class="btn btn-primary">Schedule</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="posterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Poster</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center"><img id="poster-img" src="" alt="" style="max-width:100%"></div>
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
        var toast = window.xcToast || function() {};
        var hasStream = <?= $rStream ? 'true' : 'false'; ?>;

        if ($.fn.select2) {
            $('#category_id, #bouquets, #source_id').select2({
                width: '100%'
            });
        }

        // Stage 1: stream select2 (ajax) + datetime picker + validation.
        if (!hasStream) {
            if ($.fn.select2) {
                $('#stream_id').select2({
                    width: '100%',
                    placeholder: 'Search for a stream…',
                    ajax: {
                        url: './api',
                        dataType: 'json',
                        delay: 250,
                        data: function(p) {
                            return {
                                search: p.term,
                                action: 'streamlist',
                                page: p.page
                            };
                        },
                        processResults: function(d, p) {
                            p.page = p.page || 1;
                            return {
                                results: d.items,
                                pagination: {
                                    more: (p.page * 100) < d.total_count
                                }
                            };
                        },
                        cache: true
                    }
                });
            }
            var start = document.getElementById('start_date');
            if (window.flatpickr) {
                window.flatpickr(start, {
                    enableTime: true,
                    dateFormat: 'Y-m-d H:i',
                    minDate: 'today',
                    time_24hr: true
                });
            } else {
                start.setAttribute('placeholder', 'YYYY-MM-DD HH:MM');
            }
            document.getElementById('duration').addEventListener('input', function() {
                this.value = this.value.replace(/[^\d]/g, '');
            });
            document.getElementById('record-pick').addEventListener('submit', function(e) {
                if (!document.getElementById('stream_id').value) {
                    e.preventDefault();
                    toast('Please select a stream.', 'warning');
                    return;
                }
                if (parseInt(document.getElementById('duration').value, 10) <= 0) {
                    e.preventDefault();
                    toast('Please enter a duration in minutes.', 'warning');
                }
            });
        }

        // Stage 2: poster preview + submit.
        var posterBtn = document.getElementById('poster-preview');
        if (posterBtn) {
            posterBtn.addEventListener('click', function() {
                var url = document.getElementById('stream_icon').value;
                if (!url) {
                    return;
                }
                document.getElementById('poster-img').src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(url);
                if (window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('posterModal')).show();
                }
            });
        }
        var form = document.getElementById('record-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                }
                fetch('post.php?action=record', {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(txt) {
                        var d;
                        try {
                            d = JSON.parse(txt);
                        } catch (err) {
                            d = {
                                result: false
                            };
                        }
                        if (d && d.result !== false) {
                            window.location.href = d.location || 'archive';
                            return;
                        }
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                    })
                    .catch(function() {
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                    });
            });
        }
    })();
</script>
</body>

</html>