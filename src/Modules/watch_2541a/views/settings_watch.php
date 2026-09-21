<?php

/**
 * Folder Watch settings (Bootstrap 5, new-UI). Setup scan/match tuning plus
 * per-genre Category + Bouquet mapping for Movie (type 1) and TV (type 2) folders.
 * Body-only view: the controller renders the unified admin shell around it and
 * includes settings_watch_scripts.php afterwards. Posts to post.php?action=settings_watch.
 */

use XcVm\Domain\Stream\CategoryService;

?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0">Folder Watch Settings</h4>
</div>

<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        Watch settings successfully updated!
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form id="watch-settings-form" method="POST" action="post.php?action=settings_watch" autocomplete="off">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#setup"><i class="icon-base ti tabler-id me-1"></i>Setup</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#categories"><i class="icon-base ti tabler-movie me-1"></i>Movie Categories</button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#categories-tv"><i class="icon-base ti tabler-device-tv me-1"></i>TV Categories</button></li>
            </ul>

            <div class="tab-content p-4 border border-top-0 rounded-bottom">
                <!-- Setup -->
                <div class="tab-pane fade show active" id="setup">
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="scan_seconds">Scan Frequency <i title="Scan a folder every X seconds." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="scan_seconds" name="scan_seconds" value="<?php echo htmlspecialchars($rSettings['scan_seconds']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="percentage_match">Match Percentage <i title="TMDb match tolerance. Will not accept match if below this percentage threshold." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="percentage_match" name="percentage_match" value="<?php echo htmlspecialchars($rSettings['percentage_match']); ?>">
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="thread_count">Thread Count <i title="Number of threads to run simultaneously." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="thread_count" name="thread_count" value="<?php echo htmlspecialchars($rSettings['thread_count']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="max_genres">Max Categories &amp; Bouquets <i title="Maximum number of TMDb genres to convert into categories and bouquets. Enter 0 for unlimited." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="max_genres" name="max_genres" value="<?php echo htmlspecialchars($rSettings['max_genres']); ?>">
                        </div>
                    </div>
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-label" for="max_items">Max Items <i title="Maximum number of items to add per folder per scan. Set this to 0 to scan everything." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            <input type="text" class="form-control text-center" id="max_items" name="max_items" value="<?php echo htmlspecialchars($rSettings['max_items']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="alternative_titles" name="alternative_titles" value="1" <?php if ($rSettings['alternative_titles'] == 1) {
                                    echo 'checked';
                                } ?>>
                                <label class="form-check-label" for="alternative_titles">Check Alternative Titles <i title="If a title partially matches a TMDb result, check the alternative titles of that Movie or TV Show to see if any of those match the title." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="fallback_parser" name="fallback_parser" value="1" <?php if ($rSettings['fallback_parser'] == 1) {
                                    echo 'checked';
                                } ?>>
                                <label class="form-check-label" for="fallback_parser">Use Fallback Parser <i title="If no match is found using your preferred title parser, fallback to the parser you didn't select and run again." class="icon-base ti tabler-help-circle text-secondary"></i></label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Movie Categories -->
                <div class="tab-pane fade" id="categories">
                    <p class="text-body-secondary">Select a Category and / or Bouquet to apply to each Genre.</p>
                    <?php
                    $db->query('SELECT * FROM `watch_categories` WHERE `type` = 1 ORDER BY `genre` ASC;');
                    if ($db->num_rows() > 0) {
                        foreach ($db->get_rows() as $rRow) {
                    ?>
                            <div class="row mb-4">
                                <label class="col-md-2 col-form-label" for="genre_<?php echo $rRow['genre_id']; ?>"><?php echo $rRow['genre']; ?></label>
                                <div class="col-md-4">
                                    <select name="genre_<?php echo $rRow['genre_id']; ?>" id="genre_<?php echo $rRow['genre_id']; ?>" class="form-select select2">
                                        <option <?php if (intval($rRow['category_id']) == 0) {
                                            echo 'selected';
                                        } ?> value="0">Do Not Use</option>
                                        <?php foreach (CategoryService::getAllByType('movie') as $rCategory) { ?>
                                            <option <?php if (intval($rRow['category_id']) == intval($rCategory['id'])) {
                                                echo 'selected';
                                            } ?> value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <label class="col-md-2 col-form-label" for="bouquet_<?php echo $rRow['genre_id']; ?>">Bouquets</label>
                                <div class="col-md-4">
                                    <select name="bouquet_<?php echo $rRow['genre_id']; ?>[]" id="bouquet_<?php echo $rRow['genre_id']; ?>" class="form-select select2" multiple="multiple" data-placeholder="Choose...">
                                        <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) { ?>
                                            <option <?php if (in_array(intval($rBouquet['id']), (array) json_decode($rRow['bouquets'], true))) {
                                                echo 'selected';
                                            } ?> value="<?php echo $rBouquet['id']; ?>"><?php echo $rBouquet['bouquet_name']; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                    <?php
                        }
                    }
                    ?>
                </div>

                <!-- TV Categories -->
                <div class="tab-pane fade" id="categories-tv">
                    <p class="text-body-secondary">Select a Category and / or Bouquet to apply to each Genre.</p>
                    <?php
                    $db->query('SELECT * FROM `watch_categories` WHERE `type` = 2 ORDER BY `genre` ASC;');
                    if ($db->num_rows() > 0) {
                        foreach ($db->get_rows() as $rRow) {
                    ?>
                            <div class="row mb-4">
                                <label class="col-md-2 col-form-label" for="genretv_<?php echo $rRow['genre_id']; ?>"><?php echo $rRow['genre']; ?></label>
                                <div class="col-md-4">
                                    <select name="genretv_<?php echo $rRow['genre_id']; ?>" id="genretv_<?php echo $rRow['genre_id']; ?>" class="form-select select2">
                                        <option <?php if (intval($rRow['category_id']) == 0) {
                                            echo 'selected';
                                        } ?> value="0">Do Not Use</option>
                                        <?php foreach (CategoryService::getAllByType('series') as $rCategory) { ?>
                                            <option <?php if (intval($rRow['category_id']) == intval($rCategory['id'])) {
                                                echo 'selected';
                                            } ?> value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <label class="col-md-2 col-form-label" for="bouquettv_<?php echo $rRow['genre_id']; ?>">Bouquets</label>
                                <div class="col-md-4">
                                    <select name="bouquettv_<?php echo $rRow['genre_id']; ?>[]" id="bouquettv_<?php echo $rRow['genre_id']; ?>" class="form-select select2" multiple="multiple" data-placeholder="Choose...">
                                        <?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) { ?>
                                            <option <?php if (in_array(intval($rBouquet['id']), (array) json_decode($rRow['bouquets'], true))) {
                                                echo 'selected';
                                            } ?> value="<?php echo $rBouquet['id']; ?>"><?php echo $rBouquet['bouquet_name']; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                    <?php
                        }
                    }
                    ?>
                </div>
            </div>

            <div class="text-end mt-4">
                <button type="submit" name="submit_settings" id="save-settings" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
