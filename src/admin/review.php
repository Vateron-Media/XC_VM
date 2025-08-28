<?php include 'session.php';
include 'functions.php'; ?>

<?php if (checkPermissions()): ?>
<?php else: goHome();
endif; ?>

<?php
if (isset(CoreUtilities::$rRequest['type'])) {
    $rType = intval(CoreUtilities::$rRequest['type']);
} else {
    if (isset(CoreUtilities::$rRequest['type'])) {
        $rType = intval(CoreUtilities::$rRequest['type']);
    } else {
        $rType = 1;
    }
}

if (isset(CoreUtilities::$rRequest['post_data'])) {
    $rPostData = json_decode(base64_decode(CoreUtilities::$rRequest['post_data']), true);
    $rPostData['review'] = array();
    $rPostData['notes'] = '';
    $rPostData['custom_sid'] = $rPostData['notes'];
    $rCategoryIDs = array();

    foreach (getCategories(array(1 => 'live', 2 => 'movie')[intval($rType)]) as $rCategory) {
        $rCategoryIDs[] = $rCategory['id'];
    }
    $rNewCategories = array();

    foreach (CoreUtilities::$rRequest['category_selection'] as $rCategory) {
        if (in_array($rCategory, $rCategoryIDs) || is_numeric($rCategory)) {
        } else {
            $rReturn = API::processCategory(array('category_type' => array(1 => 'live', 2 => 'movie')[intval($rType)], 'category_name' => $rCategory));
            $rNewCategories[$rCategory] = $rReturn['data']['insert_id'];
        }
    }

    foreach (CoreUtilities::$rRequest as $rKey => $rValue) {
        if (substr($rKey, 0, 7) != 'import_') {
        } else {
            $rID = intval(explode('import_', $rKey)[1]);

            if (!CoreUtilities::$rRequest['import_' . $rID]) {
            } else {
                $rCategories = array();

                foreach (json_decode(CoreUtilities::$rRequest['category_id_' . $rID], true) as $rCategory) {
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
                    $rPostData['review'][] = array('stream_source' => array(CoreUtilities::$rRequest['url_' . $rID]), 'stream_icon' => CoreUtilities::$rRequest['icon_' . $rID], 'stream_display_name' => CoreUtilities::$rRequest['name_' . $rID], 'epg_lang' => null, 'channel_id' => (!empty(CoreUtilities::$rRequest['channel_id_' . $rID]) ? CoreUtilities::$rRequest['channel_id_' . $rID] : null), 'epg_api' => (!empty(CoreUtilities::$rRequest['epg_type_' . $rID]) ? CoreUtilities::$rRequest['epg_type_' . $rID] : 0), 'epg_id' => (!empty(CoreUtilities::$rRequest['epg_id_' . $rID]) ? CoreUtilities::$rRequest['epg_id_' . $rID] : 0), 'bouquets' => json_decode(CoreUtilities::$rRequest['bouquets_' . $rID], true), 'category_id' => $rCategories);
                } else {
                    $rPostData['review'][] = array('stream_source' => array(CoreUtilities::$rRequest['url_' . $rID]), 'stream_display_name' => CoreUtilities::$rRequest['name_' . $rID], 'tmdb_id' => (!empty(CoreUtilities::$rRequest['tmdb_id_' . $rID]) ? CoreUtilities::$rRequest['tmdb_id_' . $rID] : null), 'bouquets' => json_decode(CoreUtilities::$rRequest['bouquets_' . $rID], true), 'category_id' => $rCategories);
                }
            }
        }
    }

    if ($rType == 1) {
        $rReturn = API::processStream($rPostData);
        $_STATUS = $rReturn['status'];

        if ($_STATUS != STATUS_SUCCESS) {
        } else {
            header('Location: ./streams?status=' . STATUS_SUCCESS);
            exit();
        }
    } else {
        $rReturn = API::processMovie($rPostData);
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
        unset(CoreUtilities::$rRequest['submit_stream']);
        $rPostData = base64_encode(json_encode(CoreUtilities::$rRequest));
        $rCategories = getCategories(array(1 => 'live', 2 => 'movie')[intval($rType)]);
        $rBouquets = getBouquets();
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
            $rResults = parseM3U($_FILES['m3u_file']['tmp_name']);

            foreach ($rResults as $rResult) {
                list($rTag) = $rResult->getExtTags();

                if (!$rTag) {
                } else {
                    $rURL = $rResult->getPath();

                    if ($rType == 1) {
                        $rExtensions = array('ts', 'm3u8', 'm3u', 'mpd', 'ism', '');
                    } else {
                        $rExtensions = array('mp4', 'mkv', 'mov', 'avi', 'mpg', 'mpeg', 'flv', 'wmv', 'm4v');
                    }

                    if (!in_array(strtolower(pathinfo(explode('?', $rURL)[0])['extension']), $rExtensions)) {
                    } else {
                        $rExists = in_array(str_replace('https://', 'http://', $rURL), $rSources);

                        if ($rExists && !CoreUtilities::$rRequest['duplicates']) {
                        } else {
                            if (count($rImport) < 500) {
                                if ($rType == 1) {
                                    $rImport[] = array('url' => $rURL, 'logo' => ($rTag->getAttribute('tvg-logo') ?: ''), 'tvg_id' => ($rTag->getAttribute('tvg-id') ?: ''), 'title' => ($rTag->getTitle() ?: ''), 'category' => ($rTag->getAttribute('group-title') ?: ''), 'exists' => $rExists);
                                } else {
                                    $rImport[] = array('url' => $rURL, 'title' => ($rTag->getTitle() ?: ''), 'category' => ($rTag->getAttribute('group-title') ?: ''), 'exists' => $rExists);
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
    $rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Live Stream</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

    foreach ($rServers as $rServer) {
        $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
    }
    $rStreamArguments = getStreamArguments();
    $rTranscodeProfiles = getTranscodeProfiles();
}

$rLogoSet = $rCategorySet = array();
$_TITLE = 'Review';
include 'header.php';
?>

<div class="wrapper<?php if (!isset($rImport)) {
                        echo ' boxed-layout-ext';
                    } ?>"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    } else {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <form <?php if (!isset($rImport)) {
                    echo ' enctype="multipart/form-data"';
                } ?>
            action="./review?type=<?php echo intval($rType); ?>" method="POST" id="stream_form"
            data-parsley-validate="">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <?php if (!isset($rImport)) {
                        } else { ?>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li>
                                        <input name="submit_stream" type="submit" class="btn btn-primary"
                                            value="Import Selected" />
                                    </li>
                                </ol>
                            </div>
                        <?php } ?>
                        <h4 class="page-title"><?php echo array(1 => 'Stream', 2 => 'Movie')[$rType]; ?> Review</h4>
                    </div>
                </div>
            </div>
            <?php if (!isset($rImport)) {
            } else { ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="form-group row mb-4">
                                    <div class="col-md-12">
                                        <h4 class="header-title">Category Creation</h4>
                                        <p class="sub-header">
                                            You can create categories by typing them in the below box, this will allow you
                                            to quickly add categories to the imported results.
                                        </p>
                                    </div>
                                    <div class="col-md-12">
                                        <select name="category_selection[]" id="category_selection"
                                            class="form-control col-md-12 select2-multiple" data-toggle="select2"
                                            multiple="multiple" data-placeholder="Choose...">
                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                <option selected value="<?php echo $rCategory['id']; ?>">
                                                    <?php echo $rCategory['category_name']; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <?php if ($rType == 1) { ?>
                                    <div class="form-group row">
                                        <div class="col-md-12">
                                            <h4 class="header-title">Stream Import</h4>
                                            <p class="sub-header" style="margin-bottom: 0;">
                                                To import a stream, ensure the checkbox next to it is selected. You will need to
                                                go to each page for that page of streams to be included in the import.
                                            </p>
                                        </div>
                                    </div>
                                <?php } else { ?>
                                    <div class="form-group row">
                                        <div class="col-md-12">
                                            <h4 class="header-title">Movie Import</h4>
                                            <p class="sub-header" style="margin-bottom: 0;">
                                                To import a movie, ensure the checkbox next to it is selected. You will need to
                                                go to each page for that page of movies to be included in the import.
                                            </p>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <div class="row">
                <div class="col-12">
                    <?php if (isset($_STATUS) && $_STATUS == STATUS_INVALID_FILE) { ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Invalid playlist selected, please ensure the playlist is in M3U format.
                        </div>
                    <?php } elseif (isset($_STATUS) && $_STATUS == STATUS_TOO_MANY_RESULTS) { ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            The playlist you selected has more than 500 results, the review page will not show all results.
                        </div>
                    <?php } elseif (isset($_STATUS) && $_STATUS == STATUS_NO_SOURCES) { ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            No results were found in the playlist.
                        </div>
                    <?php } elseif ($rImport) {
                    } else { ?>
                        <div class="alert alert-info" role="alert">
                            The Review page is for playlists of less than 500 items, you should use the normal M3U Import
                            function for larger playlists or reduce the playlist. The review page will cut off at 500
                            results and not process any more if you upload a larger playlist anyway.
                            <?php if ($rType != 0) {
                            } else { ?>
                                <br /><br />If you have an XMLTV EPG file for this playlist, you should add it first and rescan
                                your EPG so channels are automatically matched up against the EPG. You can however do this later
                                through the EPG review tool.
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <div class="card">
                        <div class="card-body">
                            <?php if (isset($rImport)) { ?>
                                <input type="hidden" name="post_data" value="<?php echo htmlspecialchars($rPostData); ?>" />
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($rType); ?>" />
                                <div class="row">
                                    <div class="col-12">
                                        <table id="datatable"
                                            class="table table-striped table-borderless dt-responsive nowrap">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">Add</th>
                                                    <th class="text-center"><?php echo $rType == 1 ? "Icon" : "Image"; ?>
                                                    </th>
                                                    <th><?php echo $rType == 1 ? "Stream Name" : "Movie Name"; ?></th>
                                                    <th>Category</th>
                                                    <th>Bouquets</th>
                                                    <?php if ($rType == 1) { ?>
                                                        <th>EPG Search</th>
                                                        <th class="text-center">Language</th>
                                                    <?php } else { ?>
                                                        <th>TMDb Results</th>
                                                    <?php } ?>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $i = 0;
                                                foreach ($rImport as $rStream) {
                                                    $i++; ?>
                                                    <tr id="stream_<?php echo $i; ?>" data-id="<?php echo $i; ?>">
                                                        <td class="text-center">
                                                            <div
                                                                class="checkbox checkbox-single checkbox-offset <?php echo $rStream['exists'] ? 'checkbox-warning' : 'checkbox-primary'; ?>">
                                                                <input id="check_<?php echo $i; ?>" data-id="<?php echo $i; ?>"
                                                                    type="checkbox"
                                                                    class="activate <?php if (!$rStream['exists']) {
                                                                                        echo 'checked';
                                                                                    } ?>">
                                                                <label></label>
                                                            </div>
                                                        </td>
                                                        <td class="text-center" id="picon_<?php echo $i; ?>">
                                                            <?php if (strlen($rStream['logo']) > 0) { ?>
                                                                <a href="javascript:void(0);" onClick="openImage(this);"
                                                                    data-src="./resize?maxw=512&maxh=512&url=<?php echo urlencode($rStream['logo']); ?>">
                                                                    <img class="lazyload"
                                                                        src="./resize?maxw=96&maxh=32&url=<?php echo urlencode($rStream['logo']); ?>" />
                                                                </a>
                                                            <?php } else { ?>
                                                                <a href="javascript:void(0);" onClick="openImage(this);"
                                                                    data-src="">
                                                                    <img class="lazyload" src="" />
                                                                </a>
                                                            <?php } ?>
                                                        </td>
                                                        <td>
                                                            <div class="input-group">
                                                                <input type="text" class="form-control"
                                                                    id="name_<?php echo $i; ?>"
                                                                    value="<?php echo htmlspecialchars($rStream['title']); ?>">
                                                                <? if ($rType != 1) { ?>
                                                                    <div class="input-group-append">
                                                                        <a href="javascript:void(0);"
                                                                            onClick="<?php echo 'scanTMDb(' . $i . ');'; ?>"
                                                                            class="btn btn-primary waves-effect waves-light">
                                                                            <i class="mdi mdi-magnify text-white"></i>
                                                                        </a>
                                                                    </div>
                                                                <?php } ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <select id="category_id_<?php echo $i; ?>"
                                                                class="form-control select2-multiple category_id"
                                                                data-id="<?php echo $i; ?>" data-toggle="select2"
                                                                multiple="multiple" data-placeholder="Choose...">
                                                                <?php foreach ($rCategories as $rCategory) { ?>
                                                                    <option
                                                                        <?php if (isset($rGroup) && intval($rGroup['id']) == $rCategory['id']) {
                                                                            echo 'selected';
                                                                        } ?>
                                                                        value="<?php echo $rCategory['id']; ?>">
                                                                        <?php echo $rCategory['category_name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select id="bouquets_<?php echo $i; ?>" data-id="<?php echo $i; ?>"
                                                                class="form-control select2-multiple bouquet"
                                                                data-toggle="select2" multiple="multiple"
                                                                data-placeholder="Choose...">
                                                                <?php foreach ($rBouquets as $rBouquet) { ?>
                                                                    <option value="<?php echo $rBouquet['id']; ?>">
                                                                        <?php echo $rBouquet['bouquet_name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </td>
                                                        <?php if ($rType == 1) { ?>
                                                            <td>
                                                                <select id="epg_api_<?php echo $i; ?>" data-id="<?php echo $i; ?>"
                                                                    class="form-control epg_api" data-toggle="select2"></select>
                                                            </td>
                                                            <td class="text-center">
                                                                <button onClick="clearEPG(this);" id="clear_epg_<?php echo $i; ?>"
                                                                    data-id="<?php echo $i; ?>" type="button" title="Clear EPG"
                                                                    class="tooltip btn btn-secondary btn-xs waves-effect waves-light"><i
                                                                        class="text-white fas fa-times"></i></button>
                                                                <a href="javascript:void(0);"
                                                                    title="<?php echo htmlspecialchars($rStream['url']); ?>"
                                                                    class="tooltip-left btn btn-primary btn-xs waves-effect waves-light"><i
                                                                        class="text-white mdi mdi-link"></i></a>
                                                            </td>
                                                        <?php } else { ?>
                                                            <td>
                                                                <select id="tmdb_search_<?php echo $i; ?>"
                                                                    data-id="<?php echo $i; ?>" class="form-control tmdb_search"
                                                                    data-toggle="select2"></select>
                                                            </td>
                                                            <td class="text-center">
                                                                <a href="javascript:void(0);"
                                                                    title="<?php echo htmlspecialchars($rStream['title']); ?><br/><?php echo htmlspecialchars($rStream['url']); ?>"
                                                                    class="tooltip-left btn btn-primary btn-xs waves-effect waves-light"><i
                                                                        class="text-white mdi mdi-information-variant"></i></a>
                                                            </td>
                                                        <?php } ?>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>


                                        </table>
                                    </div>
                                </div>
                            <?php } else { ?>
                                <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                                <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($rType); ?>" />
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#advanced-options" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                                <span class="d-none d-sm-inline">Options</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#load-balancing" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-server-network mr-1"></i>
                                                <span class="d-none d-sm-inline">Servers</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="advanced-options">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="duplicates">Show
                                                            Potential Duplicates <i
                                                                title="This option will remove all potential duplicate results from the review page, if you do not select this, duplicates will be unchecked by default so you are able to check them to include them anyway."
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-3">
                                                            <input name="duplicates" id="duplicates" type="checkbox"
                                                                data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd" />
                                                        </div>
                                                        <div class="col-md-6">
                                                            <input type="file" id="m3u_file" name="m3u_file"
                                                                style="padding-top: 5px;" accept=".m3u, .m3u8">
                                                        </div>
                                                    </div>
                                                    <?php if ($rType == 1) { ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="gen_timestamps">Generate
                                                                PTS <i
                                                                    title="Allow FFmpeg to generate presentation timestamps for you to achieve better synchronization with the stream codecs. In some streams this can cause de-sync."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-3">
                                                                <input name="gen_timestamps" id="gen_timestamps" type="checkbox"
                                                                    checked data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                            <label class="col-md-4 col-form-label" for="read_native">Native
                                                                Frames <i
                                                                    title="You should always read live streams as non-native frames. However if you are streaming static video files, set this to true otherwise the encoding process will fail."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-2">
                                                                <input name="read_native" id="read_native" type="checkbox"
                                                                    data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="stream_all">Stream All
                                                                Codecs <i
                                                                    title="This option will stream all codecs from your stream. Some streams have more than one audio/video/subtitles channels."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-3">
                                                                <input name="stream_all" id="stream_all" type="checkbox"
                                                                    data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                            <label class="col-md-4 col-form-label" for="allow_record">Allow
                                                                Recording</label>
                                                            <div class="col-md-2">
                                                                <input name="allow_record" id="allow_record" type="checkbox"
                                                                    checked data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="direct_source">Direct
                                                                Source <i
                                                                    title="Redirect clients to the source directly. Do not use if you want to keep your source secure."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-3">
                                                                <input name="direct_source" id="direct_source" type="checkbox"
                                                                    data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                            <label class="col-md-4 col-form-label" for="direct_proxy">Direct
                                                                Stream <i
                                                                    title="When using direct source, hide the original URL by proxying the live stream through your servers via UDP. MPEG-TS and HLS is supported as an input format, however only MPEG-TS is supported as an output format to clients.<br/><br/>Experimental! This may not work for all streams."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-2">
                                                                <input name="direct_proxy" id="direct_proxy" type="checkbox"
                                                                    data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="rtmp_output">Output RTMP
                                                                <i title="Enable RTMP output for this channel."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-3">
                                                                <input name="rtmp_output" id="rtmp_output" type="checkbox"
                                                                    data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                            <label class="col-md-4 col-form-label" for="probesize_ondemand">On
                                                                Demand Probesize <i
                                                                    title="Adjustable probesize for ondemand streams. Adjust this setting if you experience issues with no audio."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" id="probesize_ondemand"
                                                                    name="probesize_ondemand" value="128000">
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label"
                                                                for="transcode_profile_id">Transcoding Profile <i
                                                                    title="Sometimes, in order to make a stream compatible with most devices, it must be transcoded. Please note that the transcode will only be applied to the server(s) that take the stream directly from the source, all other servers attached to the transcoding server will not transcode the stream."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-3">
                                                                <select name="transcode_profile_id" id="transcode_profile_id"
                                                                    class="form-control" data-toggle="select2">
                                                                    <option selected value="0">Transcoding Disabled</option>
                                                                    <?php foreach ($rTranscodeProfiles as $rProfile) { ?>
                                                                        <option value="<?php echo $rProfile['profile_id']; ?>">
                                                                            <?php echo $rProfile['profile_name']; ?></option>
                                                                    <?php } ?>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-4 col-form-label" for="delay_minutes">Minute
                                                                Delay <i
                                                                    title="Delay stream by X minutes. Will not work with on demand streams."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" id="delay_minutes"
                                                                    name="delay_minutes" value="">
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="user_agent">User
                                                                Agent</label>
                                                            <div class="col-md-9">
                                                                <input type="text" class="form-control" id="user_agent"
                                                                    name="user_agent" value="">
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="http_proxy">HTTP Proxy
                                                                <i title="Format: ip:port"
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-9">
                                                                <input type="text" class="form-control" id="http_proxy"
                                                                    name="http_proxy" value="">
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="cookie">Cookie <i
                                                                    title="Format: key=value;"
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-9">
                                                                <input type="text" class="form-control" id="cookie"
                                                                    name="cookie" value="">
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="headers">Headers <i
                                                                    title="FFmpeg -headers command."
                                                                    class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-9">
                                                                <input type="text" class="form-control" id="headers"
                                                                    name="headers" value="">
                                                            </div>
                                                        </div>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="prevb list-inline-item">
                                                    <a href="javascript:void(0);" class="btn btn-secondary">Previous</a>
                                                </li>
                                                <li class="nextb list-inline-item float-right">
                                                    <a href="javascript:void(0);" class="btn btn-secondary">Next</a>
                                                </li>
                                            </ul>
                                        </div>

                                        <div class="tab-pane" id="load-balancing">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="servers">Server
                                                            Tree</label>
                                                        <div class="col-md-9">
                                                            <div id="server_tree"></div>
                                                        </div>
                                                    </div>
                                                    <?php if ($rType != 1): ?>
                                                    <?php else: ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="on_demand">On-Demand
                                                                Servers</label>
                                                            <div class="col-md-9">
                                                                <select name="on_demand[]" id="on_demand"
                                                                    class="form-control select2-multiple" data-toggle="select2"
                                                                    multiple="multiple" data-placeholder="Choose...">
                                                                    <?php foreach ($rServers as $rServer): ?>
                                                                        <option value="<?= $rServer['id']; ?>">
                                                                            <?= $rServer['server_name']; ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label"
                                                                for="tv_archive_server_id">Timeshift Server</label>
                                                            <div class="col-md-3">
                                                                <select name="tv_archive_server_id" id="tv_archive_server_id"
                                                                    class="form-control" data-toggle="select2">
                                                                    <option value="0">Disabled</option>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-3 col-form-label"
                                                                for="tv_archive_duration">Timeshift Days</label>
                                                            <div class="col-md-3">
                                                                <input type="text" class="form-control" id="tv_archive_duration"
                                                                    name="tv_archive_duration" value="0">
                                                            </div>
                                                        </div>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label"
                                                                for="vframes_server_id">Thumbnails</label>
                                                            <div class="col-md-3">
                                                                <select name="vframes_server_id" id="vframes_server_id"
                                                                    class="form-control" data-toggle="select2">
                                                                    <option value="0">Disabled</option>
                                                                </select>
                                                            </div>
                                                            <label class="col-md-3 col-form-label" for="llod">Low Latency
                                                                On-Demand</label>
                                                            <div class="col-md-3">
                                                                <select name="llod" id="llod" class="form-control"
                                                                    data-toggle="select2">
                                                                    <?php foreach (array('Disabled', 'LLOD v2 - FFMPEG', 'LLOD v3 - PHP') as $rValue => $rText): ?>
                                                                        <option value="<?= $rValue; ?>"><?= $rText; ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label"
                                                            for="restart_on_edit"><?= $rType == 1 ? 'Auto-Start Streams' : 'Auto-Encode Movies'; ?></label>
                                                        <div class="col-md-3">
                                                            <input name="restart_on_edit" id="restart_on_edit"
                                                                type="checkbox" data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="prevb list-inline-item">
                                                    <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                                </li>
                                                <li class="nextb list-inline-item float-right">
                                                    <input name="submit_stream" type="submit" class="btn btn-primary"
                                                        value="Review" />
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>

                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n" . '        var rBouquetSet = [];' . "\r\n" . '        var rCategorySet = [';
		echo implode(',', array_map('intval', $rCategorySet));
		echo '];' . "\r\n" . '        var rLogoSet = [';
		echo implode(',', array_map('intval', $rLogoSet));
		echo '];' . "\r\n" . '        var rCheckSet = [];' . "\r\n" . '        var rPages = [];' . "\r\n" . '        var rImages = [];' . "\r\n" . '        var rData = [];' . "\r\n" . '        var rTrigger = true;' . "\r\n\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).data("src");' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: rImage,' . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        ';

		if ($rType == 1) {
			echo '        function clearEPG(elem) {' . "\r\n" . '            var rEPG = $("#epg_api_" + $(elem).data("id")).val();' . "\r\n" . '            if (rEPG) {' . "\r\n" . '                $("#epg_api_" + $(elem).data("id")).val("").trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n";
		} else {
			echo '        function scanTMDb(rIndivID=null) {' . "\r\n" . '            $("#datatable tr").each(function() {' . "\r\n" . '                try {' . "\r\n" . '                    var rID = $(this).data("id");' . "\r\n" . '                    if (($("#check_" + rID).is(":checked")) || (rID == rIndivID)) {' . "\r\n" . '                        if ((rID == rIndivID) || (!rIndivID)) {' . "\r\n" . '                            var rName = $("#name_" + rID).val();' . "\r\n" . '                            if (rName) {' . "\r\n" . '                                $("#tmdb_search_" + rID).empty().trigger("change");' . "\r\n" . '                                $.ajax({' . "\r\n" . "                                    url: './api?action=tmdb_search&type=movie&term=' + encodeURIComponent(rName) + \"&language=";
			echo urlencode(htmlspecialchars((!empty(CoreUtilities::$rRequest['tmdb_language']) ? CoreUtilities::$rRequest['tmdb_language'] : $rSettings['tmdb_language'])));
			echo '",' . "\r\n" . '                                    success: function (data) {' . "\r\n" . '                                        var rJSON = $.parseJSON(data);' . "\r\n" . '                                        if (rJSON.result) {' . "\r\n" . '                                            $(rJSON.data).each(function() {' . "\r\n" . '                                                if (this.release_date) {' . "\r\n" . '                                                    ';

			if ($rSettings['movie_year_append'] == 0) {
				echo '                                                    rTitle = this.title + " (" + this.release_date.substring(0, 4) + ")";' . "\r\n" . '                                                    ';
			} else {
				if ($rSettings['movie_year_append'] == 1) {
					echo '                                                    rTitle = this.title + " - " + this.release_date.substring(0, 4);' . "\r\n" . '                                                    ';
				} else {
					echo '                                                    rTitle = this.title;' . "\r\n" . '                                                    ';
				}
			}

			echo '                                                } else {' . "\r\n" . '                                                    rTitle = this.title;' . "\r\n" . '                                                }' . "\r\n" . '                                                $("#tmdb_search_" + rID).append(new Option(rTitle, this.id));' . "\r\n" . '                                                if (this.poster_path) {' . "\r\n" . '                                                    window.rImages[this.id] = "https://image.tmdb.org/t/p/w600_and_h900_bestv2" + this.poster_path' . "\r\n" . '                                                }' . "\r\n" . '                                            });' . "\r\n" . '                                        }' . "\r\n" . '                                        $("#tmdb_search_" + rID).trigger("change");' . "\r\n" . '                                    }' . "\r\n" . '                                });' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                } catch (e) {}' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        ';
		}

		echo '        function saveChanges() {' . "\r\n" . '            $("#datatable tr").each(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                $("#name_i_" + rID).val($("#name_" + rID).val());' . "\r\n" . '                $("#category_id_i_" + rID).val(JSON.stringify($("#category_id_" + rID).val()));' . "\r\n" . '                $("#bouquets_i_" + rID).val(JSON.stringify($("#bouquets_" + rID).val()));' . "\r\n" . '                if ($("#check_" + rID).prop("checked")) {' . "\r\n" . '                    rInt = 1;' . "\r\n" . '                } else {' . "\r\n" . '                    rInt = 0;' . "\r\n" . '                }' . "\r\n" . '                $("#import_" + rID).val(rInt);' . "\r\n" . '                ';

		if ($rType == 1) {
			echo '                $("#channel_id_" + rID).val($("#epg_api_" + rID).val());' . "\r\n" . '                ';
		} else {
			echo '                $("#tmdb_id_" + rID).val($("#tmdb_search_" + rID).val());' . "\r\n" . '                ';
		}

		echo '            });' . "\r\n" . '        }' . "\r\n" . '        function evaluateServers() {' . "\r\n" . '            var rVVal = $("#vframes_server_id").val();' . "\r\n" . '            var rTVal = $("#tv_archive_server_id").val();' . "\r\n" . '            var rOVal = $("#on_demand").val();' . "\r\n" . '            $("#on_demand").empty();' . "\r\n" . '            $("#vframes_server_id").empty().append(new Option("Disabled", 0));' . "\r\n" . '            $("#tv_archive_server_id").empty().append(new Option("Disabled", 0));' . "\r\n" . "            \$(\$('#server_tree').jstree(true).get_json('source', {flat:true})).each(function(index, value) {" . "\r\n" . '                if (value.parent != "#") {' . "\r\n" . '                    $("#vframes_server_id").append(new Option(value.text, value.id));' . "\r\n" . '                    $("#tv_archive_server_id").append(new Option(value.text, value.id));' . "\r\n" . '                    $("#on_demand").append(new Option(value.text, value.id));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#vframes_server_id").val(rVVal).trigger("change");' . "\r\n" . '            if (!$("#vframes_server_id").val()) {' . "\r\n" . '                $("#vframes_server_id").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '            $("#tv_archive_server_id").val(rTVal).trigger("change");' . "\r\n" . '            if (!$("#tv_archive_server_id").val()) {' . "\r\n" . '                $("#tv_archive_server_id").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '            $("#on_demand").val(rOVal).trigger("change");' . "\r\n" . '            if (!$("#on_demand").val()) {' . "\r\n" . '                $("#on_demand").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function evaluateDirectSource() {' . "\r\n" . '            ';

		if ($rType == 1) {
			echo '            $(["custom_sid", "read_native", "gen_timestamps", "stream_all", "allow_record", "rtmp_output", "delay_minutes", "custom_ffmpeg", "probesize_ondemand", "user_agent", "http_proxy", "cookie", "headers", "transcode_profile_id", "custom_map", "days_to_restart", "time_to_restart", "epg_id", "epg_lang", "channel_id", "on_demand", "tv_archive_duration", "tv_archive_server_id", "vframes_server_id", "restart_on_edit"]).each(function(rID, rElement) {' . "\r\n" . '            ';
		} else {
			echo '            $(["read_native", "transcode_profile_id", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n" . '            ';
		}

		echo '                if ($(rElement)) {' . "\r\n" . '                    if ($("#direct_source").is(":checked")) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $(["direct_proxy"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if (!$("#direct_source").is(":checked")) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';

		if ($rType != 1) {
		} else {
			echo '            $(["user_agent", "http_proxy", "cookie", "headers"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if (($("#direct_proxy").is(":checked")) || (!$("#direct_source").is(":checked"))) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		echo '        }' . "\r\n" . '        function evaluateChanges() {' . "\r\n" . '            $(".bouquet").change(function() {' . "\r\n" . '                if (window.rTrigger) {' . "\r\n" . '                    window.rTrigger = false;' . "\r\n" . '                    var rThis = this;' . "\r\n" . '                    var rChangeID = $(this).data("id");' . "\r\n" . '                    $("#datatable tr").each(function() {' . "\r\n" . '                        var rID = $(this).data("id");' . "\r\n" . '                        if ((rID > rChangeID) && ($("#check_" + rID).is(":checked"))) {' . "\r\n" . '                            if ($.inArray(rID, window.rBouquetSet) == -1) {' . "\r\n" . '                                $("#bouquets_" + rID).val($(rThis).select2("val")).trigger("change");' . "\r\n" . '                            } else {' . "\r\n" . '                                return false;' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    window.rBouquetSet.push(rChangeID);' . "\r\n" . '                    window.rTrigger = true;' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $(".category_id").change(function() {' . "\r\n" . '                if (window.rTrigger) {' . "\r\n" . '                    window.rTrigger = false;' . "\r\n" . '                    var rThis = this;' . "\r\n" . '                    var rChangeID = $(this).data("id");' . "\r\n" . '                    $("#datatable tr").each(function() {' . "\r\n" . '                        var rID = $(this).data("id");' . "\r\n" . '                        if ((rID > rChangeID) && ($("#check_" + rID).is(":checked"))) {' . "\r\n" . '                            if ($.inArray(rID, window.rCategorySet) == -1) {' . "\r\n" . '                                $("#category_id_" + rID).val($(rThis).select2("val")).trigger("change");' . "\r\n" . '                            } else {' . "\r\n" . '                                return false;' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    window.rCategorySet.push(rChangeID);' . "\r\n" . '                    window.rTrigger = true;' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $(".activate").change(function() {' . "\r\n" . '                if (window.rTrigger) {' . "\r\n" . '                    window.rTrigger = false;' . "\r\n" . '                    var rThis = this;' . "\r\n" . '                    var rVal = $(this).prop("checked");' . "\r\n" . '                    var rChangeID = $(this).data("id");' . "\r\n" . '                    $("#datatable tr").each(function() {' . "\r\n" . '                        var rID = $(this).data("id");' . "\r\n" . '                        if (rID > rChangeID) {' . "\r\n" . '                            if (($.inArray(rID, window.rCheckSet) == -1) && ($("#check_" + rID).prop("checked") != rVal)) {' . "\r\n" . '                                $("#check_" + rID).prop("checked", rVal);' . "\r\n" . '                            } else {' . "\r\n" . '                                return false;' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    window.rCheckSet.push(rChangeID);' . "\r\n" . '                    window.rTrigger = true;' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';

		if ($rType == 1) {
			echo '            $(".epg_api").change(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                if (window.rData[rID]) {' . "\r\n" . '                    var rData = window.rData[rID];' . "\r\n" . '                    window.rData[rID] = null;' . "\r\n" . '                } else {' . "\r\n" . '                    var rData = $("#epg_api_" + rID).select2("data")[0];' . "\r\n" . '                }' . "\r\n" . '                if (rData) {' . "\r\n" . '                    if ($.inArray(rID, window.rLogoSet) == -1) {' . "\r\n" . '                        if (rData.icon) {' . "\r\n" . '                            $("#picon_" + rID).find("a").data("src", "./resize?maxw=512&maxh=512&url=" + rData.icon);' . "\r\n" . '                            $("#picon_" + rID).find("img").attr("src", "./resize?maxw=96&maxh=32&url=" + rData.icon);' . "\r\n" . '                            $("#icon_" + rID).val(rData.icon);' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#picon_" + rID).find("a").data("src", "");' . "\r\n" . '                            $("#picon_" + rID).find("img").attr("src", "");' . "\r\n" . '                            $("#icon_" + rID).val("");' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                    $("#clear_epg_" + rID).removeClass("btn-secondary").addClass("btn-warning");' . "\r\n" . '                    $("#epg_type_" + rID).val(rData.type);' . "\r\n" . '                    if (rData.type == 1) {' . "\r\n" . '                        $("#view_epg_" + rID).removeClass("btn-secondary").addClass("btn-success");' . "\r\n" . '                        $("#view_epg_" + rID + " i").removeClass("far").addClass("fas");' . "\r\n" . '                        $("#epg_id_" + rID).val(rData.epg_id);' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#view_epg_" + rID).removeClass("btn-success").addClass("btn-secondary");' . "\r\n" . '                        $("#view_epg_" + rID + " i").removeClass("fas").addClass("far");' . "\r\n" . '                        $("#epg_id_" + rID).val(0);' . "\r\n" . '                    }' . "\r\n" . '                } else {' . "\r\n" . '                    $("#clear_epg_" + rID).removeClass("btn-warning").addClass("btn-secondary");' . "\r\n" . '                    $("#view_epg_" + rID).removeClass("btn-success").addClass("btn-secondary");' . "\r\n" . '                    $("#view_epg_" + rID + " i").removeClass("fas").addClass("far");' . "\r\n" . '                    $("#epg_id_" + rID).val(0);' . "\r\n" . '                    $("#epg_type_" + rID).val(0);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';
		} else {
			echo '            $(".tmdb_search").change(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                if (($.inArray($(this).val(), window.rImages) == -1) && (typeof(window.rImages[$(this).val()]) != "undefined")) {' . "\r\n" . '                    $("#picon_" + rID).find("a").data("src", "./resize?maxw=512&maxh=512&url=" + window.rImages[$(this).val()]);' . "\r\n" . '                    $("#picon_" + rID).find("img").attr("src", "./resize?maxw=96&maxh=32&url=" + window.rImages[$(this).val()]);' . "\r\n" . '                    $("#icon_" + rID).val(window.rImages[$(this).val()]);' . "\r\n" . '                } else {' . "\r\n" . '                    $("#picon_" + rID).find("a").data("src", "");' . "\r\n" . '                    $("#picon_" + rID).find("img").attr("src", "");' . "\r\n" . '                    $("#icon_" + rID).val("");' . "\r\n" . '                }' . "\r\n" . '                if ($(this).find("option:selected").text()) {' . "\r\n" . '                    $("#name_" + rID).val($(this).find("option:selected").text());' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		echo '        }' . "\r\n" . '        function scanCategories() {' . "\r\n" . '            window.rTrigger = false;' . "\r\n" . '            $("#datatable tr").each(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                rValues = $("#category_id_" + rID).select2("val");' . "\r\n" . '                $("#category_id_" + rID).empty();' . "\r\n" . '                $($("#category_selection").val()).each(function() {' . "\r\n" . "                    var rCategory = \$(\"#category_selection option[value='\" + this + \"']\");" . "\r\n" . '                    $("#category_id_" + rID).append(new Option(rCategory.text(), rCategory.val()));' . "\r\n" . '                });' . "\r\n" . '                $("#category_id_" + rID).val(rValues).trigger("change");' . "\r\n" . '            });' . "\r\n" . '            window.rTrigger = true;' . "\r\n" . '        }' . "\r\n" . '        function enableChecked() {' . "\r\n" . '            window.rTrigger = false;' . "\r\n" . '            $("#datatable tr").each(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                if ($("#check_" + rID).hasClass("checked")) {' . "\r\n" . '                    $("#check_" + rID).prop("checked", true);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            window.rTrigger = true;' . "\r\n" . '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            lazyload();' . "\r\n" . '            ' . "\r\n" . '            ';

		if (isset($rImport)) {
			echo '            ';

			if ($rType == 1) {
				echo "            \$('.epg_api').select2({" . "\r\n" . '              ajax: {' . "\r\n" . "                url: './api'," . "\r\n" . "                dataType: 'json'," . "\r\n" . '                data: function (params) {' . "\r\n" . '                  return {' . "\r\n" . '                    search: params.term,' . "\r\n" . "                    action: 'epglist'," . "\r\n" . '                    page: params.page' . "\r\n" . '                  };' . "\r\n" . '                },' . "\r\n" . '                processResults: function (data, params) {' . "\r\n" . '                  params.page = params.page || 1;' . "\r\n" . '                  return {' . "\r\n" . '                    results: data.items,' . "\r\n" . '                    pagination: {' . "\r\n" . '                        more: (params.page * 100) < data.total_count' . "\r\n" . '                    }' . "\r\n" . '                  };' . "\r\n" . '                },' . "\r\n" . '                cache: true' . "\r\n" . '              },' . "\r\n" . "              placeholder: 'Search EPG API...'" . "\r\n" . '            });' . "\r\n" . '            ';
			}

			echo "\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    if ($.inArray($("#datatable").DataTable().page.info().page, window.rPages) == -1) {' . "\r\n" . '                        enableChecked();' . "\r\n" . '                        ';

			if ($rType != 1) {
				echo '                        scanTMDb();' . "\r\n" . '                        ';
			}

			echo '                        window.rPages.push($("#datatable").DataTable().page.info().page);' . "\r\n" . '                    }' . "\r\n" . '                    evaluateChanges();' . "\r\n" . '                    scanCategories();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n" . '                responsive: false,' . "\r\n" . '                searching: false,' . "\r\n" . '                bSort: false,' . "\r\n" . '                paging: true,' . "\r\n" . '                pageLength: 50,' . "\r\n" . '                lengthChange: false' . "\r\n\t\t\t" . "}).on('page.dt', function() {" . "\r\n" . '                saveChanges();' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n" . '            $("#category_selection").change(function() {' . "\r\n" . '                scanCategories();' . "\r\n" . '            });' . "\r\n" . "            \$(\"#category_selection\").select2({width: '100%', tags: true});" . "\r\n" . '            saveChanges();' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                saveChanges();' . "\r\n\t\t\t" . '});' . "\r\n" . '            ';
		} else {
			echo "\t\t\t" . "\$('#server_tree').on('redraw.jstree', function (e, data) {" . "\r\n" . '                evaluateServers();' . "\r\n" . "            }).on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            ';

			if ($rType == 1) {
			} else {
				echo '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            ';
			}

			echo '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
			echo json_encode(($rServerTree ?: array()));
			echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#direct_source").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#direct_proxy").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n" . '            evaluateDirectSource();' . "\r\n\t\t\t" . '$("form").submit(function(e){' . "\r\n" . '                if ($("#server_tree_data").length) {' . "\r\n" . "                    \$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n" . '                    if(!$("#m3u_file").val()) {' . "\r\n" . '                        $.toast("Please select a playlist to upload & review.");' . "\r\n" . '                        e.preventDefault();' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            ';
		}

		echo "\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>