<?php
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$selectedCategory = RequestManager::getAll()['category'] ?? null;
$rAutoRestart = ['days' => [], 'at' => '06:00'];
?>

<div class="wrapper boxed-layout-xl"<?= $isAjax ? ' style="display: none;"' : '' ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">
                        <?= $language::get('mass_edit_streams') ?>
                        <small id="selected_count"></small>
                    </h4>
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
                        Mass edit of streams was successfully executed!
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
                            <input type="hidden" name="streams" id="streams" value="">

                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-play mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('streams') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('details') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#auto-restart" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-clock-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('auto_restart') ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $language::get('servers') ?></span>
                                        </a>
                                    </li>
                                </ul>

                                <div class="tab-content b-0 mb-0 pt-0">

                                    <!-- STREAM SELECTION -->
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input
                                                    type="text"
                                                    class="form-control"
                                                    id="stream_search"
                                                    value=""
                                                    placeholder="<?= $language::get('search_streams_placeholder') ?>"
                                                >
                                            </div>

                                            <div class="col-md-3 col-6">
                                                <select id="stream_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('all_servers') ?></option>
                                                    <option value="-1"><?= $language::get('no_servers') ?></option>
                                                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                                        <option value="<?= (int) $rServer['id'] ?>">
                                                            <?= htmlspecialchars($rServer['server_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-md-3 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('all_categories') ?></option>
                                                    <option value="-1"><?= $language::get('no_categories') ?></option>
                                                    <?php foreach ($rCategories as $rCategory): ?>
                                                        <option
                                                            value="<?= (int) $rCategory['id'] ?>"
                                                            <?= ($selectedCategory == $rCategory['id']) ? 'selected' : '' ?>
                                                        >
                                                            <?= htmlspecialchars($rCategory['category_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-md-2 col-6">
                                                <select id="stream_filter" class="form-control" data-toggle="select2">
                                                    <option value="" selected><?= $language::get('no_filter') ?></option>
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
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                                        <option
                                                            value="<?= $rShow ?>"
                                                            <?= ($rSettings['default_entries'] == $rShow) ? 'selected' : '' ?>
                                                        >
                                                            <?= $rShow ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-md-1 col-6">
                                                <button
                                                    type="button"
                                                    class="btn btn-info waves-effect waves-light"
                                                    onclick="toggleStreams()"
                                                    style="width: 100%"
                                                >
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>

                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
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
                                    </div>

                                    <!-- STREAM DETAILS -->
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('select_categories') ?></label>
                                                    <div class="col-md-6">
                                                        <select
                                                            disabled
                                                            name="category_id[]"
                                                            id="category_id"
                                                            class="form-control select2-multiple"
                                                            data-toggle="select2"
                                                            multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>"
                                                        >
                                                            <?php foreach ($rCategories as $rCategory): ?>
                                                                <option value="<?= (int) $rCategory['id'] ?>">
                                                                    <?= htmlspecialchars($rCategory['category_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="category_id_type" id="category_id_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?>
                                                                <option value="<?= $rType ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bouquets" name="c_bouquets">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="bouquets"><?= $language::get('select_bouquets') ?></label>
                                                    <div class="col-md-6">
                                                        <select
                                                            disabled
                                                            name="bouquets[]"
                                                            id="bouquets"
                                                            class="form-control select2-multiple"
                                                            data-toggle="select2"
                                                            multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>"
                                                        >
                                                            <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                                                <option value="<?= (int) $rBouquet['id'] ?>">
                                                                    <?= htmlspecialchars($rBouquet['bouquet_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="bouquets_type" id="bouquets_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?>
                                                                <option value="<?= $rType ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="gen_timestamps" data-type="switch" name="c_gen_timestamps">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="gen_timestamps"><?= $language::get('generate_pts') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="gen_timestamps" id="gen_timestamps" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="read_native"><?= $language::get('native_frames') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="read_native" id="read_native" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="read_native" data-type="switch" name="c_read_native">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_all" data-type="switch" name="c_stream_all">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="stream_all"><?= $language::get('stream_all_codecs') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="stream_all" id="stream_all" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="allow_record"><?= $language::get('allow_recording') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="allow_record" id="allow_record" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="allow_record" data-type="switch" name="c_allow_record">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="direct_source" data-type="switch" name="c_direct_source">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="direct_source"><?= $language::get('direct_source') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="direct_source" id="direct_source" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="direct_proxy"><?= $language::get('direct_stream') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="direct_proxy" id="direct_proxy" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="direct_proxy" data-type="switch" name="c_direct_proxy">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="delay_minutes" name="c_delay_minutes">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="delay_minutes"><?= $language::get('minute_delay') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" disabled class="form-control text-center" id="delay_minutes" name="delay_minutes" value="0">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="probesize_ondemand"><?= $language::get('on_demand_probesize') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" disabled class="form-control text-center" id="probesize_ondemand" name="probesize_ondemand" value="128000">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="probesize_ondemand" name="c_probesize_ondemand">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="fps_restart" data-type="switch" name="c_fps_restart">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="fps_restart"><?= $language::get('restart_on_fps_drop') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="fps_restart" id="fps_restart" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="fps_threshold"><?= $language::get('fps_threshold') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" disabled class="form-control text-center" id="fps_threshold" name="fps_threshold" value="90">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="fps_threshold" name="c_fps_threshold">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="rtmp_output" data-type="switch" name="c_rtmp_output">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="rtmp_output"><?= $language::get('output_rtmp') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="rtmp_output" id="rtmp_output" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="custom_sid"><?= $language::get('custom_channel_sid') ?></label>
                                                    <div class="col-md-2">
                                                        <input type="text" disabled class="form-control" id="custom_sid" name="custom_sid" value="">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="custom_sid" name="c_custom_sid">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="user_agent" name="c_user_agent">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="user_agent"><?= $language::get('user_agent') ?></label>
                                                    <div class="col-md-8">
                                                        <input
                                                            type="text"
                                                            disabled
                                                            class="form-control"
                                                            id="user_agent"
                                                            name="user_agent"
                                                            value="<?= htmlspecialchars($rStreamArguments['user_agent']['argument_default_value']) ?>"
                                                        >
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="http_proxy" name="c_http_proxy">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="http_proxy"><?= $language::get('http_proxy') ?></label>
                                                    <div class="col-md-8">
                                                        <input
                                                            type="text"
                                                            disabled
                                                            class="form-control"
                                                            id="http_proxy"
                                                            name="http_proxy"
                                                            value="<?= htmlspecialchars($rStreamArguments['proxy']['argument_default_value']) ?>"
                                                        >
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="cookie" name="c_cookie">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="cookie"><?= $language::get('cookie') ?></label>
                                                    <div class="col-md-8">
                                                        <input
                                                            type="text"
                                                            disabled
                                                            class="form-control"
                                                            id="cookie"
                                                            name="cookie"
                                                            value="<?= htmlspecialchars($rStreamArguments['cookie']['argument_default_value']) ?>"
                                                        >
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="headers" name="c_headers">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="headers"><?= $language::get('headers') ?></label>
                                                    <div class="col-md-8">
                                                        <input
                                                            type="text"
                                                            disabled
                                                            class="form-control"
                                                            id="headers"
                                                            name="headers"
                                                            value="<?= htmlspecialchars($rStreamArguments['headers']['argument_default_value']) ?>"
                                                        >
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile') ?></label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">
                                                            <option selected value="0"><?= $language::get('transcoding_disabled') ?></option>
                                                            <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                                                <option value="<?= (int) $rProfile['profile_id'] ?>">
                                                                    <?= htmlspecialchars($rProfile['profile_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>

                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript:void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript:void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- AUTO RESTART -->
                                    <div class="tab-pane" id="auto-restart">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="days_to_restart" name="c_days_to_restart">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="days_to_restart"><?= $language::get('days_to_restart') ?></label>
                                                    <div class="col-md-8">
                                                        <select
                                                            disabled
                                                            id="days_to_restart"
                                                            name="days_to_restart[]"
                                                            class="form-control select2-multiple"
                                                            data-toggle="select2"
                                                            multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>"
                                                        >
                                                            <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $rDay): ?>
                                                                <option value="<?= $rDay ?>"><?= $rDay ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="time_to_restart"><?= $language::get('time_to_restart') ?></label>
                                                    <div class="col-md-8">
                                                        <div class="input-group clockpicker" data-placement="top" data-align="top" data-autoclose="true">
                                                            <input disabled id="time_to_restart" name="time_to_restart" type="text" class="form-control" value="<?= $rAutoRestart['at'] ?>">
                                                            <div class="input-group-append">
                                                                <span class="input-group-text"><i class="mdi mdi-clock-outline"></i></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript:void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript:void(0);" class="btn btn-secondary"><?= $language::get('next') ?></a>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- LOAD BALANCING -->
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="server_tree"><?= $language::get('server_tree') ?></label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="server_type"><?= $language::get('server_type') ?></label>
                                                    <div class="col-md-2">
                                                        <select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (['SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED'] as $rValue => $rType): ?>
                                                                <option value="<?= $rValue ?>"><?= $rType ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="on_demand"><?= $language::get('on_demand_servers') ?></label>
                                                    <div class="col-md-8">
                                                        <select
                                                            disabled
                                                            name="on_demand[]"
                                                            id="on_demand"
                                                            class="form-control select2-multiple"
                                                            data-toggle="select2"
                                                            multiple="multiple"
                                                            data-placeholder="<?= $language::get('choose_placeholder') ?>"
                                                        >
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?= (int) $rServer['id'] ?>">
                                                                    <?= htmlspecialchars($rServer['server_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="tv_archive_server_id" name="c_tv_archive_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="tv_archive_server_id"><?= $language::get('timeshift_server') ?></label>
                                                    <div class="col-md-8">
                                                        <select disabled name="tv_archive_server_id" id="tv_archive_server_id" class="form-control" data-toggle="select2">
                                                            <option value=""><?= $language::get('timeshift_disabled') ?></option>
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?= (int) $rServer['id'] ?>">
                                                                    <?= htmlspecialchars($rServer['server_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="vframes_server_id" name="c_vframes_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="vframes_server_id"><?= $language::get('thumbnail_server') ?></label>
                                                    <div class="col-md-8">
                                                        <select disabled name="vframes_server_id" id="vframes_server_id" class="form-control" data-toggle="select2">
                                                            <option value=""><?= $language::get('thumbnails_disabled') ?></option>
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?= (int) $rServer['id'] ?>">
                                                                    <?= htmlspecialchars($rServer['server_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="llod" data-type="switch" name="c_llod">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="llod"><?= $language::get('low_latency_on_demand') ?></label>
                                                    <div class="col-md-2">
                                                        <select name="llod" id="llod" class="form-control" data-toggle="select2">
                                                            <?php foreach (['Disabled', 'FFMPEG', 'PHP'] as $rValue => $rText): ?>
                                                                <option value="<?= $rValue ?>"><?= $rText ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="tv_archive_duration"><?= $language::get('timeshift_days') ?></label>
                                                    <div class="col-md-2">
                                                        <input disabled type="text" class="form-control text-center" id="tv_archive_duration" name="tv_archive_duration" value="0">
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="tv_archive_duration" name="c_tv_archive_duration">
                                                        <label></label>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="restart_on_edit"><?= $language::get('restart_on_edit') ?></label>
                                                    <div class="col-md-2">
                                                        <input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd">
                                                    </div>
                                                </div>

                                            </div>
                                        </div>

                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript:void(0);" class="btn btn-secondary"><?= $language::get('prev') ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_stream" type="submit" class="btn btn-primary" value="Edit Streams">
                                            </li>
                                        </ul>
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

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>

<script id="scripts">
    var resizeObserver = new ResizeObserver(entries => $(window).scroll());
    var rSelected = [];

    function getCategory() {
        return $("#category_search").val();
    }

    function getServer() {
        return $("#stream_server_id").val();
    }

    function getFilter() {
        return $("#stream_filter").val();
    }

    function toggleStreams() {
        $("#datatable-mass tr").each(function () {
            if ($(this).hasClass('selected')) {
                $(this).removeClass('selectedfilter ui-selected selected');
                if ($(this).find("td:eq(0)").text()) {
                    window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);
                }
            } else {
                $(this).addClass('selectedfilter ui-selected selected');
                if ($(this).find("td:eq(0)").text()) {
                    window.rSelected.push($(this).find("td:eq(0)").text());
                }
            }
        });

        $("#selected_count").html(" - " + window.rSelected.length + " selected");
    }

    function evaluateServers() {
        var rOVal = $("#on_demand").val();
        $("#on_demand").empty();

        $($('#server_tree').jstree(true).get_json('source', { flat: true })).each(function (index, value) {
            if (value.parent != "#") {
                $("#on_demand").append(new Option(value.text, value.id));
            }
        });

        $("#on_demand").val(rOVal).trigger("change");

        if (!$("#on_demand").val()) {
            $("#on_demand").val(0).trigger("change");
        }
    }

    $(document).ready(function () {
        resizeObserver.observe(document.body);

        $("form").attr('autocomplete', 'off');

        $(document).keypress(function (event) {
            if (event.which == 13 && event.target.nodeName != "TEXTAREA") {
                return false;
            }
        });

        $.fn.dataTable.ext.errMode = 'none';

        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function (html) {
            var switchery = new Switchery(html, { color: '#414d5f' });
            window.rSwitches[$(html).attr("id")] = switchery;
        });

        setTimeout(pingSession, 30000);

        <?php if (!$rMobile && $rSettings['header_stats']): ?>
        headerStats();
        <?php endif; ?>

        bindHref();
        refreshTooltips();

        $(window).scroll(function () {
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

        $("#scrollToTop").unbind("click").click(function () {
            $('html, body').animate({ scrollTop: 0 }, 800);
            return false;
        });

        $("#scrollToBottom").unbind("click").click(function () {
            $('html, body').animate({ scrollTop: $(document).height() }, 800);
            return false;
        });

        $(window).scroll();

        $(".nextb").unbind("click").click(function () {
            var rPos = 0;
            var rActive = null;

            $(".nav .nav-item").each(function () {
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

        $(".prevb").unbind("click").click(function () {
            var rPos = 0;
            var rActive = null;

            $($(".nav .nav-item").get().reverse()).each(function () {
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

        (function ($) {
            $.fn.inputFilter = function (inputFilter) {
                return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function () {
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
        $(".navigation-menu li").mouseenter(function () {
            $(this).find(".submenu").show();
        });

        delParam("status");

        $(window).on("popstate", function () {
            if (window.rRealURL) {
                if (
                    window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] !=
                    window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]
                ) {
                    navigate(window.location.href.split("/").reverse()[0]);
                }
            }
        });
        <?php endif; ?>

        $(document).keydown(function (e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = true;
            }
        });

        $(document).keyup(function (e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = false;
            }
        });

        document.onselectstart = function () {
            if (window.rShiftHeld) {
                return false;
            }
        };

        $('select').select2({ width: '100%' });

        elems.forEach(function (html) {
            if ($(html).attr("id") != "restart_on_edit") {
                window.rSwitches[$(html).attr("id")].disable();
            }
        });

        $('#server_tree')
            .on('redraw.jstree', function () {
                evaluateServers();
            })
            .on('select_node.jstree', function (e, data) {
                $("#c_server_tree").prop("checked", true);

                if (data.node.parent == "offline") {
                    $('#server_tree').jstree("move_node", data.node.id, "#source", "last");
                } else {
                    $('#server_tree').jstree("move_node", data.node.id, "#offline", "first");
                }
            })
            .jstree({
                core: {
                    check_callback: function (op, node, parent) {
                        switch (op) {
                            case 'move_node':
                                if ((node.id == "offline") || (node.id == "source")) return false;
                                if (parent.id == "#") return false;
                                return true;
                        }
                    },
                    data: <?= json_encode($rServerTree ?: []) ?>
                },
                plugins: ["dnd"]
            });

        $("input[type=checkbox].activate").change(function () {
            var name = $(this).data("name");
            var type = $(this).data("type");

            if ($(this).is(":checked")) {
                if (type == "switch") {
                    window.rSwitches[name].enable();
                } else {
                    $("#" + name).prop("disabled", false);

                    if (name == "days_to_restart") {
                        $("#time_to_restart").prop("disabled", false);
                    }
                    if (name == "server_tree") {
                        $("#on_demand").prop("disabled", false);
                        $("#server_type").prop("disabled", false);
                    }
                    if (name == "category_id") {
                        $("#category_id_type").prop("disabled", false);
                    }
                    if (name == "bouquets") {
                        $("#bouquets_type").prop("disabled", false);
                    }
                }
            } else {
                if (type == "switch") {
                    window.rSwitches[name].disable();
                } else {
                    $("#" + name).prop("disabled", true);

                    if (name == "days_to_restart") {
                        $("#time_to_restart").prop("disabled", true);
                    }
                    if (name == "server_tree") {
                        $("#on_demand").prop("disabled", true);
                        $("#server_type").prop("disabled", true);
                    }
                    if (name == "category_id") {
                        $("#category_id_type").prop("disabled", true);
                    }
                    if (name == "bouquets") {
                        $("#bouquets_type").prop("disabled", true);
                    }
                }
            }
        });

        $(".clockpicker").clockpicker();

        $("#probesize_ondemand").inputFilter(function (value) { return /^\d*$/.test(value); });
        $("#delay_minutes").inputFilter(function (value) { return /^\d*$/.test(value); });
        $("#tv_archive_duration").inputFilter(function (value) { return /^\d*$/.test(value); });

        rTable = $("#datatable-mass").DataTable({
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function () {
                $("#datatable-mass a").removeAttr("href");
                bindHref();
                refreshTooltips();
            },
            processing: true,
            serverSide: true,
            ajax: {
                url: "./table",
                data: function (d) {
                    d.id = "stream_list";
                    d.category = getCategory();
                    d.filter = getFilter();
                    d.server = getServer();
                }
            },
            columnDefs: [
                { className: "dt-center", targets: [0, 1, 5] }
            ],
            rowCallback: function (row, data) {
                if ($.inArray(data[0], window.rSelected) !== -1) {
                    $(row).addClass('selectedfilter ui-selected selected');
                }
            },
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10) ?>,
            order: [[0, "desc"]]
        });

        $('#stream_search').keyup(function () {
            rTable.search($(this).val()).draw();
        });

        $('#show_entries').change(function () {
            rTable.page.len($(this).val()).draw();
        });

        $('#stream_filter').change(function () {
            rTable.ajax.reload(null, false);
        });

        $('#stream_server_id').change(function () {
            rTable.ajax.reload(null, false);
        });

        $('#category_search').change(function () {
            rTable.ajax.reload(null, false);
        });

        $("#datatable-mass").selectable({
            filter: 'tr',
            selected: function (event, ui) {
                if ($(ui.selected).hasClass('selectedfilter')) {
                    $(ui.selected).removeClass('selectedfilter ui-selected selected');
                    window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);
                } else {
                    $(ui.selected).addClass('selectedfilter ui-selected selected');
                    window.rSelected.push($(ui.selected).find("td:eq(0)").text());
                }

                $("#selected_count").html(" - " + window.rSelected.length + " selected");
            }
        });

        $("form").submit(function (e) {
            e.preventDefault();

            $("#server_tree_data").val(
                JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }))
            );

            let rPass = false;
            let rSubmit = true;

            $.each($('#server_tree').jstree(true).get_json('#', { flat: true }), function (k, v) {
                if (v.parent == "source") {
                    rPass = true;
                }
            });

            $("#streams").val(JSON.stringify(window.rSelected));

            if (window.rSelected.length == 0) {
                $.toast("Select at least one stream to edit.");
                rSubmit = false;
            }

            if (rSubmit) {
                $(':input[type="submit"]').prop('disabled', true);
                submitForm(window.rCurrentPage, new FormData($("form")[0]));
            }
        });

        <?php if (SettingsManager::getAll()['enable_search']): ?>
        initSearch();
        <?php endif; ?>
    });
</script>

<script src="assets/js/listings.js"></script>
</body>
</html>