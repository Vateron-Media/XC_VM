<?php

/**
 * TV series add / edit / import (Bootstrap 5). Reached full-page from the series
 * table ("Add" → serie, "Import" → serie?import) in the new-UI shell, and as an
 * iframe modal ("Edit" → serie?id=X&modal=1). A series is metadata only (its
 * episodes are managed on their own page), so the add/edit form has just two
 * tabs — Details (title/year, TMDb search, categories, bouquets) and Information
 * (TMDb metadata). The import flow (serie?import) instead shows Details (M3U /
 * folder) + Advanced (encode options) + Server (the jstree tree) for a bulk
 * Watch-Folder push. The TMDb box auto-fills Information; the file browser is a
 * Bootstrap modal (magnificPopup is not in the new-UI). Posts to
 * post.php?action=serie via fetch; posts xcModalSaved in the modal.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Reference\LocaleReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;

$rIsImport  = RequestManager::has('import');
$rIsEdit    = isset($rSeriesArr) && !$rIsImport;
$rSeriesCat = isset($rSeriesArr) ? (json_decode((string) $rSeriesArr['category_id'], true) ?: []) : [];
$rHasTmdb   = strlen((string) SettingsManager::get('tmdb_api_key')) > 0;
$rTmdbLang  = !empty($rSeriesArr['tmdb_language']) ? $rSeriesArr['tmdb_language'] : ($rSettings['tmdb_language'] ?? 'en');
$rContainers = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
$rTitle = $rIsEdit ? $rSeriesArr['title'] : ($rIsImport ? 'Import Series' : 'Add Series');
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="series" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= htmlspecialchars((string) $rTitle, ENT_QUOTES); ?></h4>
    </div>
<?php endif; ?>

<form id="serie-form" autocomplete="off"<?= $rIsImport ? ' enctype="multipart/form-data"' : ''; ?>>
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rSeriesArr['id']; ?>">
    <?php endif; ?>
    <?php if (!$rIsImport): ?>
        <input type="hidden" id="tmdb_id" name="tmdb_id" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['tmdb_id'], ENT_QUOTES) : ''; ?>">
    <?php else: ?>
        <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
    <?php endif; ?>
    <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="">
    <input type="hidden" name="category_create_list" id="category_create_list" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if (!$rIsImport): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-info" role="tab"><i class="icon-base ti tabler-movie me-1"></i><?= $language::get('information'); ?></button></li>
                    <?php else: ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-server" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('server'); ?></button></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <?php if (!$rIsImport): ?>
                        <div class="row mb-6">
                            <div class="col-md-9">
                                <label class="form-label" for="title"><?= $language::get('series_name'); ?></label>
                                <input type="text" class="form-control" id="title" name="title" required value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['title'], ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="year"><?= $language::get('year'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control text-center" id="year" name="year" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['year'], ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <?php if ($rHasTmdb): ?>
                            <div class="row mb-6">
                                <div class="col-md-8">
                                    <label class="form-label" for="tmdb_search"><?= $language::get('tmdb_results'); ?></label>
                                    <select id="tmdb_search" class="form-select"></select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="tmdb_language"><?= $language::get('language') ?: 'Language'; ?></label>
                                    <select name="tmdb_language" id="tmdb_language" class="form-select">
                                        <?php foreach (LocaleReference::tmdbLanguages() as $rKey => $rLanguage): ?>
                                            <option value="<?= htmlspecialchars((string) $rKey, ENT_QUOTES); ?>" <?= ($rKey == $rTmdbLang) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rLanguage, ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-3"><?= $language::get('import_movies_help') ?: 'Importing parses your M3U or folder and pushes each item through Watch Folder. Category and bouquet allocation from Watch Folder Settings applies here too.'; ?></p>
                        <div class="mb-6">
                            <label class="form-label d-block"><?= $language::get('type'); ?></label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="import_type_1" name="import_kind" value="m3u" checked>
                                <label class="form-check-label" for="import_type_1"><?= $language::get('m3u'); ?></label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="import_type_2" name="import_kind" value="folder">
                                <label class="form-check-label" for="import_type_2"><?= $language::get('folder'); ?></label>
                            </div>
                        </div>
                        <div id="import_m3uf_toggle" class="mb-6">
                            <label class="form-label" for="m3u_file"><?= $language::get('m3u_file'); ?></label>
                            <input type="file" class="form-control" id="m3u_file" name="m3u_file">
                        </div>
                        <div id="import_folder_toggle" class="mb-6" hidden>
                            <label class="form-label" for="import_folder"><?= $language::get('folder'); ?></label>
                            <div class="input-group">
                                <input type="text" id="import_folder" name="import_folder" class="form-control" value="">
                                <button type="button" class="btn btn-label-primary" id="filebrowser" data-target="import_folder"><i class="icon-base ti tabler-folder"></i></button>
                            </div>
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" id="scan_recursive" name="scan_recursive" value="1">
                                <label class="form-check-label" for="scan_recursive"><?= $language::get('scan_recursively'); ?></label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-6">
                        <label class="form-label" for="category_id"><?= $rIsImport ? 'Fallback ' : ''; ?><?= $language::get('categories'); ?></label>
                        <select name="category_id[]" id="category_id" class="form-select" multiple>
                            <?php foreach (CategoryService::getAllByType('series') as $rCategory): ?>
                                <option value="<?= (int) $rCategory['id']; ?>" <?= in_array((int) $rCategory['id'], array_map('intval', $rSeriesCat), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="bouquets"><?= $rIsImport ? 'Fallback ' : ''; ?><?= $language::get('bouquets'); ?></label>
                        <select name="bouquets[]" id="bouquets" class="form-select" multiple>
                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                <option value="<?= (int) $rBouquet['id']; ?>" <?= (isset($rSeriesArr) && in_array((int) $rSeriesArr['id'], array_map('intval', json_decode((string) $rBouquet['bouquet_series'], true) ?: []), true)) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (!$rIsImport): ?>
                    <div class="tab-pane fade" id="tab-info" role="tabpanel">
                        <div class="mb-6">
                            <label class="form-label" for="cover"><?= $language::get('poster_url'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="cover" name="cover" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['cover'], ENT_QUOTES) : ''; ?>">
                                <button type="button" class="btn btn-label-secondary js-img-preview" data-target="cover"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="backdrop_path"><?= $language::get('backdrop_url'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="backdrop_path" name="backdrop_path" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) (json_decode((string) $rSeriesArr['backdrop_path'], true)[0] ?? ''), ENT_QUOTES) : ''; ?>">
                                <button type="button" class="btn btn-label-secondary js-img-preview" data-target="backdrop_path"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="plot"><?= $language::get('plot'); ?></label>
                            <textarea rows="4" class="form-control" id="plot" name="plot"><?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['plot'], ENT_QUOTES) : ''; ?></textarea>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="cast"><?= $language::get('cast'); ?></label>
                            <input type="text" class="form-control" id="cast" name="cast" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['cast'], ENT_QUOTES) : ''; ?>">
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="director"><?= $language::get('director'); ?></label>
                                <input type="text" class="form-control" id="director" name="director" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['director'], ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="genre"><?= $language::get('genres'); ?></label>
                                <input type="text" class="form-control" id="genre" name="genre" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['genre'], ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="release_date"><?= $language::get('release_date'); ?></label>
                                <input type="text" class="form-control" id="release_date" name="release_date" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['release_date'], ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="episode_run_time"><?= $language::get('runtime'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control" id="episode_run_time" name="episode_run_time" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['episode_run_time'], ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label" for="youtube_trailer"><?= $language::get('youtube_trailer_label') ?: 'YouTube Trailer'; ?></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="youtube_trailer" name="youtube_trailer" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['youtube_trailer'], ENT_QUOTES) : ''; ?>">
                                    <button type="button" class="btn btn-label-secondary" id="yt-preview"><i class="icon-base ti tabler-eye"></i></button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="rating"><?= $language::get('rating'); ?></label>
                                <input type="text" class="form-control" id="rating" name="rating" value="<?= isset($rSeriesArr) ? htmlspecialchars((string) $rSeriesArr['rating'], ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                        <div class="row g-3 mb-6">
                            <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="direct_source" name="direct_source" value="1"><label class="form-check-label" for="direct_source"><?= $language::get('direct_source'); ?></label></div></div>
                            <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="read_native" name="read_native" value="1"><label class="form-check-label" for="read_native"><?= $language::get('native_frames'); ?></label></div></div>
                            <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="movie_symlink" name="movie_symlink" value="1"><label class="form-check-label" for="movie_symlink"><?= $language::get('create_symlink'); ?></label></div></div>
                            <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="remove_subtitles" name="remove_subtitles" value="1"><label class="form-check-label" for="remove_subtitles"><?= $language::get('remove_existing_subtitles'); ?></label></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                                <select name="transcode_profile_id" id="transcode_profile_id" class="form-select opt-field">
                                    <option value="0"><?= $language::get('transcoding_disabled'); ?></option>
                                    <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                        <option value="<?= (int) $rProfile['profile_id']; ?>"><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="target_container"><?= $language::get('target_container'); ?></label>
                                <select name="target_container" id="target_container" class="form-select opt-field">
                                    <?php foreach ($rContainers as $rContainer): ?>
                                        <option value="<?= $rContainer; ?>"><?= $rContainer; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tab-server" role="tabpanel">
                        <div class="mb-6">
                            <label class="form-label"><?= $language::get('server_tree'); ?></label>
                            <div id="server_tree" class="border rounded p-2"></div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="restart_on_edit" name="restart_on_edit" value="1">
                            <label class="form-check-label" for="restart_on_edit"><?= $language::get('process_movie') ?: 'Process Now'; ?></label>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="serie-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<?php if ($rIsImport): ?>
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
                    <div class="fw-medium mb-1"><?= $language::get('directory'); ?></div>
                    <ul class="list-group" id="fb_dirs" style="max-height:300px;overflow:auto"></ul>
                    <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-primary" id="fb_select_folder"><?= $language::get('select'); ?></button></div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="modal fade" id="imgPreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body text-center p-2"><img id="imgPreviewImg" src="" alt="" style="max-width:100%"></div></div></div></div>
    <div class="modal fade" id="ytModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body p-0"><div class="ratio ratio-16x9"><iframe id="ytFrame" src="" allowfullscreen style="border:0"></iframe></div></div></div></div></div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var isImport = <?= $rIsImport ? 'true' : 'false'; ?>;
        var yearAppend = <?= (int) ($rSettings['movie_year_append'] ?? 0); ?>;
        var changeTitle = false;
        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
        function collectNew(sel) {
            var vals = $(sel).val() || [], nw = [];
            vals.forEach(function(v) { if (!/^\d+$/.test(v)) { nw.push(v); } });
            return JSON.stringify(nw);
        }

        $('#category_id, #bouquets').select2({ width: '100%', tags: true, dropdownParent: $('#tab-details') });
        $('#tmdb_search, #tmdb_language, #transcode_profile_id, #target_container').select2({ width: '100%', dropdownParent: $('#serie-form') });

        if (!isImport) {
            // ---- image / youtube preview ----
            document.querySelectorAll('.js-img-preview').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var v = document.getElementById(btn.dataset.target).value.trim();
                    if (!v) { return; }
                    document.getElementById('imgPreviewImg').src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(v);
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('imgPreviewModal')).show();
                });
            });
            document.getElementById('yt-preview').addEventListener('click', function() {
                var v = document.getElementById('youtube_trailer').value.trim();
                if (!v) { return; }
                document.getElementById('ytFrame').src = 'https://www.youtube.com/embed/' + encodeURIComponent(v);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('ytModal')).show();
            });
            document.getElementById('ytModal').addEventListener('hidden.bs.modal', function() { document.getElementById('ytFrame').src = ''; });

            // ---- TMDb search (type=series) ----
            var tmdbSearch = document.getElementById('tmdb_search');
            if (tmdbSearch) {
                $('#tmdb_language').on('change', function() { $('#title').trigger('change'); });
                $('#title').on('change', function() {
                    if (changeTitle) { changeTitle = false; return; }
                    $('#tmdb_search').empty().trigger('change');
                    var term = $('#title').val();
                    if (!term) { return; }
                    $.getJSON('./api?action=tmdb_search&type=series&term=' + encodeURIComponent(term) + '&language=' + encodeURIComponent($('#tmdb_language').val()), function(data) {
                        if (!data || data.result !== true) { $('#tmdb_search').append(new Option('No results found', -1, true, true)); return; }
                        var head = data.data.length > 0 ? ('Found ' + data.data.length + ' results') : 'No results found';
                        $('#tmdb_search').append(new Option(head, -1, true, true)).trigger('change');
                        $(data.data).each(function(i, item) {
                            var t = item.name;
                            if (item.first_air_date) {
                                var yr = item.first_air_date.substring(0, 4);
                                t = yearAppend === 0 ? (item.name + ' (' + yr + ')') : (yearAppend === 1 ? (item.name + ' - ' + yr) : item.name);
                            }
                            $('#tmdb_search').append(new Option(t, item.id, true, true));
                        });
                        $('#tmdb_search').val(-1).trigger('change');
                    });
                });
                $('#tmdb_search').on('change', function() {
                    var id = $('#tmdb_search').val();
                    if (!id || id <= -1) { return; }
                    $.getJSON('./api?action=tmdb&type=series&id=' + encodeURIComponent(id) + '&language=' + encodeURIComponent($('#tmdb_language').val()), function(data) {
                        if (!data || data.result !== true) { return; }
                        var d = data.data;
                        changeTitle = true;
                        $('#title').val(d.name);
                        $('#year').val(d.first_air_date ? d.first_air_date.substr(0, 4) : '');
                        $('#cover').val(d.poster_path ? ('https://image.tmdb.org/t/p/w600_and_h900_bestv2' + d.poster_path) : '');
                        $('#backdrop_path').val(d.backdrop_path ? ('https://image.tmdb.org/t/p/w1280' + d.backdrop_path) : '');
                        $('#release_date').val(d.first_air_date || '');
                        $('#episode_run_time').val((d.episode_run_time && d.episode_run_time[0]) || '');
                        $('#youtube_trailer').val(d.trailer || '');
                        $('#plot').val(d.overview || '');
                        $('#rating').val(d.vote_average || '');
                        $('#tmdb_id').val(id);
                        var cast = ((d.credits && d.credits.cast) || []).slice(0, 5).map(function(m) { return m.name; }).join(', ');
                        $('#cast').val(cast);
                        $('#genre').val((d.genres || []).slice(0, 3).map(function(g) { return g.name; }).join(', '));
                        var dirs = ((d.credits && d.credits.crew) || []).filter(function(m) { return m.department === 'Directing' || m.known_for_department === 'Directing'; }).slice(0, 3).map(function(m) { return m.name; }).join(', ');
                        $('#director').val(dirs);
                    });
                });
                <?php if (isset($rSeriesArr)): ?>
                $('#title').trigger('change');
                <?php endif; ?>
            }
        }

        if (isImport) {
            // ---- import M3U/folder toggle ----
            document.getElementById('import_type_1').addEventListener('change', function() { document.getElementById('import_m3uf_toggle').hidden = false; document.getElementById('import_folder_toggle').hidden = true; });
            document.getElementById('import_type_2').addEventListener('change', function() { document.getElementById('import_m3uf_toggle').hidden = true; document.getElementById('import_folder_toggle').hidden = false; });

            // ---- direct source disables encode options ----
            var directEl = document.getElementById('direct_source');
            function applyDirect() {
                var off = directEl.checked;
                ['movie_symlink', 'read_native', 'remove_subtitles', 'transcode_profile_id', 'target_container'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) { el.disabled = off; if ($(el).hasClass('select2-hidden-accessible')) { $(el).prop('disabled', off).trigger('change.select2'); } }
                });
            }
            directEl.addEventListener('change', applyDirect);
            applyDirect();

            // ---- jstree server tree ----
            $('#server_tree')
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

            // ---- file browser (folder pick) ----
            var fbDir = '/', fbModal = document.getElementById('fileBrowserModal');
            function fbList() {
                var server = $('#fb_server').val();
                fbDir = $('#fb_path').val();
                if (fbDir.slice(-1) !== '/') { fbDir += '/'; }
                $('#fb_path').val(fbDir);
                $('#fb_dirs').html('<li class="list-group-item text-muted">…</li>');
                $.getJSON('./api?action=listdir&dir=' + encodeURIComponent(fbDir) + '&server=' + encodeURIComponent(server) + '&filter=video', function(data) {
                    var dirs = '';
                    if (fbDir !== '/') { dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name=".."><i class="icon-base ti tabler-arrow-back-up me-1"></i>..</li>'; }
                    if (data && data.result === true) {
                        $(data.data.dirs).each(function(i, d) { dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name="' + esc(d) + '"><i class="icon-base ti tabler-folder me-1"></i>' + esc(d) + '</li>'; });
                    }
                    $('#fb_dirs').html(dirs || '<li class="list-group-item text-muted">—</li>');
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
            document.getElementById('fb_select_folder').addEventListener('click', function() {
                document.getElementById('import_folder').value = 's:' + $('#fb_server').val() + ':' + fbDir;
                bootstrap.Modal.getInstance(fbModal).hide();
            });
        }

        // ---- submit ----
        document.getElementById('serie-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!isImport) {
                if (!document.getElementById('title').value.trim()) { alert(errText); return; }
            } else if (!document.getElementById('m3u_file').value && !document.getElementById('import_folder').value) {
                alert(errText); return;
            }
            if (isImport) {
                document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }));
            }
            document.getElementById('category_create_list').value = collectNew('#category_id');
            document.getElementById('bouquet_create_list').value = collectNew('#bouquets');
            document.querySelectorAll('#serie-form :disabled').forEach(function(el) { el.disabled = false; });
            var btn = document.getElementById('serie-submit');
            btn.disabled = true;
            fetch('post.php?action=serie', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) { window.parent.postMessage('xcModalSaved', '*'); }
                        else { window.location.href = dt.location || 'series'; }
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
