<?php

/**
 * Movie add / edit / import (Bootstrap 5). Reached full-page from the movies
 * table ("Add" → movie, "Import" → movie?import) inside the new-UI shell, and as
 * an iframe modal ("Edit" → movie?id=X&modal=1) inside the modal shell. Tabs:
 * Details (name/year, TMDb search, source path + file browser + provider search,
 * categories, bouquets), Information (TMDb metadata — not in import mode),
 * Advanced (encode/symlink/subtitle/transcode options) and Server (the jstree
 * load-balancer tree). Categories/bouquets use select2 tags; the TMDb search
 * box auto-fills the Information tab; the file browser and provider search are
 * Bootstrap modals (magnificPopup is not part of the new-UI). Posts to
 * post.php?action=movie via fetch; in the modal it posts xcModalSaved to the
 * parent, full-page it returns to the list. Requires jstree (declared by the
 * controller).
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Reference\LocaleReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;

$rIsEdit   = isset($rMovie['id']);
$rIsImport = RequestManager::has('import');
$rProps    = $rMovie['properties'] ?? [];
$rMovieCat = $rIsEdit ? (json_decode((string) $rMovie['category_id'], true) ?: []) : [];
$rHasTmdb  = strlen((string) SettingsManager::get('tmdb_api_key')) > 0;
$rTmdbLang = !empty($rMovie['tmdb_language']) ? $rMovie['tmdb_language'] : ($rSettings['tmdb_language'] ?? 'en');
$rContainers = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];

$rSubFile = '';
if ($rIsEdit) {
    $rSubData = json_decode((string) $rMovie['movie_subtitles'], true);
    if (isset($rSubData['location'])) {
        $rSubFile = 's:' . $rSubData['location'] . ':' . ($rSubData['files'][0] ?? '');
    }
}
$rTitle = $rIsEdit ? $rMovie['stream_display_name'] : ($rIsImport ? $language::get('import_movies') : $language::get('add_movie'));
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4">
        <a href="movies" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
        <h4 class="mb-0"><?= htmlspecialchars((string) $rTitle, ENT_QUOTES); ?></h4>
    </div>
<?php endif; ?>

<form id="movie-form" autocomplete="off"<?= $rIsImport ? ' enctype="multipart/form-data"' : ''; ?>>
    <?php if ($rIsEdit): ?>
        <input type="hidden" name="edit" value="<?= (int) $rMovie['id']; ?>">
    <?php endif; ?>
    <input type="hidden" id="tmdb_id" name="tmdb_id" value="<?= $rIsEdit ? htmlspecialchars((string) ($rMovie['tmdb_id'] ?: ($rProps['tmdb_id'] ?? '')), ENT_QUOTES) : ''; ?>">
    <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
    <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="">
    <input type="hidden" name="category_create_list" id="category_create_list" value="">

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                    <?php if (!$rIsImport): ?>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-info" role="tab"><i class="icon-base ti tabler-movie me-1"></i><?= $language::get('information'); ?></button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-advanced" role="tab"><i class="icon-base ti tabler-adjustments me-1"></i><?= $language::get('advanced'); ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-server" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('server'); ?></button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <?php if (!$rIsImport): ?>
                        <div class="row mb-6">
                            <div class="col-md-9">
                                <label class="form-label" for="stream_display_name"><?= $language::get('movie_name'); ?></label>
                                <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" required value="<?= $rIsEdit ? htmlspecialchars((string) $rMovie['stream_display_name'], ENT_QUOTES) : (RequestManager::has('title') ? htmlspecialchars((string) RequestManager::get('title'), ENT_QUOTES) : ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="year"><?= $language::get('year'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control text-center" id="year" name="year" value="<?= $rIsEdit ? htmlspecialchars((string) $rMovie['year'], ENT_QUOTES) : ''; ?>">
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
                        <div class="mb-6">
                            <label class="form-label" for="stream_source"><?= $language::get('movie_path_or_url'); ?></label>
                            <div class="input-group">
                                <input type="text" id="stream_source" name="stream_source" class="form-control" required value="<?= $rIsEdit ? htmlspecialchars((string) $rPathSources, ENT_QUOTES) : (RequestManager::has('path') ? htmlspecialchars((string) RequestManager::get('path'), ENT_QUOTES) : ''); ?>">
                                <button type="button" class="btn btn-label-primary" id="filebrowser" data-target="stream_source"><i class="icon-base ti tabler-folder"></i></button>
                                <?php if (empty($rMobile)): ?>
                                    <button type="button" class="btn btn-label-primary" id="provider-streams"><i class="icon-base ti tabler-search"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-3"><?= $language::get('import_movies_help') ?: 'Importing movies parses your M3U or folder and pushes each item through Watch Folder. Category and bouquet allocation from Watch Folder Settings applies here too.'; ?></p>
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
                                <input type="text" id="import_folder" name="import_folder" class="form-control" value="<?= htmlspecialchars((string) $rPathSources, ENT_QUOTES); ?>">
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
                            <?php foreach (CategoryService::getAllByType('movie') as $rCategory): ?>
                                <option value="<?= (int) $rCategory['id']; ?>" <?= in_array((int) $rCategory['id'], array_map('intval', $rMovieCat), true) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="bouquets"><?= $rIsImport ? 'Fallback ' : ''; ?><?= $language::get('bouquets'); ?></label>
                        <select name="bouquets[]" id="bouquets" class="form-select" multiple>
                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                <option value="<?= (int) $rBouquet['id']; ?>" <?= ($rIsEdit && in_array((int) $rMovie['id'], array_map('intval', json_decode((string) $rBouquet['bouquet_movies'], true) ?: []), true)) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($rIsImport): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="disable_tmdb" name="disable_tmdb" value="1">
                                    <label class="form-check-label" for="disable_tmdb">Disable TMDb</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="ignore_no_match" name="ignore_no_match" value="1">
                                    <label class="form-check-label" for="ignore_no_match">Ignore No Match</label>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$rIsImport): ?>
                    <div class="tab-pane fade" id="tab-info" role="tabpanel">
                        <div class="mb-6">
                            <label class="form-label" for="movie_image"><?= $language::get('poster_url'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="movie_image" name="movie_image" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['movie_image'] ?? ''), ENT_QUOTES) : ''; ?>">
                                <button type="button" class="btn btn-label-secondary js-img-preview" data-target="movie_image"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="backdrop_path"><?= $language::get('backdrop_url'); ?></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="backdrop_path" name="backdrop_path" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['backdrop_path'][0] ?? ''), ENT_QUOTES) : ''; ?>">
                                <button type="button" class="btn btn-label-secondary js-img-preview" data-target="backdrop_path"><i class="icon-base ti tabler-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="plot"><?= $language::get('plot'); ?></label>
                            <textarea rows="4" class="form-control" id="plot" name="plot"><?= $rIsEdit ? htmlspecialchars((string) ($rProps['plot'] ?? ''), ENT_QUOTES) : ''; ?></textarea>
                        </div>
                        <div class="mb-6">
                            <label class="form-label" for="cast"><?= $language::get('cast'); ?></label>
                            <input type="text" class="form-control" id="cast" name="cast" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['cast'] ?? ''), ENT_QUOTES) : ''; ?>">
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="director"><?= $language::get('director'); ?></label>
                                <input type="text" class="form-control" id="director" name="director" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['director'] ?? ''), ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="genre"><?= $language::get('genres'); ?></label>
                                <input type="text" class="form-control" id="genre" name="genre" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['genre'] ?? ''), ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <label class="form-label" for="release_date"><?= $language::get('release_date'); ?></label>
                                <input type="text" class="form-control" id="release_date" name="release_date" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['release_date'] ?? ''), ENT_QUOTES) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="episode_run_time"><?= $language::get('runtime'); ?></label>
                                <input type="text" inputmode="numeric" class="form-control" id="episode_run_time" name="episode_run_time" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['episode_run_time'] ?? ''), ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <div class="row mb-6">
                            <div class="col-md-8">
                                <label class="form-label" for="youtube_trailer"><?= $language::get('youtube_trailer'); ?></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="youtube_trailer" name="youtube_trailer" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['youtube_trailer'] ?? ''), ENT_QUOTES) : ''; ?>">
                                    <button type="button" class="btn btn-label-secondary" id="yt-preview"><i class="icon-base ti tabler-eye"></i></button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="rating"><?= $language::get('rating'); ?></label>
                                <input type="text" class="form-control" id="rating" name="rating" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['rating'] ?? ''), ENT_QUOTES) : ''; ?>">
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="country"><?= $language::get('country'); ?></label>
                            <input type="text" class="form-control" id="country" name="country" value="<?= $rIsEdit ? htmlspecialchars((string) ($rProps['country'] ?? ''), ENT_QUOTES) : ''; ?>">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tab-pane fade" id="tab-advanced" role="tabpanel">
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="direct_source" name="direct_source" value="1" <?= ($rIsEdit && $rMovie['direct_source'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="direct_source"><?= $language::get('direct_source'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="direct_proxy" name="direct_proxy" value="1" <?= ($rIsEdit && $rMovie['direct_proxy'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="direct_proxy">Direct Stream</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="read_native" name="read_native" value="1" <?= ($rIsEdit && $rMovie['read_native'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="read_native"><?= $language::get('native_frames'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="movie_symlink" name="movie_symlink" value="1" <?= ($rIsEdit && $rMovie['movie_symlink'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="movie_symlink"><?= $language::get('create_symlink'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="remove_subtitles" name="remove_subtitles" value="1" <?= ($rIsEdit && $rMovie['remove_subtitles'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="remove_subtitles"><?= $language::get('remove_existing_subtitles'); ?></label>
                            </div>
                        </div>
                    </div>
                    <?php if (!$rIsImport): ?>
                        <div class="mb-6">
                            <label class="form-label" for="movie_subtitles"><?= $language::get('subtitle_location'); ?></label>
                            <div class="input-group">
                                <input type="text" id="movie_subtitles" name="movie_subtitles" class="form-control" value="<?= htmlspecialchars((string) $rSubFile, ENT_QUOTES); ?>">
                                <button type="button" class="btn btn-label-primary" id="filebrowser-sub" data-target="movie_subtitles"><i class="icon-base ti tabler-folder"></i></button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-8">
                            <label class="form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                            <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                                <option value="0" <?= ($rIsEdit && (int) $rMovie['transcode_profile_id'] === 0) ? 'selected' : ''; ?>><?= $language::get('transcoding_disabled'); ?></option>
                                <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                    <option value="<?= (int) $rProfile['profile_id']; ?>" <?= ($rIsEdit && (int) $rMovie['transcode_profile_id'] === (int) $rProfile['profile_id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="target_container"><?= $language::get('target_container'); ?></label>
                            <select name="target_container" id="target_container" class="form-select">
                                <?php foreach ($rContainers as $rContainer): ?>
                                    <option value="<?= $rContainer; ?>" <?= ($rIsEdit && $rMovie['target_container'] === $rContainer) ? 'selected' : ''; ?>><?= $rContainer; ?></option>
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
                        <label class="form-check-label" for="restart_on_edit"><?= $rIsEdit ? $language::get('reprocess_on_edit') : $language::get('process_movie'); ?></label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <button type="submit" class="btn btn-primary" id="movie-submit"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
    </div>
</form>

<!-- File browser modal -->
<div class="modal fade" id="fileBrowserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('file_browser') ?: 'File Browser'; ?></h5>
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
                            <input type="text" id="fb_path" class="form-control" value="/">
                            <button class="btn btn-label-primary" type="button" id="fb_go"><i class="icon-base ti tabler-arrow-right"></i></button>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="input-group">
                        <input type="text" id="fb_search" class="form-control" placeholder="<?= htmlspecialchars((string) $language::get('filter_files'), ENT_QUOTES); ?>...">
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
                <?php if ($rIsImport): ?>
                    <div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-primary" id="fb_select_folder"><?= $language::get('select') ?: 'Select'; ?></button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Provider search modal -->
<div class="modal fade" id="providerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('provider_streams') ?: 'Provider Streams'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="card-datatable table-responsive">
                    <table id="datatable-provider-movies" class="table"><thead><tr><th><?= $language::get('stream_name'); ?></th><th><?= $language::get('provider'); ?></th><th class="text-center"><?= $language::get('actions'); ?></th></tr></thead><tbody></tbody></table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Image / trailer preview modals -->
<div class="modal fade" id="imgPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body text-center p-2"><img id="imgPreviewImg" src="" alt="" style="max-width:100%"></div></div></div>
</div>
<div class="modal fade" id="ytModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-body p-0"><div class="ratio ratio-16x9"><iframe id="ytFrame" src="" allowfullscreen style="border:0"></iframe></div></div></div></div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var lang = {
            errText: <?= json_encode($language::get('error_occured')); ?>,
            noName: <?= json_encode($language::get('enter_movie_name')); ?>,
            noSource: <?= json_encode($language::get('enter_movie_source')); ?>,
            noM3u: <?= json_encode($language::get('select_m3u_file')); ?>,
            parent: <?= json_encode($language::get('parent_directory')); ?>,
            loading: <?= json_encode($language::get('loading')); ?>,
            found: <?= json_encode($language::get('found_results')); ?>,
            none: <?= json_encode($language::get('no_results_found')); ?>
        };
        var isImport = <?= $rIsImport ? 'true' : 'false'; ?>;
        var yearAppend = <?= (int) ($rSettings['movie_year_append'] ?? 0); ?>;
        var changeTitle = false;

        // ---- select2 tags (categories / bouquets) ----
        $('#category_id, #bouquets').select2({ width: '100%', tags: true, dropdownParent: $('#tab-details') });
        $('#tmdb_search, #tmdb_language, #transcode_profile_id, #target_container').select2({ width: '100%', dropdownParent: $('#movie-form') });
        function collectNew(sel) {
            var vals = $(sel).val() || [], nw = [];
            vals.forEach(function(v) { if (!/^\d+$/.test(v)) { nw.push(v); } });
            return JSON.stringify(nw);
        }

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
                            if (parent.id !== 'offline' && parent.id !== 'source') { return false; }
                            if (parent.id === '#') { return false; }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        // ---- image / youtube preview ----
        document.querySelectorAll('.js-img-preview').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var v = document.getElementById(btn.dataset.target).value.trim();
                if (!v) { return; }
                document.getElementById('imgPreviewImg').src = 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(v);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('imgPreviewModal')).show();
            });
        });
        var ytBtn = document.getElementById('yt-preview');
        if (ytBtn) {
            ytBtn.addEventListener('click', function() {
                var v = document.getElementById('youtube_trailer').value.trim();
                if (!v) { return; }
                document.getElementById('ytFrame').src = 'https://www.youtube.com/embed/' + encodeURIComponent(v);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('ytModal')).show();
            });
        }
        document.getElementById('ytModal').addEventListener('hidden.bs.modal', function() { document.getElementById('ytFrame').src = ''; });

        // ---- file browser ----
        var fbTarget = 'stream_source', fbDir = '/';
        var fbModal = document.getElementById('fileBrowserModal');
        function fbList() {
            var server = $('#fb_server').val();
            fbDir = $('#fb_path').val();
            if (fbDir.slice(-1) !== '/') { fbDir += '/'; }
            $('#fb_path').val(fbDir);
            var filter = (fbTarget === 'movie_subtitles') ? 'subs' : 'video';
            $('#fb_dirs, #fb_files').html('<li class="list-group-item text-muted">' + lang.loading + '...</li>');
            $.getJSON('./api?action=listdir&dir=' + encodeURIComponent(fbDir) + '&server=' + encodeURIComponent(server) + '&filter=' + filter, function(data) {
                var dirs = '', files = '';
                if (fbDir !== '/') { dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name=".."><i class="icon-base ti tabler-arrow-back-up me-1"></i>' + lang.parent + '</li>'; }
                if (data && data.result === true) {
                    $(data.data.dirs).each(function(i, d) { dirs += '<li class="list-group-item list-group-item-action fb-dir" data-name="' + esc(d) + '"><i class="icon-base ti tabler-folder me-1"></i>' + esc(d) + '</li>'; });
                    $(data.data.files).each(function(i, f) { files += '<li class="list-group-item list-group-item-action fb-file" data-name="' + esc(f) + '"><i class="icon-base ti tabler-file me-1"></i>' + esc(f) + '</li>'; });
                }
                $('#fb_dirs').html(dirs || '<li class="list-group-item text-muted">—</li>');
                $('#fb_files').html(files || '<li class="list-group-item text-muted">—</li>');
            });
        }
        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
        document.querySelectorAll('#filebrowser, #filebrowser-sub').forEach(function(b) {
            b.addEventListener('click', function() {
                fbTarget = b.dataset.target;
                $('#fb_path').val('/'); fbList();
                bootstrap.Modal.getOrCreateInstance(fbModal).show();
            });
        });
        document.getElementById('fb_go').addEventListener('click', fbList);
        $('#fb_server').on('change', function() { $('#fb_path').val('/'); fbList(); });
        document.getElementById('fb_clear').addEventListener('click', function() { $('#fb_search').val(''); $('#fb_files .fb-file').show(); });
        $('#fb_search').on('input', function() {
            var q = this.value.toLowerCase();
            $('#fb_files .fb-file').each(function() { $(this).toggle($(this).data('name').toLowerCase().indexOf(q) !== -1); });
        });
        $('#fb_dirs').on('click', '.fb-dir', function() {
            var name = $(this).data('name');
            if (name === '..') { fbDir = fbDir.split('/').slice(0, -2).join('/') + '/'; }
            else { fbDir += name + '/'; }
            $('#fb_path').val(fbDir); fbList();
        });
        $('#fb_files').on('click', '.fb-file', function() {
            var name = $(this).data('name'), val = 's:' + $('#fb_server').val() + ':' + fbDir + name;
            document.getElementById(fbTarget).value = val;
            if (fbTarget === 'stream_source') {
                var ext = name.substr(name.lastIndexOf('.') + 1);
                if ($('#target_container option[value="' + ext + '"]').length) { $('#target_container').val(ext).trigger('change'); }
                $('#stream_source').trigger('change');
            }
            bootstrap.Modal.getInstance(fbModal).hide();
        });
        var fbSelFolder = document.getElementById('fb_select_folder');
        if (fbSelFolder) {
            fbSelFolder.addEventListener('click', function() {
                document.getElementById('import_folder').value = 's:' + $('#fb_server').val() + ':' + fbDir;
                bootstrap.Modal.getInstance(fbModal).hide();
            });
        }

        // ---- provider search ----
        var provBtn = document.getElementById('provider-streams');
        if (provBtn) {
            var provTable = $('#datatable-provider-movies').DataTable({
                processing: true, serverSide: true, searchDelay: 250, responsive: false,
                order: [[0, 'asc']], pageLength: <?= (int) ($rSettings['default_entries'] ?? 10) ?: 10; ?>,
                ajax: { url: './table', data: function(d) { d.id = 'provider_streams'; d.type = 'movie'; } },
                columnDefs: [{ className: 'dt-center', targets: [2] }]
            });
            provBtn.addEventListener('click', function() {
                provTable.search(document.getElementById('stream_display_name').value).draw();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('providerModal')).show();
            });
            // The provider rows render a button that calls this global (legacy contract).
            window.addStream = function(name, url) {
                if (url === undefined) { url = name; name = null; }
                document.getElementById('stream_source').value = url;
                if (name !== null) { $('#stream_display_name').val(name).trigger('change'); }
                bootstrap.Modal.getInstance(document.getElementById('providerModal')).hide();
            };
        }

        // ---- direct source / symlink enable-disable ----
        function toggle(ids, off) { ids.forEach(function(id) { var el = document.getElementById(id); if (el) { el.disabled = off; } }); }
        var dsFields = ['movie_symlink', 'read_native', 'transcode_profile_id', 'remove_subtitles', 'movie_subtitles'];
        var slFields = ['direct_source', 'direct_proxy', 'read_native', 'remove_subtitles', 'target_container', 'transcode_profile_id', 'movie_subtitles'];
        function evaluateDirectSource() {
            var ds = document.getElementById('direct_source').checked;
            toggle(dsFields, ds);
            document.getElementById('direct_proxy').disabled = !ds;
        }
        function checkSymlink() {
            var s = document.getElementById('stream_source').value;
            if (document.getElementById('movie_symlink').checked && s && s.indexOf('s:') !== 0 && s.indexOf('/') !== 0) {
                document.getElementById('movie_symlink').checked = false;
            }
        }
        function evaluateSymlink() {
            if (document.getElementById('direct_source').checked) { return; }
            checkSymlink();
            toggle(slFields, document.getElementById('movie_symlink').checked);
        }
        if (!isImport) {
            document.getElementById('direct_source').addEventListener('change', function() { evaluateDirectSource(); evaluateSymlink(); });
            document.getElementById('direct_proxy').addEventListener('change', evaluateDirectSource);
            document.getElementById('movie_symlink').addEventListener('change', evaluateSymlink);
            document.getElementById('stream_source').addEventListener('change', checkSymlink);
            evaluateDirectSource();
            evaluateSymlink();
        }

        // ---- import M3U/folder toggle ----
        var it1 = document.getElementById('import_type_1'), it2 = document.getElementById('import_type_2');
        if (it1 && it2) {
            it1.addEventListener('change', function() { document.getElementById('import_m3uf_toggle').hidden = false; document.getElementById('import_folder_toggle').hidden = true; });
            it2.addEventListener('change', function() { document.getElementById('import_m3uf_toggle').hidden = true; document.getElementById('import_folder_toggle').hidden = false; });
        }

        // ---- TMDb search + auto-fill ----
        var tmdbSearch = document.getElementById('tmdb_search');
        if (tmdbSearch) {
            $('#tmdb_language').on('change', function() { $('#stream_display_name').trigger('change'); });
            $('#stream_display_name').on('change', function() {
                if (changeTitle) { changeTitle = false; return; }
                $('#tmdb_search').empty().trigger('change');
                var term = $('#stream_display_name').val();
                if (!term) { return; }
                $.getJSON('./api?action=tmdb_search&type=movie&term=' + encodeURIComponent(term) + '&language=' + encodeURIComponent($('#tmdb_language').val()), function(data) {
                    if (!data || data.result !== true) { $('#tmdb_search').append(new Option(lang.none, -1, true, true)); return; }
                    var head = data.data.length > 0 ? lang.found.replace('{num}', data.data.length) : lang.none;
                    $('#tmdb_search').append(new Option(head, -1, true, true)).trigger('change');
                    $(data.data).each(function(i, item) {
                        var t = item.title;
                        if (item.release_date) {
                            var yr = item.release_date.substring(0, 4);
                            t = yearAppend === 0 ? (item.title + ' (' + yr + ')') : (yearAppend === 1 ? (item.title + ' - ' + yr) : item.title);
                        }
                        $('#tmdb_search').append(new Option(t, item.id, true, true));
                    });
                    $('#tmdb_search').val(-1).trigger('change');
                });
            });
            $('#tmdb_search').on('change', function() {
                var id = $('#tmdb_search').val();
                if (!id || id <= -1) { return; }
                $.getJSON('./api?action=tmdb&type=movie&id=' + encodeURIComponent(id) + '&language=' + encodeURIComponent($('#tmdb_language').val()), function(data) {
                    if (!data || data.result !== true) { return; }
                    var d = data.data;
                    changeTitle = true;
                    $('#year').val(d.release_date ? d.release_date.substr(0, 4) : '');
                    $('#stream_display_name').val(d.title);
                    $('#movie_image').val(d.poster_path ? ('https://image.tmdb.org/t/p/w600_and_h900_bestv2' + d.poster_path) : '');
                    $('#backdrop_path').val(d.backdrop_path ? ('https://image.tmdb.org/t/p/w1280' + d.backdrop_path) : '');
                    $('#release_date').val(d.release_date || '');
                    $('#episode_run_time').val(d.runtime || '');
                    $('#youtube_trailer').val(d.trailer || '');
                    $('#plot').val(d.overview || '');
                    $('#rating').val(d.vote_average || '');
                    $('#tmdb_id').val(d.id || '');
                    var cast = ((d.credits && d.credits.cast) || []).slice(0, 5).map(function(m) { return m.name; }).join(', ');
                    $('#cast').val(cast);
                    var genres = (d.genres || []).slice(0, 3).map(function(g) { return g.name; }).join(', ');
                    $('#genre').val(genres);
                    var dirs = ((d.credits && d.credits.crew) || []).filter(function(m) { return m.department === 'Directing' || m.known_for_department === 'Directing'; }).slice(0, 3).map(function(m) { return m.name; }).join(', ');
                    $('#director').val(dirs);
                    $('#country').val((d.production_countries && d.production_countries[0]) ? d.production_countries[0].name : '');
                });
            });
            <?php if ($rIsEdit || RequestManager::has('title')): ?>
            $('#stream_display_name').trigger('change');
            <?php endif; ?>
        }

        // ---- submit ----
        document.getElementById('movie-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var ok = true;
            if (!isImport) {
                if (!document.getElementById('stream_display_name').value.trim()) { alert(lang.noName); ok = false; }
                if (!document.getElementById('stream_source').value.trim()) { alert(lang.noSource); ok = false; }
            } else if (!document.getElementById('m3u_file').value && !document.getElementById('import_folder').value) {
                alert(lang.noM3u); ok = false;
            }
            if (!ok) { return; }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }));
            document.getElementById('category_create_list').value = collectNew('#category_id');
            document.getElementById('bouquet_create_list').value = collectNew('#bouquets');
            // Re-enable disabled fields so their values still post.
            document.querySelectorAll('#movie-form :disabled').forEach(function(el) { el.disabled = false; });
            var btn = document.getElementById('movie-submit');
            btn.disabled = true;
            fetch('post.php?action=movie', { method: 'POST', body: new FormData(e.target), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var dt; try { dt = JSON.parse(txt); } catch (err) { dt = { result: false }; }
                    if (dt && dt.result !== false) {
                        if (window.parent !== window) { window.parent.postMessage('xcModalSaved', '*'); }
                        else { window.location.href = dt.location || 'movies'; }
                        return;
                    }
                    btn.disabled = false;
                    if (!isImport) { evaluateDirectSource(); evaluateSymlink(); }
                    alert(lang.errText);
                })
                .catch(function() {
                    btn.disabled = false;
                    if (!isImport) { evaluateDirectSource(); evaluateSymlink(); }
                    alert(lang.errText);
                });
        });
    })();
</script>
</body>

</html>
