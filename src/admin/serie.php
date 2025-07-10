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