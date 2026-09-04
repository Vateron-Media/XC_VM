<?php

/**
 * Stream tools (Bootstrap 5). Four self-contained tools in tabs: DNS replacement and
 * move-streams (posted to post.php?action=stream_tools), URL decrypt (./api?action=
 * decrypt_text) and auto-assign EPG (batched ./api?action=epg_auto_assign, categories from
 * ./api?action=epg_categories). Reached full-page in the new-UI shell.
 */
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('stream_tools'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#dns-replacement" role="tab"><i class="icon-base ti tabler-dna me-1"></i><?= $language::get('dns_replacement'); ?></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#move-streams" role="tab"><i class="icon-base ti tabler-folder-symlink me-1"></i><?= $language::get('move_streams'); ?></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#url-decrypt" role="tab"><i class="icon-base ti tabler-lock-open me-1"></i><?= $language::get('url_decrypt'); ?></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#epg-auto-assign" role="tab"><i class="icon-base ti tabler-calendar-check me-1"></i>Auto-assign EPG</button></li>
        </ul>
        <div class="tab-content p-0">
            <!-- DNS replacement -->
            <div class="tab-pane fade show active" id="dns-replacement" role="tabpanel">
                <form id="dns_form">
                    <input type="hidden" name="replace_dns" value="true">
                    <p class="text-body-secondary">Replace any text within a stream's source (domain, username, password …) across all streams.</p>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="old_dns"><?= $language::get('old_dns'); ?></label>
                        <div class="col-md-9"><input type="text" class="form-control" id="old_dns" name="old_dns" placeholder="http://example.com" required></div>
                    </div>
                    <div class="row mb-4">
                        <label class="col-md-3 col-form-label" for="new_dns"><?= $language::get('new_dns'); ?></label>
                        <div class="col-md-9"><input type="text" class="form-control" id="new_dns" name="new_dns" placeholder="http://newdns.com" required></div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-primary">Replace DNS</button></div>
                </form>
            </div>
            <!-- Move streams -->
            <div class="tab-pane fade" id="move-streams" role="tabpanel">
                <form id="move_form">
                    <input type="hidden" name="move_streams" value="true">
                    <p class="text-body-secondary">Move all streams of the chosen content type from one server to another.</p>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="content_type">Content</label>
                        <div class="col-md-9">
                            <select name="content_type" id="content_type" class="form-select">
                                <?php foreach (['Everything', 'Live Streams', 3 => 'Created Channels', 2 => 'Movies', 5 => 'TV Shows', 4 => 'Radio Stations'] as $rID => $rType): ?>
                                    <option value="<?= $rID; ?>"><?= $rType; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="source_server">Source Server</label>
                        <div class="col-md-9">
                            <select name="source_server" id="source_server" class="form-select">
                                <?php foreach ($rServers as $rServer): ?><option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <label class="col-md-3 col-form-label" for="replacement_server">Replacement Server</label>
                        <div class="col-md-9">
                            <select name="replacement_server" id="replacement_server" class="form-select">
                                <?php foreach ($rServers as $rServer): ?><option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-primary">Move Streams</button></div>
                </form>
            </div>
            <!-- URL decrypt -->
            <div class="tab-pane fade" id="url-decrypt" role="tabpanel">
                <p class="text-body-secondary">Decrypt URLs (or parts of a URL) that your service encrypted.</p>
                <div class="row mb-3">
                    <label class="col-md-3 col-form-label" for="encrypted_text">Encrypted Text</label>
                    <div class="col-md-9"><textarea class="form-control" id="encrypted_text" rows="6"></textarea></div>
                </div>
                <div class="row mb-4">
                    <label class="col-md-3 col-form-label" for="decrypted_text">Decrypted Text</label>
                    <div class="col-md-9"><textarea class="form-control" id="decrypted_text" rows="6" readonly></textarea></div>
                </div>
                <div class="text-end"><button type="button" class="btn btn-primary" id="decrypt-btn">Decrypt Text</button></div>
            </div>
            <!-- Auto-assign EPG -->
            <div class="tab-pane fade" id="epg-auto-assign" role="tabpanel">
                <p class="text-body-secondary">Automatically assigns EPG to live streams with no EPG configured. Suffixes like HD, FHD, SD, 4K are ignored during matching. Streams below the threshold are left unchanged.</p>
                <div class="alert alert-info">
                    <p class="mb-2"><strong>Don't have EPG yet? Use your providers' guide in 3 steps:</strong></p>
                    <ol class="mb-0 ps-3">
                        <li>Go to <strong>Providers</strong>, open a provider and click <strong>Import EPG Source</strong> — this saves the provider's XML guide URL automatically.</li>
                        <li>Go to <strong>EPG</strong> and click <strong>Force Reload</strong> on the newly added source to download the channel list.</li>
                        <li>Come back here, select a category (optional) and click <strong>Auto-assign EPG</strong>.</li>
                    </ol>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium" for="epg_category_id">Category</label>
                    <select id="epg_category_id" class="form-select"><option value="">All categories</option></select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium" for="epg_threshold">Match threshold: <span id="epg_threshold_val">80</span>%</label>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-body-secondary small">50%</span>
                        <input type="range" id="epg_threshold" min="50" max="100" value="80" step="5" class="form-range flex-grow-1">
                        <span class="text-body-secondary small">100%</span>
                    </div>
                    <small class="text-body-secondary">Higher = safer but fewer matches. 80% recommended.</small>
                </div>
                <div id="epg_assign_result" class="alert mb-3" hidden></div>
                <div class="text-end"><button type="button" class="btn btn-primary" id="epg_auto_assign_btn"><i class="icon-base ti tabler-calendar-check me-1"></i>Auto-assign EPG</button></div>
            </div>
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
        if (!$) { return; }
        var toast = window.xcToast || function() {};
        if ($.fn.select2) { $('#content_type, #source_server, #replacement_server').select2({ width: '100%' }); }

        function postForm(form, okMsg) {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            fetch('post.php?action=stream_tools', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (e) { d = { result: false }; }
                    if (btn) { btn.disabled = false; }
                    toast(d && d.result !== false ? okMsg : <?= json_encode($language::get('error_occured')); ?>, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(<?= json_encode($language::get('error_occured')); ?>, 'error'); });
        }
        document.getElementById('dns_form').addEventListener('submit', function(e) { e.preventDefault(); postForm(this, 'DNS replacement complete.'); });
        document.getElementById('move_form').addEventListener('submit', function(e) { e.preventDefault(); postForm(this, 'Streams moved.'); });

        // URL decrypt.
        document.getElementById('decrypt-btn').addEventListener('click', function() {
            var text = document.getElementById('encrypted_text').value;
            var out = document.getElementById('decrypted_text');
            out.value = '';
            if (!text.length) { toast('Please enter data in the encrypted text field.', 'warning'); return; }
            fetch('./api?action=decrypt_text&text=' + encodeURIComponent(text), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) { if (d && d.data) { out.value = d.data.join('\n\n'); } else { toast('Text could not be decrypted.', 'error'); } })
                .catch(function() { toast('Text could not be decrypted.', 'error'); });
        });

        // Threshold slider label.
        document.getElementById('epg_threshold').addEventListener('input', function() { document.getElementById('epg_threshold_val').textContent = this.value; });

        // Auto-assign EPG — load categories on first tab show, then batch-process.
        var epgLoaded = false;
        document.querySelector('[data-bs-target="#epg-auto-assign"]').addEventListener('shown.bs.tab', function() {
            if (epgLoaded) { return; }
            epgLoaded = true;
            fetch('./api?action=epg_categories', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.status === 1 && d.data) {
                        var sel = document.getElementById('epg_category_id');
                        d.data.forEach(function(cat) { var o = document.createElement('option'); o.value = cat.id; o.textContent = cat.category_name; sel.appendChild(o); });
                    }
                }).catch(function() {});
        });
        document.getElementById('epg_auto_assign_btn').addEventListener('click', function() {
            var btn = this, result = document.getElementById('epg_assign_result');
            var totalAssigned = 0, totalSkipped = 0, totalProcessed = 0, grandTotal = 0;
            var threshold = document.getElementById('epg_threshold').value || 80;
            var categoryId = document.getElementById('epg_category_id').value || '';
            btn.disabled = true;
            result.className = 'alert alert-info mb-3';
            result.hidden = false;
            result.textContent = 'Starting…';
            function runBatch(lastId) {
                var url = './api?action=epg_auto_assign&last_id=' + lastId + '&threshold=' + threshold + (categoryId !== '' ? '&category_id=' + encodeURIComponent(categoryId) : '');
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || d.status !== 1) { result.className = 'alert alert-danger mb-3'; result.textContent = 'Error during processing. Please try again.'; btn.disabled = false; return; }
                        if (grandTotal === 0) { grandTotal = d.data.total; }
                        totalAssigned += d.data.assigned; totalSkipped += d.data.skipped; totalProcessed += d.data.batch_size;
                        var pct = grandTotal > 0 ? Math.min(100, Math.round(totalProcessed / grandTotal * 100)) : 100;
                        result.textContent = 'Processing… ' + pct + '% (' + totalProcessed + ' / ' + grandTotal + ')';
                        if (d.data.has_more) { runBatch(d.data.next_last_id); }
                        else { result.className = 'alert alert-success mb-3'; result.textContent = 'Done! ' + totalAssigned + ' assigned — ' + totalSkipped + ' below threshold'; btn.disabled = false; }
                    })
                    .catch(function() { result.className = 'alert alert-danger mb-3'; result.textContent = 'Request failed. Please try again.'; btn.disabled = false; });
            }
            runBatch(0);
        });
    })();
</script>
</body>

</html>
