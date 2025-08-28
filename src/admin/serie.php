<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || !($rSeriesArr = getSerie(CoreUtilities::$rRequest['id']))) {
    goHome();
}

if (isset($rSeriesArr) && isset(CoreUtilities::$rRequest['import'])) {
    unset(CoreUtilities::$rRequest['import']);
}

$rServerTree = [
    ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
    ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]]
];

foreach ($rServers as $rServer) {
    $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
}
$_TITLE = 'TV Series';
include 'header.php';
?>
<div class="wrapper boxed-layout"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        echo '';
    } else {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">
                        <?php
                        if (isset($rSeriesArr['id'])) {
                            echo $rSeriesArr['title'];
                        } else {
                            if (isset(CoreUtilities::$rRequest['import'])) {
                                echo 'Import Series';
                            } else {
                                echo 'Add Series';
                            }
                        }
                        ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form <?php if (isset(CoreUtilities::$rRequest['import'])) {
                                    echo ' enctype="multipart/form-data"';
                                } ?>
                            action="#" method="POST" data-parsley-validate="">
                            <?php
                            if (!isset(CoreUtilities::$rRequest['import'])) {
                                if (isset($rSeriesArr)) {
                            ?>
                                    <input type="hidden" name="edit" value="<?php echo $rSeriesArr['id']; ?>" />
                                <?php
                                }
                                ?>
                                <input type="hidden" id="tmdb_id" name="tmdb_id"
                                    value="<?php if (isset($rSeriesArr)) {
                                                echo htmlspecialchars($rSeriesArr['tmdb_id']);
                                            } ?>" />
                            <?php
                            } else {
                            ?>
                                <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <?php
                            }
                            ?>
                            <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="" />
                            <input type="hidden" name="category_create_list" id="category_create_list" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab"
                                            class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <?php
                                    if (!isset(CoreUtilities::$rRequest['import'])) {
                                    ?>
                                        <li class="nav-item">
                                            <a href="#movie-information" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-movie-outline mr-1"></i>
                                                <span class="d-none d-sm-inline">Information</span>
                                            </a>
                                        </li>
                                    <?php
                                    } else {
                                    ?>
                                        <li class="nav-item">
                                            <a href="#advanced-details" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                                <span class="d-none d-sm-inline">Advanced</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#load-balancing" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-server-network mr-1"></i>
                                                <span class="d-none d-sm-inline">Server</span>
                                            </a>
                                        </li>
                                    <?php
                                    }
                                    ?>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <!-- Stream Details Content -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="title"><?= isset(CoreUtilities::$rRequest['import']) ? 'Fallback ' : ''; ?>Series
                                                        Name</label>
                                                    <div class="col-md-5">
                                                        <input type="text" class="form-control" id="title" name="title"
                                                            value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['title']) : ''; ?>"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <input type="text" class="form-control text-center"
                                                            placeholder="Year" id="year" name="year"
                                                            value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['year']) : ''; ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="category_id"><?= isset(CoreUtilities::$rRequest['import']) ? 'Fallback ' : ''; ?>Categories</label>
                                                    <div class="col-md-8">
                                                        <select name="category_id[]" id="category_id"
                                                            class="form-control select2-multiple" data-toggle="select2"
                                                            multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getCategories('series') as $rCategory): ?>
                                                                <option value="<?= $rCategory['id']; ?>"
                                                                    <?= isset($rSeriesArr) && in_array(intval($rCategory['id']), json_decode($rSeriesArr['category_id'], true)) ? 'selected' : ''; ?>>
                                                                    <?= $rCategory['category_name']; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="bouquets"><?= isset(CoreUtilities::$rRequest['import']) ? 'Fallback ' : ''; ?>Bouquets</label>
                                                    <div class="col-md-8">
                                                        <select name="bouquets[]" id="bouquets"
                                                            class="form-control select2-multiple" data-toggle="select2"
                                                            multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet): ?>
                                                                <option value="<?= $rBouquet['id']; ?>"
                                                                    <?= isset($rSeriesArr) && in_array($rSeriesArr['id'], json_decode($rBouquet['bouquet_series'], true)) ? 'selected' : ''; ?>>
                                                                    <?= htmlspecialchars($rBouquet['bouquet_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                    if (!isset(CoreUtilities::$rRequest['import'])) {
                                    ?>
                                        <div class="tab-pane" id="movie-information">
                                            <div class="row">
                                                <div class="col-12">
                                                    <!-- Movie Information Content -->
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="cover">Poster
                                                            URL</label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" class="form-control" id="cover" name="cover"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['cover']) : ''; ?>">
                                                            <div class="input-group-append">
                                                                <button class="btn btn-primary waves-effect waves-light"
                                                                    type="button" onClick="openImage(this)">
                                                                    <i class="mdi mdi-eye"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="backdrop_path">Backdrop
                                                            URL</label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" class="form-control" id="backdrop_path"
                                                                name="backdrop_path"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars(json_decode($rSeriesArr['backdrop_path'], true)[0]) : ''; ?>">
                                                            <div class="input-group-append">
                                                                <button class="btn btn-primary waves-effect waves-light"
                                                                    type="button" onClick="openImage(this)">
                                                                    <i class="mdi mdi-eye"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="plot">Plot</label>
                                                        <div class="col-md-8">
                                                            <textarea rows="6" class="form-control" id="plot"
                                                                name="plot"><?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['plot']) : ''; ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="cast">Cast</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="cast" name="cast"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['cast']) : ''; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="director">Director</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center"
                                                                id="director" name="director"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['director']) : ''; ?>">
                                                        </div>
                                                        <label class="col-md-2 col-form-label" for="genre">Genres</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center" id="genre"
                                                                name="genre"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['genre']) : ''; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="release_date">Release
                                                            Date</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center"
                                                                id="release_date" name="release_date"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['release_date']) : ''; ?>">
                                                        </div>
                                                        <label class="col-md-2 col-form-label"
                                                            for="episode_run_time">Runtime</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center"
                                                                id="episode_run_time" name="episode_run_time"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['episode_run_time']) : ''; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="youtube_trailer">Youtube
                                                            Trailer</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center"
                                                                id="youtube_trailer" name="youtube_trailer"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['youtube_trailer']) : ''; ?>">
                                                        </div>
                                                        <label class="col-md-2 col-form-label" for="rating">Rating</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center" id="rating"
                                                                name="rating"
                                                                value="<?= isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['rating']) : ''; ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php
                                    } else {
                                    ?>
                                        <div class="tab-pane" id="advanced-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <!-- Advanced Details Content -->
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="direct_source">Direct
                                                            Source <i
                                                                title="Use direct source (e.g., Original Source without transcoding)"
                                                                class="mdi mdi-information-outline"></i></label>
                                                        <div class="col-md-2">
                                                            <input type="checkbox" id="direct_source" name="direct_source"
                                                                data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd"
                                                                <?= isset($rSeriesArr) && $rSeriesArr['direct_source'] ? 'checked' : ''; ?> />
                                                        </div>
                                                        <label class="col-md-4 col-form-label" for="read_native">Read Native
                                                            Frames <i
                                                                title="Enable to read native frames without processing"
                                                                class="mdi mdi-information-outline"></i></label>
                                                        <div class="col-md-2">
                                                            <input type="checkbox" id="read_native" name="read_native"
                                                                data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd"
                                                                <?= isset($rSeriesArr) && $rSeriesArr['read_native'] ? 'checked' : ''; ?> />
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="transcode_profile_id">Transcoding Profile <i
                                                                title="Select transcoding profile for the series"
                                                                class="mdi mdi-information-outline"></i></label>
                                                        <div class="col-md-8">
                                                            <select name="transcode_profile_id" id="transcode_profile_id"
                                                                class="form-control" data-toggle="select2">
                                                                <option value="0">None</option>
                                                                <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                                                    <option value="<?= $rProfile['profile_id']; ?>"
                                                                        <?= isset($rSeriesArr) && $rSeriesArr['transcode_profile_id'] == $rProfile['profile_id'] ? 'selected' : ''; ?>>
                                                                        <?= $rProfile['profile_name']; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="movie_symlink">Create
                                                            Symlink <i title="Create symbolic link instead of copying files"
                                                                class="mdi mdi-information-outline"></i></label>
                                                        <div class="col-md-2">
                                                            <input type="checkbox" id="movie_symlink" name="movie_symlink"
                                                                data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd"
                                                                <?= isset($rSeriesArr) && $rSeriesArr['movie_symlink'] ? 'checked' : ''; ?> />
                                                        </div>
                                                    </div>
                                                    <!-- Additional advanced settings fields can be added here as required -->

                                                </div>
                                            </div>
                                        </div>
                                        <div class="tab-pane" id="load-balancing">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="servers"><?php echo $_['server_tree']; ?></label>
                                                        <div class="col-md-8">
                                                            <div id="server_tree"></div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="restart_on_edit">Process
                                                            Episodes</label>
                                                        <div class="col-md-2">
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
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_series" type="submit" class="btn btn-primary"
                                                        value="Import" />
                                                </li>
                                            </ul>
                                        </div>


                                    <?php
                                    }
                                    ?>
                                </div>
                            </div>
                        </form>
                        <div id="file-browser" class="mfp-hide white-popup-block">
                            <div class="col-12">
                                <div class="form-group row mb-4">
                                    <label class="col-md-4 col-form-label"
                                        for="server_id"><?php echo $_['server_name']; ?></label>
                                    <div class="col-md-8">
                                        <select id="server_id" class="form-control" data-toggle="select2">
                                            <?php foreach (getStreamingServers() as $rServer): ?>
                                                <option value="<?php echo $rServer['id']; ?>">
                                                    <?php echo htmlspecialchars($rServer['server_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group row mb-4">
                                    <label class="col-md-4 col-form-label"
                                        for="current_path"><?php echo $_['current_path']; ?></label>
                                    <div class="col-md-8 input-group">
                                        <input type="text" id="current_path" name="current_path" class="form-control"
                                            value="/">
                                        <div class="input-group-append">
                                            <button class="btn btn-primary waves-effect waves-light" type="button"
                                                id="changeDir"><i class="mdi mdi-chevron-right"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group row mb-4">
                                    <div class="col-md-6">
                                        <table id="datatable" class="table">
                                            <thead>
                                                <tr>
                                                    <th width="20px"></th>
                                                    <th><?php echo $_['directory']; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table id="datatable-files" class="table">
                                            <thead>
                                                <tr>
                                                    <th width="20px"></th>
                                                    <th><?php echo $_['filename']; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="float-right">
                                    <input id="select_folder" type="button" class="btn btn-info" value="Select" />
                                </div>
                            </div>
                        </div>


                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'var changeTitle = false;' . "\r\n\r\n" . '        function selectDirectory(elem) {' . "\r\n\t\t\t" . 'window.currentDirectory += elem + "/";' . "\r\n\t\t\t" . '$("#current_path").val(window.currentDirectory);' . "\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectParent() {' . "\r\n\t\t\t" . '$("#current_path").val(window.currentDirectory.split("/").slice(0,-2).join("/") + "/");' . "\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t" . '}' . "\r\n" . '        function clearSearch() {' . "\r\n\t\t\t" . '$("#search").val("");' . "\r\n\t\t\t" . '$("#doSearch").click();' . "\r\n\t\t" . '}' . "\r\n" . '        function selectFile(rFile) {' . "\r\n\t\t\t" . "if (\$('li.nav-item .active').attr('href') == \"#stream-details\") {" . "\r\n\t\t\t\t" . '$("#stream_source").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);' . "\r\n\t\t\t\t" . "var rExtension = rFile.substr((rFile.lastIndexOf('.')+1));" . "\r\n\t\t\t\t" . "if (\$(\"#target_container option[value='\" + rExtension + \"']\").length > 0) {" . "\r\n\t\t\t\t\t" . "\$(\"#target_container\").val(rExtension).trigger('change');" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#movie_subtitles").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$.magnificPopup.close();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function openImage(elem) {' . "\r\n\t\t\t" . 'rPath = $(elem).parent().parent().find("input").val();' . "\r\n\t\t\t" . 'if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rPath)," . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            $("#category_id").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#category_id').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#category_create").show();' . "\r\n" . "                    \$(\"#category_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#category_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#category_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n" . '            $("#bouquets").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#bouquets').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#bouquet_create").show();' . "\r\n" . "                    \$(\"#bouquet_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#bouquet_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#bouquet_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n" . '            $("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'scrollY: "250px",' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]},' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . '"language": {' . "\r\n\t\t\t\t\t" . '"emptyTable": ""' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#datatable-files").DataTable({' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'scrollY: "250px",' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]},' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"language": {' . "\r\n\t\t\t\t\t" . '"emptyTable": "';
		echo $_['no_compatible_file'];
		echo '"' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#select_folder").click(function() {' . "\r\n\t\t\t\t" . '$("#import_folder").val("s:" + $("#server_id").val() + ":" + window.currentDirectory);' . "\r\n\t\t\t\t" . '$.magnificPopup.close();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#changeDir").click(function() {' . "\r\n\t\t\t\t" . '$("#search").val("");' . "\r\n\t\t\t\t" . 'window.currentDirectory = $("#current_path").val();' . "\r\n\t\t\t\t" . 'if (window.currentDirectory.substr(-1) != "/") {' . "\r\n\t\t\t\t\t" . 'window.currentDirectory += "/";' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$("#current_path").val(window.currentDirectory);' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().clear();' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().row.add(["", "';
		echo $_['loading'];
		echo '..."]);' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().draw(true);' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().clear();' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().row.add(["", "';
		echo $_['please_wait'];
		echo '..."]);' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().draw(true);' . "\r\n\t\t\t\t" . "if (\$('li.nav-item .active').attr('href') == \"#stream-details\") {" . "\r\n\t\t\t\t\t" . 'rFilter = "video";' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'rFilter = "subs";' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=" + rFilter, function(data) {' . "\r\n\t\t\t\t\t" . '$("#datatable").DataTable().clear();' . "\r\n\t\t\t\t\t" . '$("#datatable-files").DataTable().clear();' . "\r\n\t\t\t\t\t" . 'if (window.currentDirectory != "/") {' . "\r\n\t\t\t\t\t\t" . "\$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-subdirectory-arrow-left'></i>\", \"";
		echo $_['parent_directory'];
		echo '"]);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t" . '$(data.data.dirs).each(function(id, dir) {' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-folder-open-outline'></i>\", dir]);" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable").DataTable().draw(true);' . "\r\n\t\t\t\t\t\t" . '$(data.data.files).each(function(id, dir) {' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#datatable-files\").DataTable().row.add([\"<i class='mdi mdi-file-video'></i>\", dir]);" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable-files").DataTable().draw(true);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#datatable').on('click', 'tbody > tr', function() {" . "\r\n\t\t\t\t" . 'if ($(this).find("td").eq(1).html() == "';
		echo $_['parent_directory'];
		echo '") {' . "\r\n\t\t\t\t\t" . 'selectParent();' . "\r\n\t\t\t\t" . '} else if ($(this).find("td").eq(1).html() != "';
		echo $_['loading'];
		echo '...") {' . "\r\n\t\t\t\t\t" . 'selectDirectory($(this).find("td").eq(1).html());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#datatable-files').on('click', 'tbody > tr', function() {" . "\r\n\t\t\t\t" . 'selectFile($(this).find("td").eq(1).html());' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#server_tree').on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#filebrowser").magnificPopup({' . "\r\n\t\t\t\t" . "type: 'inline'," . "\r\n\t\t\t\t" . 'preloader: false,' . "\r\n\t\t\t\t" . "focus: '#server_id'," . "\r\n\t\t\t\t" . 'callbacks: {' . "\r\n\t\t\t\t\t" . 'beforeOpen: function() {' . "\r\n\t\t\t\t\t\t" . 'if ($(window).width() < 830) {' . "\r\n\t\t\t\t\t\t\t" . 'this.st.focus = false;' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . "this.st.focus = '#server_id';" . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#filebrowser").on("mfpOpen", function() {' . "\r\n\t\t\t\t" . 'clearSearch();' . "\r\n\t\t\t\t" . "\$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n\t\t\t\t" . '$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#server_id").change(function() {' . "\r\n\t\t\t\t" . '$("#current_path").val("/");' . "\r\n\t\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#direct_source").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#movie_symlink").change(function() {' . "\r\n\t\t\t\t" . 'evaluateSymlink();' . "\r\n\t\t\t" . '});' . "\r\n" . '            function evaluateDirectSource() {' . "\r\n\t\t\t\t" . '$(["movie_symlink", "read_native", "transcode_profile_id", "target_container", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if ($("#direct_source").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n" . '            function evaluateSymlink() {' . "\r\n" . '                if ($("#direct_source").is(":checked")) { return; }' . "\r\n\t\t\t\t" . '$(["direct_source", "read_native", "transcode_profile_id", "target_container", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if ($("#movie_symlink").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#import_type_1").click(function() {' . "\r\n\t\t\t\t" . '$("#import_m3uf_toggle").show();' . "\r\n\t\t\t\t" . '$("#import_folder_toggle").hide();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#import_type_2").click(function() {' . "\r\n\t\t\t\t" . '$("#import_m3uf_toggle").hide();' . "\r\n\t\t\t\t" . '$("#import_folder_toggle").show();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#title").change(function() {' . "\r\n\t\t\t\t" . 'if (!window.changeTitle) {' . "\r\n\t\t\t\t\t" . "\$(\"#tmdb_search\").empty().trigger('change');" . "\r\n\t\t\t\t\t" . 'if ($("#title").val()) {' . "\r\n\t\t\t\t\t\t" . '$.getJSON("./api?action=tmdb_search&type=series&term=" + encodeURIComponent($("#title").val()) + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {' . "\r\n\t\t\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t\t\t" . 'if (data.data.length > 0) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'newOption = new Option("Found " + data.data.length + " results", -1, true, true);' . "\r\n\t\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'newOption = new Option("No results found", -1, true, true);' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . "\$(\"#tmdb_search\").append(newOption).trigger('change');" . "\r\n\t\t\t\t\t\t\t\t" . '$(data.data).each(function(id, item) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (item.first_air_date) {' . "\r\n" . '                                        ';

		if ($rSettings['movie_year_append'] == 0) {
			echo '                                        rTitle = item.name + " (" + item.first_air_date.substring(0, 4) + ")";' . "\r\n" . '                                        ';
		} else {
			if ($rSettings['movie_year_append'] == 1) {
				echo '                                        rTitle = item.name + " - " + item.first_air_date.substring(0, 4);' . "\r\n" . '                                        ';
			} else {
				echo '                                        rTitle = item.name;' . "\r\n" . '                                        ';
			}
		}

		echo "\t\t\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rTitle = item.name;' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'newOption = new Option(rTitle, item.id, true, true);' . "\r\n\t\t\t\t\t\t\t\t\t" . '$("#tmdb_search").append(newOption);' . "\r\n\t\t\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . 'newOption = new Option("No results found", -1, true, true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#tmdb_search\").val(-1).trigger('change');" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'window.changeTitle = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#tmdb_search").change(function() {' . "\r\n\t\t\t\t" . 'if (($("#tmdb_search").val()) && ($("#tmdb_search").val() > -1)) {' . "\r\n\t\t\t\t\t" . '$.getJSON("./api?action=tmdb&type=series&id=" + encodeURIComponent($("#tmdb_search").val()) + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {' . "\r\n\t\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t\t" . 'window.changeTitle = true;' . "\r\n\t\t\t\t\t\t\t" . '$("#title").val(data.data.name);' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.first_air_date) {' . "\r\n" . '                                $("#year").val(data.data.first_air_date.substr(0, 4));' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n" . '                                $("#year").val("");' . "\r\n" . '                            }' . "\r\n\t\t\t\t\t\t\t" . '$("#cover").val("");' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.poster_path) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#cover").val("https://image.tmdb.org/t/p/w600_and_h900_bestv2" + data.data.poster_path);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '$("#backdrop_path").val("");' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.backdrop_path) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#backdrop_path").val("https://image.tmdb.org/t/p/w1280" + data.data.backdrop_path);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '$("#release_date").val(data.data.first_air_date);' . "\r\n\t\t\t\t\t\t\t" . '$("#episode_run_time").val(data.data.episode_run_time[0]);' . "\r\n\t\t\t\t\t\t\t" . '$("#youtube_trailer").val("");' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.trailer) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#youtube_trailer").val(data.data.trailer);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . 'rCast = "";' . "\r\n\t\t\t\t\t\t\t" . 'rMemberID = 0;' . "\r\n\t\t\t\t\t\t\t" . '$(data.data.credits.cast).each(function(id, member) {' . "\r\n\t\t\t\t\t\t\t\t" . 'rMemberID += 1;' . "\r\n\t\t\t\t\t\t\t\t" . 'if (rMemberID <= 5) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (rCast) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rCast += ", ";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rCast += member.name;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t\t" . '$("#cast").val(rCast);' . "\r\n\t\t\t\t\t\t\t" . 'rGenres = "";' . "\r\n\t\t\t\t\t\t\t" . 'rGenreID = 0;' . "\r\n\t\t\t\t\t\t\t" . '$(data.data.genres).each(function(id, genre) {' . "\r\n\t\t\t\t\t\t\t\t" . 'rGenreID += 1;' . "\r\n\t\t\t\t\t\t\t\t" . 'if (rGenreID <= 3) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (rGenres) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rGenres += ", ";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rGenres += genre.name;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t\t" . '$("#genre").val(rGenres);' . "\r\n\t\t\t\t\t\t\t" . 'rDirectors = "";' . "\r\n" . '                            rDirectorID = 0;' . "\r\n\t\t\t\t\t\t\t" . '$(data.data.credits.crew).each(function(id, member) {' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((member.department == "Directing") || (member.known_for_department == "Directing")) {' . "\r\n" . '                                    rDirectorID += 1;' . "\r\n" . '                                    if (rDirectorID <= 3) {' . "\r\n" . '                                        if (rDirectors) {' . "\r\n" . '                                            rDirectors += ", ";' . "\r\n" . '                                        }' . "\r\n" . '                                        rDirectors += member.name;' . "\r\n" . '                                    }' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '});' . "\r\n" . '                            $("#director").val(rDirectors);' . "\r\n\t\t\t\t\t\t\t" . '$("#plot").val(data.data.overview);' . "\r\n\t\t\t\t\t\t\t" . '$("#rating").val(data.data.vote_average);' . "\r\n\t\t\t\t\t\t\t" . '$("#tmdb_id").val($("#tmdb_search").val());' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#episode_run_time").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#year").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            ' . "\r\n" . '            $("#changeDir").click();' . "\r\n\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . 'evaluateSymlink();' . "\r\n" . '            ' . "\r\n\t\t\t";

		if (!isset($rSeriesArr)) {
		} else {
			echo "\t\t\t" . '$("#title").trigger("change");' . "\r\n\t\t\t";
		}

		echo '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                ';

		if (!isset(CoreUtilities::$rRequest['import'])) {
			echo "\t\t\t\t" . 'if ($("#title").val().length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Enter a series name.");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n" . '                ';
		} else {
			echo '                if (($("#m3u_file").val().length == 0) && ($("#import_folder").val().length == 0)) {' . "\r\n\t\t\t\t\t" . '$.toast("';
			echo $_['select_m3u_file'];
			echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "                    \$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n" . '                ';
		}

		echo "\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>