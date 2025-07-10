<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php $rCategories = getCategories('movie'); ?>

<?php if (isset(CoreUtilities::$rRequest['submit_stream'])): ?>
    <?php $rReturn = API::massEditMovies(CoreUtilities::$rRequest); ?>
    <?php $_STATUS = $rReturn['status']; ?>
    <?php if ($_STATUS == 0): ?>
        <?php header('Location: ./movies_mass?status=0');
        exit(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php $rTranscodeProfiles = getTranscodeProfiles(); ?>
<?php $rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true))); ?>
<?php foreach ($rServers as $rServer): ?>
    <?php $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true)); ?>
<?php endforeach; ?>
<?php $_TITLE = 'Mass Edit Movies'; ?>
<?php include 'header.php'; ?>

<div class="wrapper boxed-layout-xl" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'): ?><?php else: ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['mass_edit_movies']; ?> <small id="selected_count"></small></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?php echo $_['mass_edit_movies_success']; ?>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
                            <input type="hidden" name="streams" id="streams" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-play mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['movies']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['servers']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="Search Movies...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="movie_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                                        <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach ($rCategories as $rCategory): ?>
                                                        <option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']): ?> selected<?php endif; ?>><?php echo $rCategory['category_name']; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?php echo $_['no_filter']; ?></option>
                                                    <option value="1"><?php echo $_['encoded']; ?></option>
                                                    <option value="2"><?php echo $_['encoding']; ?></option>
                                                    <option value="3"><?php echo $_['down']; ?></option>
                                                    <option value="4"><?php echo $_['ready']; ?></option>
                                                    <option value="5"><?php echo $_['direct']; ?></option>
                                                    <option value="6"><?php echo $_['no_tmdb_match']; ?></option>
                                                    <option value="8">Transcoding</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                        <option<?php if ($rSettings['default_entries'] == $rShow): ?> selected<?php endif; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center"><?php echo $_['id']; ?></th>
                                                        <th class="text-center">Image</th>
                                                        <th><?php echo $_['name']; ?></th>
                                                        <th><?php echo $_['category']; ?></th>
                                                        <th><?php echo $_['servers']; ?></th>
                                                        <th class="text-center"><?php echo $_['status']; ?></th>
                                                        <th class="text-center">TMDb</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header"><?php echo $_['mass_edit_info']; ?></p>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="category_id">Select Categories</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rCategories as $rCategory): ?>
                                                                <option value="<?php echo intval($rCategory['id']); ?>"><?php echo htmlspecialchars($rCategory['category_name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="category_id_type" id="category_id_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType): ?>
                                                                <option value="<?php echo $rType; ?>"><?php echo $rType; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bouquets" name="c_bouquets">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="bouquets"><?php echo $_['select_bouquets']; ?></label>
                                                    <div class="col-md-6">
                                                        <select disabled name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet): ?>
                                                                <option value="<?php echo $rBouquet['id']; ?>"><?php echo $rBouquet['bouquet_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="bouquets_type" id="bouquets_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType): ?>
                                                                <option value="<?php echo $rType; ?>"><?php echo $rType; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="direct_source" data-type="switch" name="c_direct_source">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="direct_source"><?php echo $_['direct_source']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="direct_source" id="direct_source" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="direct_proxy">Direct Stream</label>
                                                    <div class="col-md-2">
                                                        <input name="direct_proxy" id="direct_proxy" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="direct_proxy" data-type="switch" name="c_direct_proxy">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="movie_symlink" data-type="switch" name="c_movie_symlink">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="movie_symlink"><?php echo $_['create_symlink']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="movie_symlink" id="movie_symlink" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="read_native"><?php echo $_['native_frames']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="read_native" id="read_native" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="read_native" data-type="switch" name="c_read_native">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="target_container" name="c_target_container">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="target_container"><?php echo $_['target_container']; ?></label>
                                                    <div class="col-md-2">
                                                        <select disabled name="target_container" id="target_container" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer): ?>
                                                                <option value="<?php echo $rContainer; ?>"><?php echo $rContainer; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="remove_subtitles"><?php echo $_['remove_subtitles']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="remove_subtitles" id="remove_subtitles" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="remove_subtitles" data-type="switch" name="c_remove_subtitles">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="transcode_profile_id"><?php echo $_['transcoding_profile']; ?></label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">
                                                            <option selected value="0"><?php echo $_['transcoding_disabled']; ?></option>
                                                            <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                                                <option value="<?php echo $rProfile['profile_id']; ?>"><?php echo $rProfile['profile_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="server_tree"><?php echo $_['server_tree']; ?></label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="server_type">Server Type</label>
                                                    <div class="col-md-2">
                                                        <select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED') as $rValue => $rType): ?>
                                                                <option value="<?php echo $rValue; ?>"><?php echo $rType; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="reencode_on_edit"><?php echo $_['reencode_on_edit']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="reprocess_tmdb"><?php echo $_['reprocess_tmdb_data']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="reprocess_tmdb" id="reprocess_tmdb" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>