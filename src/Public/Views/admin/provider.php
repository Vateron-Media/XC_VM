<?php

/**
 * Provider (Supplier) add / edit (Vuexy). Full-page form reached from the
 * providers table (href="provider?id=X"). Vuexy vertical layout: a Details card
 * with the connection settings, and — when editing — Available Streams / Movies
 * cards backed by serverSide datatables-bs5 tables that proxy the provider's
 * Xtream API (./api?action=provider_streams) and offer per-row import + copy-URL.
 * Posts to post.php?action=provider via fetch; on success returns to the list.
 */

$rIsEdit = isset($rProvider);
?>

<div class="d-flex align-items-center mb-4">
    <a href="providers" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?> <?= $language::get('provider_name') ?: 'Provider'; ?></h4>
</div>

<form id="provider-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rProvider['id']; ?>">
    <?php endif; ?>

    <div class="card mb-6">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= $language::get('details'); ?></h5>
            <?php if ($rIsEdit): ?>
                <button type="button" class="btn btn-sm btn-label-info" id="import-epg"><i class="icon-base ti tabler-calendar-plus me-1"></i>Import EPG Source</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row mb-6">
                <div class="col-md-6">
                    <label class="form-label" for="name"><?= $language::get('provider_name'); ?></label>
                    <input type="text" class="form-control" id="name" name="name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rProvider['name'], ENT_QUOTES) : ''; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="ip"><?= $language::get('server_ip_domain'); ?></label>
                    <input type="text" class="form-control" id="ip" name="ip" required value="<?= $rIsEdit ? htmlspecialchars((string) $rProvider['ip'], ENT_QUOTES) : ''; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="port"><?= $language::get('broadcast_port'); ?></label>
                    <input type="text" inputmode="numeric" class="form-control" id="port" name="port" required value="<?= $rIsEdit ? htmlspecialchars((string) $rProvider['port'], ENT_QUOTES) : '80'; ?>">
                </div>
            </div>
            <div class="row mb-6">
                <div class="col-md-6">
                    <label class="form-label" for="username"><?= $language::get('username'); ?></label>
                    <input type="text" class="form-control" id="username" name="username" required value="<?= $rIsEdit ? htmlspecialchars((string) $rProvider['username'], ENT_QUOTES) : ''; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password"><?= $language::get('password'); ?></label>
                    <input type="text" class="form-control" id="password" name="password" required value="<?= $rIsEdit ? htmlspecialchars((string) $rProvider['password'], ENT_QUOTES) : ''; ?>">
                </div>
            </div>
            <div class="row g-3">
                <?php
                $rSwitches = [
                    'enabled' => $language::get('enabled'),
                    'ssl'     => $language::get('ssl'),
                    'legacy'  => $language::get('legacy_xc'),
                    'hls'     => $language::get('use_hls'),
                ];
                foreach ($rSwitches as $rKey => $rLabel):
                    $rChecked = $rKey === 'enabled' ? (!$rIsEdit || $rProvider['enabled'] == 1) : ($rIsEdit && $rProvider[$rKey] == 1);
                ?>
                    <div class="col-6 col-md-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="<?= $rKey; ?>" name="<?= $rKey; ?>" value="1" <?= $rChecked ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?= $rKey; ?>"><?= htmlspecialchars((string) $rLabel, ENT_QUOTES); ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="provider-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php if ($rIsEdit): ?>
    <?php
    $rProvScheme = $rProvider['ssl'] ? 'https' : 'http';
    $rProvBase   = $rProvScheme . '://' . $rProvider['ip'] . ':' . $rProvider['port'];
    $rProvExt    = $rProvider['hls'] ? '.m3u8' : ($rProvider['legacy'] ? '.ts' : '');
    ?>
    <div class="card mb-6">
        <div class="card-header"><h5 class="mb-0"><?= $language::get('available_streams'); ?></h5></div>
        <div class="card-datatable table-responsive">
            <table id="datatable-streams" class="table" style="width:100%">
                <thead>
                    <tr>
                        <th><?= $language::get('id'); ?></th>
                        <th><?= $language::get('stream_name'); ?></th>
                        <th><?= $language::get('categories'); ?></th>
                        <th><?= $language::get('modified'); ?></th>
                        <th class="text-center"><?= $language::get('actions'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="mb-0"><?= $language::get('available_movies'); ?></h5></div>
        <div class="card-datatable table-responsive">
            <table id="datatable-movies" class="table" style="width:100%">
                <thead>
                    <tr>
                        <th><?= $language::get('id'); ?></th>
                        <th><?= $language::get('movie_name'); ?></th>
                        <th><?= $language::get('categories'); ?></th>
                        <th><?= $language::get('modified'); ?></th>
                        <th class="text-center"><?= $language::get('actions'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var $ = jQuery;

        // Port: digits only.
        document.getElementById('port').addEventListener('input', function() { this.value = this.value.replace(/[^0-9]/g, ''); });

        // Submit → post.php?action=provider.
        document.getElementById('provider-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('provider-submit');
            btn.disabled = true;
            fetch('post.php?action=provider', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) { window.location.href = dt.location || 'providers'; return; }
                    btn.disabled = false;
                    alert(errText);
                })
                .catch(function() { btn.disabled = false; alert(errText); });
        });

        <?php if ($rIsEdit): ?>
            var p = {
                id: <?= (int) $rProvider['id']; ?>,
                base: <?= json_encode($rProvBase); ?>,
                user: <?= json_encode($rProvider['username']); ?>,
                pass: <?= json_encode($rProvider['password']); ?>,
                ext: <?= json_encode($rProvExt); ?>
            };
            var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
            var fmtCats = function(json) { try { return esc(JSON.parse(json).join(', ')); } catch (e) { return ''; } };
            var fmtDate = function(ts) { var d = new Date(ts * 1000); return d.toISOString().slice(0, 10) + '<br><small class="text-body-secondary">' + d.toISOString().slice(11, 19) + '</small>'; };
            var copyURL = function(url) {
                if (navigator.clipboard) { navigator.clipboard.writeText(url); }
                else { var t = document.createElement('textarea'); t.value = url; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); }
            };

            var initProviderTable = function(tableId, streamType, urlFn, addPage) {
                $('#' + tableId).DataTable({
                    processing: true,
                    serverSide: true,
                    ajax: { url: './api?action=provider_streams&provider_id=' + p.id + '&stream_type=' + streamType, type: 'GET' },
                    columns: [
                        { data: 'stream_id', className: 'text-center' },
                        { data: 'stream_display_name', render: esc },
                        { data: 'category_array', orderable: false, render: function(d) { return fmtCats(d); } },
                        { data: 'modified', className: 'text-nowrap', render: function(d) { return fmtDate(d); } },
                        {
                            data: null, orderable: false, className: 'text-center text-nowrap', render: function(d, t, row) {
                                var url = urlFn(row);
                                var addHref = (streamType === 'live')
                                    ? 'stream?title=' + encodeURIComponent(row.stream_display_name) + '&url=' + encodeURIComponent(url) + '&icon=' + encodeURIComponent(row.stream_icon || '')
                                    : 'movie?title=' + encodeURIComponent(row.stream_display_name) + '&path=' + encodeURIComponent(url);
                                return '<a href="' + esc(addHref) + '" class="btn btn-sm btn-icon btn-label-primary" title="Import"><i class="icon-base ti tabler-plus"></i></a> ' +
                                    '<button type="button" class="btn btn-sm btn-icon btn-label-secondary js-copy" data-url="' + esc(url) + '" title="Copy URL"><i class="icon-base ti tabler-clipboard"></i></button>';
                            }
                        }
                    ],
                    order: [[3, 'desc']],
                    responsive: false,
                    layout: { topStart: 'pageLength', topEnd: 'search' }
                });
            };
            initProviderTable('datatable-streams', 'live', function(row) { return p.base + '/live/' + p.user + '/' + p.pass + '/' + row.stream_id + p.ext; }, 'stream');
            initProviderTable('datatable-movies', 'movie', function(row) { return p.base + '/movie/' + p.user + '/' + p.pass + '/' + row.stream_id + '.' + row.channel_id; }, 'movie');

            $(document).on('click', '.js-copy', function() { copyURL(this.getAttribute('data-url')); });

            // Import the provider's playlist as an EPG source.
            var epgBtn = document.getElementById('import-epg');
            if (epgBtn) {
                epgBtn.addEventListener('click', function() {
                    epgBtn.disabled = true;
                    fetch('./api?action=provider_import_epg&provider_id=' + p.id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d && d.status === 1) { epgBtn.innerHTML = '<i class="icon-base ti tabler-check me-1"></i>EPG Imported'; epgBtn.classList.replace('btn-label-info', 'btn-label-success'); }
                            else { alert(d && d.status === 2 ? 'EPG source already exists.' : ('Error: ' + ((d && d.data) || 'Could not import EPG source.'))); epgBtn.disabled = false; }
                        })
                        .catch(function() { alert(errText); epgBtn.disabled = false; });
                });
            }
        <?php endif; ?>
    })();
</script>
</body>

</html>
