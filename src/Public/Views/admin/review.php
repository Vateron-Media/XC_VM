<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Reference\LocaleReference;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\Vod\MovieService;

if (RequestManager::has('type')) {
    $rType = intval(RequestManager::get('type'));
} else {
    if (RequestManager::has('type')) {
        $rType = intval(RequestManager::get('type'));
    } else {
        $rType = 1;
    }
}

if (RequestManager::has('post_data')) {
    $rPostData = json_decode(base64_decode(RequestManager::get('post_data')), true);
    $rPostData['review'] = array();
    $rPostData['notes'] = '';
    $rPostData['custom_sid'] = $rPostData['notes'];
    $rCategoryIDs = array();

    foreach (CategoryService::getAllByType(array(1 => 'live', 2 => 'movie')[intval($rType)]) as $rCategory) {
        $rCategoryIDs[] = $rCategory['id'];
    }
    $rNewCategories = array();

    foreach (RequestManager::get('category_selection') as $rCategory) {
        if (in_array($rCategory, $rCategoryIDs) || is_numeric($rCategory)) {
        } else {
            $rReturn = CategoryService::process(array('category_type' => array(1 => 'live', 2 => 'movie')[intval($rType)], 'category_name' => $rCategory));
            $rNewCategories[$rCategory] = $rReturn['data']['insert_id'];
        }
    }

    foreach (RequestManager::getAll() as $rKey => $rValue) {
        if (substr($rKey, 0, 7) != 'import_') {
        } else {
            $rID = intval(explode('import_', $rKey)[1]);

            if (!RequestManager::get('import_' . $rID)) {
            } else {
                $rCategories = array();

                foreach (json_decode(RequestManager::get('category_id_' . $rID), true) as $rCategory) {
                    if (!is_numeric($rCategory) && isset($rNewCategories[$rCategory])) {
                        $rCategories[] = intval($rNewCategories[$rCategory]);
                    } else {
                        if (!is_numeric($rCategory)) {
                        } else {
                            $rCategories[] = intval($rCategory);
                        }
                    }
                }

                if ($rType == 1) {
                    $rPostData['review'][] = array('stream_source' => array(RequestManager::get('url_' . $rID)), 'stream_icon' => RequestManager::get('icon_' . $rID), 'stream_display_name' => RequestManager::get('name_' . $rID), 'epg_lang' => null, 'channel_id' => (!empty(RequestManager::get('channel_id_' . $rID)) ? RequestManager::get('channel_id_' . $rID) : null), 'epg_api' => (!empty(RequestManager::get('epg_type_' . $rID)) ? RequestManager::get('epg_type_' . $rID) : 0), 'epg_id' => (!empty(RequestManager::get('epg_id_' . $rID)) ? RequestManager::get('epg_id_' . $rID) : 0), 'bouquets' => json_decode(RequestManager::get('bouquets_' . $rID), true), 'category_id' => $rCategories);
                } else {
                    $rPostData['review'][] = array('stream_source' => array(RequestManager::get('url_' . $rID)), 'stream_display_name' => RequestManager::get('name_' . $rID), 'tmdb_id' => (!empty(RequestManager::get('tmdb_id_' . $rID)) ? RequestManager::get('tmdb_id_' . $rID) : null), 'bouquets' => json_decode(RequestManager::get('bouquets_' . $rID), true), 'category_id' => $rCategories);
                }
            }
        }
    }

    if ($rType == 1) {
        $rReturn = StreamService::process($rPostData);
        $_STATUS = $rReturn['status'];

        if ($_STATUS != STATUS_SUCCESS) {
        } else {
            header('Location: ./streams?status=' . STATUS_SUCCESS);
            exit();
        }
    } else {
        $rReturn = MovieService::process($rPostData);
        $_STATUS = $rReturn['status'];

        if ($_STATUS != STATUS_SUCCESS) {
        } else {
            header('Location: ./movies?status=' . STATUS_SUCCESS);
            exit();
        }
    }
} else {
    if (!isset($_FILES['m3u_file'])) {
    } else {
        unset(RequestManager::getAll()['submit_stream']);
        $rPostData = base64_encode(json_encode(RequestManager::getAll()));
        $rCategories = CategoryService::getAllByType(array(1 => 'live', 2 => 'movie')[intval($rType)]);
        $rBouquets = BouquetService::getAllSimple();
        $rSources = array();
        $rDuplicates = array();
        $db->query('SELECT `stream_source` FROM `streams` WHERE `type` = ?;', $rType);

        foreach ($db->get_rows() as $rRow) {
            foreach (json_decode($rRow['stream_source'], true) as $rURL) {
                if (in_array($rURL, $rSources)) {
                } else {
                    $rSources[] = str_replace('https://', 'http://', $rURL);
                }
            }
        }
        $rStreamDatabase = array();

        if (empty($_FILES['m3u_file']['tmp_name']) || !in_array(strtolower(pathinfo($_FILES['m3u_file']['name'], PATHINFO_EXTENSION)), array('m3u', 'm3u8'))) {
            $_STATUS = STATUS_INVALID_FILE;
        } else {
            $rImport = array();
            $rResults = StreamService::parseM3U($_FILES['m3u_file']['tmp_name']);

            foreach ($rResults as $rResult) {
                $rTags = $rResult->getExtTags();
                $rTag = $rTags[0] ?? null;
                $rURL = $rResult->getPath();

                if ($rURL) {
                    if ($rType == 1) {
                        $rExtensions = array('ts', 'm3u8', 'm3u', 'mpd', 'ism', '');
                    } else {
                        $rExtensions = array('mp4', 'mkv', 'mov', 'avi', 'mpg', 'mpeg', 'flv', 'wmv', 'm4v');
                    }

                    if (!in_array(strtolower(pathinfo(explode('?', $rURL)[0])['extension'] ?? ''), $rExtensions)) {
                    } else {
                        $rExists = in_array(str_replace('https://', 'http://', $rURL), $rSources);

                        if ($rExists && !RequestManager::get('duplicates')) {
                        } else {
                            if (count($rImport) < 500) {
                                if ($rType == 1) {
                                    $rImport[] = array('url' => $rURL, 'logo' => ($rTag ? ($rTag->getAttribute('tvg-logo') ?: ($rTag->getAttribute('logo') ?: '')) : ''), 'tvg_id' => ($rTag ? ($rTag->getAttribute('tvg-id') ?: '') : ''), 'title' => ($rTag ? ($rTag->getTitle() ?: basename(parse_url($rURL, PHP_URL_PATH) ?: $rURL)) : basename(parse_url($rURL, PHP_URL_PATH) ?: $rURL)), 'category' => ($rTag ? ($rTag->getAttribute('group-title') ?: '') : ''), 'exists' => $rExists);
                                } else {
                                    $rImport[] = array('url' => $rURL, 'title' => ($rTag ? ($rTag->getTitle() ?: basename(parse_url($rURL, PHP_URL_PATH) ?: $rURL)) : basename(parse_url($rURL, PHP_URL_PATH) ?: $rURL)), 'category' => ($rTag ? ($rTag->getAttribute('group-title') ?: '') : ''), 'exists' => $rExists);
                                }
                            } else {
                                $_STATUS = STATUS_TOO_MANY_RESULTS;
                                break;
                            }
                        }
                    }
                }
            }

            if (count($rImport) == 0) {
                $_STATUS = STATUS_NO_SOURCES;
                $rImport = null;
            }
        }
    }
}

if (isset($rImport) && $rImport) {
    // Code for processing $rImport
} else {
    $rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='text-success'>Live Stream</strong>", 'icon' => 'icon-base ti tabler-player-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='text-muted'>Offline</strong>", 'icon' => 'icon-base ti tabler-player-stop', 'state' => array('opened' => true)));

    foreach ($rServers as $rServer) {
        $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'icon-base ti tabler-server', 'state' => array('opened' => true));
    }
    $rStreamArguments = StreamConfigRepository::getStreamArguments();
    $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();
}

$rLogoSet = $rCategorySet = array();

$rTmdbLang   = !empty(RequestManager::get('tmdb_language')) ? RequestManager::get('tmdb_language') : ($rSettings['tmdb_language'] ?? 'en');
$rYearAppend = intval($rSettings['movie_year_append'] ?? 0);
$rTitleText  = ($rType == 1 ? $language::get('stream') : $language::get('movie')) . ' ' . $language::get('review');
$rBackHref   = $rType == 1 ? 'streams' : 'movies';
?>

<form id="stream_form" action="./review?type=<?= intval($rType); ?>" method="POST" autocomplete="off" <?= !isset($rImport) ? ' enctype="multipart/form-data"' : ''; ?>>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center">
            <a href="<?= $rBackHref; ?>" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
            <h4 class="mb-0"><?= htmlspecialchars((string) $rTitleText, ENT_QUOTES); ?></h4>
        </div>
        <?php if (isset($rImport)): ?>
            <button type="submit" name="submit_stream" value="Import Selected" class="btn btn-primary"><?= $language::get('import_selected') ?: 'Import Selected'; ?></button>
        <?php endif; ?>
    </div>

    <?php if (isset($_STATUS) && $_STATUS == STATUS_INVALID_FILE): ?>
        <div class="alert alert-danger alert-dismissible" role="alert">
            <?= $language::get('invalid_playlist') ?: 'Invalid playlist selected, please ensure the playlist is in M3U format.'; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif (isset($_STATUS) && $_STATUS == STATUS_TOO_MANY_RESULTS): ?>
        <div class="alert alert-danger alert-dismissible" role="alert">
            <?= $language::get('too_many_results') ?: 'The playlist you selected has more than 500 results, the review page will not show all results.'; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif (isset($_STATUS) && $_STATUS == STATUS_NO_SOURCES): ?>
        <div class="alert alert-danger alert-dismissible" role="alert">
            <?= $language::get('no_sources_found') ?: 'No results were found in the playlist.'; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif (!isset($rImport)): ?>
        <div class="alert alert-info" role="alert">
            <?= $language::get('review_info_500') ?: 'The Review page is for playlists of fewer than 500 items; use the normal M3U Import function for larger playlists or reduce the playlist. The review page will cut off at 500 results and not process any more if you upload a larger playlist anyway.'; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($rImport)): ?>
        <input type="hidden" name="post_data" value="<?= htmlspecialchars($rPostData); ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars((string) $rType); ?>">

        <div class="card mb-6">
            <div class="card-body">
                <h5 class="mb-1"><?= $language::get('category_creation') ?: 'Category Creation'; ?></h5>
                <p class="text-muted small mb-3"><?= $language::get('category_creation_help') ?: 'You can create categories by typing them in the box below; this lets you quickly add categories to the imported results.'; ?></p>
                <div class="row">
                    <div class="<?= $rType == 1 ? 'col-12' : 'col-md-8'; ?>">
                        <select name="category_selection[]" id="category_selection" class="form-select" multiple data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                            <?php foreach ($rCategories as $rCategory): ?>
                                <option selected value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($rType != 1): ?>
                        <div class="col-md-4">
                            <select name="tmdb_language" id="tmdb_language" class="form-select">
                                <?php foreach (LocaleReference::tmdbLanguages() as $rKey => $rLanguage): ?>
                                    <option value="<?= htmlspecialchars((string) $rKey, ENT_QUOTES); ?>" <?= ($rKey == $rTmdbLang) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rLanguage, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <h5 class="mb-1"><?= $rType == 1 ? ($language::get('stream_import') ?: 'Stream Import') : ($language::get('movie_import') ?: 'Movie Import'); ?></h5>
                <p class="text-muted small mb-3"><?= $rType == 1 ? ($language::get('stream_import_help') ?: 'To import a stream, ensure the checkbox next to it is selected. You must open each page for that page of streams to be included in the import.') : ($language::get('movie_import_help') ?: 'To import a movie, ensure the checkbox next to it is selected. You must open each page for that page of movies to be included in the import.'); ?></p>
                <div class="table-responsive">
                    <table id="datatable" class="table table-striped">
                        <thead>
                            <tr>
                                <th class="text-center"><?= $language::get('add'); ?></th>
                                <th class="text-center"><?= $rType == 1 ? $language::get('icon') : $language::get('image'); ?></th>
                                <th><?= $rType == 1 ? $language::get('stream_name') : $language::get('movie_name'); ?></th>
                                <th><?= $language::get('category'); ?></th>
                                <th><?= $language::get('bouquets'); ?></th>
                                <?php if ($rType == 1): ?>
                                    <th><?= $language::get('epg_search'); ?></th>
                                    <th class="text-center"><?= $language::get('language'); ?></th>
                                <?php else: ?>
                                    <th><?= $language::get('tmdb_results'); ?></th>
                                    <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 0;
                            foreach ($rImport as $rStream):
                                $i++;
                                $rLogo  = $rStream['logo'] ?? '';
                                $rTvgId = $rStream['tvg_id'] ?? ''; ?>
                                <tr id="stream_<?= $i; ?>" data-id="<?= $i; ?>">
                                    <td class="text-center">
                                        <div class="form-check d-inline-block">
                                            <input id="check_<?= $i; ?>" data-id="<?= $i; ?>" type="checkbox" class="form-check-input activate<?= $rStream['exists'] ? '' : ' checked'; ?>">
                                        </div>
                                        <input type="hidden" id="import_<?= $i; ?>" name="import_<?= $i; ?>" value="<?= $rStream['exists'] ? '0' : '1'; ?>">
                                        <input type="hidden" id="name_i_<?= $i; ?>" name="name_<?= $i; ?>" value="<?= htmlspecialchars((string) $rStream['title'], ENT_QUOTES); ?>">
                                        <input type="hidden" id="category_id_i_<?= $i; ?>" name="category_id_<?= $i; ?>" value="[]">
                                        <input type="hidden" id="bouquets_i_<?= $i; ?>" name="bouquets_<?= $i; ?>" value="[]">
                                        <input type="hidden" id="url_<?= $i; ?>" name="url_<?= $i; ?>" value="<?= htmlspecialchars((string) $rStream['url'], ENT_QUOTES); ?>">
                                        <input type="hidden" id="icon_<?= $i; ?>" name="icon_<?= $i; ?>" value="<?= htmlspecialchars((string) $rLogo, ENT_QUOTES); ?>">
                                        <?php if ($rType == 1): ?>
                                            <input type="hidden" id="channel_id_<?= $i; ?>" name="channel_id_<?= $i; ?>" value="<?= htmlspecialchars((string) $rTvgId, ENT_QUOTES); ?>">
                                            <input type="hidden" id="epg_type_<?= $i; ?>" name="epg_type_<?= $i; ?>" value="0">
                                            <input type="hidden" id="epg_id_<?= $i; ?>" name="epg_id_<?= $i; ?>" value="0">
                                        <?php else: ?>
                                            <input type="hidden" id="tmdb_id_<?= $i; ?>" name="tmdb_id_<?= $i; ?>" value="">
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center" id="picon_<?= $i; ?>">
                                        <a href="javascript:void(0);" onClick="openImage(this);" data-src="<?= strlen((string) $rLogo) > 0 ? './resize?maxw=512&maxh=512&url=' . urlencode((string) $rLogo) : ''; ?>">
                                            <img loading="lazy" src="<?= strlen((string) $rLogo) > 0 ? './resize?maxw=96&maxh=32&url=' . urlencode((string) $rLogo) : ''; ?>" alt="">
                                        </a>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="name_<?= $i; ?>" value="<?= htmlspecialchars((string) $rStream['title'], ENT_QUOTES); ?>">
                                            <?php if ($rType != 1): ?>
                                                <button type="button" onClick="scanTMDb(<?= $i; ?>);" class="btn btn-label-primary"><i class="icon-base ti tabler-search"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <select id="category_id_<?= $i; ?>" class="form-select category_id" data-id="<?= $i; ?>" multiple data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                            <?php foreach ($rCategories as $rCategory): ?>
                                                <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select id="bouquets_<?= $i; ?>" data-id="<?= $i; ?>" class="form-select bouquet" multiple data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                            <?php foreach ($rBouquets as $rBouquet): ?>
                                                <option value="<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <?php if ($rType == 1): ?>
                                        <td>
                                            <select id="epg_api_<?= $i; ?>" data-id="<?= $i; ?>" class="form-select epg_api"></select>
                                        </td>
                                        <td class="text-center">
                                            <button onClick="clearEPG(this);" id="clear_epg_<?= $i; ?>" data-id="<?= $i; ?>" type="button" title="<?= $language::get('clear_epg'); ?>" class="btn btn-label-secondary btn-sm"><i class="icon-base ti tabler-x"></i></button>
                                            <a href="javascript:void(0);" title="<?= htmlspecialchars((string) $rStream['url'], ENT_QUOTES); ?>" class="btn btn-label-primary btn-sm"><i class="icon-base ti tabler-link"></i></a>
                                        </td>
                                    <?php else: ?>
                                        <td>
                                            <select id="tmdb_search_<?= $i; ?>" data-id="<?= $i; ?>" class="form-select tmdb_search"></select>
                                        </td>
                                        <td class="text-center">
                                            <a href="javascript:void(0);" title="<?= htmlspecialchars((string) $rStream['title'], ENT_QUOTES) . ' — ' . htmlspecialchars((string) $rStream['url'], ENT_QUOTES); ?>" class="btn btn-label-primary btn-sm"><i class="icon-base ti tabler-info-circle"></i></a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
        <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
        <input type="hidden" name="type" value="<?= htmlspecialchars((string) $rType); ?>">

        <div class="card mb-6">
            <div class="card-header px-0 pt-2">
                <div class="nav-align-top">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-options" role="tab"><i class="icon-base ti tabler-folder me-1"></i><?= $language::get('options'); ?></button></li>
                        <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-servers" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
                    </ul>
                </div>
            </div>
            <div class="card-body">
                <div class="tab-content p-0">
                    <div class="tab-pane fade show active" id="tab-options" role="tabpanel">
                        <div class="row mb-6">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="duplicates" name="duplicates" value="1">
                                    <label class="form-check-label" for="duplicates"><?= $language::get('show_potential_duplicates') ?: 'Show Potential Duplicates'; ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('this_option_will_remove_all_tooltip'); ?>"></i></label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="m3u_file"><?= $language::get('m3u_file'); ?></label>
                                <input type="file" class="form-control" id="m3u_file" name="m3u_file" accept=".m3u,.m3u8">
                            </div>
                        </div>
                        <?php if ($rType == 1): ?>
                            <div class="row g-3 mb-6">
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="gen_timestamps" name="gen_timestamps" value="1" checked>
                                        <label class="form-check-label" for="gen_timestamps"><?= $language::get('generate_pts'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('allow_ffmpeg_to_generate_presentation_tooltip'); ?>"></i></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="read_native" name="read_native" value="1">
                                        <label class="form-check-label" for="read_native"><?= $language::get('native_frames'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('you_should_always_read_live_tooltip'); ?>"></i></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="stream_all" name="stream_all" value="1">
                                        <label class="form-check-label" for="stream_all"><?= $language::get('stream_all_codecs'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('this_option_will_stream_all_tooltip'); ?>"></i></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_record" name="allow_record" value="1" checked>
                                        <label class="form-check-label" for="allow_record"><?= $language::get('allow_recording'); ?></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="direct_source" name="direct_source" value="1">
                                        <label class="form-check-label" for="direct_source"><?= $language::get('direct_source'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('redirect_clients_to_the_source_tooltip'); ?>"></i></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="direct_proxy" name="direct_proxy" value="1">
                                        <label class="form-check-label" for="direct_proxy"><?= $language::get('direct_stream'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('when_using_direct_source_hide_tooltip_title'); ?>"></i></label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="rtmp_output" name="rtmp_output" value="1">
                                        <label class="form-check-label" for="rtmp_output"><?= $language::get('output_rtmp'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('enable_rtmp_output_for_this_channel'); ?>"></i></label>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-6">
                                <div class="col-md-4">
                                    <label class="form-label" for="probesize_ondemand"><?= $language::get('on_demand_probesize'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('adjustable_probesize_for_ondemand_streams_tooltip'); ?>"></i></label>
                                    <input type="text" inputmode="numeric" class="form-control" id="probesize_ondemand" name="probesize_ondemand" value="128000">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('episode_tooltip_7'); ?>"></i></label>
                                    <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                                        <option selected value="0"><?= $language::get('transcoding_disabled'); ?></option>
                                        <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                            <option value="<?= (int) $rProfile['profile_id']; ?>"><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="delay_minutes"><?= $language::get('minute_delay'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('delay_stream_by_x_minutes_tooltip'); ?>"></i></label>
                                    <input type="text" inputmode="numeric" class="form-control" id="delay_minutes" name="delay_minutes" value="">
                                </div>
                            </div>
                            <div class="mb-6">
                                <label class="form-label" for="user_agent"><?= $language::get('user_agent'); ?></label>
                                <input type="text" class="form-control" id="user_agent" name="user_agent" value="">
                            </div>
                            <div class="mb-6">
                                <label class="form-label" for="http_proxy"><?= $language::get('http_proxy'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('format_ipport'); ?>"></i></label>
                                <input type="text" class="form-control" id="http_proxy" name="http_proxy" value="">
                            </div>
                            <div class="mb-6">
                                <label class="form-label" for="cookie"><?= $language::get('cookie'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('format_keyvalue'); ?>"></i></label>
                                <input type="text" class="form-control" id="cookie" name="cookie" value="">
                            </div>
                            <div>
                                <label class="form-label" for="headers"><?= $language::get('headers'); ?> <i class="icon-base ti tabler-help-circle text-muted" title="<?= $language::get('ffmpeg_headers_command'); ?>"></i></label>
                                <input type="text" class="form-control" id="headers" name="headers" value="">
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="tab-servers" role="tabpanel">
                        <div class="mb-6">
                            <label class="form-label"><?= $language::get('server_tree'); ?></label>
                            <div id="server_tree" class="border rounded p-2"></div>
                        </div>
                        <?php if ($rType == 1): ?>
                            <div class="mb-6">
                                <label class="form-label" for="on_demand"><?= $language::get('on_demand_servers'); ?></label>
                                <select name="on_demand[]" id="on_demand" class="form-select" multiple data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                    <?php foreach ($rServers as $rServer): ?>
                                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row mb-6">
                                <div class="col-md-6">
                                    <label class="form-label" for="tv_archive_server_id"><?= $language::get('timeshift_server'); ?></label>
                                    <select name="tv_archive_server_id" id="tv_archive_server_id" class="form-select">
                                        <option value="0"><?= $language::get('disabled'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="tv_archive_duration"><?= $language::get('timeshift_days'); ?></label>
                                    <input type="text" inputmode="numeric" class="form-control" id="tv_archive_duration" name="tv_archive_duration" value="0">
                                </div>
                            </div>
                            <div class="row mb-6">
                                <div class="col-md-6">
                                    <label class="form-label" for="vframes_server_id"><?= $language::get('thumbnails') ?: 'Thumbnails'; ?></label>
                                    <select name="vframes_server_id" id="vframes_server_id" class="form-select">
                                        <option value="0"><?= $language::get('disabled'); ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="llod"><?= $language::get('low_latency_ondemand') ?: 'Low Latency On-Demand'; ?></label>
                                    <select name="llod" id="llod" class="form-select">
                                        <?php foreach (array('Disabled', 'LLOD v2 - FFMPEG', 'LLOD v3 - PHP') as $rValue => $rText): ?>
                                            <option value="<?= (int) $rValue; ?>"><?= $rValue === 0 ? $language::get('disabled') : $rText; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="restart_on_edit" name="restart_on_edit" value="1">
                            <label class="form-check-label" for="restart_on_edit"><?= $rType == 1 ? ($language::get('auto_start_streams') ?: 'Auto-Start Streams') : ($language::get('auto_encode_movies') ?: 'Auto-Encode Movies'); ?></label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end mb-6">
            <button type="submit" name="submit_stream" value="Review" class="btn btn-primary"><?= $language::get('review'); ?></button>
        </div>
    <?php endif; ?>
</form>

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

        var reviewPhase = <?= (isset($rImport) && $rImport) ? 'true' : 'false'; ?>;
        var streamType = <?= $rType == 1 ? 'true' : 'false'; ?>;
        var tmdbLang = <?= json_encode($rTmdbLang); ?>;
        var yearAppend = <?= (int) $rYearAppend; ?>;
        var serverTree = <?= json_encode($rServerTree ?? array()); ?>;
        var lang = {
            selectPlaylist: <?= json_encode($language::get('select_playlist_toast') ?: 'Please select a playlist to upload & review.'); ?>,
            disabledText: <?= json_encode($language::get('disabled')); ?>
        };

        var rBouquetSet = [];
        var rCategorySet = [<?= implode(',', array_map('intval', $rCategorySet)); ?>];
        var rLogoSet = [<?= implode(',', array_map('intval', $rLogoSet)); ?>];
        var rCheckSet = [];
        var rPages = [];
        var rImages = [];
        var rData = [];
        var rTrigger = true;

        // ---- poster preview (magnificPopup -> Bootstrap modal) ----
        window.openImage = function(elem) {
            var src = $(elem).data('src');
            if (!src) {
                return;
            }
            document.getElementById('imgPreviewImg').src = src;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('imgPreviewModal')).show();
        };

        // ---- EPG clear (streams) ----
        window.clearEPG = function(elem) {
            var id = $(elem).data('id');
            if ($('#epg_api_' + id).val()) {
                $('#epg_api_' + id).val('').trigger('change');
            }
        };

        // ---- TMDb bulk / per-row search (movies) ----
        window.scanTMDb = function(rIndivID) {
            rIndivID = rIndivID || null;
            $('#datatable tr').each(function() {
                try {
                    var rID = $(this).data('id');
                    if (($('#check_' + rID).is(':checked')) || (rID == rIndivID)) {
                        if ((rID == rIndivID) || (!rIndivID)) {
                            var rName = $('#name_' + rID).val();
                            if (rName) {
                                var langVal = $('#tmdb_language').length ? $('#tmdb_language').val() : tmdbLang;
                                $('#tmdb_search_' + rID).empty().trigger('change');
                                $.getJSON('./api?action=tmdb_search&type=movie&term=' + encodeURIComponent(rName) + '&language=' + encodeURIComponent(langVal), function(rJSON) {
                                    if (rJSON && rJSON.result) {
                                        $(rJSON.data).each(function() {
                                            var rTitle;
                                            if (this.release_date) {
                                                rTitle = yearAppend === 0 ? (this.title + ' (' + this.release_date.substring(0, 4) + ')') : (yearAppend === 1 ? (this.title + ' - ' + this.release_date.substring(0, 4)) : this.title);
                                            } else {
                                                rTitle = this.title;
                                            }
                                            $('#tmdb_search_' + rID).append(new Option(rTitle, this.id));
                                            if (this.poster_path) {
                                                rImages[this.id] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' + this.poster_path;
                                            }
                                        });
                                    }
                                    $('#tmdb_search_' + rID).trigger('change');
                                });
                            }
                        }
                    }
                } catch (e) {}
            });
        };

        // ---- copy visible inputs into the hidden posting inputs ----
        function saveChanges() {
            $('#datatable tr').each(function() {
                var rID = $(this).data('id');
                $('#name_i_' + rID).val($('#name_' + rID).val());
                $('#category_id_i_' + rID).val(JSON.stringify($('#category_id_' + rID).val()));
                $('#bouquets_i_' + rID).val(JSON.stringify($('#bouquets_' + rID).val()));
                $('#import_' + rID).val($('#check_' + rID).prop('checked') ? 1 : 0);
                if (streamType) {
                    $('#channel_id_' + rID).val($('#epg_api_' + rID).val());
                } else {
                    $('#tmdb_id_' + rID).val($('#tmdb_search_' + rID).val());
                }
            });
        }

        // ---- input-phase: populate server selects from the jstree ----
        function evaluateServers() {
            var rVVal = $('#vframes_server_id').val();
            var rTVal = $('#tv_archive_server_id').val();
            var rOVal = $('#on_demand').val();
            $('#on_demand').empty();
            $('#vframes_server_id').empty().append(new Option(lang.disabledText, 0));
            $('#tv_archive_server_id').empty().append(new Option(lang.disabledText, 0));
            $($('#server_tree').jstree(true).get_json('source', {
                flat: true
            })).each(function(index, value) {
                if (value.parent != '#') {
                    $('#vframes_server_id').append(new Option(value.text, value.id));
                    $('#tv_archive_server_id').append(new Option(value.text, value.id));
                    $('#on_demand').append(new Option(value.text, value.id));
                }
            });
            $('#vframes_server_id').val(rVVal).trigger('change');
            if (!$('#vframes_server_id').val()) {
                $('#vframes_server_id').val(0).trigger('change');
            }
            $('#tv_archive_server_id').val(rTVal).trigger('change');
            if (!$('#tv_archive_server_id').val()) {
                $('#tv_archive_server_id').val(0).trigger('change');
            }
            $('#on_demand').val(rOVal).trigger('change');
            if (!$('#on_demand').val()) {
                $('#on_demand').val(0).trigger('change');
            }
        }

        // ---- input-phase: direct-source cascade (Switchery -> plain disable) ----
        function toggleFields(ids, disabled) {
            ids.forEach(function(id) {
                var el = document.getElementById(id);
                if (!el) {
                    return;
                }
                if (disabled && el.type === 'checkbox') {
                    el.checked = false;
                }
                el.disabled = disabled;
                if ($(el).hasClass('select2-hidden-accessible')) {
                    $(el).trigger('change.select2');
                }
            });
        }

        function evaluateDirectSource() {
            var ds = document.getElementById('direct_source');
            if (!ds) {
                return;
            }
            var checked = ds.checked;
            toggleFields(['read_native', 'gen_timestamps', 'stream_all', 'allow_record', 'rtmp_output', 'delay_minutes', 'probesize_ondemand', 'transcode_profile_id', 'on_demand', 'tv_archive_duration', 'tv_archive_server_id', 'vframes_server_id', 'restart_on_edit'], checked);
            toggleFields(['direct_proxy'], !checked);
            var dp = document.getElementById('direct_proxy');
            var enableHeaders = (dp && dp.checked) || !checked;
            toggleFields(['user_agent', 'http_proxy', 'cookie', 'headers'], !enableHeaders);
        }

        // ---- review-phase: cascade changes down to checked rows ----
        function evaluateChanges() {
            $('.bouquet').off('change.rev').on('change.rev', function() {
                if (rTrigger) {
                    rTrigger = false;
                    var rThis = this;
                    var rChangeID = $(this).data('id');
                    $('#datatable tr').each(function() {
                        var rID = $(this).data('id');
                        if ((rID > rChangeID) && ($('#check_' + rID).is(':checked'))) {
                            if ($.inArray(rID, rBouquetSet) == -1) {
                                $('#bouquets_' + rID).val($(rThis).val()).trigger('change');
                            } else {
                                return false;
                            }
                        }
                    });
                    rBouquetSet.push(rChangeID);
                    rTrigger = true;
                }
            });
            $('.category_id').off('change.rev').on('change.rev', function() {
                if (rTrigger) {
                    rTrigger = false;
                    var rThis = this;
                    var rChangeID = $(this).data('id');
                    $('#datatable tr').each(function() {
                        var rID = $(this).data('id');
                        if ((rID > rChangeID) && ($('#check_' + rID).is(':checked'))) {
                            if ($.inArray(rID, rCategorySet) == -1) {
                                $('#category_id_' + rID).val($(rThis).val()).trigger('change');
                            } else {
                                return false;
                            }
                        }
                    });
                    rCategorySet.push(rChangeID);
                    rTrigger = true;
                }
            });
            $('.activate').off('change.rev').on('change.rev', function() {
                if (rTrigger) {
                    rTrigger = false;
                    var rVal = $(this).prop('checked');
                    var rChangeID = $(this).data('id');
                    $('#datatable tr').each(function() {
                        var rID = $(this).data('id');
                        if (rID > rChangeID) {
                            if (($.inArray(rID, rCheckSet) == -1) && ($('#check_' + rID).prop('checked') != rVal)) {
                                $('#check_' + rID).prop('checked', rVal);
                            } else {
                                return false;
                            }
                        }
                    });
                    rCheckSet.push(rChangeID);
                    rTrigger = true;
                }
            });
            if (streamType) {
                $('.epg_api').off('change.rev').on('change.rev', function() {
                    var rID = $(this).data('id');
                    var rDataItem;
                    if (rData[rID]) {
                        rDataItem = rData[rID];
                        rData[rID] = null;
                    } else {
                        rDataItem = $('#epg_api_' + rID).select2('data')[0];
                    }
                    if (rDataItem) {
                        if ($.inArray(rID, rLogoSet) == -1) {
                            if (rDataItem.icon) {
                                $('#picon_' + rID).find('a').data('src', './resize?maxw=512&maxh=512&url=' + rDataItem.icon);
                                $('#picon_' + rID).find('img').attr('src', './resize?maxw=96&maxh=32&url=' + rDataItem.icon);
                                $('#icon_' + rID).val(rDataItem.icon);
                            } else {
                                $('#picon_' + rID).find('a').data('src', '');
                                $('#picon_' + rID).find('img').attr('src', '');
                                $('#icon_' + rID).val('');
                            }
                        }
                        $('#clear_epg_' + rID).removeClass('btn-label-secondary').addClass('btn-label-warning');
                        $('#epg_type_' + rID).val(rDataItem.type);
                        if (rDataItem.type == 1) {
                            $('#epg_id_' + rID).val(rDataItem.epg_id);
                        } else {
                            $('#epg_id_' + rID).val(0);
                        }
                    } else {
                        $('#clear_epg_' + rID).removeClass('btn-label-warning').addClass('btn-label-secondary');
                        $('#epg_id_' + rID).val(0);
                        $('#epg_type_' + rID).val(0);
                    }
                });
            } else {
                $('.tmdb_search').off('change.rev').on('change.rev', function() {
                    var rID = $(this).data('id');
                    var val = $(this).val();
                    if (($.inArray(val, rImages) == -1) && (typeof(rImages[val]) != 'undefined')) {
                        $('#picon_' + rID).find('a').data('src', './resize?maxw=512&maxh=512&url=' + rImages[val]);
                        $('#picon_' + rID).find('img').attr('src', './resize?maxw=96&maxh=32&url=' + rImages[val]);
                        $('#icon_' + rID).val(rImages[val]);
                    } else {
                        $('#picon_' + rID).find('a').data('src', '');
                        $('#picon_' + rID).find('img').attr('src', '');
                        $('#icon_' + rID).val('');
                    }
                    if ($(this).find('option:selected').text()) {
                        $('#name_' + rID).val($(this).find('option:selected').text());
                    }
                });
            }
        }

        // ---- review-phase: rebuild each row's category options from #category_selection ----
        function scanCategories() {
            rTrigger = false;
            $('#datatable tr').each(function() {
                var rID = $(this).data('id');
                var rValues = $('#category_id_' + rID).val();
                $('#category_id_' + rID).empty();
                $($('#category_selection').val()).each(function() {
                    var rCategory = $("#category_selection option[value='" + this + "']");
                    $('#category_id_' + rID).append(new Option(rCategory.text(), rCategory.val()));
                });
                $('#category_id_' + rID).val(rValues).trigger('change');
            });
            rTrigger = true;
        }

        // ---- review-phase: auto-check rows flagged with the .checked marker class ----
        function enableChecked() {
            rTrigger = false;
            $('#datatable tr').each(function() {
                var rID = $(this).data('id');
                if ($('#check_' + rID).hasClass('checked')) {
                    $('#check_' + rID).prop('checked', true);
                }
            });
            rTrigger = true;
        }

        $(function() {
            $.fn.dataTable.ext.errMode = 'none';

            if (reviewPhase) {
                $('#category_selection').select2({
                    width: '100%',
                    tags: true
                });
                $('#tmdb_language').select2({
                    width: '100%'
                });
                $('#datatable select').not('.epg_api').select2({
                    width: '100%'
                });

                if (streamType) {
                    $('.epg_api').select2({
                        width: '100%',
                        ajax: {
                            url: './api',
                            dataType: 'json',
                            data: function(params) {
                                return {
                                    search: params.term,
                                    action: 'epglist',
                                    page: params.page
                                };
                            },
                            processResults: function(data, params) {
                                params.page = params.page || 1;
                                return {
                                    results: data.items,
                                    pagination: {
                                        more: (params.page * 100) < data.total_count
                                    }
                                };
                            },
                            cache: true
                        },
                        placeholder: <?= json_encode($language::get('epg_search')); ?>
                    });
                }

                $('#datatable').DataTable({
                    language: {
                        paginate: {
                            previous: "<i class='icon-base ti tabler-chevron-left'></i>",
                            next: "<i class='icon-base ti tabler-chevron-right'></i>"
                        }
                    },
                    drawCallback: function() {
                        if ($.inArray($('#datatable').DataTable().page.info().page, rPages) == -1) {
                            enableChecked();
                            <?php if ($rType != 1): ?>
                                scanTMDb();
                            <?php endif; ?>
                            rPages.push($('#datatable').DataTable().page.info().page);
                        }
                        evaluateChanges();
                        scanCategories();
                    },
                    bAutoWidth: false,
                    responsive: false,
                    searching: false,
                    bSort: false,
                    paging: true,
                    pageLength: 50,
                    lengthChange: false
                }).on('page.dt', function() {
                    saveChanges();
                });
                $('#datatable').css('width', '100%');

                $('#category_selection').on('change', function() {
                    scanCategories();
                });
                saveChanges();
                $('#stream_form').on('submit', function() {
                    saveChanges();
                });
            } else {
                $('#stream_form select').select2({
                    width: '100%'
                });

                $('#server_tree').on('redraw.jstree', function() {
                    evaluateServers();
                }).on('select_node.jstree', function(e, data) {
                    if (data.node.parent == 'offline') {
                        $('#server_tree').jstree('move_node', data.node.id, '#source', 'last');
                    } else {
                        $('#server_tree').jstree('move_node', data.node.id, '#offline', 'first');
                    }
                }).jstree({
                    core: {
                        check_callback: function(op, node, parent) {
                            switch (op) {
                                case 'move_node':
                                    if ((node.id == 'offline') || (node.id == 'source')) {
                                        return false;
                                    }
                                    <?php if ($rType != 1): ?>
                                        if (parent.id != 'offline' && parent.id != 'source') {
                                            return false;
                                        }
                                    <?php endif; ?>
                                    if (parent.id == '#') {
                                        return false;
                                    }
                                    return true;
                            }
                            return true;
                        },
                        data: serverTree
                    },
                    plugins: ['dnd']
                });

                if (document.getElementById('direct_source')) {
                    $('#direct_source, #direct_proxy').on('change', function() {
                        evaluateDirectSource();
                    });
                    evaluateDirectSource();
                }

                $('#stream_form').on('submit', function(e) {
                    if ($('#server_tree_data').length) {
                        $('#server_tree_data').val(JSON.stringify($('#server_tree').jstree(true).get_json('source', {
                            flat: true
                        })));
                        if (!$('#m3u_file').val()) {
                            if (window.xcToast) {
                                window.xcToast(lang.selectPlaylist, 'error');
                            } else {
                                alert(lang.selectPlaylist);
                            }
                            e.preventDefault();
                        }
                    }
                });

                function numFilter(sel) {
                    $(sel).on('input', function() {
                        this.value = this.value.replace(/[^\d]/g, '');
                    });
                }
                numFilter('#probesize_ondemand');
                numFilter('#delay_minutes');
                numFilter('#tv_archive_duration');
            }
        });
    })();
</script>
</body>

</html>