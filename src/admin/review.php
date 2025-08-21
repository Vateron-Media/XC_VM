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