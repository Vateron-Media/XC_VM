<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

set_time_limit(0);
ini_set('max_execution_time', 0);
$_TITLE = 'Mass Delete';
include 'header.php'; ?>
<div class="wrapper boxed-layout-xl">
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') : ?>
    <?php else : ?>
        <div style="display: none;">
        <?php endif; ?>
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title"><?php echo $_['mass_delete']; ?></h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <?php if (isset($_STATUS) && $_STATUS == 1) : ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Mass delete has been executed.
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-body">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-play mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['streams']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#movie-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['movies']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#radio-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-radio mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['stations']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#episodes-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-open-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['episodes']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#series-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-youtube-tv mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['series']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#line-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-wallet-membership mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['lines']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#user-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account mr-1"></i>
                                            <span class="d-none d-sm-inline">Users</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#mag-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-monitor mr-1"></i>
                                            <span class="d-none d-sm-inline">MAGs</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#enigma-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-desktop-tower-monitor mr-1"></i>
                                            <span class="d-none d-sm-inline">Enigmas</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <form action="#" method="POST" id="stream_form">
                                            <input type="hidden" name="streams" id="streams" value="" />
                                            <div class="row">
                                                <div class="col-md-2 col-6">
                                                    <input type="text" class="form-control" id="stream_search" placeholder="<?php echo $_['search_streams']; ?>...">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="stream_server_id" class="form-control" data-toggle="select2">
                                                        <option value="" selected>All Servers</option>
                                                        <option value="-1">No Servers</option>
                                                        <?php foreach (getStreamingServers() as $rServer) : ?>
                                                            <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="stream_category_search" class="form-control" data-toggle="select2">
                                                        <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                        <option value="-1">No Categories</option>
                                                        <?php foreach (getCategories('live') as $rCategory) : ?>
                                                            <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-6">
                                                    <select id="stream_filter" class="form-control" data-toggle="select2">
                                                        <option value="">No Filter</option>
                                                        <option value="1">Online</option>
                                                        <option value="2">Down</option>
                                                        <option value="3">Stopped</option>
                                                        <option value="4">Starting</option>
                                                        <option value="5">On Demand</option>
                                                        <option value="6">Direct</option>
                                                        <option value="7">Timeshift</option>
                                                        <option value="8">Looping</option>
                                                        <option value="9">Has EPG</option>
                                                        <option value="10">No EPG</option>
                                                        <option value="11">Adaptive Link</option>
                                                        <option value="12">Title Sync</option>
                                                        <option value="13">Transcoding</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <select id="show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md1" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th class="text-center">Icon</th>
                                                            <th>Stream Name</th>
                                                            <th>Category</th>
                                                            <th>Server</th>
                                                            <th class="text-center">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                                <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                    <li class="list-inline-item float-right">
                                                        <input name="submit_streams" type="submit" class="btn btn-primary" value="<?php echo $_['delete_streams']; ?>" />
                                                    </li>
                                                </ul>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="movie-selection">
                                        <form action="#" method="POST" id="movie_form">
                                            <input type="hidden" name="movies" id="movies" value="" />
                                            <div class="row">
                                                <div class="col-md-2 col-6">
                                                    <input type="text" class="form-control" id="movie_search" placeholder="<?php echo $_['search_movies']; ?>...">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="movie_server_id" class="form-control" data-toggle="select2">
                                                        <option value="" selected>All Servers</option>
                                                        <option value="-1">No Servers</option>
                                                        <?php foreach (getStreamingServers() as $rServer) : ?>
                                                            <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="movie_category_search" class="form-control" data-toggle="select2">
                                                        <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                        <option value="-1">No Categories</option>
                                                        <?php foreach (getCategories('movie') as $rCategory) : ?>
                                                            <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-6">
                                                    <select id="movie_filter" class="form-control" data-toggle="select2">
                                                        <option value="" selected><?php echo $_['no_filter']; ?></option>
                                                        <option value="1"><?php echo $_['encoded']; ?></option>
                                                        <option value="2"><?php echo $_['encoding']; ?></option>
                                                        <option value="3"><?php echo $_['down']; ?></option>
                                                        <option value="4"><?php echo $_['ready']; ?></option>
                                                        <option value="5"><?php echo $_['direct']; ?></option>
                                                        <option value="6"><?php echo $_['no_tmdb_match']; ?></option>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <select id="movie_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleMovies()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md2" class="table table-borderless mb-0">
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
                                                <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                    <li class="list-inline-item float-right">
                                                        <input name="submit_movies" type="submit" class="btn btn-primary" value="<?php echo $_['delete_movies']; ?>" />
                                                    </li>
                                                </ul>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="radio-selection">
                                        <form action="#" method="POST" id="radio_form">
                                            <input type="hidden" name="radios" id="radios" value="" />
                                            <div class="row">
                                                <div class="col-md-2 col-6">
                                                    <input type="text" class="form-control" id="radio_search" placeholder="Search Stations...">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="station_server_id" class="form-control" data-toggle="select2">
                                                        <option value="" selected>All Servers</option>
                                                        <option value="-1">No Servers</option>
                                                        <?php foreach (getStreamingServers() as $rServer) : ?>
                                                            <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="radio_category_search" class="form-control" data-toggle="select2">
                                                        <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                        <option value="-1">No Categories</option>
                                                        <?php foreach (getCategories('radio') as $rCategory) : ?>
                                                            <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-6">
                                                    <select id="radio_filter" class="form-control" data-toggle="select2">
                                                        <option value="">No Filter</option>
                                                        <option value="1">Online</option>
                                                        <option value="2">Down</option>
                                                        <option value="3">Stopped</option>
                                                        <option value="4">Starting</option>
                                                        <option value="5">On Demand</option>
                                                        <option value="6">Direct</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <select id="radio_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleRadios()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md6" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th class="text-center">Icon</th>
                                                            <th>Station Name</th>
                                                            <th>Category</th>
                                                            <th><?php echo $_['servers']; ?></th>
                                                            <th class="text-center">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                                <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                    <li class="list-inline-item float-right">
                                                        <input name="submit_streams" type="submit" class="btn btn-primary" value="Delete Stations" />
                                                    </li>
                                                </ul>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="series-selection">
                                        <form action="#" method="POST" id="series_form">
                                            <input type="hidden" name="series" id="series" value="" />
                                            <div class="row">
                                                <div class="col-md-6 col-6">
                                                    <input type="text" class="form-control" id="series_search" value="" placeholder="<?php echo $_['search_series']; ?>...">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="series_category_search" class="form-control" data-toggle="select2">
                                                        <option value="" selected><?php echo $_['all_categories']; ?></option>
                                                        <option value="-1"><?php echo $_['no_tmdb_match']; ?></option>
                                                        <option value="-2">No Categories</option>
                                                        <?php foreach (getCategories('series') as $rCategory): ?>
                                                            <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-6">
                                                    <select id="series_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleSeries()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md4" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th class="text-center">Image</th>
                                                            <th>Name</th>
                                                            <th>Category</th>
                                                            <th class="text-center">Seasons</th>
                                                            <th class="text-center">Episodes</th>
                                                            <th class="text-center">TMDb</th>
                                                            <th class="text-center">First Aired</th>
                                                            <th class="text-center">Last Updated</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_series" type="submit" class="btn btn-primary" value="<?php echo $_['delete_series']; ?>" />
                                                </li>
                                            </ul>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="episodes-selection">
                                        <form action="#" method="POST" id="episodes_form">
                                            <input type="hidden" name="episodes" id="episodes" value="" />
                                            <div class="row">
                                                <div class="col-md-2 col-6">
                                                    <input type="text" class="form-control" id="episode_search" value="" placeholder="<?php echo $_['search_episodes']; ?>...">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="episode_series" class="form-control" data-toggle="select2">
                                                        <option value=""><?php echo $_['all_series']; ?></option>
                                                        <?php foreach (getSeries() as $rSerie): ?>
                                                            <option value="<?php echo $rSerie['id']; ?>"><?php echo $rSerie['title']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="episode_server_id" class="form-control" data-toggle="select2">
                                                        <option value="" selected>All Servers</option>
                                                        <option value="-1">No Servers</option>
                                                        <?php foreach (getStreamingServers() as $rServer): ?>
                                                            <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-6">
                                                    <select id="episode_filter" class="form-control" data-toggle="select2">
                                                        <option value="" selected><?php echo $_['no_filter']; ?></option>
                                                        <option value="1"><?php echo $_['encoded']; ?></option>
                                                        <option value="2"><?php echo $_['encoding']; ?></option>
                                                        <option value="3"><?php echo $_['down']; ?></option>
                                                        <option value="4"><?php echo $_['ready']; ?></option>
                                                        <option value="5"><?php echo $_['direct']; ?></option>
                                                        <option value="7">Transcoding</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <select id="episode_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-6">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleEpisodes()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md5" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center"><?php echo $_['id']; ?></th>
                                                            <th class="text-center">Image</th>
                                                            <th><?php echo $_['name']; ?></th>
                                                            <th><?php echo $_['server']; ?></th>
                                                            <th class="text-center"><?php echo $_['status']; ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_episodes" type="submit" class="btn btn-primary" value="<?php echo $_['delete_episodes']; ?>" />
                                                </li>
                                            </ul>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="line-selection">
                                        <form action="#" method="POST" id="line_form">
                                            <input type="hidden" name="lines" id="lines" value="" />
                                            <div class="row">
                                                <div class="col-md-3 col-6">
                                                    <input type="text" class="form-control" id="line_search" value="" placeholder="Search Lines...">
                                                </div>
                                                <div class="col-md-3">
                                                    <select id="reseller_search" class="form-control" data-toggle="select2">
                                                        <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))): ?>
                                                            <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <label class="col-md-1 col-form-label text-center" for="reseller_search">
                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();">CLEAR</button>
                                                </label>
                                                <div class="col-md-2">
                                                    <select id="line_filter" class="form-control" data-toggle="select2">
                                                        <option value="" selected>No Filter</option>
                                                        <option value="1">Active</option>
                                                        <option value="2">Disabled</option>
                                                        <option value="3">Banned</option>
                                                        <option value="4">Expired</option>
                                                        <option value="5">Trial</option>
                                                        <option value="6">Restreamer</option>
                                                        <option value="7">Ministra</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-8">
                                                    <select id="line_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-2">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleLines()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md3" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Username</th>
                                                            <th></th>
                                                            <th>Owner</th>
                                                            <th class="text-center">Status</th>
                                                            <th></th>
                                                            <th class="text-center">Trial</th>
                                                            <th class="text-center">Restreamer</th>
                                                            <th></th>
                                                            <th class="text-center">Connections</th>
                                                            <th class="text-center">Expiration</th>
                                                            <th></th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_lines" type="submit" class="btn btn-primary" value="Delete Lines" />
                                                </li>
                                            </ul>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="user-selection">
                                        <form action="#" method="POST" id="user_form">
                                            <input type="hidden" name="users" id="users" value="" />
                                            <div class="row">
                                                <div class="col-md-3 col-6">
                                                    <input type="text" class="form-control" id="user_search" value="" placeholder="Search Users...">
                                                </div>
                                                <div class="col-md-3">
                                                    <select id="user_reseller_search" class="form-control" data-toggle="select2">
                                                        <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))): ?>
                                                            <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <label class="col-md-1 col-form-label text-center" for="user_reseller_search">
                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearUserOwner();">CLEAR</button>
                                                </label>
                                                <div class="col-md-2">
                                                    <select id="user_filter" class="form-control" data-toggle="select2">
                                                        <option value="" selected>No Filter</option>
                                                        <option value="-1">Active</option>
                                                        <option value="-2">Disabled</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-8">
                                                    <select id="user_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-2">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleUsers()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md7" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Username</th>
                                                            <th>Owner</th>
                                                            <th class="text-center">IP</th>
                                                            <th class="text-center">Type</th>
                                                            <th class="text-center">Status</th>
                                                            <th class="text-center">Credits</th>
                                                            <th class="text-center">Users</th>
                                                            <th class="text-center">Last Login</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_users" type="submit" class="btn btn-primary" value="Delete Users" />
                                                </li>
                                            </ul>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="mag-selection">
                                        <form action="#" method="POST" id="mag_form">
                                            <input type="hidden" name="mags" id="mags" value="" />
                                            <div class="row">
                                                <div class="col-md-3 col-6">
                                                    <input type="text" class="form-control" id="mag_search" value="" placeholder="Search Devices...">
                                                </div>
                                                <div class="col-md-3">
                                                    <select id="mag_reseller_search" class="form-control" data-toggle="select2">
                                                        <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))): ?>
                                                            <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <label class="col-md-1 col-form-label text-center" for="mag_reseller_search">
                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearMagOwner();">CLEAR</button>
                                                </label>
                                                <div class="col-md-2">
                                                    <select id="mag_filter" class="form-control" data-toggle="select2">
                                                        <option value="" selected>No Filter</option>
                                                        <option value="1">Active</option>
                                                        <option value="2">Disabled</option>
                                                        <option value="3">Banned</option>
                                                        <option value="4">Expired</option>
                                                        <option value="5">Trial</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-8">
                                                    <select id="mag_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-2">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleMags()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md8" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Username</th>
                                                            <th class="text-center">MAC Address</th>
                                                            <th class="text-center">Device</th>
                                                            <th>Owner</th>
                                                            <th class="text-center">Status</th>
                                                            <th class="text-center">Online</th>
                                                            <th class="text-center">Trial</th>
                                                            <th class="text-center">Expiration</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_mags" type="submit" class="btn btn-primary" value="Delete Devices" />
                                                </li>
                                            </ul>
                                        </form>
                                    </div>
                                    <div class="tab-pane" id="enigma-selection">
                                        <form action="#" method="POST" id="enigma_form">
                                            <input type="hidden" name="enigmas" id="enigmas" value="" />
                                            <div class="row">
                                                <div class="col-md-3 col-6">
                                                    <input type="text" class="form-control" id="enigma_search" value="" placeholder="Search Devices...">
                                                </div>
                                                <div class="col-md-3">
                                                    <select id="enigma_reseller_search" class="form-control" data-toggle="select2">
                                                        <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))): ?>
                                                            <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <label class="col-md-1 col-form-label text-center" for="enigma_reseller_search">
                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearE2Owner();">CLEAR</button>
                                                </label>
                                                <div class="col-md-2">
                                                    <select id="enigma_filter" class="form-control" data-toggle="select2">
                                                        <option value="" selected>No Filter</option>
                                                        <option value="1">Active</option>
                                                        <option value="2">Disabled</option>
                                                        <option value="3">Banned</option>
                                                        <option value="4">Expired</option>
                                                        <option value="5">Trial</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-8">
                                                    <select id="enigma_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option <?php if ($rSettings['default_entries'] == $rShow) echo 'selected'; ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-2">
                                                    <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleEnigmas()" style="width: 100%">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-md9" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Username</th>
                                                            <th class="text-center">MAC Address</th>
                                                            <th class="text-center">Device</th>
                                                            <th>Owner</th>
                                                            <th class="text-center">Status</th>
                                                            <th class="text-center">Online</th>
                                                            <th class="text-center">Trial</th>
                                                            <th class="text-center">Expiration</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_enigmas" type="submit" class="btn btn-primary" value="Delete Devices" />
                                                </li>
                                            </ul>
                                        </form>
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