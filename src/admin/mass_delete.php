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
<div class="wrapper boxed-layout-xl" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'): ?><?php else: ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= $language::get('mass_delete'); ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == 1): ?>
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
                                        <span class="d-none d-sm-inline"><?= $language::get('streams'); ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#movie-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('movies'); ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#radio-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-radio mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('stations'); ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#episodes-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-folder-open-outline mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('episodes') ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#series-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-youtube-tv mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('series') ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#line-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-wallet-membership mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('lines') ?></span>
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
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="<?= $language::get('search_streams') ?>...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="stream_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                                        <option value="<?= intval($rServer['id']) ?>"><?= $rServer['server_name'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="stream_category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('all_categories') ?></option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach (getCategories('live') as $rCategory): ?>
                                                        <option value="<?= $rCategory['id'] ?>"><?= $rCategory['category_name'] ?></option>
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
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_streams" type="submit" class="btn btn-primary" value="<?= $language::get('delete_streams') ?>" />
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                                <div class="tab-pane" id="movie-selection">
                                    <form action="#" method="POST" id="movie_form">
                                        <input type="hidden" name="movies" id="movies" value="" />
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="movie_search" value="" placeholder="<?= $language::get('search_movies') ?>...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="movie_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                                        <option value="<?= intval($rServer['id']) ?>"><?= $rServer['server_name'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="movie_category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('all_categories') ?></option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach (getCategories('movie') as $rCategory): ?>
                                                        <option value="<?= $rCategory['id'] ?>"><?= $rCategory['category_name'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="movie_filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('no_filter') ?></option>
                                                    <option value="1"><?= $language::get('encoded') ?></option>
                                                    <option value="2"><?= $language::get('encoding') ?></option>
                                                    <option value="3"><?= $language::get('down') ?></option>
                                                    <option value="4"><?= $language::get('ready') ?></option>
                                                    <option value="5"><?= $language::get('direct') ?></option>
                                                    <option value="6"><?= $language::get('no_tmdb_match') ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <select id="movie_show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <th class="text-center"><?= $language::get('id') ?></th>
                                                        <th class="text-center">Image</th>
                                                        <th><?= $language::get('name') ?></th>
                                                        <th><?= $language::get('category') ?></th>
                                                        <th><?= $language::get('servers') ?></th>
                                                        <th class="text-center"><?= $language::get('status') ?></th>
                                                        <th class="text-center">TMDb</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_movies" type="submit" class="btn btn-primary" value="<?= $language::get('delete_movies') ?>" />
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                                <div class="tab-pane" id="radio-selection">
                                    <form action="#" method="POST" id="radio_form">
                                        <input type="hidden" name="radios" id="radios" value="" />
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="radio_search" value="" placeholder="Search Stations...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="station_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                                        <option value="<?= intval($rServer['id']) ?>"><?= $rServer['server_name'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="radio_category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('all_categories') ?></option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach (getCategories('radio') as $rCategory): ?>
                                                        <option value="<?= $rCategory['id'] ?>"><?= $rCategory['category_name'] ?></option>
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
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <th><?= $language::get('servers') ?></th>
                                                        <th class="text-center">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_streams" type="submit" class="btn btn-primary" value="Delete Stations" />
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                                <div class="tab-pane" id="series-selection">
                                    <form action="#" method="POST" id="series_form">
                                        <input type="hidden" name="series" id="series" value="" />
                                        <div class="row">
                                            <div class="col-md-6 col-6">
                                                <input type="text" class="form-control" id="series_search" value="" placeholder="<?= $language::get('search_series') ?>...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="series_category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('all_categories') ?></option>
                                                    <option value="-1"><?= $language::get('no_tmdb_match') ?></option>
                                                    <option value="-2">No Categories</option>
                                                    <?php foreach (getCategories('series') as $rCategory): ?>
                                                        <option value="<?= $rCategory['id'] ?>"><?= $rCategory['category_name'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="series_show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                <input name="submit_series" type="submit" class="btn btn-primary" value="<?= $language::get('delete_series') ?>" />
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                                <div class="tab-pane" id="episodes-selection">
                                    <form action="#" method="POST" id="episodes_form">
                                        <input type="hidden" name="episodes" id="episodes" value="" />
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="episode_search" value="" placeholder="<?= $language::get('search_episodes') ?>...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="episode_series" class="form-control" data-toggle="select2">
                                                    <option value=""><?= $language::get('all_series') ?></option>
                                                    <?php foreach (getSeries() as $rSerie): ?>
                                                        <option value="<?= $rSerie['id'] ?>"><?= $rSerie['title'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="episode_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer): ?>
                                                        <option value="<?= intval($rServer['id']) ?>"><?= $rServer['server_name'] ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="episode_filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('no_filter') ?></option>
                                                    <option value="1"><?php echo $language::get('encoded'); ?></option>
                                                    <option value="2"><?php echo $language::get('encoding'); ?></option>
                                                    <option value="3"><?php echo $language::get('down'); ?></option>
                                                    <option value="4"><?php echo $language::get('ready'); ?></option>
                                                    <option value="5"><?php echo $language::get('direct'); ?></option>
                                                    <option value="7">Transcoding</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <select id="episode_show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <th class="text-center"><?= $language::get('id') ?></th>
                                                        <th class="text-center">Image</th>
                                                        <th><?= $language::get('name') ?></th>
                                                        <th><?= $language::get('server') ?></th>
                                                        <th class="text-center"><?= $language::get('status') ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:20px;">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_episodes" type="submit" class="btn btn-primary" value="<?= $language::get('delete_episodes') ?>" />
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
                                                        <option value="<?= intval($rOwner['id']) ?>" selected="selected"><?= $rOwner['username'] ?></option>
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
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <option value="<?= intval($rOwner['id']) ?>" selected="selected"><?= $rOwner['username'] ?></option>
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
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <option value="<?= intval($rOwner['id']) ?>" selected="selected"><?= $rOwner['username'] ?></option>
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
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <th class="text-center"><?= $language::get('id') ?></th>
                                                        <th><?= $language::get('username') ?></th>
                                                        <th class="text-center"><?= $language::get('mac_address') ?></th>
                                                        <th class="text-center">Device</th>
                                                        <th><?= $language::get('owner') ?></th>
                                                        <th class="text-center"><?= $language::get('status') ?></th>
                                                        <th class="text-center"><?= $language::get('online') ?></th>
                                                        <th class="text-center"><?= $language::get('trial') ?></th>
                                                        <th class="text-center"><?= $language::get('expiration') ?></th>
                                                        <th class="text-center"><?= $language::get('actions') ?></th>
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
                                                        <option value="<?= intval($rOwner['id']) ?>" selected="selected"><?= $rOwner['username'] ?></option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            <label class="col-md-1 col-form-label text-center" for="enigma_reseller_search"><button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearE2Owner();">CLEAR</button></label>
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
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>>
                                                            <?= $rShow ?>
                                                        </option>
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
                                                        <th class="text-center"><?= $language::get('id') ?></th>
                                                        <th><?= $language::get('username') ?></th>
                                                        <th class="text-center"><?= $language::get('mac_address') ?></th>
                                                        <th class="text-center">Device</th>
                                                        <th><?= $language::get('owner') ?></th>
                                                        <th class="text-center"><?= $language::get('status') ?></th>
                                                        <th class="text-center"><?= $language::get('online') ?></th>
                                                        <th class="text-center"><?= $language::get('trial') ?></th>
                                                        <th class="text-center"><?= $language::get('expiration') ?></th>
                                                        <th class="text-center"><?= $language::get('actions') ?></th>
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
<script id="scripts">
    var resizeObserver = new ResizeObserver(entries => $(window).scroll());
    $(document).ready(function() {
        resizeObserver.observe(document.body)
        $("form").attr('autocomplete', 'off');
        $(document).keypress(function(event) {
            if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
        });
        $.fn.dataTable.ext.errMode = 'none';
        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function(html) {
            var switchery = new Switchery(html, {
                'color': '#414d5f'
            });
            window.rSwitches[$(html).attr("id")] = switchery;
        });
        setTimeout(pingSession, 30000);
        <?php if (!$rMobile && $rSettings['header_stats']): ?>
            headerStats();
        <?php endif; ?>
        bindHref();
        refreshTooltips();
        $(window).scroll(function() {
            if ($(this).scrollTop() > 200) {
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeOut();
                }
                $('#scrollToTop').fadeIn();
            } else {
                $('#scrollToTop').fadeOut();
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeIn();
                } else {
                    $('#scrollToBottom').hide();
                }
            }
        });
        $("#scrollToTop").unbind("click");
        $('#scrollToTop').click(function() {
            $('html, body').animate({
                scrollTop: 0
            }, 800);
            return false;
        });
        $("#scrollToBottom").unbind("click");
        $('#scrollToBottom').click(function() {
            $('html, body').animate({
                scrollTop: $(document).height()
            }, 800);
            return false;
        });
        $(window).scroll();
        $(".nextb").unbind("click");
        $(".nextb").click(function() {
            var rPos = 0;
            var rActive = null;
            $(".nav .nav-item").each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        $(".prevb").unbind("click");
        $(".prevb").click(function() {
            var rPos = 0;
            var rActive = null;
            $($(".nav .nav-item").get().reverse()).each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        (function($) {
            $.fn.inputFilter = function(inputFilter) {
                return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
                    if (inputFilter(this.value)) {
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty("oldValue")) {
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    }
                });
            };
        }(jQuery));
        <?php if ($rSettings['js_navigate']): ?>
            $(".navigation-menu li").mouseenter(function() {
                $(this).find(".submenu").show();
            });
            delParam("status");
            $(window).on("popstate", function() {
                if (window.rRealURL) {
                    if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
                        navigate(window.location.href.split("/").reverse()[0]);
                    }
                }
            });
        <?php endif; ?>
        $(document).keydown(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = true;
            }
        });
        $(document).keyup(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = false;
            }
        });
        document.onselectstart = function() {
            if (window.rShiftHeld) {
                return false;
            }
        }
    });

    <?php
    echo '' . "\r\n\t\t" . 'var rStreams = [];' . "\r\n\t\t" . 'var rMovies = [];' . "\r\n\t\t" . 'var rSeries = [];' . "\r\n\t\t" . 'var rEpisodes = [];' . "\r\n\t\t" . 'var rUsers = [];' . "\r\n" . 'var rLines = [];' . "\r\n" . 'var rRadios = [];' . "\r\n" . 'var rMAGs = [];' . "\r\n" . 'var rEnigmas = [];' . "\r\n\r\n\t\t" . 'function getStreamCategory() {' . "\r\n\t\t\t" . 'return $("#stream_category_search").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getStreamFilter() {' . "\r\n" . 'return $("#stream_filter").val();' . "\r\n" . '}' . "\r\n" . 'function getRadioCategory() {' . "\r\n\t\t\t" . 'return $("#radio_category_search").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getRadioFilter() {' . "\r\n" . 'return $("#radio_filter").val();' . "\r\n" . '}' . "\r\n\t\t" . 'function getMovieCategory() {' . "\r\n\t\t\t" . 'return $("#movie_category_search").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getSeriesCategory() {' . "\r\n\t\t\t" . 'return $("#series_category_search").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getMovieFilter() {' . "\r\n\t\t\t" . 'return $("#movie_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getLineFilter() {' . "\r\n\t\t\t" . 'return $("#line_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getEpisodeFilter() {' . "\r\n\t\t\t" . 'return $("#episode_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getEpisodeSeries() {' . "\r\n\t\t\t" . 'return $("#episode_series").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getReseller() {' . "\r\n\t\t\t" . 'return $("#reseller_search").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getUserFilter() {' . "\r\n\t\t\t" . 'return $("#user_filter").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getUserReseller() {' . "\r\n\t\t\t" . 'return $("#user_reseller_search").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getMagFilter() {' . "\r\n\t\t\t" . 'return $("#mag_filter").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getMagReseller() {' . "\r\n\t\t\t" . 'return $("#mag_reseller_search").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getEnigmaFilter() {' . "\r\n\t\t\t" . 'return $("#enigma_filter").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getEnigmaReseller() {' . "\r\n\t\t\t" . 'return $("#enigma_reseller_search").val();' . "\r\n\t\t" . '}' . "\r\n" . 'function getStreamServer() {' . "\r\n" . 'return $("#stream_server_id").val();' . "\r\n" . '}' . "\r\n" . 'function getMovieServer() {' . "\r\n" . 'return $("#movie_server_id").val();' . "\r\n" . '}' . "\r\n" . 'function getEpisodeServer() {' . "\r\n" . 'return $("#episode_server_id").val();' . "\r\n" . '}' . "\r\n" . 'function getRadioServer() {' . "\r\n" . 'return $("#station_server_id").val();' . "\r\n" . '}' . "\r\n\t\t" . 'function toggleStreams() {' . "\r\n\t\t\t" . '$("#datatable-md1 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rStreams.splice($.inArray($(this).find("td:eq(0)").text(), window.rStreams), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rStreams.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n" . 'function toggleRadios() {' . "\r\n\t\t\t" . '$("#datatable-md6 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rRadios.splice($.inArray($(this).find("td:eq(0)").text(), window.rRadios), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rRadios.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleMovies() {' . "\r\n\t\t\t" . '$("#datatable-md2 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rMovies.splice($.inArray($(this).find("td:eq(0)").text(), window.rMovies), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rMovies.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleSeries() {' . "\r\n\t\t\t" . '$("#datatable-md4 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSeries.splice($.inArray($(this).find("td:eq(0)").text(), window.rSeries), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSeries.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleEpisodes() {' . "\r\n\t\t\t" . '$("#datatable-md5 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rEpisodes.splice($.inArray($(this).find("td:eq(0)").text(), window.rEpisodes), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rEpisodes.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleLines() {' . "\r\n\t\t\t" . '$("#datatable-md3 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rLines.splice($.inArray($(this).find("td:eq(0)").text(), window.rLines), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rLines.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n" . 'function toggleUsers() {' . "\r\n\t\t\t" . '$("#datatable-md7 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rUsers.splice($.inArray($(this).find("td:eq(0)").text(), window.rUsers), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rUsers.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n" . 'function toggleMags() {' . "\r\n\t\t\t" . '$("#datatable-md8 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rMAGs.splice($.inArray($(this).find("td:eq(0)").text(), window.rMAGs), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rMAGs.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n" . 'function toggleEnigmas() {' . "\r\n\t\t\t" . '$("#datatable-md9 tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rEnigmas.splice($.inArray($(this).find("td:eq(0)").text(), window.rEnigmas), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rEnigmas.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearOwner() {' . "\r\n" . "\$('#reseller_search').val(\"\").trigger('change');" . "\r\n" . '}' . "\r\n" . 'function clearUserOwner() {' . "\r\n" . "\$('#user_reseller_search').val(\"\").trigger('change');" . "\r\n" . '}' . "\r\n" . 'function clearMagOwner() {' . "\r\n" . "\$('#mag_reseller_search').val(\"\").trigger('change');" . "\r\n" . '}' . "\r\n" . 'function clearE2Owner() {' . "\r\n" . "\$('#enigma_reseller_search').val(\"\").trigger('change');" . "\r\n" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . "\$('#reseller_search').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n" . "\$('#user_reseller_search').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n" . "\$('#mag_reseller_search').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n" . "\$('#enigma_reseller_search').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'reguserlist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for an owner...'" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#stream_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#streams").val(JSON.stringify(window.rStreams));' . "\r\n\t\t\t\t" . 'if (window.rStreams.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_6');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_streams", new FormData($("#stream_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#radio_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#radios").val(JSON.stringify(window.rRadios));' . "\r\n\t\t\t\t" . 'if (window.rRadios.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_11');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_radios", new FormData($("#radio_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#user_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#users").val(JSON.stringify(window.rUsers));' . "\r\n\t\t\t\t" . 'if (window.rUsers.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_13');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_users", new FormData($("#user_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#movie_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#movies").val(JSON.stringify(window.rMovies));' . "\r\n\t\t\t\t" . 'if (window.rMovies.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_7');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_movies", new FormData($("#movie_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#series_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#series").val(JSON.stringify(window.rSeries));' . "\r\n\t\t\t\t" . 'if (window.rSeries.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_8');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_series", new FormData($("#series_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#episodes_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#episodes").val(JSON.stringify(window.rEpisodes));' . "\r\n\t\t\t\t" . 'if (window.rEpisodes.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_9');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_episodes", new FormData($("#episodes_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#line_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#lines").val(JSON.stringify(window.rLines));' . "\r\n\t\t\t\t" . 'if (window.rLines.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_10');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_lines", new FormData($("#line_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#mag_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#mags").val(JSON.stringify(window.rMAGs));' . "\r\n\t\t\t\t" . 'if (window.rMAGs.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_12');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_mags", new FormData($("#mag_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#enigma_form").submit(function(e){' . "\r\n" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '$("#enigmas").val(JSON.stringify(window.rEnigmas));' . "\r\n\t\t\t\t" . 'if (window.rEnigmas.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
    echo $language::get('mass_delete_message_12');
    echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "\$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . 'submitForm("mass_delete_enigmas", new FormData($("#enigma_form")[0]));' . "\r\n" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'sTable = $("#datatable-md1").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "stream_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getStreamCategory(),' . "\r\n" . ' d.filter = getStreamFilter(),' . "\r\n" . ' d.server = getStreamServer(),' . "\r\n\t\t\t\t\t\t" . 'd.include_channels = true' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,5]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rStreams) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo ',' . "\r\n" . 'order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'sTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'sTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#stream_category_search').change(function(){" . "\r\n\t\t\t\t" . 'sTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "\$('#stream_server_id').change(function(){" . "\r\n\t\t\t\t" . 'sTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "\$('#stream_filter').change(function(){" . "\r\n\t\t\t\t" . 'sTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . 'cTable = $("#datatable-md6").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "radio_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getRadioCategory(),' . "\r\n" . ' d.filter = getRadioFilter(),' . "\r\n" . ' d.server = getRadioServer()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,5]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rRadios) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo ',' . "\r\n" . 'order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#radio_search').keyup(function(){" . "\r\n\t\t\t\t" . 'cTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#radio_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'cTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#radio_category_search').change(function(){" . "\r\n\t\t\t\t" . 'cTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "\$('#station_server_id').change(function(){" . "\r\n\t\t\t\t" . 'cTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "\$('#radio_filter').change(function(){" . "\r\n\t\t\t\t" . 'cTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . 'rTable = $("#datatable-md2").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "movie_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getMovieCategory(),' . "\r\n\t\t\t\t\t\t" . 'd.filter = getMovieFilter()' . "\r\n" . ' d.server = getMovieServer()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,5,6]},' . "\r\n" . '{"orderable": false, "targets": [1,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rMovies) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo ',' . "\r\n" . 'order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#movie_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#movie_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#movie_category_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "\$('#movie_server_id').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#movie_filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . 'gTable = $("#datatable-md4").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "series_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getSeriesCategory()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,4,5,6,7,8]},' . "\r\n\t\t\t\t\t" . '{"orderable": false, "targets": [1,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSeries) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo ',' . "\r\n" . 'order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#series_search').keyup(function(){" . "\r\n\t\t\t\t" . 'gTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#series_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'gTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#series_category_search').change(function(){" . "\r\n\t\t\t\t" . 'gTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . 'wTable = $("#datatable-md5").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "episode_list",' . "\r\n\t\t\t\t\t\t" . 'd.series = getEpisodeSeries(),' . "\r\n\t\t\t\t\t\t" . 'd.filter = getEpisodeFilter(),' . "\r\n" . ' d.server = getEpisodeServer()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,4]},' . "\r\n" . '{"orderable": false, "targets": [1]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rEpisodes) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo ',' . "\r\n" . 'order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#episode_search').keyup(function(){" . "\r\n\t\t\t\t" . 'wTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#episode_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'wTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#episode_series').change(function(){" . "\r\n\t\t\t\t" . 'wTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "\$('#episode_server_id').change(function(){" . "\r\n\t\t\t\t" . 'wTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#episode_filter').change(function(){" . "\r\n\t\t\t\t" . 'wTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n" . 'lTable = $("#datatable-md3").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . 'searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "lines",' . "\r\n\t\t\t\t\t\t" . 'd.filter = getLineFilter(),' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getReseller(),' . "\r\n" . ' d.no_url = true' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,4,6,7,9,10]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [2,5,8,11,12]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rLines) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo "\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#line_search').keyup(function(){" . "\r\n\t\t\t\t" . 'lTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#line_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'lTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#reseller_search').change(function(){" . "\r\n\t\t\t\t" . 'lTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#line_filter').change(function(){" . "\r\n\t\t\t\t" . 'lTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n" . 'uTable = $("#datatable-md7").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . 'searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "reg_users",' . "\r\n\t\t\t\t\t\t" . 'd.filter = getUserFilter(),' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getUserReseller(),' . "\r\n" . ' d.no_url = true' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,4,5,6,7]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [3,8,9]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rUsers) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo "\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#user_search').keyup(function(){" . "\r\n\t\t\t\t" . 'uTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#user_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'uTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#user_reseller_search').change(function(){" . "\r\n\t\t\t\t" . 'uTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#user_filter').change(function(){" . "\r\n\t\t\t\t" . 'uTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n" . 'mTable = $("#datatable-md8").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . 'searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "mags",' . "\r\n\t\t\t\t\t\t" . 'd.filter = getMagFilter(),' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getMagReseller(),' . "\r\n" . ' d.no_url = true' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,5,7,8]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [1,3,6,9]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rMAGs) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo "\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#mag_search').keyup(function(){" . "\r\n\t\t\t\t" . 'mTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#mag_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'mTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#mag_reseller_search').change(function(){" . "\r\n\t\t\t\t" . 'mTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#mag_filter').change(function(){" . "\r\n\t\t\t\t" . 'mTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n" . 'eTable = $("#datatable-md9").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . 'searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "enigmas",' . "\r\n\t\t\t\t\t\t" . 'd.filter = getEnigmaFilter(),' . "\r\n\t\t\t\t\t\t" . 'd.reseller = getEnigmaReseller(),' . "\r\n" . ' d.no_url = true' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,5,7,8]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [1,3,6,9]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rEnigmas) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
    echo (intval($rSettings['default_entries']) ?: 10);
    echo "\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#enigma_search').keyup(function(){" . "\r\n\t\t\t\t" . 'eTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#enigma_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'eTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#enigma_reseller_search').change(function(){" . "\r\n\t\t\t\t" . 'eTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#enigma_filter').change(function(){" . "\r\n\t\t\t\t" . 'eTable.ajax.reload( null, false );' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-md1").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rStreams.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rStreams), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rStreams.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#datatable-md6").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rRadios.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rRadios), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rRadios.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-md2").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rMovies.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rMovies), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rMovies.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-md4").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSeries.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSeries), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSeries.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-md5").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rEpisodes.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rEpisodes), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rEpisodes.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-md3").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rLines.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rLines), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rLines.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#datatable-md7").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rUsers.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rUsers), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rUsers.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#datatable-md8").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rMAGs.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rMAGs), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rMAGs.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '$("#datatable-md9").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rEnigmas.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rEnigmas), 1);' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rEnigmas.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '' . "\r\n\t\t";
    ?>
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>