<?php

use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamConfigRepository;

?>

<div class="d-flex align-items-center mb-4">
    <a href="plex" class="btn btn-icon btn-label-secondary me-3"><i class="icon-base ti tabler-arrow-left"></i></a>
    <h4 class="mb-0"><?= isset($rFolder) ? 'Edit' : 'Add' ?> Library</h4>
</div>

<form action="#" method="POST" id="library-form" autocomplete="off">
    <?php if (isset($rFolder)) : ?>
        <input type="hidden" name="edit" value="<?= intval($rFolder['id']); ?>" />
    <?php endif; ?>
    <input type="hidden" name="libraries" id="libraries" value="<?= isset($rFolder['plex_libraries']) ? htmlspecialchars($rFolder['plex_libraries']) : ''; ?>" />

    <div class="card mb-6">
        <div class="card-header px-0 pt-2">
            <div class="nav-align-top">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#folder-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i>Details</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#settings" role="tab"><i class="icon-base ti tabler-settings me-1"></i>Settings</button></li>
                </ul>
            </div>
        </div>
        <div class="card-body">
            <div class="tab-content p-0">
                <div class="tab-pane fade show active" id="folder-details" role="tabpanel">
                    <div class="mb-6">
                        <label class="form-label" for="server_id">Server Name</label>
                        <select name="server_id[]" id="server_id" class="form-select" multiple="multiple" data-placeholder="Choose...">
                            <?php
                            $rActiveServers = array();
                            if (isset($rFolder)) {
                                if ($rFolder['server_id']) {
                                    $rActiveServers[] = $rFolder['server_id'];
                                    echo '<option value="' . $rFolder['server_id'] . '" selected>' . ServerRepository::getAll()[$rFolder['server_id']]['server_name'] . '</option>';
                                }

                                if ($rFolder['server_add']) {
                                    foreach (json_decode($rFolder['server_add'], true) as $rServerID) {
                                        $rActiveServers[] = $rServerID;
                                        echo '<option value="' . $rServerID['server_id'] . '" selected>' . ServerRepository::getAll()[$rServerID]['server_name'] . '</option>';
                                    }
                                }
                            }
                            foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer) {
                                if (!in_array($rServer['id'], $rActiveServers)) {
                                    echo '<option value="' . $rServer['id'] . '">' . $rServer['server_name'] . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-8">
                            <label class="form-label" for="plex_ip">Plex Server</label>
                            <input type="text" id="plex_ip" name="plex_ip" class="form-control" value="<?= isset($rFolder) ? $rFolder['plex_ip'] : ''; ?>" placeholder="Server IP" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="plex_port">Port</label>
                            <input type="text" id="plex_port" name="plex_port" class="form-control text-center" value="<?= isset($rFolder) ? $rFolder['plex_port'] : ''; ?>" placeholder="Port" required>
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="form-control" value="<?= isset($rFolder) ? $rFolder['plex_username'] : ''; ?>" placeholder="Username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" value="<?= isset($rFolder) ? $rFolder['plex_password'] : ''; ?>" placeholder="Password">
                        </div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="library_id">Library</label>
                        <div class="input-group">
                            <select id="library_id" name="library_id" class="form-select">
                                <?php
                                $rLibraries = isset($rFolder['plex_libraries']) ? json_decode($rFolder['plex_libraries'], true) : array();
                                foreach ((is_array($rLibraries ?? null) ? $rLibraries : []) as $rLibrary) {
                                    if ($rFolder['directory'] == $rLibrary['key']) {
                                        echo '<option selected value="' . $rLibrary['key'] . '">' . $rLibrary['title'] . '</option>';
                                    } else {
                                        echo '<option value="' . $rLibrary['key'] . '">' . $rLibrary['title'] . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <button class="btn btn-primary" type="button" id="scanPlex"><i class="icon-base ti tabler-refresh"></i></button>
                        </div>
                    </div>
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="active" id="active" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['active'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="active">Enabled</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="direct_proxy" id="direct_proxy" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['direct_proxy'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="direct_proxy">Direct Stream <i title="When using direct source, hide the original Plex URL by proxying the movie through your servers. This will consume bandwidth but won't require the movie to be saved to your servers permanently." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="settings" role="tabpanel">
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="read_native" id="read_native" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['read_native']) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="read_native">Native Frames <i title="Read input video at native frame rate." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="movie_symlink" id="movie_symlink" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['movie_symlink'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="movie_symlink">Create Symlink <i title="Generate a symlink to the original file instead of encoding. File needs to exist on all selected servers." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="auto_encode" id="auto_encode" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['auto_encode'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="auto_encode">Auto-Encode <i title="Start encoding as soon as the movie is added." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="scan_missing" id="scan_missing" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['scan_missing']) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="scan_missing">Scan Missing ID's <i title="Check all Plex ID's in the XC_VM database against Plex database and scan missing items too. If this is off, XC_VM will only request items modified after the last scan date. Turning this on will increase time taken to scan as the entire library needs to be scanned instead of the recent items." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="auto_upgrade" id="auto_upgrade" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['auto_upgrade'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="auto_upgrade">Auto-Upgrade Quality <i title="Automatically upgrade quality if the system finds a new file with better quality that has the same Plex or TMDb ID." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="store_categories" id="store_categories" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['store_categories'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="store_categories">Store Categories <i title="Save unrecognised categories to Plex Settings, this will allow you to allocate a category after the first run and it will then be added on the second run." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mb-6">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="check_tmdb" id="check_tmdb" type="checkbox" value="1" class="form-check-input" <?php if (!isset($rFolder) || (isset($rFolder) && $rFolder['check_tmdb'])) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="check_tmdb">Check Against TMDb <i title="If the item has a TMDb ID, check it against the database to ensure duplicates aren't created due to previous content in the XC_VM system." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input name="remove_subtitles" id="remove_subtitles" type="checkbox" value="1" class="form-check-input" <?php if (isset($rFolder) && $rFolder['remove_subtitles']) {
                                    echo 'checked ';
                                } ?> />
                                <label class="form-check-label" for="remove_subtitles">Remove Existing Subtitles <i title="Remove existing subtitles from file before encoding. You can't remove hardcoded subtitles using this method." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="target_container"><?= $language::get('target_container'); ?> <i title="Which container to use when transcoding files." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                        <select name="target_container" id="target_container" class="form-select">
                            <?php foreach (array('auto', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $container) { ?>
                                <option <?php if (isset($rFolder) && $rFolder['target_container']) {
                                            echo 'checked ';
                                        } ?> value="<?= $container; ?>"><?= $container; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="override_bouquets">Override Bouquets</label>
                        <select name="override_bouquets[]" id="override_bouquets" class="form-select" multiple="multiple" data-placeholder="Choose...">
                            <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) { ?>
                                <?php $folderBouquets = (array) json_decode($rFolder['bouquets'] ?? '[]', true); ?>
                                <option <?php if (in_array(intval($rBouquet['id']), $folderBouquets)) {
                                            echo 'selected ';
                                        } ?> value="<?= $rBouquet['id']; ?>"><?= $rBouquet['bouquet_name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="fallback_bouquets">Fallback Bouquets</label>
                        <select name="fallback_bouquets[]" id="fallback_bouquets" class="form-select" multiple="multiple" data-placeholder="Choose...">
                            <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) { ?>
                                <?php $folderBouquets = (array) json_decode($rFolder['fb_bouquets'] ?? '[]', true); ?>
                                <option <?php if (in_array(intval($rBouquet['id']), $folderBouquets)) {
                                            echo 'selected';
                                        } ?> value="<?= $rBouquet['id']; ?>"><?= $rBouquet['bouquet_name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="mb-6" id="override_category">
                        <label class="form-label" for="override_category">Override Category</label>
                        <select name="override_category" id="override_category" class="form-select">
                            <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == 0) {
                                        echo 'selected ';
                                    } ?> value="0">Do Not Use</option>
                            <optgroup label="Movies">
                                <?php foreach (CategoryService::getAllByType('movie') as $rCategory) { ?>
                                    <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id'])) {
                                                echo 'selected ';
                                            } ?> value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                                <?php } ?>
                            <optgroup label="Series">
                                <?php foreach (CategoryService::getAllByType('series') as $rCategory) { ?>
                                    <option <?php if (isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id'])) {
                                                echo 'selected ';
                                            } ?> value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                                <?php } ?>
                        </select>
                    </div>
                    <div class="mb-6" id="fallback_category">
                        <label class="form-label" for="fallback_category">Fallback Category</label>
                        <select name="fallback_category" id="fallback_category" class="form-select">
                            <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == 0) {
                                        echo 'selected ';
                                    } ?> value="0">Do Not Use</option>
                            <optgroup label="Movies">
                                <?php foreach (CategoryService::getAllByType('movie') as $rCategory) { ?>
                                    <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id'])) {
                                                echo 'selected ';
                                            } ?> value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                                <?php } ?>
                            <optgroup label="Series">
                                <?php foreach (CategoryService::getAllByType('series') as $rCategory) { ?>
                                    <option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id'])) {
                                                echo 'selected ';
                                            } ?> value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
                                <?php } ?>
                        </select>
                    </div>
                    <div class="mb-6">
                        <label class="form-label" for="transcode_profile_id">Transcoding Profile <i title="Select a transcoding profile to autoamtically encode videos." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                        <select name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                            <option <?php if (isset($rFolder) && intval($rFolder['transcode_profile_id']) == 0) {
                                        echo 'selected ';
                                    } ?>value="0">Transcoding Disabled</option>
                            <?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile) { ?>
                                <option <?php if (isset($rFolder) && intval($rFolder['transcode_profile_id']) == intval($rProfile['profile_id'])) {
                                            echo 'selected ';
                                        } ?> value="<?= $rProfile['profile_id']; ?>"><?= $rProfile['profile_name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-6">
        <input name="submit_folder" type="submit" class="btn btn-primary" value="<?= isset($rFolder) ? 'Edit' : 'Add'; ?>" />
    </div>
</form>
