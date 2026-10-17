<?php

/**
 * Stream providers (Bootstrap 5). Client-side table: ProviderService::getAll provides
 * the rows, rendered server-side into the DOM, with a client-side datatables-bs5
 * table. Force-reload / delete via api?action=provider&sub=reload|delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Stream\ProviderService;

if (!Authorization::check('adv', 'streams')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    LayoutRenderer::renderFooter('admin');
    echo '</body></html>';
    return;
endif;
?>

<?php $rCanProbe = Authorization::check('adv', 'add_stream') || Authorization::check('adv', 'edit_stream'); ?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('stream_providers'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="providers-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('provider'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('connections'); ?></th>
                    <th><?= $language::get('streams'); ?></th>
                    <th><?= $language::get('movies'); ?></th>
                    <th><?= $language::get('series'); ?></th>
                    <th><?= $language::get('expires'); ?></th>
                    <th><?= $language::get('last_changed'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (ProviderService::getAll() as $rProvider): ?>
                    <?php
                    $rData = json_decode($rProvider['data'] ?? '[]', true) ?: [];
                    $rState = !$rProvider['enabled'] ? 0 : (($rProvider['status']) ? 2 : 1);
                    $rStateCls = [0 => 'text-body-secondary', 1 => 'text-danger', 2 => 'text-success'][$rState];
                    $rMax = (int) ($rData['max_connections'] ?? 0);
                    $rActive = (int) ($rData['active_connections'] ?? 0);
                    $rConnCls = $rMax > 0 ? ($rMax * 0.75 < $rActive ? 'danger' : ($rMax * 0.5 < $rActive ? 'warning' : 'success')) : 'success';
                    $rExp = $rData['exp_date'] ?? 0;
                    $rPreviewAttrs = 'data-id="' . (int) $rProvider['id'] . '" data-name="' . htmlspecialchars((string) $rProvider['name'], ENT_QUOTES) . '" data-updated="' . htmlspecialchars($rProvider['last_changed'] ? date('Y-m-d H:i', (int) $rProvider['last_changed']) : $language::get('never'), ENT_QUOTES) . '"';
                    ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rProvider['id']; ?></td>
                        <td class="text-center" data-order="<?= $rState; ?>"><i class="icon-base ti tabler-square-filled <?= $rStateCls; ?>"></i></td>
                        <td><?= htmlspecialchars((string) $rProvider['name'], ENT_QUOTES); ?><br><small class="text-body-secondary"><?= htmlspecialchars((string) $rProvider['ip'], ENT_QUOTES); ?>:<?= (int) $rProvider['port']; ?></small></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rProvider['username'], ENT_QUOTES); ?></td>
                        <td class="text-center text-nowrap" data-order="<?= $rActive; ?>">
                            <a href="streams?search=<?= urlencode(strtolower((string) $rProvider['ip'])); ?>&filter=1" class="badge bg-label-<?= $rConnCls; ?>"><?= number_format($rActive, 0); ?> / <?= $rMax > 0 ? number_format($rMax, 0) : '&infin;'; ?></a>
                        </td>
                        <td class="text-center" data-order="<?= (int) ($rData['streams'] ?? 0); ?>"><button type="button" class="badge border-0 bg-label-<?= ($rData['streams'] ?? 0) > 0 ? 'info' : 'secondary'; ?> js-channels" <?= $rPreviewAttrs; ?> aria-label="<?= htmlspecialchars($language::get('provider_streams') . ': ' . (string) $rProvider['name'], ENT_QUOTES); ?>"><?= number_format((int) ($rData['streams'] ?? 0), 0); ?></button></td>
                        <td class="text-center" data-order="<?= (int) ($rData['movies'] ?? 0); ?>"><span class="badge bg-label-<?= ($rData['movies'] ?? 0) > 0 ? 'info' : 'secondary'; ?>"><?= number_format((int) ($rData['movies'] ?? 0), 0); ?></span></td>
                        <td class="text-center" data-order="<?= (int) ($rData['series'] ?? 0); ?>"><span class="badge bg-label-<?= ($rData['series'] ?? 0) > 0 ? 'info' : 'secondary'; ?>"><?= number_format((int) ($rData['series'] ?? 0), 0); ?></span></td>
                        <td class="text-center text-nowrap" data-order="<?= (int) ($rExp === -1 ? 0 : $rExp); ?>">
                            <?= $rExp == -1 ? 'Unknown' : ($rExp ? date('Y-m-d', (int) $rExp) : 'Never'); ?>
                        </td>
                        <td class="text-center text-nowrap" data-order="<?= (int) $rProvider['last_changed']; ?>">
                            <?= $rProvider['last_changed'] ? date('Y-m-d H:i', (int) $rProvider['last_changed']) : 'Never'; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <button type="button" class="btn btn-sm btn-icon btn-label-info js-channels" <?= $rPreviewAttrs; ?> title="<?= $language::get('provider_streams'); ?>" aria-label="<?= $language::get('provider_streams'); ?>"><i class="icon-base ti tabler-list-details"></i></button>
                            <a href="provider?id=<?= (int) $rProvider['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-secondary js-reload" data-id="<?= (int) $rProvider['id']; ?>" title="<?= $language::get('force_reload'); ?>"><i class="icon-base ti tabler-refresh"></i></button>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rProvider['id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="provider-channels-modal" tabindex="-1" aria-labelledby="provider-channels-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="provider-channels-title"><?= $language::get('provider_streams'); ?> — <span id="provider-channels-name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $language::get('close'); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="text-body-secondary"><?= $language::get('last_updated'); ?>: <span id="provider-channels-updated"></span></p>
                <div id="provider-channels-error" class="alert alert-danger d-none" role="alert"><?= $language::get('error_occured'); ?></div>
                <div class="table-responsive">
                    <table id="provider-channels-table" class="table w-100">
                        <thead><tr>
                            <th><?= $language::get('id'); ?></th>
                            <th><?= $language::get('stream_name'); ?></th>
                            <th><?= $language::get('category'); ?></th>
                            <th><?= $language::get('epg'); ?></th>
                            <?php if ($rCanProbe): ?><th><?= $language::get('actions'); ?></th><?php endif; ?>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="provider-channels-retry" class="btn btn-label-primary"><?= $language::get('refresh'); ?></button>
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var errMsg = <?= json_encode($language::get('error_occured')); ?>;
        var delMsg = <?= json_encode($language::get('delete') . '?'); ?>;
        var table = jQuery('#providers-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [1, 'asc']
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

        var modalElement = document.getElementById('provider-channels-modal');
        var preview = null;
        var providerId = null;
        var activeRequest = null;
        var requestSequence = 0;
        var sourceRequest = null;
        var sourceSequence = 0;
        var sourceLoading = <?= json_encode($language::get('loading')); ?>;
        var sourceFailed = <?= json_encode($language::get('probe_failed')); ?>;
        var textRenderer = jQuery.fn.dataTable.render.text();

        function loadChannels(data, callback) {
            var sequence = ++requestSequence;
            if (activeRequest) {
                activeRequest.abort();
            }
            var controller = new AbortController();
            activeRequest = controller;
            var timeout = setTimeout(function() { controller.abort(); }, 15000);
            var error = document.getElementById('provider-channels-error');
            error.classList.add('d-none');
            var params = new URLSearchParams({
                action: 'provider_streams', provider_id: providerId, stream_type: 'live',
                draw: data.draw, start: data.start, length: data.length,
                'search[value]': data.search.value
            });
            return fetch('./api?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal
            }).then(function(response) {
                if (!response.ok) { throw new Error('Request failed'); }
                return response.json();
            }).then(function(result) {
                if (!result || !Array.isArray(result.data) || result.error) { throw new Error('Invalid catalogue'); }
                if (sequence === requestSequence) { callback(result); }
            }).catch(function() {
                if (sequence !== requestSequence) { return; }
                error.classList.remove('d-none');
                callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
            }).finally(function() {
                clearTimeout(timeout);
                if (sequence === requestSequence) { activeRequest = null; }
            });
        }

        jQuery('#providers-table tbody').on('click', '.js-channels', function() {
            sourceSequence++;
            if (sourceRequest) { sourceRequest.abort(); sourceRequest = null; }
            providerId = this.getAttribute('data-id');
            document.getElementById('provider-channels-name').textContent = this.getAttribute('data-name');
            document.getElementById('provider-channels-updated').textContent = this.getAttribute('data-updated');
            bootstrap.Modal.getOrCreateInstance(modalElement).show();
            if (preview) {
                document.querySelector('#provider-channels-table tbody').replaceChildren();
                preview.search('').page('first').ajax.reload();
            } else {
                preview = jQuery('#provider-channels-table').DataTable({
                    serverSide: true, processing: true, ordering: false, searchDelay: 350,
                    pageLength: 25, lengthMenu: [25, 50, 100], ajax: loadChannels,
                    language: { emptyTable: <?= json_encode($language::get('no_results')); ?> },
                    columns: [
                        { data: 'stream_id', render: textRenderer, defaultContent: '' },
                        { data: 'stream_display_name', render: textRenderer, defaultContent: '' },
                        { data: 'category_array', render: function(value, type) {
                            var categories;
                            try { categories = JSON.parse(value || '[]'); } catch (e) { categories = []; }
                            var label = Array.isArray(categories) ? categories.filter(function(item) { return typeof item === 'string'; }).join(' / ') : '';
                            return type === 'display' ? textRenderer.display(label) : label;
                        } },
                        { data: 'channel_id', render: textRenderer, defaultContent: '' }
                        <?php if ($rCanProbe): ?>,
                        { data: 'stream_id', orderable: false, searchable: false, render: function(value, type) {
                            var id = Number(value);
                            if (type !== 'display' || !Number.isInteger(id) || id <= 0) { return ''; }
                            return '<button type="button" class="btn btn-sm btn-label-info text-wrap js-provider-source" data-id="' + id + '">' + textRenderer.display(<?= json_encode($language::get('scan_source')); ?>) + '</button>';
                        } }
                        <?php endif; ?>
                    ]
                });
            }
        });
        jQuery('#provider-channels-table tbody').on('click', '.js-provider-source', function() {
            if (sourceRequest) { return; }
            var button = this;
            var sequence = ++sourceSequence;
            button.textContent = sourceLoading + '…';
            button.classList.remove('btn-label-success', 'btn-label-danger');
            button.classList.add('btn-label-info');
            button.setAttribute('aria-live', 'polite');
            button.disabled = true;
            // Use exactly the source probe already used by the Add Stream form.
            sourceRequest = jQuery.ajax({
                url: './api', dataType: 'html', timeout: 45000,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: { action: 'probe_stream', provider_id: providerId, provider_stream_id: button.getAttribute('data-id') }
            }).done(function(html) {
                if (sequence === sourceSequence) {
                    var rows = jQuery('<div>').html(html).find('tr');
                    var online = rows.length > 1;
                    var details = online ? rows.eq(1).find('td').map(function() { return jQuery(this).text().replace(/\s+/g, ' ').trim(); }).get().slice(0, 4).filter(Boolean).join(' · ') : '';
                    button.textContent = online ? <?= json_encode($language::get('online')); ?> + (details ? ' · ' + details : '') : sourceFailed;
                    button.classList.remove('btn-label-info');
                    button.classList.add(online ? 'btn-label-success' : 'btn-label-danger');
                }
            }).fail(function() {
                if (sequence === sourceSequence) { button.textContent = sourceFailed; button.classList.remove('btn-label-info'); button.classList.add('btn-label-danger'); }
            }).always(function() {
                button.disabled = false;
                if (sequence === sourceSequence) { sourceRequest = null; }
            });
        });
        document.getElementById('provider-channels-retry').addEventListener('click', function() {
            if (preview) { preview.ajax.reload(null, false); }
        });
        modalElement.addEventListener('shown.bs.modal', function() {
            if (preview) { preview.columns.adjust(); }
        });
        modalElement.addEventListener('hidden.bs.modal', function() {
            requestSequence++;
            sourceSequence++;
            if (sourceRequest) { sourceRequest.abort(); sourceRequest = null; }
            if (activeRequest) { activeRequest.abort(); activeRequest = null; }
        });

        function call(sub, id) {
            return fetch('./api?action=provider&sub=' + sub + '&id=' + encodeURIComponent(id), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(r) {
                return r.json();
            });
        }
        jQuery('#providers-table tbody').on('click', '.js-reload', function() {
            call('reload', this.getAttribute('data-id')).catch(function() {});
        });
        jQuery('#providers-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) {
                return;
            }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) {
                    return;
                }
                call('delete', id)
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
    })();
</script>
</body>

</html>