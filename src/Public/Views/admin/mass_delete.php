<?php

/**
 * Mass Delete (Bootstrap 5). Nine independent selection tabs (streams, movies, stations,
 * episodes, series, lines, users, mags, enigmas). Each tab is its own <form> with a hidden
 * JSON field of the selected ids and a serverSide ./table picker (positional arrays). Clicking
 * a row toggles it into the matching window.r* array; submit writes the ids to the hidden field
 * and POSTs to post.php?action=mass_delete_<type>. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\Vod\SeriesService;

?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_delete'); ?></h4>
</div>

<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $language::get('mass_delete_executed'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-tabs flex-wrap mb-4" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#stream-selection" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><span class="d-none d-sm-inline"><?= $language::get('streams'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#movie-selection" role="tab"><i class="icon-base ti tabler-movie me-1"></i><span class="d-none d-sm-inline"><?= $language::get('movies'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#radio-selection" role="tab"><i class="icon-base ti tabler-radio me-1"></i><span class="d-none d-sm-inline"><?= $language::get('stations'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#episodes-selection" role="tab"><i class="icon-base ti tabler-list me-1"></i><span class="d-none d-sm-inline"><?= $language::get('episodes'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#series-selection" role="tab"><i class="icon-base ti tabler-device-tv me-1"></i><span class="d-none d-sm-inline"><?= $language::get('series'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#line-selection" role="tab"><i class="icon-base ti tabler-key me-1"></i><span class="d-none d-sm-inline"><?= $language::get('lines'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#user-selection" role="tab"><i class="icon-base ti tabler-user me-1"></i><span class="d-none d-sm-inline"><?= $language::get('users'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#mag-selection" role="tab"><i class="icon-base ti tabler-device-desktop me-1"></i><span class="d-none d-sm-inline"><?= $language::get('mags'); ?></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#enigma-selection" role="tab"><i class="icon-base ti tabler-device-tv-old me-1"></i><span class="d-none d-sm-inline"><?= $language::get('enigmas'); ?></span></button></li>
        </ul>

        <div class="tab-content">
            <!-- Streams -->
            <div class="tab-pane fade show active" id="stream-selection" role="tabpanel">
                <form action="#" method="POST" id="stream_form">
                    <input type="hidden" name="streams" id="streams" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="stream_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_streams'), ENT_QUOTES) ?>..."></div>
                        <div class="col-md-3 col-6">
                            <select id="stream_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers') ?></option>
                                <option value="-1"><?= $language::get('no_servers') ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= intval($rServer['id']) ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="stream_category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories') ?></option>
                                <option value="-1"><?= $language::get('no_categories') ?></option>
                                <?php foreach (CategoryService::getAllByType('live') as $rCategory): ?>
                                    <option value="<?= intval($rCategory['id']) ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="stream_filter" class="form-select">
                                <option value=""><?= $language::get('no_filter') ?></option>
                                <option value="1"><?= $language::get('online') ?></option>
                                <option value="2"><?= $language::get('down') ?></option>
                                <option value="3"><?= $language::get('stopped') ?></option>
                                <option value="4"><?= $language::get('starting') ?></option>
                                <option value="5"><?= $language::get('on_demand') ?></option>
                                <option value="6"><?= $language::get('direct') ?></option>
                                <option value="7"><?= $language::get('timeshift') ?></option>
                                <option value="8"><?= $language::get('looping') ?></option>
                                <option value="9"><?= $language::get('has_epg') ?></option>
                                <option value="10"><?= $language::get('no_epg') ?></option>
                                <option value="11"><?= $language::get('adaptive_link') ?></option>
                                <option value="12"><?= $language::get('title_sync') ?></option>
                                <option value="13"><?= $language::get('transcoding') ?></option>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <select id="show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <button type="button" class="btn btn-info w-100" onClick="toggleStreams()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md1" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th class="text-center"><?= $language::get('icon') ?></th>
                                    <th><?= $language::get('stream_name') ?></th>
                                    <th><?= $language::get('category') ?></th>
                                    <th><?= $language::get('server') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_streams" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_streams'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Movies -->
            <div class="tab-pane fade" id="movie-selection" role="tabpanel">
                <form action="#" method="POST" id="movie_form">
                    <input type="hidden" name="movies" id="movies" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="movie_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_movies'), ENT_QUOTES) ?>..."></div>
                        <div class="col-md-3 col-6">
                            <select id="movie_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers') ?></option>
                                <option value="-1"><?= $language::get('no_servers') ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= intval($rServer['id']) ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="movie_category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories') ?></option>
                                <option value="-1"><?= $language::get('no_categories') ?></option>
                                <?php foreach (CategoryService::getAllByType('movie') as $rCategory): ?>
                                    <option value="<?= intval($rCategory['id']) ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="movie_filter" class="form-select">
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
                            <select id="movie_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <button type="button" class="btn btn-info w-100" onClick="toggleMovies()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md2" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th class="text-center"><?= $language::get('image') ?></th>
                                    <th><?= $language::get('name') ?></th>
                                    <th><?= $language::get('category') ?></th>
                                    <th><?= $language::get('servers') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                    <th class="text-center"><?= $language::get('tmdb') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_movies" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_movies'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Stations (radios) -->
            <div class="tab-pane fade" id="radio-selection" role="tabpanel">
                <form action="#" method="POST" id="radio_form">
                    <input type="hidden" name="radios" id="radios" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="radio_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_stations'), ENT_QUOTES) ?>"></div>
                        <div class="col-md-3 col-6">
                            <select id="station_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers') ?></option>
                                <option value="-1"><?= $language::get('no_servers') ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= intval($rServer['id']) ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="radio_category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories') ?></option>
                                <option value="-1"><?= $language::get('no_categories') ?></option>
                                <?php foreach (CategoryService::getAllByType('radio') as $rCategory): ?>
                                    <option value="<?= intval($rCategory['id']) ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="radio_filter" class="form-select">
                                <option value=""><?= $language::get('no_filter') ?></option>
                                <option value="1"><?= $language::get('online') ?></option>
                                <option value="2"><?= $language::get('down') ?></option>
                                <option value="3"><?= $language::get('stopped') ?></option>
                                <option value="4"><?= $language::get('starting') ?></option>
                                <option value="5"><?= $language::get('on_demand') ?></option>
                                <option value="6"><?= $language::get('direct') ?></option>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <select id="radio_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <button type="button" class="btn btn-info w-100" onClick="toggleRadios()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md6" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th class="text-center"><?= $language::get('icon') ?></th>
                                    <th><?= $language::get('station_name') ?></th>
                                    <th><?= $language::get('category') ?></th>
                                    <th><?= $language::get('servers') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_streams" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_stations'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Episodes -->
            <div class="tab-pane fade" id="episodes-selection" role="tabpanel">
                <form action="#" method="POST" id="episodes_form">
                    <input type="hidden" name="episodes" id="episodes" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="episode_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_episodes'), ENT_QUOTES) ?>..."></div>
                        <div class="col-md-3 col-6">
                            <select id="episode_series" class="form-select">
                                <option value=""><?= $language::get('all_series') ?></option>
                                <?php foreach (SeriesService::getAll() as $rSerie): ?>
                                    <option value="<?= intval($rSerie['id']) ?>"><?= htmlspecialchars((string) $rSerie['title'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="episode_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers') ?></option>
                                <option value="-1"><?= $language::get('no_servers') ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= intval($rServer['id']) ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="episode_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter') ?></option>
                                <option value="1"><?= $language::get('encoded') ?></option>
                                <option value="2"><?= $language::get('encoding') ?></option>
                                <option value="3"><?= $language::get('down') ?></option>
                                <option value="4"><?= $language::get('ready') ?></option>
                                <option value="5"><?= $language::get('direct') ?></option>
                                <option value="7"><?= $language::get('transcoding') ?></option>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <select id="episode_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <button type="button" class="btn btn-info w-100" onClick="toggleEpisodes()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md5" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th class="text-center"><?= $language::get('image') ?></th>
                                    <th><?= $language::get('name') ?></th>
                                    <th><?= $language::get('server') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_episodes" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_episodes'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Series -->
            <div class="tab-pane fade" id="series-selection" role="tabpanel">
                <form action="#" method="POST" id="series_form">
                    <input type="hidden" name="series" id="series" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-6 col-6"><input type="text" class="form-control" id="series_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_series'), ENT_QUOTES) ?>..."></div>
                        <div class="col-md-3 col-6">
                            <select id="series_category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories') ?></option>
                                <option value="-1"><?= $language::get('no_tmdb_match') ?></option>
                                <option value="-2"><?= $language::get('no_categories') ?></option>
                                <?php foreach (CategoryService::getAllByType('series') as $rCategory): ?>
                                    <option value="<?= intval($rCategory['id']) ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="series_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-6">
                            <button type="button" class="btn btn-info w-100" onClick="toggleSeries()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md4" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th class="text-center"><?= $language::get('image') ?></th>
                                    <th><?= $language::get('name') ?></th>
                                    <th><?= $language::get('category') ?></th>
                                    <th class="text-center"><?= $language::get('seasons') ?></th>
                                    <th class="text-center"><?= $language::get('episodes') ?></th>
                                    <th class="text-center"><?= $language::get('tmdb') ?></th>
                                    <th class="text-center"><?= $language::get('first_aired') ?></th>
                                    <th class="text-center"><?= $language::get('last_updated') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_series" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_series'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Lines -->
            <div class="tab-pane fade" id="line-selection" role="tabpanel">
                <form action="#" method="POST" id="line_form">
                    <input type="hidden" name="lines" id="lines" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="line_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_lines'), ENT_QUOTES) ?>"></div>
                        <div class="col-md-3">
                            <select id="reseller_search" class="form-select">
                                <?php if (RequestManager::has('owner') && ($rOwner = UserRepository::getRegisteredUserById(intval(RequestManager::get('owner'))))): ?>
                                    <option value="<?= intval($rOwner['id']) ?>" selected><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-center">
                            <button type="button" class="btn btn-label-secondary w-100" onClick="clearOwner();"><?= $language::get('clear_btn') ?></button>
                        </div>
                        <div class="col-md-2">
                            <select id="line_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter') ?></option>
                                <option value="1"><?= $language::get('active') ?></option>
                                <option value="2"><?= $language::get('disabled') ?></option>
                                <option value="3"><?= $language::get('banned') ?></option>
                                <option value="4"><?= $language::get('expired') ?></option>
                                <option value="5"><?= $language::get('trial') ?></option>
                                <option value="6"><?= $language::get('restreamer') ?></option>
                                <option value="7"><?= $language::get('ministra') ?></option>
                            </select>
                        </div>
                        <div class="col-md-2 col-8">
                            <select id="line_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-2">
                            <button type="button" class="btn btn-info w-100" onClick="toggleLines()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md3" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th><?= $language::get('username') ?></th>
                                    <th><?= $language::get('owner') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                    <th class="text-center"><?= $language::get('expiration') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_lines" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_lines'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Users -->
            <div class="tab-pane fade" id="user-selection" role="tabpanel">
                <form action="#" method="POST" id="user_form">
                    <input type="hidden" name="users" id="users" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="user_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_users'), ENT_QUOTES) ?>"></div>
                        <div class="col-md-3">
                            <select id="user_reseller_search" class="form-select">
                                <?php if (RequestManager::has('owner') && ($rOwner = UserRepository::getRegisteredUserById(intval(RequestManager::get('owner'))))): ?>
                                    <option value="<?= intval($rOwner['id']) ?>" selected><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-center">
                            <button type="button" class="btn btn-label-secondary w-100" onClick="clearUserOwner();"><?= $language::get('clear_btn') ?></button>
                        </div>
                        <div class="col-md-2">
                            <select id="user_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter') ?></option>
                                <option value="-1"><?= $language::get('active') ?></option>
                                <option value="-2"><?= $language::get('disabled') ?></option>
                            </select>
                        </div>
                        <div class="col-md-2 col-8">
                            <select id="user_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-2">
                            <button type="button" class="btn btn-info w-100" onClick="toggleUsers()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md7" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th><?= $language::get('username') ?></th>
                                    <th><?= $language::get('owner') ?></th>
                                    <th class="text-center"><?= $language::get('status') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_users" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_users'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- MAGs -->
            <div class="tab-pane fade" id="mag-selection" role="tabpanel">
                <form action="#" method="POST" id="mag_form">
                    <input type="hidden" name="mags" id="mags" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="mag_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_devices_placeholder'), ENT_QUOTES) ?>"></div>
                        <div class="col-md-3">
                            <select id="mag_reseller_search" class="form-select">
                                <?php if (RequestManager::has('owner') && ($rOwner = UserRepository::getRegisteredUserById(intval(RequestManager::get('owner'))))): ?>
                                    <option value="<?= intval($rOwner['id']) ?>" selected><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-center">
                            <button type="button" class="btn btn-label-secondary w-100" onClick="clearMagOwner();"><?= $language::get('clear_btn') ?></button>
                        </div>
                        <div class="col-md-2">
                            <select id="mag_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter') ?></option>
                                <option value="1"><?= $language::get('active') ?></option>
                                <option value="2"><?= $language::get('disabled') ?></option>
                                <option value="3"><?= $language::get('banned') ?></option>
                                <option value="4"><?= $language::get('expired') ?></option>
                                <option value="5"><?= $language::get('trial') ?></option>
                            </select>
                        </div>
                        <div class="col-md-2 col-8">
                            <select id="mag_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-2">
                            <button type="button" class="btn btn-info w-100" onClick="toggleMags()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md8" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th><?= $language::get('username') ?></th>
                                    <th class="text-center"><?= $language::get('mac_address') ?></th>
                                    <th class="text-center"><?= $language::get('device') ?></th>
                                    <th><?= $language::get('owner') ?></th>
                                    <th class="text-center"><?= $language::get('expiration') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_mags" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_devices'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>

            <!-- Enigmas -->
            <div class="tab-pane fade" id="enigma-selection" role="tabpanel">
                <form action="#" method="POST" id="enigma_form">
                    <input type="hidden" name="enigmas" id="enigmas" value="">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="enigma_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_devices_placeholder'), ENT_QUOTES) ?>"></div>
                        <div class="col-md-3">
                            <select id="enigma_reseller_search" class="form-select">
                                <?php if (RequestManager::has('owner') && ($rOwner = UserRepository::getRegisteredUserById(intval(RequestManager::get('owner'))))): ?>
                                    <option value="<?= intval($rOwner['id']) ?>" selected><?= htmlspecialchars((string) $rOwner['username'], ENT_QUOTES) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-center">
                            <button type="button" class="btn btn-label-secondary w-100" onClick="clearE2Owner();"><?= $language::get('clear_btn') ?></button>
                        </div>
                        <div class="col-md-2">
                            <select id="enigma_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter') ?></option>
                                <option value="1"><?= $language::get('active') ?></option>
                                <option value="2"><?= $language::get('disabled') ?></option>
                                <option value="3"><?= $language::get('banned') ?></option>
                                <option value="4"><?= $language::get('expired') ?></option>
                                <option value="5"><?= $language::get('trial') ?></option>
                            </select>
                        </div>
                        <div class="col-md-2 col-8">
                            <select id="enigma_show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                    <option value="<?= $rShow ?>" <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>><?= $rShow ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-2">
                            <button type="button" class="btn btn-info w-100" onClick="toggleEnigmas()" title="<?= htmlspecialchars((string) $language::get('select_all'), ENT_QUOTES) ?>"><i class="icon-base ti tabler-select-all"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-md9" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th><?= $language::get('username') ?></th>
                                    <th class="text-center"><?= $language::get('mac_address') ?></th>
                                    <th><?= $language::get('owner') ?></th>
                                    <th class="text-center"><?= $language::get('expiration') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3">
                        <input name="submit_enigmas" type="submit" class="btn btn-primary" value="<?= htmlspecialchars((string) $language::get('delete_devices'), ENT_QUOTES) ?>">
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var toast = window.xcToast || function() {};
        if ($.fn.dataTable) {
            $.fn.dataTable.ext.errMode = 'none';
        }

        // Per-type selection buckets (referenced by each table's rowCallback).
        window.rStreams = window.rStreams || [];
        window.rMovies = window.rMovies || [];
        window.rRadios = window.rRadios || [];
        window.rSeries = window.rSeries || [];
        window.rEpisodes = window.rEpisodes || [];
        window.rLines = window.rLines || [];
        window.rUsers = window.rUsers || [];
        window.rMAGs = window.rMAGs || [];
        window.rEnigmas = window.rEnigmas || [];

        var PAGE_LEN = <?= (intval($rSettings['default_entries']) ?: 10) ?>;

        function val(id) {
            var el = document.getElementById(id);
            return el ? el.value : '';
        }

        // Owner search (select2 + ./api reguserlist) for line/user/mag/enigma reseller pickers.
        function initOwnerSearch(id) {
            if (!$.fn.select2) {
                return;
            }
            $('#' + id).select2({
                width: '100%',
                placeholder: 'Search for an owner…',
                allowClear: true,
                ajax: {
                    url: './api',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            search: params.term,
                            action: 'reguserlist',
                            page: params.page
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.items,
                            pagination: {
                                more: (params.page * 100) < data.total_count
                            }
                        };
                    },
                    cache: true
                }
            });
        }
        window.clearOwner = function() {
            $('#reseller_search').val('').trigger('change');
        };
        window.clearUserOwner = function() {
            $('#user_reseller_search').val('').trigger('change');
        };
        window.clearMagOwner = function() {
            $('#mag_reseller_search').val('').trigger('change');
        };
        window.clearE2Owner = function() {
            $('#enigma_reseller_search').val('').trigger('change');
        };

        // Client-side render helpers for keyed (raw-data) picker tables.
        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : s);
            return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        var VOD_BADGE = {
            0: ['secondary', 'Not Encoded'],
            1: ['success', 'Encoded'],
            2: ['warning', 'Encoding'],
            3: ['primary', 'Direct Source'],
            4: ['danger', 'Down'],
            5: ['info', 'Direct Stream']
        };
        function vodBadge(code) {
            var m = VOD_BADGE[code] || ['secondary', 'Unknown'];
            return '<span class="badge bg-label-' + m[0] + '">' + m[1] + '</span>';
        }
        function episodeImage(url) {
            if (!url) {
                return '';
            }
            return '<a href="javascript:void(0);" data-src="resize?maxw=512&maxh=512&url=' + encodeURIComponent(url) +
                '"><img loading="lazy" src="resize?maxh=32&maxw=64&url=' + encodeURIComponent(url) + '"></a>';
        }

        var STREAM_BADGE = {
            '-1': ['secondary', 'NO SERVERS'],
            0: ['dark', 'STOPPED'],
            1: ['success', 'ONLINE'],
            2: ['warning', 'STARTING'],
            3: ['danger', 'DOWN'],
            4: ['info', 'ON DEMAND'],
            5: ['primary', 'DIRECT SOURCE'],
            6: ['primary', 'CREATING...'],
            7: ['primary', 'DIRECT STREAM']
        };
        function streamBadge(code) {
            var m = STREAM_BADGE[code] || STREAM_BADGE[0];
            return '<span class="badge bg-label-' + m[0] + '">' + m[1] + '</span>';
        }
        function streamIcon(url) {
            if (!url) {
                return '';
            }
            return '<img loading="lazy" src="resize?maxw=96&maxh=32&url=' + encodeURIComponent(url) + '">';
        }
        function serverNameCell(name, count) {
            if (!name) {
                return 'No Server Selected';
            }
            var html = esc(name);
            if (count > 1) {
                html += ' &nbsp; <button type="button" class="btn btn-info btn-xs waves-effect waves-light">+ ' +
                    (count - 1) + '</button>';
            }
            return html;
        }

        function movieImage(url) {
            if (!url) {
                return '';
            }
            return '<a href="javascript:void(0);" data-src="resize?maxw=512&maxh=512&url=' + encodeURIComponent(url) +
                '"><img loading="lazy" src="resize?maxh=58&maxw=32&url=' + encodeURIComponent(url) + '"></a>';
        }
        function ratingStars(rating) {
            if (!rating) {
                return '';
            }
            var star = Math.round(rating) / 2;
            var full = Math.floor(star);
            var half = (star - full) > 0;
            var empty = 5 - (full + (half ? 1 : 0));
            var h = '';
            for (var i = 0; i < full; i++) {
                h += "<i class='mdi mdi-star'></i>";
            }
            if (half) {
                h += "<i class='mdi mdi-star-half'></i>";
            }
            for (var j = 0; j < empty; j++) {
                h += "<i class='mdi mdi-star-outline'></i>";
            }
            return h;
        }
        function movieName(row) {
            var year = row.year ? '<strong>' + esc(row.year) + '</strong> &nbsp;' : '';
            return esc(row.stream_display_name) + '<br><span style="font-size:11px;">' + year + ratingStars(row.rating) + '</span>';
        }
        function tmdbBadge(has) {
            if (has) {
                return '<i class="icon-base ti tabler-circle-check-filled text-success" title="TMDb"></i>';
            }
            return '<i class="icon-base ti tabler-circle-minus text-secondary" title="No TMDb"></i>';
        }

        function seriesTitle(row) {
            var year = row.year ? '<strong>' + esc(row.year) + '</strong> &nbsp;' : '';
            return '<strong>' + esc(row.title) + '</strong><br><span style="font-size:11px;">' + year + ratingStars(row.rating) + '</span>';
        }
        function countBadge(n) {
            return '<span class="badge bg-label-' + (n > 0 ? 'info' : 'secondary') + '">' + (n || 0) + '</span>';
        }

        var LINE_STATUS = {
            active: ['success', 'Active'],
            banned: ['danger', 'Banned'],
            disabled: ['secondary', 'Disabled'],
            expired: ['warning', 'Expired']
        };
        function lineStatusBadge(sv) {
            var m = LINE_STATUS[sv] || ['secondary', sv || ''];
            return '<span class="badge bg-label-' + m[0] + '">' + esc(m[1]) + '</span>';
        }
        function userStatusBadge(code) {
            return code == 1 ? '<span class="badge bg-label-success">Active</span>' :
                '<span class="badge bg-label-secondary">Disabled</span>';
        }
        function expStrCell(d, expired) {
            if (!d) {
                return '<span class="fs-4">&infin;</span>';
            }
            var parts = String(d).split(' ');
            var body = esc(parts[0]) + (parts[1] ? '<br><small class="text-body-secondary">' + esc(parts[1]) + '</small>' : '');
            return expired ? '<span class="text-danger">' + body + '</span>' : body;
        }
        function fmtExpDate(unix) {
            if (!unix) {
                return '<span class="fs-4">&infin;</span>';
            }
            var dt = new Date(unix * 1000);
            if (isNaN(dt.getTime())) {
                return '';
            }
            var z = function(n) { return (n < 10 ? '0' : '') + n; };
            return dt.getFullYear() + '-' + z(dt.getMonth() + 1) + '-' + z(dt.getDate());
        }

        // Build one selection table: serverSide picker + row-click select + search/entries/reload wiring.
        function initTable(opts) {
            var arr = opts.arr;
            var dtOpts = {
                serverSide: true,
                searching: true,
                ajax: {
                    url: './table',
                    data: opts.data
                },
                columnDefs: opts.columnDefs,
                rowCallback: function(row, data) {
                    var rid = opts.columns ? String(data && data[opts.idKey || 'id'] != null ? data[opts.idKey || 'id'] : '') : String(data[0]);
                    if ($.inArray(rid.trim(), arr) !== -1) {
                        $(row).addClass('table-active');
                    }
                },
                pageLength: PAGE_LEN,
                // Page size is driven by the toolbar's #*_show_entries selector
                // (opts.len below); drop DataTables' built-in length control so
                // the two don't render as a duplicate "entries per page" picker.
                layout: {
                    topStart: null,
                    topEnd: null
                }
            };
            if (opts.columns) {
                dtOpts.columns = opts.columns;
                delete dtOpts.columnDefs;
            }
            if (opts.order) {
                dtOpts.order = opts.order;
            }
            if (opts.searchDelay) {
                dtOpts.searchDelay = opts.searchDelay;
            }
            var table = $(opts.tid).DataTable(dtOpts);

            $(opts.tid + ' tbody').on('click', 'tr', function() {
                var id = $(this).find('td:eq(0)').text().trim();
                if (!id) {
                    return;
                }
                if ($(this).hasClass('table-active')) {
                    $(this).removeClass('table-active');
                    var i = arr.indexOf(id);
                    if (i > -1) {
                        arr.splice(i, 1);
                    }
                } else {
                    $(this).addClass('table-active');
                    if (arr.indexOf(id) === -1) {
                        arr.push(id);
                    }
                }
            });

            if (opts.search) {
                $(opts.search).on('keyup', function() {
                    table.search(this.value).draw();
                });
            }
            if (opts.len) {
                $(opts.len).on('change', function() {
                    table.page.len(parseInt(this.value, 10)).draw();
                });
            }
            (opts.reload || []).forEach(function(sel) {
                $(sel).on('change', function() {
                    table.ajax.reload(null, false);
                });
            });

            // Toggle-all for the currently rendered page.
            window[opts.toggle] = function() {
                var allSelected = true;
                $(opts.tid + ' tbody tr').each(function() {
                    if (!$(this).hasClass('table-active')) {
                        allSelected = false;
                    }
                });
                $(opts.tid + ' tbody tr').each(function() {
                    var id = $(this).find('td:eq(0)').text().trim();
                    if (!id) {
                        return;
                    }
                    if (allSelected) {
                        $(this).removeClass('table-active');
                        var i = arr.indexOf(id);
                        if (i > -1) {
                            arr.splice(i, 1);
                        }
                    } else if (!$(this).hasClass('table-active')) {
                        $(this).addClass('table-active');
                        if (arr.indexOf(id) === -1) {
                            arr.push(id);
                        }
                    }
                });
            };
            return table;
        }

        // Wire a form's submit to the mass-delete endpoint (JSON id list + fetch).
        function wireSubmit(formId, hiddenId, arr, action, emptyMsg) {
            var form = document.getElementById(formId);
            if (!form) {
                return;
            }
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (arr.length === 0) {
                    toast(emptyMsg, 'error');
                    return;
                }
                document.getElementById(hiddenId).value = JSON.stringify(arr);
                var btn = form.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                }
                fetch('post.php?action=' + action + '&referer=', {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(txt) {
                        var d;
                        try {
                            d = JSON.parse(txt);
                        } catch (err) {
                            d = {};
                        }
                        if (d && d.location) {
                            window.location = d.location;
                        } else {
                            window.location.reload();
                        }
                    })
                    .catch(function() {
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast(<?= json_encode($language::get('error_occured')) ?>, 'error');
                    });
            });
        }

        // Guard against accidental submit when pressing Enter inside a filter input.
        $('#stream_form, #movie_form, #radio_form, #episodes_form, #series_form, #line_form, #user_form, #mag_form, #enigma_form').on('keydown', function(e) {
            if (e.which === 13 && e.target.nodeName !== 'TEXTAREA') {
                e.preventDefault();
            }
        });

        $(function() {
            if ($.fn.select2) {
                $('#stream_server_id, #stream_category_search, #stream_filter, #show_entries, ' +
                    '#movie_server_id, #movie_category_search, #movie_filter, #movie_show_entries, ' +
                    '#station_server_id, #radio_category_search, #radio_filter, #radio_show_entries, ' +
                    '#episode_series, #episode_server_id, #episode_filter, #episode_show_entries, ' +
                    '#series_category_search, #series_show_entries, ' +
                    '#line_filter, #line_show_entries, #user_filter, #user_show_entries, ' +
                    '#mag_filter, #mag_show_entries, #enigma_filter, #enigma_show_entries').select2({
                    width: '100%'
                });
            }
            initOwnerSearch('reseller_search');
            initOwnerSearch('user_reseller_search');
            initOwnerSearch('mag_reseller_search');
            initOwnerSearch('enigma_reseller_search');

            initTable({
                tid: '#datatable-md1',
                arr: window.rStreams,
                toggle: 'toggleStreams',
                data: function(d) {
                    d.id = 'stream_list';
                    d.category = val('stream_category_search');
                    d.filter = val('stream_filter');
                    d.server = val('stream_server_id');
                    d.include_channels = true;
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'stream_icon',
                    className: 'dt-center',
                    render: function(d) {
                        return streamIcon(d);
                    }
                }, {
                    data: 'stream_display_name',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'category',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'server_name',
                    render: function(d, t, row) {
                        return serverNameCell(d, row.server_count);
                    }
                }, {
                    data: 'status',
                    className: 'dt-center',
                    render: function(d) {
                        return streamBadge(d);
                    }
                }],
                order: [
                    [0, 'desc']
                ],
                search: '#stream_search',
                len: '#show_entries',
                reload: ['#stream_category_search', '#stream_server_id', '#stream_filter']
            });
            initTable({
                tid: '#datatable-md6',
                arr: window.rRadios,
                toggle: 'toggleRadios',
                data: function(d) {
                    d.id = 'radio_list';
                    d.category = val('radio_category_search');
                    d.filter = val('radio_filter');
                    d.server = val('station_server_id');
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'stream_icon',
                    orderable: false,
                    className: 'dt-center',
                    render: function(d) {
                        return streamIcon(d);
                    }
                }, {
                    data: 'stream_display_name',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'category',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'server_name',
                    render: function(d, t, row) {
                        return serverNameCell(d, row.server_count);
                    }
                }, {
                    data: 'status',
                    className: 'dt-center',
                    render: function(d) {
                        return streamBadge(d);
                    }
                }],
                order: [
                    [0, 'desc']
                ],
                search: '#radio_search',
                len: '#radio_show_entries',
                reload: ['#radio_category_search', '#station_server_id', '#radio_filter']
            });
            initTable({
                tid: '#datatable-md2',
                arr: window.rMovies,
                toggle: 'toggleMovies',
                data: function(d) {
                    d.id = 'movie_list';
                    d.category = val('movie_category_search');
                    d.filter = val('movie_filter');
                    d.server = val('movie_server_id');
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'movie_image',
                    orderable: false,
                    className: 'dt-center',
                    render: function(d) {
                        return movieImage(d);
                    }
                }, {
                    data: 'stream_display_name',
                    render: function(d, t, row) {
                        return movieName(row);
                    }
                }, {
                    data: 'category',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'server_name',
                    render: function(d, t, row) {
                        return serverNameCell(d, row.server_count);
                    }
                }, {
                    data: 'status',
                    className: 'dt-center',
                    render: function(d) {
                        return vodBadge(d);
                    }
                }, {
                    data: 'has_tmdb',
                    orderable: false,
                    className: 'dt-center',
                    render: function(d) {
                        return tmdbBadge(d);
                    }
                }],
                order: [
                    [0, 'desc']
                ],
                search: '#movie_search',
                len: '#movie_show_entries',
                reload: ['#movie_category_search', '#movie_server_id', '#movie_filter']
            });
            initTable({
                tid: '#datatable-md4',
                arr: window.rSeries,
                toggle: 'toggleSeries',
                data: function(d) {
                    d.id = 'series_list';
                    d.category = val('series_category_search');
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'cover',
                    orderable: false,
                    className: 'dt-center',
                    render: function(d) {
                        return movieImage(d);
                    }
                }, {
                    data: 'title',
                    render: function(d, t, row) {
                        return seriesTitle(row);
                    }
                }, {
                    data: 'category',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'latest_season',
                    className: 'dt-center',
                    render: function(d) {
                        return countBadge(d);
                    }
                }, {
                    data: 'episode_count',
                    className: 'dt-center',
                    render: function(d) {
                        return countBadge(d);
                    }
                }, {
                    data: 'has_tmdb',
                    orderable: false,
                    className: 'dt-center',
                    render: function(d) {
                        return tmdbBadge(d);
                    }
                }, {
                    data: 'release_date',
                    className: 'dt-center',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'last_modified',
                    className: 'dt-center',
                    render: function(d) {
                        return esc(d);
                    }
                }],
                order: [
                    [0, 'desc']
                ],
                search: '#series_search',
                len: '#series_show_entries',
                reload: ['#series_category_search']
            });
            initTable({
                tid: '#datatable-md5',
                arr: window.rEpisodes,
                toggle: 'toggleEpisodes',
                data: function(d) {
                    d.id = 'episode_list';
                    d.series = val('episode_series');
                    d.filter = val('episode_filter');
                    d.server = val('episode_server_id');
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'movie_image',
                    orderable: false,
                    className: 'dt-center',
                    render: function(d) {
                        return episodeImage(d);
                    }
                }, {
                    data: 'stream_display_name',
                    render: function(d, t, row) {
                        var s = (row.series_title || '') + ' - Season ' + (row.season_num == null ? '' : row.season_num);
                        return '<strong>' + esc(d) + '</strong><br><span style="font-size:11px;">' + esc(s) + '</span>';
                    }
                }, {
                    data: 'server_name',
                    render: function(d, t, row) {
                        var name = d ? esc(d) : 'No Server Selected';
                        if (row.server_count > 1) {
                            name += ' &nbsp; <button type="button" class="btn btn-info btn-xs waves-effect waves-light">+ ' +
                                (row.server_count - 1) + '</button>';
                        }
                        return name;
                    }
                }, {
                    data: 'status',
                    className: 'dt-center',
                    render: function(d) {
                        return vodBadge(d);
                    }
                }],
                order: [
                    [0, 'desc']
                ],
                search: '#episode_search',
                len: '#episode_show_entries',
                reload: ['#episode_series', '#episode_server_id', '#episode_filter']
            });
            initTable({
                tid: '#datatable-md3',
                arr: window.rLines,
                toggle: 'toggleLines',
                data: function(d) {
                    d.id = 'lines';
                    d.filter = val('line_filter');
                    d.reseller = val('reseller_search');
                    d.no_url = true;
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'username',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'owner_name',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'status',
                    className: 'dt-center',
                    render: function(d) {
                        return lineStatusBadge(d);
                    }
                }, {
                    data: 'exp_str',
                    className: 'dt-center text-nowrap',
                    render: function(d, t, row) {
                        return expStrCell(d, row.exp_expired);
                    }
                }],
                idKey: 'id',
                searchDelay: 250,
                search: '#line_search',
                len: '#line_show_entries',
                reload: ['#reseller_search', '#line_filter']
            });
            initTable({
                tid: '#datatable-md7',
                arr: window.rUsers,
                toggle: 'toggleUsers',
                data: function(d) {
                    d.id = 'reg_users';
                    d.filter = val('user_filter');
                    d.reseller = val('user_reseller_search');
                    d.no_url = true;
                },
                columns: [{
                    data: 'id',
                    className: 'dt-center'
                }, {
                    data: 'username',
                    render: function(d) {
                        return esc(d);
                    }
                }, {
                    data: 'owner_username',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'status',
                    className: 'dt-center',
                    render: function(d) {
                        return userStatusBadge(d);
                    }
                }],
                idKey: 'id',
                searchDelay: 250,
                search: '#user_search',
                len: '#user_show_entries',
                reload: ['#user_reseller_search', '#user_filter']
            });
            initTable({
                tid: '#datatable-md8',
                arr: window.rMAGs,
                toggle: 'toggleMags',
                data: function(d) {
                    d.id = 'mags';
                    d.filter = val('mag_filter');
                    d.reseller = val('mag_reseller_search');
                    d.no_url = true;
                },
                columns: [{
                    data: 'mag_id',
                    className: 'dt-center'
                }, {
                    data: 'username',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'mac',
                    className: 'dt-center',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'stb_type',
                    className: 'dt-center',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'owner_name',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'exp_date',
                    className: 'dt-center text-nowrap',
                    render: function(d) {
                        return fmtExpDate(d);
                    }
                }],
                idKey: 'mag_id',
                searchDelay: 250,
                search: '#mag_search',
                len: '#mag_show_entries',
                reload: ['#mag_reseller_search', '#mag_filter']
            });
            initTable({
                tid: '#datatable-md9',
                arr: window.rEnigmas,
                toggle: 'toggleEnigmas',
                data: function(d) {
                    d.id = 'enigmas';
                    d.filter = val('enigma_filter');
                    d.reseller = val('enigma_reseller_search');
                    d.no_url = true;
                },
                columns: [{
                    data: 'device_id',
                    className: 'dt-center'
                }, {
                    data: 'username',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'mac',
                    className: 'dt-center',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'owner_name',
                    render: function(d) {
                        return esc(d || '');
                    }
                }, {
                    data: 'exp_date',
                    className: 'dt-center text-nowrap',
                    render: function(d) {
                        return fmtExpDate(d);
                    }
                }],
                idKey: 'device_id',
                searchDelay: 250,
                search: '#enigma_search',
                len: '#enigma_show_entries',
                reload: ['#enigma_reseller_search', '#enigma_filter']
            });

            wireSubmit('stream_form', 'streams', window.rStreams, 'mass_delete_streams', <?= json_encode($language::get('mass_delete_message_6')) ?>);
            wireSubmit('movie_form', 'movies', window.rMovies, 'mass_delete_movies', <?= json_encode($language::get('mass_delete_message_7')) ?>);
            wireSubmit('radio_form', 'radios', window.rRadios, 'mass_delete_radios', <?= json_encode($language::get('mass_delete_message_11')) ?>);
            wireSubmit('series_form', 'series', window.rSeries, 'mass_delete_series', <?= json_encode($language::get('mass_delete_message_8')) ?>);
            wireSubmit('episodes_form', 'episodes', window.rEpisodes, 'mass_delete_episodes', <?= json_encode($language::get('mass_delete_message_9')) ?>);
            wireSubmit('line_form', 'lines', window.rLines, 'mass_delete_lines', <?= json_encode($language::get('mass_delete_message_10')) ?>);
            wireSubmit('user_form', 'users', window.rUsers, 'mass_delete_users', <?= json_encode($language::get('mass_delete_message_13')) ?>);
            wireSubmit('mag_form', 'mags', window.rMAGs, 'mass_delete_mags', <?= json_encode($language::get('mass_delete_message_12')) ?>);
            wireSubmit('enigma_form', 'enigmas', window.rEnigmas, 'mass_delete_enigmas', <?= json_encode($language::get('mass_delete_message_12')) ?>);
        });
    })();
</script>
</body>

</html>