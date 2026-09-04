<?php

/**
 * Created channel add / edit (Bootstrap 5) — the 24/7 channel builder. Reached
 * full-page from the created_channels table and as an iframe modal
 * (created_channel?id=X&modal=1). A created channel assembles an ordered playlist
 * from one of three sources, chosen by the "Selection Type": a 24/7 Series
 * (channel_type 0), a File Browser pick (type 1) or a VOD Selection of movies /
 * episodes (type 2). Tabs shown depend on the type: Details always; Videos for
 * type 1 (file browser + ordered list); Selection + Review for type 2 (a VOD
 * table whose picks flow into an ordered review list). RTMP push and the jstree
 * server tree follow. The ordered playlist is posted as video_files (a JSON array
 * of source strings) plus server_tree_data. select2 tags for categories/bouquets;
 * the file browser and image preview are Bootstrap modals. Posts to
 * post.php?action=created_channel via fetch; posts xcModalSaved in the modal.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Vod\SeriesService;

$rIsEdit  = isset($rChannel);
$rChanCat = $rIsEdit ? (json_decode((string) $rChannel['category_id'], true) ?: []) : [];
$rChanType = (int) ($rProperties['type'] ?? 0);
$rReviewSources = ($rIsEdit && $rChanType === 2) ? (json_decode((string) $rChannel['stream_source'], true) ?: []) : [];
$rVideoSources  = ($rIsEdit && $rChanType === 1) ? (json_decode((string) $rChannel['stream_source'], true) ?: []) : [];
$rSrcPath = static fn(string $s): string => (substr($s, 0, 2) === 's:') ? urldecode(explode(':', $s, 3)[2] ?? '') : $s;
$rRTMPPush = $rIsEdit ? (json_decode((string) $rChannel['external_push'], true) ?: [0 => ['']]) : [0 => ['']];
$rTitle = $rIsEdit ? $rChannel['stream_display_name'] : 'Create Channel';
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="created_channels" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= htmlspecialchars((string) $rTitle, ENT_QUOTES); ?></h4>
    </div>
<?php endif; ?>

<form id="cchannel-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rChannel['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="video_files" id="video_files" value="">
    <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
    <input type="hidden" name="external_push" id="external_push" value="">
    <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="">
    <input type="hidden" name="category_create_list" id="category_create_list" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <li class="nav-item" id="nav-selection" hidden><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-selection" role="tab"><i class="icon-base ti tabler-movie me-1"></i><?= $language::get('selection'); ?></button></li>
                    <li class="nav-item" id="nav-review" hidden><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-review" role="tab"><i class="icon-base ti tabler-list-check me-1"></i><?= $language::get('review'); ?></button></li>
                    <li class="nav-item" id="nav-videos" hidden><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-videos" role="tab"><i class="icon-base ti tabler-video me-1"></i><?= $language::get('videos'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rtmp" role="tab"><i class="icon-base ti tabler-broadcast me-1"></i><?= $language::get('rtmp_push'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-server" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="stream_display_name"><?= $language::get('channel_name'); ?></label>
                        <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rChannel['stream_display_name'], ENT_QUOTES) : ''; ?>">
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="stream_icon"><?= $language::get('channel_logo'); ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="stream_icon" name="stream_icon" value="<?= $rIsEdit ? htmlspecialchars((string) $rChannel['stream_icon'], ENT_QUOTES) : ''; ?>">
                            <button type="button" class="btn btn-label-secondary" id="icon-preview"><i class="icon-base ti tabler-eye"></i></button>
                        </div>
                        <div class="mt-2"><img id="icon-img" src="" alt="" style="max-height:80px" hidden></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="category_id"><?= $language::get('categories'); ?></label>
                        <select name="category_id[]" id="category_id" class="form-select" multiple>
                            <?php foreach (CategoryService::getAllByType('live') as $rCategory): ?>
                                <option value="<?= (int) $rCategory['id']; ?>" <?= in_array((int) $rCategory['id'], array_map('intval', $rChanCat), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="bouquets"><?= $language::get('bouquets'); ?></label>
                        <select name="bouquets[]" id="bouquets" class="form-select" multiple>
                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                <option value="<?= (int) $rBouquet['id']; ?>" <?= ($rIsEdit && in_array((int) $rChannel['id'], array_map('intval', json_decode((string) $rBouquet['bouquet_channels'], true) ?: []), true)) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="channel_type">Selection Type</label>
                        <select name="channel_type" id="channel_type" class="form-select">
                            <?php foreach (['Series', 'File Browser', 'VOD Selection'] as $rTid => $rTt): ?>
                                <option value="<?= $rTid; ?>" <?= ($rChanType === $rTid) ? 'selected' : ''; ?>><?= $rTt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6" id="series-row" hidden>
                        <label class="form-label" for="series_no">24/7 Series</label>
                        <select name="series_no" id="series_no" class="form-select">
                            <option value="0"><?= $language::get('select_a_series'); ?></option>
                            <?php foreach (SeriesService::getAll() as $rSeries): ?>
                                <option value="<?= (int) $rSeries['id']; ?>" <?= ($rIsEdit && (int) $rChannel['series_no'] === (int) $rSeries['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rSeries['title'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="alert alert-warning d-none" role="alert" id="transcode-warning">Not all videos stream live as-is (no video/audio may show); transcode if needed. Symlinks are only created on the file's origin server.</div>
                    <div class="mb-6">
                        <label class="form-label" for="transcode_profile_id">Transcoding Profile</label>
                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                            <option value="0" <?= (!$rIsEdit || (int) $rChannel['transcode_profile_id'] === 0) ? 'selected' : ''; ?>><?= $language::get('quick_transcode_copy_codecs'); ?></option>
                            <option value="-1" <?= ($rIsEdit && (int) $rChannel['transcode_profile_id'] === -1) ? 'selected' : ''; ?>><?= $language::get('dont_transcode_symlink_files'); ?></option>
                            <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                <option value="<?= (int) $rProfile['profile_id']; ?>" <?= ($rIsEdit && (int) $rChannel['transcode_profile_id'] === (int) $rProfile['profile_id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-6">
                        <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="rtmp_output" name="rtmp_output" value="1" <?= ($rIsEdit && $rChannel['rtmp_output'] == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="rtmp_output">Output RTMP</label></div></div>
                        <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="allow_record" name="allow_record" value="1" <?= (!$rIsEdit || $rChannel['allow_record'] == 1) ? 'checked' : ''; ?>><label class="form-check-label" for="allow_record">Allow Recording</label></div></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="custom_sid">Custom Channel SID</label>
                        <input type="text" class="form-control" id="custom_sid" name="custom_sid" value="<?= $rIsEdit ? htmlspecialchars((string) $rChannel['custom_sid'], ENT_QUOTES) : ''; ?>">
                    </div>
                    <div>
                        <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rChannel['notes'], ENT_QUOTES) : ''; ?></textarea>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-selection" role="tabpanel">
                    <div class="row mb-3">
                        <div class="col-md-4 mb-2">
                            <label class="form-label" for="server_idc"><?= $language::get('server_name'); ?></label>
                            <select id="server_idc" class="form-select">
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label" for="category_idv"><?= $language::get('category_series'); ?></label>
                            <select id="category_idv" class="form-select">
                                <option value=""><?= $language::get('no_filter'); ?></option>
                                <?php foreach (CategoryService::getAllByType('movie') as $rCategory): ?>
                                    <option value="0:<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                                <?php foreach (SeriesService::getList() as $rSeries): ?>
                                    <option value="1:<?= (int) $rSeries['id']; ?>"><?= htmlspecialchars((string) $rSeries['title'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label" for="vod_search"><?= $language::get('search'); ?></label>
                            <input type="text" class="form-control" id="vod_search">
                        </div>
                    </div>
                    <div class="card-datatable table-responsive">
                        <table id="datatable-movies" class="table"><thead><tr><th class="text-center"><?= $language::get('id'); ?></th><th><?= $language::get('name'); ?></th><th><?= $language::get('category_series'); ?></th><th class="text-center"><?= $language::get('actions'); ?></th></tr></thead><tbody></tbody></table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-review" role="tabpanel">
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-label-secondary btn-sm" data-sort="review" data-op="up"><i class="icon-base ti tabler-chevron-up"></i></button>
                        <button type="button" class="btn btn-label-secondary btn-sm" data-sort="review" data-op="down"><i class="icon-base ti tabler-chevron-down"></i></button>
                        <button type="button" class="btn btn-label-info btn-sm" data-sort="review" data-op="atoz"><?= $language::get('a_to_z'); ?></button>
                    </div>
                    <select multiple id="review_sort" name="review_sort" class="form-select" size="15">
                        <?php foreach ($rReviewSources as $rSource): ?>
                            <option value="<?= htmlspecialchars((string) $rSource, ENT_QUOTES); ?>"><?= htmlspecialchars($rSrcPath((string) $rSource), ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tab-pane fade" id="tab-videos" role="tabpanel">
                    <div class="mb-3">
                        <label class="form-label" for="cc_folder"><?= $language::get('import_folder'); ?></label>
                        <div class="input-group">
                            <input type="text" id="cc_folder" readonly class="form-control" value="">
                            <button type="button" class="btn btn-label-primary" id="filebrowser"><i class="icon-base ti tabler-folder"></i></button>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-label-secondary btn-sm" data-sort="videos" data-op="up"><i class="icon-base ti tabler-chevron-up"></i></button>
                        <button type="button" class="btn btn-label-secondary btn-sm" data-sort="videos" data-op="down"><i class="icon-base ti tabler-chevron-down"></i></button>
                        <button type="button" class="btn btn-label-warning btn-sm" data-sort="videos" data-op="remove"><i class="icon-base ti tabler-x"></i></button>
                        <button type="button" class="btn btn-label-info btn-sm" data-sort="videos" data-op="atoz"><?= $language::get('a_to_z'); ?></button>
                    </div>
                    <select multiple id="videos_sort" name="videos_sort" class="form-select" size="15">
                        <?php foreach ($rVideoSources as $rSource): ?>
                            <option value="<?= htmlspecialchars((string) $rSource, ENT_QUOTES); ?>"><?= htmlspecialchars($rSrcPath((string) $rSource), ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tab-pane fade" id="tab-rtmp" role="tabpanel">
                    <div class="alert alert-info" role="alert">RTMP Push pushes your channels to RTMP servers. The "Push From" server must be enabled in the Servers tab.</div>
                    <div id="rtmp-list">
                        <?php $i = 0;
                        foreach ($rRTMPPush as $rServerID => $rSources):
                            foreach ((array) $rSources as $rSource): ?>
                                <div class="rtmp-row input-group mb-2">
                                    <select class="form-select rtmp-server" style="max-width:40%">
                                        <?php foreach ($rServers as $rServer): ?>
                                            <option value="<?= (int) $rServer['id']; ?>" <?= ($rIsEdit && $rServerID == $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" class="form-control rtmp-url" value="<?= htmlspecialchars((string) $rSource, ENT_QUOTES); ?>" placeholder="rtmp://...">
                                    <button type="button" class="btn btn-label-danger rtmp-remove"><i class="icon-base ti tabler-x"></i></button>
                                </div>
                                <?php $i++;
                            endforeach;
                        endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-label-info btn-sm" id="rtmp-add"><i class="icon-base ti tabler-plus me-1"></i><?= $language::get('add_rtmp_url'); ?></button>
                </div>

                <div class="tab-pane fade" id="tab-server" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label"><?= $language::get('server_tree'); ?></label>
                        <div id="server_tree" class="border rounded p-2"></div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="on_demand"><?= $language::get('on_demand_servers'); ?></label>
                        <select name="on_demand[]" id="on_demand" class="form-select" multiple>
                            <?php foreach ($rServers as $rServer): ?>
                                <option value="<?= (int) $rServer['id']; ?>" <?= in_array((int) $rServer['id'], array_map('intval', (array) $rOnDemand), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($rIsEdit): ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="reencode_on_edit" name="reencode_on_edit" value="1">
                            <label class="form-check-label" for="reencode_on_edit"><?= $language::get('full_re_encode_on_edit'); ?></label>
                        </div>
                    <?php endif; ?>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="restart_on_edit" name="restart_on_edit" value="1">
                        <label class="form-check-label" for="restart_on_edit"><?= $rIsEdit ? 'Restart on Edit' : 'Start After Creation'; ?></label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="cchannel-submit"><?= $rIsEdit ? $language::get('edit') : ($language::get('create') ?: 'Create'); ?></button>
    </div>
</form>

<!-- File browser modal -->
<div class="modal fade" id="fileBrowserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title mb-0"><?= $language::get('file_browser'); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6 mb-2">
                        <label class="form-label" for="fb_server"><?= $language::get('server_name'); ?></label>
                        <select id="fb_server" class="form-select">
                            <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label" for="fb_path"><?= $language::get('current_path'); ?></label>
                        <div class="input-group">
                            <input type="text" id="fb_path" class="form-control" value="/">
                            <button class="btn btn-label-primary" type="button" id="fb_go"><i class="icon-base ti tabler-arrow-right"></i></button>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="fw-medium mb-1"><?= $language::get('directory'); ?></div>
                        <ul class="list-group" id="fb_dirs" style="max-height:260px;overflow:auto"></ul>
                    </div>
                    <div class="col-md-6">
                        <div class="fw-medium mb-1"><?= $language::get('filename'); ?></div>
                        <ul class="list-group" id="fb_files" style="max-height:260px;overflow:auto"></ul>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-primary" id="fb_add_dir"><?= $language::get('add_this_directory') ?: 'Add This Directory'; ?></button></div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="imgPreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body text-center p-2"><img id="imgPreviewImg" src="" alt="" style="max-width:100%"></div></div></div></div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var VIDEO_EXT = ['mp4', 'mkv', 'mov', 'avi', 'mpg', 'mpeg', 'flv', 'wmv', 'm4v'];
        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
        function collectNew(sel) {
            var vals = $(sel).val() || [], nw = [];
            vals.forEach(function(v) { if (!/^\d+$/.test(v)) { nw.push(v); } });
            return JSON.stringify(nw);
        }

        $('#category_id, #bouquets').select2({ width: '100%', tags: true, dropdownParent: $('#tab-details') });
        $('#channel_type, #series_no, #transcode_profile_id').select2({ width: '100%', dropdownParent: $('#tab-details') });
        $('#server_idc, #category_idv').select2({ width: '100%', dropdownParent: $('#tab-selection') });
        $('#on_demand').select2({ width: '100%', dropdownParent: $('#tab-server') });

        // ---- logo preview ----
        document.getElementById('icon-preview').addEventListener('click', function() {
            var v = document.getElementById('stream_icon').value.trim(), img = document.getElementById('icon-img');
            if (!v) { img.hidden = true; return; }
            img.src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(v); img.hidden = false;
        });

        // ---- channel_type drives which tabs/rows show ----
        function applyType() {
            var t = $('#channel_type').val();
            document.getElementById('series-row').hidden = (t !== '0');
            document.getElementById('nav-selection').hidden = (t !== '2');
            document.getElementById('nav-review').hidden = (t !== '2');
            document.getElementById('nav-videos').hidden = (t !== '1');
        }
        $('#channel_type').on('change', applyType);
        applyType();
        $('#series_no').on('change', function() {
            if ($('#series_no').val() > 0) { $('#stream_display_name').val('24/7 ' + $('#series_no option:selected').text()); }
        });
        $('#transcode_profile_id').on('change', function() {
            var v = $(this).val();
            document.getElementById('transcode-warning').classList.toggle('d-none', !(v === '0' || v === '-1'));
        }).trigger('change');

        // ---- ordered list ops (review / videos) ----
        document.querySelectorAll('[data-sort]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sel = $('#' + btn.dataset.sort + '_sort'), op = btn.dataset.op, opts = sel.find('option:selected');
                if (op === 'atoz') {
                    sel.append(sel.find('option').remove().sort(function(a, b) {
                        var at = $(a).text().toUpperCase().split('/').pop(), bt = $(b).text().toUpperCase().split('/').pop();
                        return at > bt ? 1 : (at < bt ? -1 : 0);
                    }));
                    return;
                }
                if (!opts.length) { return; }
                if (op === 'up') { var p = opts.first().prev(); if (p.length) { p.before(opts); } }
                else if (op === 'down') { var n = opts.last().next(); if (n.length) { n.after(opts); } }
                else if (op === 'remove') { opts.remove(); }
            });
        });

        // ---- VOD selection ----
        var rSelection = [];
        function reviewSelection() {
            $.post('./api?action=review_selection', { data: rSelection }, function(rData) {
                if (!rData || rData.result !== true) { return; }
                var active = [];
                $(rData.streams).each(function(i) {
                    var src = $.parseJSON(rData.streams[i].stream_source)[0];
                    active.push(src);
                    var ext = src.split('.').pop().toLowerCase();
                    if (VIDEO_EXT.indexOf(ext) !== -1 && $('#review_sort option').filter(function() { return this.value === src; }).length === 0) {
                        $('#review_sort').append(new Option(src, src));
                    }
                });
                $('#review_sort option').each(function() { if (active.indexOf(this.value) === -1) { $(this).remove(); } });
            }, 'json');
        }
        window.toggleSelection = function(id) {
            id = parseInt(id, 10);
            var idx = rSelection.indexOf(id);
            if (idx > -1) { rSelection.splice(idx, 1); } else { rSelection.push(id); }
            if (window.__ccMovies) { window.__ccMovies.ajax.reload(null, false); }
            reviewSelection();
        };
        window.__ccMovies = $('#datatable-movies').DataTable({
            processing: true, serverSide: true, searching: true, lengthChange: false, info: false,
            pageLength: <?= (int) ($rSettings['default_entries'] ?? 10) ?: 10; ?>,
            ajax: { url: './table', data: function(d) { d.id = 'vod_selection'; d.category_id = $('#category_idv').val(); d.server_id = $('#server_idc').val(); } },
            columnDefs: [{ className: 'dt-center', targets: [0, 3] }],
            createdRow: function(row, data) {
                $(row).addClass('vod-' + data[0]);
                if (rSelection.indexOf(parseInt(data[0], 10)) > -1) { $(row).find('.btn-remove').show(); } else { $(row).find('.btn-add').show(); }
            }
        });
        $('#category_idv, #server_idc').on('change', function() { window.__ccMovies.ajax.reload(null, false); });
        $('#vod_search').on('keyup', function() { window.__ccMovies.search($(this).val()).draw(); });

        // ---- file browser (videos) ----
        var fbDir = '/', fbModal = document.getElementById('fileBrowserModal');
        function fbList() {
            var server = $('#fb_server').val();
            fbDir = $('#fb_path').val();
            if (fbDir.slice(-1) !== '/') { fbDir += '/'; }
            $('#fb_path').val(fbDir);
            $('#fb_dirs, #fb_files').html('<li class="list-group-item text-muted">…</li>');
            $.getJSON('./api?action=listdir&dir=' + encodeURIComponent(fbDir) + '&server=' + encodeURIComponent(server) + '&filter=video', function(data) {
                var dirs = '', files = '';
                if (fbDir !== '/') { dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name=".."><i class="icon-base ti tabler-arrow-back-up me-1"></i>..</li>'; }
                if (data && data.result === true) {
                    $(data.data.dirs).each(function(i, d) { dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name="' + esc(d) + '"><i class="icon-base ti tabler-folder me-1"></i>' + esc(d) + '</li>'; });
                    $(data.data.files).each(function(i, f) { files += '<li class="list-group-item fb-file" data-name="' + esc(f) + '"><i class="icon-base ti tabler-file me-1"></i>' + esc(f) + '</li>'; });
                }
                $('#fb_dirs').html(dirs || '<li class="list-group-item text-muted">—</li>');
                $('#fb_files').html(files || '<li class="list-group-item text-muted">—</li>');
            });
        }
        document.getElementById('filebrowser').addEventListener('click', function() { $('#fb_path').val('/'); fbList(); bootstrap.Modal.getOrCreateInstance(fbModal).show(); });
        document.getElementById('fb_go').addEventListener('click', fbList);
        $('#fb_server').on('change', function() { $('#fb_path').val('/'); fbList(); });
        $('#fb_dirs').on('click', '.fb-dir', function() {
            var name = $(this).data('name');
            if (name === '..') { fbDir = fbDir.split('/').slice(0, -2).join('/') + '/'; } else { fbDir += name + '/'; }
            $('#fb_path').val(fbDir); fbList();
        });
        document.getElementById('fb_add_dir').addEventListener('click', function() {
            var server = $('#fb_server').val();
            $('#cc_folder').val($('#fb_server option:selected').text());
            $('#fb_files .fb-file').each(function() {
                var name = $(this).data('name'), ext = name.split('.').pop().toLowerCase();
                var val = 's:' + server + ':' + fbDir + name;
                if (VIDEO_EXT.indexOf(ext) !== -1 && $('#videos_sort option').filter(function() { return this.value === val; }).length === 0) {
                    $('#videos_sort').append(new Option(fbDir + name, val));
                }
            });
            bootstrap.Modal.getInstance(fbModal).hide();
        });

        // ---- RTMP rows ----
        document.getElementById('rtmp-add').addEventListener('click', function() {
            var list = document.getElementById('rtmp-list'), first = list.querySelector('.rtmp-row');
            if (!first) { return; }
            var clone = first.cloneNode(true);
            clone.querySelector('.rtmp-url').value = '';
            list.appendChild(clone);
            $(clone).find('.rtmp-server').select2({ width: '100%' });
        });
        $('#rtmp-list').on('click', '.rtmp-remove', function() {
            var rows = document.querySelectorAll('#rtmp-list .rtmp-row');
            if (rows.length > 1) { this.closest('.rtmp-row').remove(); } else { this.closest('.rtmp-row').querySelector('.rtmp-url').value = ''; }
        });
        $('#rtmp-list .rtmp-server').select2({ width: '100%' });

        // ---- jstree server tree ----
        function evaluateServers() {
            var cur = $('#on_demand').val();
            $('#on_demand').empty();
            $($('#server_tree').jstree(true).get_json('source', { flat: true })).each(function(i, v) {
                if (v.parent !== '#') { $('#on_demand').append(new Option(v.text, v.id)); }
            });
            $('#on_demand').val(cur).trigger('change');
        }
        $('#server_tree')
            .on('redraw.jstree', function() { evaluateServers(); })
            .on('select_node.jstree', function(e, data) {
                if (data.node.id === 'source' || data.node.id === 'offline') { return; }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') { return false; }
                            if (parent.id === '#') { return false; }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        // ---- submit ----
        document.getElementById('cchannel-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var t = $('#channel_type').val(), files = [], ok = true;
            if (t === '0') { if ($('#series_no').val() == 0) { alert('Please select a series to map.'); ok = false; } }
            else if (t === '1') {
                if ($('#videos_sort option').length === 0) { alert('Please add at least one video.'); ok = false; }
                $('#videos_sort option').each(function() { files.push(this.value); });
            } else if (t === '2') {
                if ($('#review_sort option').length === 0) { alert('Please add at least one video.'); ok = false; }
                $('#review_sort option').each(function() { files.push(this.value); });
            }
            if (!$('#transcode_profile_id').val()) { alert('Please select a transcoding profile.'); ok = false; }
            if (!document.getElementById('stream_display_name').value.trim()) { ok = false; }
            if (!ok) { return; }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }));
            document.getElementById('video_files').value = JSON.stringify(files);
            document.getElementById('category_create_list').value = collectNew('#category_id');
            document.getElementById('bouquet_create_list').value = collectNew('#bouquets');
            var rtmp = {};
            document.querySelectorAll('#rtmp-list .rtmp-row').forEach(function(row) {
                var sid = row.querySelector('.rtmp-server').value, src = row.querySelector('.rtmp-url').value;
                if (sid > 0 && src.length > 0) { (rtmp[sid] = rtmp[sid] || []).push(src); }
            });
            document.getElementById('external_push').value = JSON.stringify(rtmp);
            var btn = document.getElementById('cchannel-submit');
            btn.disabled = true;
            fetch('post.php?action=created_channel', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) { window.parent.postMessage('xcModalSaved', '*'); }
                        else { window.location.href = dt.location || 'created_channels'; }
                        return;
                    }
                    btn.disabled = false; alert(errText);
                })
                .catch(function() { btn.disabled = false; alert(errText); });
        });
    })();
</script>
</body>

</html>
