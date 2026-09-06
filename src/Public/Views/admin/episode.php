<?php

/**
 * Episode add / edit / import (Bootstrap 5). Reached full-page from the episodes
 * table: "Add" → episode?sid=X, "Edit" → episode?id=X&sid=X, bulk import →
 * episode?sid=X&multi, inside the new-UI shell. In single mode the tabs are
 * Details (season/episode number, TMDb episode search, name, source path + file
 * browser, notes), Information (TMDb metadata), Advanced (encode/symlink/subtitle
 * /transcode options) and Server (the jstree load-balancer tree). In multi mode
 * there is no Information tab: Details becomes a season-folder picker that
 * enumerates the folder's video files into an episode list (get_episode_ids),
 * and Advanced drops the per-file target container / subtitle / custom SID
 * fields. Categories/bouquets are not used here. The TMDb box (keyed by the
 * series TMDb id + season number) auto-fills the Information tab; the file
 * browser is a Bootstrap modal (magnificPopup is not part of the new-UI). Posts
 * to post.php?action=episode via fetch and returns to the list on success.
 * Requires jstree (must be declared by the controller).
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamRepository;

$rIsEdit     = isset($rEpisode);
$rMulti      = !empty($rMulti);
$rHasTmdb    = strlen((string) SettingsManager::get('tmdb_api_key')) > 0;
$rTmdbLang   = !empty($rSeriesArr['tmdb_language']) ? $rSeriesArr['tmdb_language'] : ($rSettings['tmdb_language'] ?? 'en');
$rContainers = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];

$rEpisodeSource = '';
if ($rIsEdit) {
    $rDecodedSource = json_decode((string) $rEpisode['stream_source'], true);
    $rEpisodeSource = is_array($rDecodedSource) ? (string) ($rDecodedSource[0] ?? '') : '';
}

$rSubFile = '';
if ($rIsEdit) {
    $rSubData = json_decode((string) $rEpisode['movie_subtitles'], true);
    if (isset($rSubData['location'])) {
        $rSubFile = 's:' . $rSubData['location'] . ':' . ($rSubData['files'][0] ?? '');
    }
}

$rProps = $rIsEdit ? ($rEpisode['properties'] ?? []) : [];
$rTitle = $rIsEdit ? $rEpisode['stream_display_name'] : ($rMulti ? $language::get('add_multiple') : $language::get('add_single'));
?>

<div class="d-flex align-items-center mb-4">
    <a href="episodes?series=<?= (int) $rSeriesArr['id']; ?>" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= htmlspecialchars((string) $rTitle, ENT_QUOTES); ?></h4>
</div>

<?php if ($rIsEdit): ?>
    <?php foreach (StreamRepository::getEncodeErrors($rEpisode['id']) as $rServerID => $rEncodeError): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong><?= $language::get('error_on_server'); ?> - <?= htmlspecialchars((string) ($rServers[$rServerID]['server_name'] ?? ''), ENT_QUOTES); ?></strong><br>
            <?= str_replace("\n", '<br>', htmlspecialchars((string) $rEncodeError, ENT_QUOTES)); ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<form id="episode-form" autocomplete="off">
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rEpisode['id']; ?>">
    <?php endif; ?>
    <?php if ($rMulti): ?>
        <input type="hidden" name="multi" id="multi" value="">
        <input type="hidden" name="server" id="server" value="">
        <input type="hidden" id="tmdb_id" name="tmdb_id" value="<?= htmlspecialchars((string) ($rSeriesArr['tmdb_id'] ?? ''), ENT_QUOTES); ?>">
    <?php else: ?>
        <input type="hidden" id="tmdb_id" name="tmdb_id" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['tmdb_id'] ?? ''), ENT_QUOTES) : ''; ?>">
    <?php endif; ?>
    <input type="hidden" name="series" value="<?= (int) $rSeriesArr['id']; ?>">
    <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
    <input type="hidden" id="tmdb_language" value="<?= htmlspecialchars((string) $rTmdbLang, ENT_QUOTES); ?>">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if (!$rMulti): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-info" role="tab"><i class="icon-base ti tabler-movie me-1"></i><?= $language::get('information'); ?></button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-server" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <?php if (!$rMulti): ?>
                        <div class="mb-6">
                            <label class="form-label" for="series_name"><?= $language::get('series_name'); ?></label>
                            <input type="text" class="form-control" id="series_name" name="series_name" value="<?= htmlspecialchars((string) $rSeriesArr['title'], ENT_QUOTES); ?>" readonly>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="season_num"><?= $language::get('season_number'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control text-center" id="season_num" name="season_num" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEpisode['season'], ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="episode"><?= $language::get('episode_number'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control text-center" id="episode" name="episode" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEpisode['episode'], ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <?php if ($rHasTmdb): ?>
                            <div class="mb-6">
                                <label class="form-label" for="tmdb_search"><?= $language::get('tmdb_results'); ?></label>
                                <select id="tmdb_search" class="form-select"></select>
                            </div>
                        <?php endif; ?>
                        <div class="mb-6">
                            <label class="form-label" for="stream_display_name"><?= $language::get('episode_name'); ?></label>
                            <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rEpisode['stream_display_name'], ENT_QUOTES) : ''; ?>">
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="stream_source"><?= $language::get('episode_path'); ?></label>
                            <div class="input-group">
                                <input type="text" id="stream_source" name="stream_source" class="form-control" required value="<?= htmlspecialchars((string) $rEpisodeSource, ENT_QUOTES); ?>">
                                <button type="button" class="btn btn-label-primary" id="filebrowser" data-target="stream_source"><i class="icon-base ti tabler-folder"></i></button>
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="notes"><?= $language::get('notes'); ?></label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?= $rIsEdit ? htmlspecialchars((string) $rEpisode['notes'], ENT_QUOTES) : ''; ?></textarea>
                        </div>
                    <?php else: ?>
                        <div class="row mb-6">
                            <div class="col-md-9">
                                <label class="form-label" for="series_name"><?= $language::get('series_name'); ?></label>
                                <input type="text" class="form-control" id="series_name" name="series_name" value="<?= htmlspecialchars((string) $rSeriesArr['title'], ENT_QUOTES); ?>" readonly>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="season_num"><?= $language::get('season'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control text-center" id="season_num" name="season_num" required value="">
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="season_folder"><?= $language::get('season_folder'); ?></label>
                            <div class="input-group">
                                <input type="text" id="season_folder" name="season_folder" readonly required class="form-control" value="">
                                <button type="button" class="btn btn-label-primary" id="filebrowser" data-target="season_folder"><i class="icon-base ti tabler-folder"></i></button>
                            </div>
                        </div>
                        <div id="episode_add" class="mb-6"></div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="addName1" name="addName1" value="1" checked>
                                    <label class="form-check-label" for="addName1"><?= $language::get('add_series_name'); ?></label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="addName2" name="addName2" value="1" checked>
                                    <label class="form-check-label" for="addName2"><?= $language::get('add_episode_number'); ?></label>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$rMulti): ?>
                    <div class="tab-pane fade" id="tab-info" role="tabpanel">
                        <div class="mb-6">
                            <label class="form-label" for="movie_image"><?= $language::get('image_url'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="movie_image" name="movie_image" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['movie_image'] ?? ''), ENT_QUOTES) : ''; ?>">
                                <button type="button" class="btn btn-label-secondary js-img-preview" data-target="movie_image"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="plot"><?= $language::get('plot'); ?></label>
                            <textarea rows="6" class="form-control" id="plot" name="plot"><?= $rIsEdit ? htmlspecialchars((string) ($rProps['plot'] ?? ''), ENT_QUOTES) : ''; ?></textarea>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-8">
                                <label class="form-label" for="release_date"><?= $language::get('release_date'); ?></label>
                                <input type="text" class="form-control text-center" id="release_date" name="release_date" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['release_date'] ?? ''), ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="episode_run_time"><?= $language::get('runtime'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="<?= $rIsEdit ? (int) (($rProps['duration_secs'] ?? 0) / 60) : ''; ?>">
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="rating"><?= $language::get('rating'); ?></label>
                            <input type="text" class="form-control text-center" id="rating" name="rating" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['rating'] ?? ''), ENT_QUOTES) : ''; ?>">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="direct_source" name="direct_source" value="1" <?= ($rIsEdit && $rEpisode['direct_source'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="direct_source"><?= $language::get('direct_source'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="direct_proxy" name="direct_proxy" value="1" <?= ($rIsEdit && $rEpisode['direct_proxy'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="direct_proxy">Direct Stream</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="read_native" name="read_native" value="1" <?= ($rIsEdit && $rEpisode['read_native'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="read_native"><?= $language::get('native_frames'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="movie_symlink" name="movie_symlink" value="1" <?= ($rIsEdit && $rEpisode['movie_symlink'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="movie_symlink"><?= $language::get('create_symlink'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="remove_subtitles" name="remove_subtitles" value="1" <?= ($rIsEdit && $rEpisode['remove_subtitles'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="remove_subtitles"><?= $language::get('remove_existing_subtitles'); ?></label>
                            </div>
                        </div>
                    </div>
                    <?php if (!$rMulti): ?>
                        <div class="row mb-6">
                            <div class="col-md-8">
                                <label class="form-label" for="target_container"><?= $language::get('target_container'); ?></label>
                                <select name="target_container" id="target_container" class="form-select">
                                    <?php foreach ($rContainers as $rContainer): ?>
                                        <option value="<?= $rContainer; ?>" <?= ($rIsEdit && $rEpisode['target_container'] === $rContainer) ? 'selected' : ''; ?>><?= $rContainer; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="custom_sid"><?= $language::get('custom_channel_sid'); ?></label>
                                <input type="text" class="form-control" id="custom_sid" name="custom_sid" value="<?= $rIsEdit ? htmlspecialchars((string) $rEpisode['custom_sid'], ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="movie_subtitles"><?= $language::get('subtitle_location'); ?></label>
                            <div class="input-group">
                                <input type="text" id="movie_subtitles" name="movie_subtitles" class="form-control" value="<?= htmlspecialchars((string) $rSubFile, ENT_QUOTES); ?>">
                                <button type="button" class="btn btn-label-primary" id="filebrowser-sub" data-target="movie_subtitles"><i class="icon-base ti tabler-folder"></i></button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                            <option value="0" <?= ($rIsEdit && (int) $rEpisode['transcode_profile_id'] === 0) ? 'selected' : ''; ?>><?= $language::get('transcoding_disabled'); ?></option>
                            <?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile): ?>
                                <option value="<?= (int) $rProfile['profile_id']; ?>" <?= ($rIsEdit && (int) $rEpisode['transcode_profile_id'] === (int) $rProfile['profile_id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-server" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label"><?= $language::get('server_tree'); ?></label>
                        <div id="server_tree" class="border rounded p-2"></div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="restart_on_edit" name="restart_on_edit" value="1">
                        <label class="form-check-label" for="restart_on_edit"><?= $rIsEdit ? $language::get('reprocess_on_edit') : $language::get('process_now'); ?></label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" name="submit_episode" class="btn btn-primary" id="episode-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<!-- File browser modal -->
<div class="modal fade" id="fileBrowserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('file_browser'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6 mb-2">
                        <label class="form-label" for="fb_server"><?= $language::get('server_name'); ?></label>
                        <select id="fb_server" class="form-select">
                            <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                <option value="<?= (int) $rServer['id']; ?>" <?= (RequestManager::has('server') && RequestManager::get('server') == $rServer['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label" for="fb_path"><?= $language::get('current_path'); ?></label>
                        <div class="input-group">
                            <input type="text" id="fb_path" name="current_path" class="form-control" value="/">
                            <button class="btn btn-label-primary" type="button" id="fb_go"><i class="icon-base ti tabler-arrow-right"></i></button>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="input-group">
                        <input type="text" id="fb_search" name="search" class="form-control" placeholder="<?= htmlspecialchars((string) $language::get('filter_directory'), ENT_QUOTES); ?>">
                        <button class="btn btn-label-secondary" type="button" id="fb_clear"><i class="icon-base ti tabler-x"></i></button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="fw-medium mb-1"><?= $language::get('directory'); ?></div>
                        <ul class="list-group" id="fb_dirs" style="max-height:280px;overflow:auto"></ul>
                    </div>
                    <div class="col-md-6">
                        <div class="fw-medium mb-1"><?= $language::get('filename'); ?></div>
                        <ul class="list-group" id="fb_files" style="max-height:280px;overflow:auto"></ul>
                    </div>
                </div>
                <?php if ($rMulti): ?>
                    <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-primary" id="fb_select_folder"><?= $language::get('add_this_directory'); ?></button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Image preview modal -->
<div class="modal fade" id="imgPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-body text-center p-2"><img id="imgPreviewImg" src="" alt="" style="max-width:100%"></div>
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
        var lang = {
            errText: <?= json_encode($language::get('error_occured')); ?>,
            noName: <?= json_encode($language::get('enter_an_episode_name')); ?>,
            noSource: <?= json_encode($language::get('enter_an_episode_source')); ?>,
            symlink: <?= json_encode($language::get('subtitle_location')); ?>,
            parent: <?= json_encode($language::get('parent_directory')); ?>,
            loading: <?= json_encode($language::get('loading')); ?>,
            foundEp: <?= json_encode($language::get('found_episodes')); ?>,
            noneEp: <?= json_encode($language::get('no_episodes_found')); ?>,
            none: <?= json_encode($language::get('no_results_found')); ?>,
            epLabel: <?= json_encode($language::get('episode')); ?>,
            epToAdd: <?= json_encode($language::get('episode_to_add')); ?>
        };
        var isMulti = <?= $rMulti ? 'true' : 'false'; ?>;
        var seriesTmdbId = <?= json_encode((string) ($rSeriesArr['tmdb_id'] ?? '')); ?>;
        var seriesEpRun = <?= json_encode((string) ($rSeriesArr['episode_run_time'] ?? '')); ?>;
        var changeTitle = false;
        var rEpisodes = {};
        var fbFilesList = [];
        var videoExt = ['mp4', 'mkv', 'mov', 'avi', 'mpg', 'mpeg', 'flv', 'wmv', 'm4v'];

        function esc(s) {
            return $('<div>').text(s == null ? '' : s).html();
        }

        function pad(n) {
            return n < 10 ? ('0' + n) : n;
        }

        function numFilter(el) {
            if (el) {
                el.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
        }

        // ---- select2 ----
        $('#tmdb_search, #target_container, #transcode_profile_id').select2({
            width: '100%',
            dropdownParent: $('#episode-form')
        });
        $('#fb_server').select2({
            width: '100%',
            dropdownParent: $('#fileBrowserModal')
        });

        // ---- jstree server tree ----
        $('#server_tree')
            .on('select_node.jstree', function(e, data) {
                if (data.node.id === 'source' || data.node.id === 'offline') {
                    return;
                }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') {
                                return false;
                            }
                            if (parent.id !== 'offline' && parent.id !== 'source') {
                                return false;
                            }
                            if (parent.id === '#') {
                                return false;
                            }
                            if (parent.id > 0 && $('#direct_proxy').is(':checked')) {
                                return false;
                            }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree ?: []); ?>
                },
                plugins: ['dnd']
            });

        // ---- image preview ----
        document.querySelectorAll('.js-img-preview').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var v = document.getElementById(btn.dataset.target).value.trim();
                if (!v) {
                    return;
                }
                document.getElementById('imgPreviewImg').src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(v);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('imgPreviewModal')).show();
            });
        });

        // ---- file browser ----
        var fbTarget = 'stream_source',
            fbDir = '/';
        var fbModal = document.getElementById('fileBrowserModal');

        function fbList() {
            var server = $('#fb_server').val();
            fbDir = $('#fb_path').val();
            if (fbDir.slice(-1) !== '/') {
                fbDir += '/';
            }
            $('#fb_path').val(fbDir);
            $('#fb_search').val('');
            var filter = (fbTarget === 'movie_subtitles') ? 'subs' : 'video';
            fbFilesList = [];
            $('#fb_dirs, #fb_files').html('<li class="list-group-item text-muted">' + lang.loading + '...</li>');
            $.getJSON('./api?action=listdir&dir=' + encodeURIComponent(fbDir) + '&server=' + encodeURIComponent(server) + '&filter=' + filter, function(data) {
                var dirs = '',
                    files = '';
                if (fbDir !== '/') {
                    dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name=".."><i class="icon-base ti tabler-arrow-back-up me-1"></i>' + lang.parent + '</li>';
                }
                if (data && data.result === true) {
                    $(data.data.dirs).each(function(i, d) {
                        dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name="' + esc(d) + '"><i class="icon-base ti tabler-folder me-1"></i>' + esc(d) + '</li>';
                    });
                    $(data.data.files).each(function(i, f) {
                        fbFilesList.push(f);
                        files += '<li class="list-group-item list-group-item-action fb-file" data-name="' + esc(f) + '"><i class="icon-base ti tabler-file me-1"></i>' + esc(f) + '</li>';
                    });
                }
                $('#fb_dirs').html(dirs || '<li class="list-group-item text-muted">-</li>');
                $('#fb_files').html(files || '<li class="list-group-item text-muted">-</li>');
            });
        }
        document.querySelectorAll('#filebrowser, #filebrowser-sub').forEach(function(b) {
            b.addEventListener('click', function() {
                fbTarget = b.dataset.target;
                $('#fb_path').val('/');
                fbList();
                bootstrap.Modal.getOrCreateInstance(fbModal).show();
            });
        });
        document.getElementById('fb_go').addEventListener('click', fbList);
        $('#fb_server').on('change', function() {
            $('#fb_path').val('/');
            fbList();
        });
        document.getElementById('fb_clear').addEventListener('click', function() {
            $('#fb_search').val('');
            $('#fb_files .fb-file').show();
        });
        $('#fb_search').on('input', function() {
            var q = this.value.toLowerCase();
            $('#fb_files .fb-file').each(function() {
                $(this).toggle($(this).data('name').toLowerCase().indexOf(q) !== -1);
            });
        });
        $('#fb_dirs').on('click', '.fb-dir', function() {
            var name = $(this).data('name');
            if (name === '..') {
                fbDir = fbDir.split('/').slice(0, -2).join('/') + '/';
            } else {
                fbDir += name + '/';
            }
            $('#fb_path').val(fbDir);
            fbList();
        });
        $('#fb_files').on('click', '.fb-file', function() {
            if (isMulti) {
                return;
            }
            var name = $(this).data('name'),
                val = 's:' + $('#fb_server').val() + ':' + fbDir + name;
            document.getElementById(fbTarget).value = val;
            if (fbTarget === 'stream_source') {
                var ext = name.substr(name.lastIndexOf('.') + 1);
                if ($('#target_container option[value="' + ext + '"]').length) {
                    $('#target_container').val(ext).trigger('change');
                }
                $('#stream_source').trigger('change');
            }
            bootstrap.Modal.getInstance(fbModal).hide();
        });

        // ---- multi: pick season folder and enumerate episodes ----
        var fbSelFolder = document.getElementById('fb_select_folder');
        if (fbSelFolder) {
            fbSelFolder.addEventListener('click', function() {
                document.getElementById('season_folder').value = fbDir;
                document.getElementById('server').value = $('#fb_server').val();
                var rID = 1,
                    rNames = {};
                $('#episode_add').html('');
                fbFilesList.forEach(function(fileName) {
                    var ext = fileName.split('.').pop().toLowerCase();
                    if (videoExt.indexOf(ext) !== -1) {
                        $('#episode_add').append(
                            '<div class="row mb-4"><div class="col-md-9"><input type="text" class="form-control" id="episode_' + rID + '_name" name="episode_' + rID + '_name" value="' + esc(fileName) + '" readonly></div>' +
                            '<div class="col-md-3"><input type="text" inputmode="numeric" class="form-control text-center" id="episode_' + rID + '_num" name="episode_' + rID + '_num" placeholder="' + esc(lang.epLabel) + '" value=""></div></div>'
                        );
                        numFilter(document.getElementById('episode_' + rID + '_num'));
                        rNames[rID] = fileName;
                        rID++;
                    }
                });
                $.post('./api?action=get_episode_ids', {
                    data: JSON.stringify(rNames)
                }, function(data) {
                    $(data.data).each(function(id, item) {
                        $('#episode_' + item[0] + '_num').val(item[1]);
                    });
                    var nextEpisode = 1;
                    $('[id^=episode_][id$=_num]').each(function() {
                        if (!$(this).val()) {
                            $(this).val(nextEpisode);
                        }
                        nextEpisode++;
                    });
                }, 'json');
                bootstrap.Modal.getInstance(fbModal).hide();
            });
        }

        // ---- direct source / symlink enable-disable ----
        function setDisabled(id, off) {
            var el = document.getElementById(id);
            if (!el) {
                return;
            }
            el.disabled = off;
            if ($(el).hasClass('select2-hidden-accessible')) {
                $(el).prop('disabled', off).trigger('change.select2');
            }
        }

        function toggle(ids, off) {
            ids.forEach(function(id) {
                setDisabled(id, off);
            });
        }
        var dsFields = ['movie_symlink', 'read_native', 'transcode_profile_id', 'remove_subtitles', 'movie_subtitles'];
        var slFields = ['direct_source', 'read_native', 'remove_subtitles', 'target_container', 'transcode_profile_id', 'movie_subtitles'];

        function evaluateDirectSource() {
            var ds = document.getElementById('direct_source').checked;
            toggle(dsFields, ds);
            document.getElementById('direct_proxy').disabled = !ds;
        }

        function checkSymlink() {
            var src = document.getElementById('stream_source');
            if (!src) {
                return;
            }
            var s = src.value;
            if (document.getElementById('movie_symlink').checked && s && s.indexOf('s:') !== 0 && s.indexOf('/') !== 0) {
                document.getElementById('movie_symlink').checked = false;
                window.xcToast(lang.symlink, 'error');
            }
        }

        function evaluateSymlink() {
            if (document.getElementById('direct_source').checked) {
                return;
            }
            checkSymlink();
            toggle(slFields, document.getElementById('movie_symlink').checked);
        }
        document.getElementById('direct_source').addEventListener('change', function() {
            evaluateDirectSource();
            evaluateSymlink();
        });
        document.getElementById('direct_proxy').addEventListener('change', evaluateDirectSource);
        document.getElementById('movie_symlink').addEventListener('change', evaluateSymlink);
        var srcEl = document.getElementById('stream_source');
        if (srcEl) {
            srcEl.addEventListener('change', checkSymlink);
        }
        evaluateDirectSource();
        evaluateSymlink();

        // ---- numeric filters ----
        numFilter(document.getElementById('season_num'));
        numFilter(document.getElementById('episode'));
        numFilter(document.getElementById('episode_run_time'));

        // ---- TMDb episode search + auto-fill (single mode only) ----
        var tmdbSearch = document.getElementById('tmdb_search');
        if (tmdbSearch && !isMulti) {
            $('#season_num').on('change', function() {
                if (changeTitle) {
                    changeTitle = false;
                    return;
                }
                $('#tmdb_search').empty().trigger('change');
                if (!$('#season_num').val()) {
                    return;
                }
                rEpisodes = {};
                $.getJSON('./api?action=tmdb_search&type=episode&term=' + encodeURIComponent(seriesTmdbId) + '&season=' + encodeURIComponent($('#season_num').val()) + '&language=' + encodeURIComponent($('#tmdb_language').val()), function(data) {
                    if (!data || data.result !== true) {
                        $('#tmdb_search').append(new Option(lang.none, -1, true, true));
                        $('#tmdb_search').val(-1).trigger('change');
                        return;
                    }
                    var eps = (data.data && data.data.episodes) || [];
                    var head = eps.length > 0 ? lang.foundEp.replace('{num}', eps.length) : lang.noneEp;
                    $('#tmdb_search').append(new Option(head, -1, true, true)).trigger('change');
                    $(eps).each(function(i, item) {
                        rEpisodes[item.id] = item;
                        $('#tmdb_search').append(new Option(lang.epLabel + ' ' + item.episode_number + ' - ' + item.name, item.id, true, true));
                    });
                    $('#tmdb_search').val(-1).trigger('change');
                });
            });
            $('#tmdb_search').on('change', function() {
                var id = $('#tmdb_search').val();
                if (!id || id <= -1) {
                    return;
                }
                var ep = rEpisodes[id];
                if (!ep) {
                    return;
                }
                var rFormat = 'S' + pad(ep.season_number) + 'E' + pad(ep.episode_number);
                $('#stream_display_name').val($('#series_name').val() + ' - ' + rFormat + ' - ' + ep.name);
                $('#movie_image').val(ep.still_path ? ('https://image.tmdb.org/t/p/w1280' + ep.still_path) : '');
                $('#release_date').val(ep.air_date || '');
                $('#episode_run_time').val(seriesEpRun);
                $('#plot').val(ep.overview || '');
                $('#rating').val(ep.vote_average || '');
                $('#tmdb_id').val(ep.id || '');
                $('#episode').val(ep.episode_number || '');
            });
            <?php if ($rIsEdit): ?>
                $('#season_num').trigger('change');
            <?php endif; ?>
        }

        // ---- submit ----
        document.getElementById('episode-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var ok = true;
            if (!isMulti) {
                if (!document.getElementById('stream_display_name').value.trim()) {
                    window.xcToast(lang.noName, 'error');
                    ok = false;
                }
                if (!document.getElementById('stream_source').value.trim()) {
                    window.xcToast(lang.noSource, 'error');
                    ok = false;
                }
            }
            if (!ok) {
                return;
            }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', {
                flat: true
            }));
            // Re-enable disabled fields so their values still post.
            document.querySelectorAll('#episode-form :disabled').forEach(function(el) {
                el.disabled = false;
            });
            var btn = document.getElementById('episode-submit');
            btn.disabled = true;
            fetch('post.php?action=episode&referer=', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var dt;
                    try {
                        dt = JSON.parse(txt);
                    } catch (err) {
                        dt = {
                            result: false
                        };
                    }
                    if (dt && dt.result !== false) {
                        if (dt.location) {
                            window.location = dt.location;
                        }
                        return;
                    }
                    btn.disabled = false;
                    evaluateDirectSource();
                    evaluateSymlink();
                    window.xcToast(lang.errText, 'error');
                })
                .catch(function() {
                    btn.disabled = false;
                    evaluateDirectSource();
                    evaluateSymlink();
                    window.xcToast(lang.errText, 'error');
                });
        });
    })();
</script>
</body>

</html>