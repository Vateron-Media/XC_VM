<?php
include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || ($rFolder = getWatchFolder(CoreUtilities::$rRequest['id']))) {
} else {
    goHome();
}



$rBouquets = getBouquets();
$_TITLE = 'Add Library';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                        echo ' style="display: none;"';
                                    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo isset($rFolder) ? 'Edit' : 'Add'; ?> Library</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rFolder)): ?>
                                <input type="hidden" name="edit" value="<?php echo intval($rFolder['id']); ?>" />
                            <?php endif; ?>
                            <input type="hidden" name="libraries" id="libraries" value="<?php echo isset($rFolder['plex_libraries']) ? htmlspecialchars($rFolder['plex_libraries']) : ''; ?>" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#folder-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#settings" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-wrench mr-1"></i>
                                            <span class="d-none d-sm-inline">Settings</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="folder-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="server_id">Server Name</label>
                                                    <div class="col-md-8">
                                                        <select name="server_id[]" id="server_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php $rActiveServers = array();
                                                            if (isset($rFolder)) {
                                                                if ($rFolder['server_id']) {
                                                                    $rActiveServers[] = $rFolder['server_id'];
                                                                    echo '<option value="' . $rFolder['server_id'] . '" selected>' . CoreUtilities::$rServers[$rFolder['server_id']]['server_name'] . '</option>';
                                                                }
                                                                if ($rFolder['server_add']) {
                                                                    foreach (json_decode($rFolder['server_add'], true) as $rServerID) {
                                                                        $rActiveServers[] = $rServerID;
                                                                        echo '<option value="' . $rServerID['server_id'] . '" selected>' . CoreUtilities::$rServers[$rServerID]['server_name'] . '</option>';
                                                                    }
                                                                }
                                                            }
                                                            foreach (getStreamingServers() as $rServer) {
                                                                if (!in_array($rServer['id'], $rActiveServers)) {
                                                                    echo '<option value="' . $rServer['id'] . '">' . $rServer['server_name'] . '</option>';
                                                                }
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="plex_ip">Plex Server</label>
                                                    <div class="col-md-6">
                                                        <input type="text" id="plex_ip" name="plex_ip" class="form-control" value="<?php echo isset($rFolder) ? $rFolder['plex_ip'] : ''; ?>" placeholder="Server IP" required data-parsley-trigger="change">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <input type="text" id="plex_port" name="plex_port" class="form-control text-center" value="<?php echo isset($rFolder) ? $rFolder['plex_port'] : ''; ?>" placeholder="Port" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="username">Credentials</label>
                                                    <div class="col-md-4">
                                                        <input type="text" id="username" name="username" class="form-control" value="<?php echo isset($rFolder) ? $rFolder['plex_username'] : ''; ?>" placeholder="Username" required data-parsley-trigger="change">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="password" id="password" name="password" class="form-control" value="<?php echo isset($rFolder) ? $rFolder['plex_password'] : ''; ?>" placeholder="Password">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="library_id">Library</label>
                                                    <div class="col-md-7">
                                                        <select id="library_id" name="library_id" class="form-control" data-toggle="select2">
                                                            <?php
                                                            $rLibraries = isset($rFolder['plex_libraries']) ? json_decode($rFolder['plex_libraries'], true) : array();
                                                            foreach ($rLibraries as $rLibrary) {
                                                                $selected = ($rFolder['directory'] == $rLibrary['key']) ? ' selected' : '';
                                                                echo '<option value="' . $rLibrary['key'] . '"' . $selected . '>' . $rLibrary['title'] . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-1">
                                                        <button class="btn btn-primary waves-effect waves-light" type="button" id="scanPlex"><i class="mdi mdi-reload"></i></button>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="active">Enabled</label>
                                                    <div class="col-md-2">
                                                        <input name="active" id="active" type="checkbox" <?php echo isset($rFolder) && $rFolder['active'] ? 'checked ' : 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="direct_proxy">Direct Stream <i title="When using direct source, hide the original Plex URL by proxying the movie through your servers. This will consume bandwidth but won't require the movie to be saved to your servers permanently." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="direct_proxy" id="direct_proxy" type="checkbox" <?php echo isset($rFolder) && $rFolder['direct_proxy'] ? 'checked ' : 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="settings">
                                        <div class="row">
                                            <div class="col-12">
                                                <!-- Native Frames -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="read_native">Native Frames <i title="Read input video at native frame rate." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="read_native" id="read_native" type="checkbox" <?php echo isset($rFolder) && $rFolder['read_native'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <!-- Create Symlink -->
                                                    <label class="col-md-4 col-form-label" for="movie_symlink">Create Symlink <i title="Generate a symlink to the original file instead of encoding. File needs to exist on all selected servers." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="movie_symlink" id="movie_symlink" type="checkbox" <?php echo isset($rFolder) && $rFolder['movie_symlink'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <!-- Auto-Encode -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="auto_encode">Auto-Encode <i title="Start encoding as soon as the movie is added." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="auto_encode" id="auto_encode" type="checkbox" <?php echo isset($rFolder) && $rFolder['auto_encode'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <!-- Scan Missing ID's -->
                                                    <label class="col-md-4 col-form-label" for="scan_missing">Scan Missing ID's <i title="Check all Plex ID's in the XC_VM database against Plex database and scan missing items too. If this is off, XC_VM will only request items modified after the last scan date. Turning this on will increase time taken to scan as the entire library needs to be scanned instead of the recent items." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="scan_missing" id="scan_missing" type="checkbox" <?php echo isset($rFolder) && $rFolder['scan_missing'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <!-- Auto-Upgrade Quality -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="auto_upgrade">Auto-Upgrade Quality <i title="Automatically upgrade quality if the system finds a new file with better quality that has the same Plex or TMDb ID." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="auto_upgrade" id="auto_upgrade" type="checkbox" <?php echo isset($rFolder) && $rFolder['auto_upgrade'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <!-- Store Categories -->
                                                    <label class="col-md-4 col-form-label" for="store_categories">Store Categories <i title="Save unrecognised categories to Plex Settings, this will allow you to allocate a category after the first run and it will then be added on the second run." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="store_categories" id="store_categories" type="checkbox" <?php echo isset($rFolder) && $rFolder['store_categories'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <!-- Check Against TMDb -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="check_tmdb">Check Against TMDb <i title="If the item has a TMDb ID, check it against the database to ensure duplicates aren't created due to previous content in the XC_VM system." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="check_tmdb" id="check_tmdb" type="checkbox" <?php echo isset($rFolder) && $rFolder['check_tmdb'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <!-- Remove Existing Subtitles -->
                                                    <label class="col-md-4 col-form-label" for="remove_subtitles">Remove Existing Subtitles <i title="Remove existing subtitles from file before encoding. You can't remove hardcoded subtitles using this method." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="remove_subtitles" id="remove_subtitles" type="checkbox" <?php echo isset($rFolder) && $rFolder['remove_subtitles'] ? 'checked ' : ''; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                                <!-- Transcoding Profile -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="transcode_profile_id">Transcoding Profile <i title="Select a transcoding profile to automatically encode videos." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">
                                                            <option value="0" <?php echo !isset($rFolder) || intval($rFolder['transcode_profile_id']) == 0 ? 'selected' : ''; ?>>Transcoding Disabled</option>
                                                            <?php
                                                            foreach (getTranscodeProfiles() as $rProfile) {
                                                                $selected = isset($rFolder) && intval($rFolder['transcode_profile_id']) == intval($rProfile['profile_id']) ? ' selected' : '';
                                                                echo '<option value="' . $rProfile['profile_id'] . '"' . $selected . '>' . $rProfile['profile_name'] . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Target Container -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="target_container">Target Container <i title="Which container to use when transcoding files." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <select name="target_container" id="target_container" class="form-control" data-toggle="select2">
                                                            <?php
                                                            $containers = ['auto', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
                                                            foreach ($containers as $container) {
                                                                $selected = isset($rFolder) && $rFolder['target_container'] == $container ? ' selected' : '';
                                                                echo "<option value=\"{$container}\"{$selected}>{$container}</option>";
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Override Bouquets -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="override_bouquets">Override Bouquets</label>
                                                    <div class="col-md-8">
                                                        <select name="override_bouquets[]" id="override_bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php
                                                            foreach ($rBouquets as $rBouquet) {
                                                                $selected = in_array(intval($rBouquet['id']), json_decode($rFolder['bouquets'], true)) ? ' selected' : '';
                                                                echo '<option value="' . $rBouquet['id'] . '"' . $selected . '>' . $rBouquet['bouquet_name'] . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Fallback Bouquets -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="fallback_bouquets">Fallback Bouquets</label>
                                                    <div class="col-md-8">
                                                        <select name="fallback_bouquets[]" id="fallback_bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php
                                                            foreach ($rBouquets as $rBouquet) {
                                                                $selected = in_array(intval($rBouquet['id']), json_decode($rFolder['fb_bouquets'], true)) ? ' selected' : '';
                                                                echo '<option value="' . $rBouquet['id'] . '"' . $selected . '>' . $rBouquet['bouquet_name'] . '</option>';
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Override Category -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="override_category">Override Category</label>
                                                    <div class="col-md-8">
                                                        <select name="override_category" id="override_category" class="form-control select2" data-toggle="select2">
                                                            <option value="0" <?php echo !isset($rFolder) || intval($rFolder['category_id']) == 0 ? 'selected' : ''; ?>>Do Not Use</option>
                                                            <?php
                                                            // Assuming getCategories() is a function that returns categories
                                                            // Example categories could be fetched for 'movie' and 'series' types
                                                            foreach (['movie' => 'Movies', 'series' => 'Series'] as $type => $label) {
                                                                echo "<optgroup label=\"{$label}\">";
                                                                foreach (getCategories($type) as $rCategory) {
                                                                    $selected = isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id']) ? ' selected' : '';
                                                                    echo '<option value="' . intval($rCategory['id']) . '"' . $selected . '>' . $rCategory['category_name'] . '</option>';
                                                                }
                                                                echo "</optgroup>";
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <!-- Fallback Category -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="fallback_category">Fallback Category</label>
                                                    <div class="col-md-8">
                                                        <select name="fallback_category" id="fallback_category" class="form-control select2" data-toggle="select2">
                                                            <option value="0" <?php echo !isset($rFolder) || intval($rFolder['fb_category_id']) == 0 ? 'selected' : ''; ?>>Do Not Use</option>
                                                            <?php
                                                            foreach (['movie' => 'Movies', 'series' => 'Series'] as $type => $label) {
                                                                echo "<optgroup label=\"{$label}\">";
                                                                foreach (getCategories($type) as $rCategory) {
                                                                    $selected = isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id']) ? ' selected' : '';
                                                                    echo '<option value="' . intval($rCategory['id']) . '"' . $selected . '>' . $rCategory['category_name'] . '</option>';
                                                                }
                                                                echo "</optgroup>";
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                            <!-- <ul class="list-inline wizard mb-0">
                                <li class="prevb list-inline-item">
                                    <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                </li>
                                <li class="list-inline-item float-right">
                                    <input name="submit_folder" type="submit" class="btn btn-primary" value="<?php echo isset($rFolder) ? 'Edit' : 'Add'; ?>" />
                                </li>
                            </ul> -->
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>