<?php

/**
 * Mass edit streams (Bootstrap 5). Four tabs: a serverSide selection table
 * (stream_list, positional rows — click a row to select), a details tab whose
 * fields are each gated by an "activate" checkbox (only ticked fields are
 * applied), an auto-restart tab (days + time) and a load-balancing tab with the
 * jstree server tree + on-demand / timeshift / thumbnail servers. On submit the
 * selected ids (streams JSON) plus the server tree (server_tree_data JSON) and
 * the activated fields POST to post.php?action=stream_mass. Reached full-page in
 * the new-UI shell. Requires the jstree bundle (declared by StreamMassController).
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;

$selectedCategory = RequestManager::get('category') ?? null;
$rAutoRestart = ['days' => [], 'at' => '06:00'];
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_streams'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form" autocomplete="off">
            <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
            <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
            <input type="hidden" name="streams" id="streams" value="">

            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#stream-selection" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('streams'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#stream-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#auto-restart" role="tab"><i class="icon-base ti tabler-clock me-1"></i><?= $language::get('auto_restart'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#load-balancing" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
            </ul>

            <div class="tab-content p-4 border rounded">

                <!-- STREAM SELECTION -->
                <div class="tab-pane fade show active" id="stream-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="stream_search" placeholder="<?= $language::get('search_streams_placeholder'); ?>"></div>
                        <div class="col-md-3 col-6">
                            <select id="stream_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers'); ?></option>
                                <option value="-1"><?= $language::get('no_servers'); ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories'); ?></option>
                                <option value="-1"><?= $language::get('no_categories'); ?></option>
                                <?php foreach ($rCategories as $rCategory): ?>
                                    <option value="<?= (int) $rCategory['id']; ?>" <?= ($selectedCategory == $rCategory['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="stream_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter'); ?></option>
                                <option value="1"><?= $language::get('online'); ?></option>
                                <option value="2"><?= $language::get('down'); ?></option>
                                <option value="3"><?= $language::get('stopped'); ?></option>
                                <option value="4"><?= $language::get('starting'); ?></option>
                                <option value="5"><?= $language::get('on_demand'); ?></option>
                                <option value="6"><?= $language::get('direct'); ?></option>
                                <option value="7"><?= $language::get('timeshift'); ?></option>
                                <option value="8"><?= $language::get('looping'); ?></option>
                                <option value="9"><?= $language::get('has_epg'); ?></option>
                                <option value="10"><?= $language::get('no_epg'); ?></option>
                                <option value="11"><?= $language::get('adaptive_link'); ?></option>
                                <option value="12"><?= $language::get('title_sync'); ?></option>
                                <option value="13"><?= $language::get('transcoding'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-1 col-8">
                            <select id="show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?><option value="<?= $rShow; ?>" <?= $rSettings['default_entries'] == $rShow ? 'selected' : ''; ?>><?= $rShow; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-2"><button type="button" class="btn btn-info w-100" onclick="toggleStreams()" title="<?= $language::get('select'); ?>"><i class="icon-base ti tabler-select-all"></i></button></div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-mass" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th class="text-center"><?= $language::get('icon'); ?></th>
                                    <th><?= $language::get('stream_name'); ?></th>
                                    <th><?= $language::get('category'); ?></th>
                                    <th><?= $language::get('server'); ?></th>
                                    <th class="text-center"><?= $language::get('status'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <!-- STREAM DETAILS -->
                <div class="tab-pane fade" id="stream-details" role="tabpanel">
                    <p class="text-body-secondary"><?= $language::get('to_mass_edit_any_of_the_below'); ?></p>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="category_id" name="c_category_id"></div>
                        <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('select_categories'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="category_id[]" id="category_id" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach ($rCategories as $rCategory): ?>
                                    <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select disabled name="category_id_type" id="category_id_type" class="form-select">
                                <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?><option value="<?= $rType; ?>"><?= $rType; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="bouquets" name="c_bouquets"></div>
                        <label class="col-md-3 col-form-label" for="bouquets"><?= $language::get('select_bouquets'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="bouquets[]" id="bouquets" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                    <option value="<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select disabled name="bouquets_type" id="bouquets_type" class="form-select">
                                <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?><option value="<?= $rType; ?>"><?= $rType; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="gen_timestamps" name="c_gen_timestamps"></div>
                        <label class="col-md-3 col-form-label" for="gen_timestamps"><?= $language::get('generate_pts'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="gen_timestamps" id="gen_timestamps"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="read_native" name="c_read_native"></div>
                        <label class="col-md-3 col-form-label" for="read_native"><?= $language::get('native_frames'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="read_native" id="read_native"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="stream_all" name="c_stream_all"></div>
                        <label class="col-md-3 col-form-label" for="stream_all"><?= $language::get('stream_all_codecs'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="stream_all" id="stream_all"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="allow_record" name="c_allow_record"></div>
                        <label class="col-md-3 col-form-label" for="allow_record"><?= $language::get('allow_recording'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="allow_record" id="allow_record"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="direct_source" name="c_direct_source"></div>
                        <label class="col-md-3 col-form-label" for="direct_source"><?= $language::get('direct_source'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="direct_source" id="direct_source"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="direct_proxy" name="c_direct_proxy"></div>
                        <label class="col-md-3 col-form-label" for="direct_proxy"><?= $language::get('direct_stream'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="direct_proxy" id="direct_proxy"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="delay_minutes" name="c_delay_minutes"></div>
                        <label class="col-md-3 col-form-label" for="delay_minutes"><?= $language::get('minute_delay'); ?></label>
                        <div class="col-md-8"><input type="text" inputmode="numeric" disabled class="form-control" id="delay_minutes" name="delay_minutes" value="0"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="probesize_ondemand" name="c_probesize_ondemand"></div>
                        <label class="col-md-3 col-form-label" for="probesize_ondemand"><?= $language::get('on_demand_probesize'); ?></label>
                        <div class="col-md-8"><input type="text" inputmode="numeric" disabled class="form-control" id="probesize_ondemand" name="probesize_ondemand" value="128000"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="fps_restart" name="c_fps_restart"></div>
                        <label class="col-md-3 col-form-label" for="fps_restart"><?= $language::get('restart_on_fps_drop'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="fps_restart" id="fps_restart"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="fps_threshold" name="c_fps_threshold"></div>
                        <label class="col-md-3 col-form-label" for="fps_threshold"><?= $language::get('fps_threshold'); ?></label>
                        <div class="col-md-8"><input type="text" inputmode="numeric" disabled class="form-control" id="fps_threshold" name="fps_threshold" value="90"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="rtmp_output" name="c_rtmp_output"></div>
                        <label class="col-md-3 col-form-label" for="rtmp_output"><?= $language::get('output_rtmp'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="rtmp_output" id="rtmp_output"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="custom_sid" name="c_custom_sid"></div>
                        <label class="col-md-3 col-form-label" for="custom_sid"><?= $language::get('custom_channel_sid'); ?></label>
                        <div class="col-md-8"><input type="text" disabled class="form-control" id="custom_sid" name="custom_sid" value=""></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="user_agent" name="c_user_agent"></div>
                        <label class="col-md-3 col-form-label" for="user_agent"><?= $language::get('user_agent'); ?></label>
                        <div class="col-md-8"><input type="text" disabled class="form-control" id="user_agent" name="user_agent" value="<?= htmlspecialchars((string) $rStreamArguments['user_agent']['argument_default_value'], ENT_QUOTES); ?>"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="http_proxy" name="c_http_proxy"></div>
                        <label class="col-md-3 col-form-label" for="http_proxy"><?= $language::get('http_proxy'); ?></label>
                        <div class="col-md-8"><input type="text" disabled class="form-control" id="http_proxy" name="http_proxy" value="<?= htmlspecialchars((string) $rStreamArguments['proxy']['argument_default_value'], ENT_QUOTES); ?>"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="cookie" name="c_cookie"></div>
                        <label class="col-md-3 col-form-label" for="cookie"><?= $language::get('cookie'); ?></label>
                        <div class="col-md-8"><input type="text" disabled class="form-control" id="cookie" name="cookie" value="<?= htmlspecialchars((string) $rStreamArguments['cookie']['argument_default_value'], ENT_QUOTES); ?>"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="headers" name="c_headers"></div>
                        <label class="col-md-3 col-form-label" for="headers"><?= $language::get('headers'); ?></label>
                        <div class="col-md-8"><input type="text" disabled class="form-control" id="headers" name="headers" value="<?= htmlspecialchars((string) $rStreamArguments['headers']['argument_default_value'], ENT_QUOTES); ?>"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="transcode_profile_id" name="c_transcode_profile_id"></div>
                        <label class="col-md-3 col-form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                                <option selected value="0"><?= $language::get('transcoding_disabled'); ?></option>
                                <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                    <option value="<?= (int) $rProfile['profile_id']; ?>"><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- AUTO RESTART -->
                <div class="tab-pane fade" id="auto-restart" role="tabpanel">
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="days_to_restart" name="c_days_to_restart"></div>
                        <label class="col-md-3 col-form-label" for="days_to_restart"><?= $language::get('days_to_restart'); ?></label>
                        <div class="col-md-8">
                            <select disabled id="days_to_restart" name="days_to_restart[]" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $rDay): ?>
                                    <option value="<?= $rDay; ?>"><?= $rDay; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="time_to_restart"><?= $language::get('time_to_restart'); ?></label>
                        <div class="col-md-8"><input disabled id="time_to_restart" name="time_to_restart" type="text" class="form-control" value="<?= htmlspecialchars((string) $rAutoRestart['at'], ENT_QUOTES); ?>"></div>
                    </div>
                </div>

                <!-- LOAD BALANCING -->
                <div class="tab-pane fade" id="load-balancing" role="tabpanel">
                    <div class="row mb-3">
                        <div class="col-md-1 text-center"><input type="checkbox" data-name="server_tree" class="form-check-input activate" name="c_server_tree" id="c_server_tree"></div>
                        <label class="col-md-3 col-form-label" for="server_tree"><?= $language::get('server_tree'); ?></label>
                        <div class="col-md-8"><div id="server_tree" class="border rounded p-2"></div></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="server_type"><?= $language::get('server_type'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="server_type" id="server_type" class="form-select">
                                <?php foreach (['SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED'] as $rValue => $rType): ?>
                                    <option value="<?= $rValue; ?>"><?= $rType; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="on_demand"><?= $language::get('on_demand_servers'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="on_demand[]" id="on_demand" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="tv_archive_server_id" name="c_tv_archive_server_id"></div>
                        <label class="col-md-3 col-form-label" for="tv_archive_server_id"><?= $language::get('timeshift_server'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="tv_archive_server_id" id="tv_archive_server_id" class="form-select">
                                <option value=""><?= $language::get('timeshift_disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="vframes_server_id" name="c_vframes_server_id"></div>
                        <label class="col-md-3 col-form-label" for="vframes_server_id"><?= $language::get('thumbnail_server'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="vframes_server_id" id="vframes_server_id" class="form-select">
                                <option value=""><?= $language::get('thumbnails_disabled'); ?></option>
                                <?php foreach ($rServers as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="llod" name="c_llod"></div>
                        <label class="col-md-3 col-form-label" for="llod"><?= $language::get('low_latency_on_demand'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="llod" id="llod" class="form-select">
                                <?php foreach (['Disabled', 'FFMPEG', 'PHP'] as $rValue => $rText): ?>
                                    <option value="<?= $rValue; ?>"><?= $rText; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="tv_archive_duration" name="c_tv_archive_duration"></div>
                        <label class="col-md-3 col-form-label" for="tv_archive_duration"><?= $language::get('timeshift_days'); ?></label>
                        <div class="col-md-8"><input disabled type="text" inputmode="numeric" class="form-control" id="tv_archive_duration" name="tv_archive_duration" value="0"></div>
                    </div>

                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="restart_on_edit"><?= $language::get('restart_on_edit'); ?></label>
                        <div class="col-md-8"><div class="form-check form-switch"><input type="checkbox" value="1" class="form-check-input" name="restart_on_edit" id="restart_on_edit"></div></div>
                    </div>
                </div>

            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_stream" value="1"><?= $language::get('mass_edit') ?: 'Mass Edit'; ?></button></div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var toast = window.xcToast || function() {};
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var selected = [];

        function updateCount() { document.getElementById('selected_count').textContent = selected.length ? '— ' + selected.length + ' selected' : ''; }
        window.getCategory = function() { return document.getElementById('category_search').value; };
        window.getServer = function() { return document.getElementById('stream_server_id').value; };
        window.getFilter = function() { return document.getElementById('stream_filter').value; };
        window.toggleStreams = function() {
            var allSelected = true;
            $('#datatable-mass tbody tr').each(function() { if (!$(this).hasClass('table-active')) { allSelected = false; } });
            $('#datatable-mass tbody tr').each(function() {
                var id = $(this).find('td:eq(0)').text().trim();
                if (!id) { return; }
                if (allSelected) { $(this).removeClass('table-active'); var i = selected.indexOf(id); if (i > -1) { selected.splice(i, 1); } }
                else if (!$(this).hasClass('table-active')) { $(this).addClass('table-active'); if (selected.indexOf(id) === -1) { selected.push(id); } }
            });
            updateCount();
        };

        // select2 on every filter + detail select (all live inside #mass-form).
        if ($.fn.select2) {
            $('#mass-form select').select2({ width: '100%' });
        }

        // flatpickr time picker (replaces the legacy clockpicker).
        if (window.flatpickr) {
            flatpickr('#time_to_restart', { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true });
        }

        // Enable/disable a field (and its select2 mirror) by id.
        function setEnabled(id, on) {
            var el = document.getElementById(id);
            if (!el) { return; }
            el.disabled = !on;
            if ($(el).hasClass('select2-hidden-accessible')) { $(el).prop('disabled', !on).trigger('change.select2'); }
        }

        // Activate checkboxes gate their field; some fields carry extra companions.
        $('.activate').on('change', function() {
            var name = this.getAttribute('data-name');
            var on = this.checked;
            setEnabled(name, on);
            if (name === 'days_to_restart') { setEnabled('time_to_restart', on); }
            if (name === 'server_tree') { setEnabled('on_demand', on); setEnabled('server_type', on); }
            if (name === 'category_id') { setEnabled('category_id_type', on); }
            if (name === 'bouquets') { setEnabled('bouquets_type', on); }
        });

        // jstree load-balancer tree: click a server to toggle Online/Offline; drag
        // also works. on_demand options mirror the Online servers.
        function evaluateServers() {
            var cur = $('#on_demand').val();
            $('#on_demand').empty();
            $($('#server_tree').jstree(true).get_json('source', { flat: true })).each(function(i, v) {
                if (v.parent !== '#') { $('#on_demand').append(new Option(v.text, v.id)); }
            });
            $('#on_demand').val(cur).trigger('change');
        }
        $('#server_tree')
            .on('redraw.jstree', function() { evaluateServers(); })
            .on('select_node.jstree', function(e, data) {
                $('#c_server_tree').prop('checked', true).trigger('change');
                if (data.node.id === 'source' || data.node.id === 'offline') { return; }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') { return false; }
                            if (parent.id === '#') { return false; }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        var rTable = $('#datatable-mass').DataTable({
            processing: true, serverSide: true, searchDelay: 250,
            ajax: { url: './table', data: function(d) { d.id = 'stream_list'; d.category = getCategory(); d.filter = getFilter(); d.server = getServer(); } },
            columnDefs: [{ className: 'text-center', targets: [0, 1, 5] }],
            rowCallback: function(row, data) { if (selected.indexOf(String(data[0])) !== -1) { $(row).addClass('table-active'); } },
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            order: [[0, 'desc']],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        // Row click toggles selection.
        $('#datatable-mass tbody').on('click', 'tr', function() {
            var id = $(this).find('td:eq(0)').text().trim();
            if (!id) { return; }
            if ($(this).hasClass('table-active')) { $(this).removeClass('table-active'); var i = selected.indexOf(id); if (i > -1) { selected.splice(i, 1); } }
            else { $(this).addClass('table-active'); if (selected.indexOf(id) === -1) { selected.push(id); } }
            updateCount();
        });

        $('#stream_search').on('keyup', function() { rTable.search(this.value).draw(); });
        $('#show_entries').on('change', function() { rTable.page.len(parseInt(this.value, 10)).draw(); });
        $('#stream_filter, #stream_server_id, #category_search').on('change', function() { rTable.ajax.reload(null, false); });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) { toast('Select at least one stream to edit.', 'warning'); return; }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }));
            document.getElementById('streams').value = JSON.stringify(selected);
            var btn = this.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            var fd = new FormData(this);
            fd.append('submit_stream', '1');
            fetch('post.php?action=stream_mass', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (d && d.result !== false) { toast('Mass edit applied.', 'success'); setTimeout(function() { location.reload(); }, 800); return; }
                    if (btn) { btn.disabled = false; }
                    toast(errText, 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(errText, 'error'); });
        });
    })();
</script>
</body>

</html>
