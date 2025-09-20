<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Quick Tools';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Quick Tools</h4>
                </div>
            </div>
        </div>
        <form action="#" method="POST">
            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-body">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#streams" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-play mr-1"></i>
                                            <span class="d-none d-sm-inline">Streams</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#lines" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-user mr-1"></i>
                                            <span class="d-none d-sm-inline">Lines</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#mag" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-hdd mr-1"></i>
                                            <span class="d-none d-sm-inline">MAG</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#enigma" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-desktop mr-1"></i>
                                            <span class="d-none d-sm-inline">Enigma</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#logs" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-clipboard mr-1"></i>
                                            <span class="d-none d-sm-inline">Logs</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#general" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-wrench mr-1"></i>
                                            <span class="d-none d-sm-inline">General</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#asns" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="fas fa-server mr-1"></i>
                                            <span class="d-none d-sm-inline">ASN's</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="streams">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Restart All Streams</label>
                                                    <div class="col-md-2">
                                                        <input name="restart_all_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Restart Online Streams</label>
                                                    <div class="col-md-2">
                                                        <input name="restart_online_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Start Offline Streams</label>
                                                    <div class="col-md-2">
                                                        <input name="start_offline_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Stop Online Streams</label>
                                                    <div class="col-md-2">
                                                        <input name="stop_online_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Stop Down Streams</label>
                                                    <div class="col-md-2">
                                                        <input name="stop_down_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Restart Down Streams</label>
                                                    <div class="col-md-2">
                                                        <input name="restart_down_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Symlink All Movies</label>
                                                    <div class="col-md-2">
                                                        <input name="symlink_all_movies" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Symlink All Episodes</label>
                                                    <div class="col-md-2">
                                                        <input name="symlink_all_episodes" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Recreate Channels</label>
                                                    <div class="col-md-2">
                                                        <input name="recreate_channels" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Delete Duplicate VOD</label>
                                                    <div class="col-md-2">
                                                        <input name="delete_duplicates" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Replace Movie Years</label>
                                                    <div class="col-md-2">
                                                        <input name="replace_movie_years" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Replace Series Years</label>
                                                    <div class="col-md-2">
                                                        <input name="replace_series_years" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Check Web Player Compatibility</label>
                                                    <div class="col-md-2">
                                                        <input name="check_compatibility" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Re-Scan All VOD</label>
                                                    <div class="col-md-2">
                                                        <input name="rescan_vod" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Add TMDb ID to Movies</label>
                                                    <div class="col-md-2">
                                                        <input name="add_tmdb_ids" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Restore Lost Images</label>
                                                    <div class="col-md-2">
                                                        <input name="restore_images" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="lines">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Remove Expired Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_expired" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Remove Trial Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_trial" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Remove Expired Trial Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_expired_trial" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Remove NULL Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_null_lines" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Enable ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="enable_isp" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Disable ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="disable_isp" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Flush ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_isp" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="mag">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Remove Expired Devices</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_expired_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Remove Trial Devices</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_trial_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Remove Expired Trial Devices</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_expired_trial_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Flush ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_isp_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Enable ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="enable_isp_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Disable ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="disable_isp_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Enable MAG Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="enable_mag_lock" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Disable MAG Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="disable_mag_lock" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Flush MAG Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_mag_lock" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Purge Unlinked Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="purge_unlinked_lines_mag" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Update Movie Ratings</label>
                                                    <div class="col-md-2">
                                                        <input name="update_ratings" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="enigma">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Remove Expired Devices</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_expired_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Remove Trial Devices</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_trial_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Remove Expired Trial Devices</label>
                                                    <div class="col-md-2">
                                                        <input name="remove_expired_trial_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Flush ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_isp_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Enable ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="enable_isp_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Disable ISP Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="disable_isp_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Purge Unlinked Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="purge_unlinked_lines_e2" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="logs">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clear Activity Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_activity_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Clear Client Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_client_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clear Credit Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_credit_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Clear Login Flood</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_login_flood" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clear Login Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_login_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Clear MAG Events</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_mag_events" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clear Panel Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_panel_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Clear Stream Errors</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_stream_errors" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clear Stream Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_stream_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Clear User Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_user_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clear Watch Logs</label>
                                                    <div class="col-md-2">
                                                        <input name="clear_watch_logs" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="general">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Block Trial Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="block_trial_lines" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Unblock Trial Lines</label>
                                                    <div class="col-md-2">
                                                        <input name="unblock_trial_lines" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Reauthorise MySQL on Servers</label>
                                                    <div class="col-md-2">
                                                        <input name="reauthorise_mysql" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Flush Blocked IP's</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_blocked_ips" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Flush Blocked ISP's</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_blocked_isps" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Flush Blocked UA's</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_blocked_uas" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Flush Country Lock</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_country_lock" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Force EPG Update</label>
                                                    <div class="col-md-2">
                                                        <input name="force_epg_update" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Clean Up Streams Table</label>
                                                    <div class="col-md-2">
                                                        <input name="cleanup_streams" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Force Movies TMDb Refresh</label>
                                                    <div class="col-md-2">
                                                        <input name="force_update_movies" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Force Series TMDb Refresh</label>
                                                    <div class="col-md-2">
                                                        <input name="force_update_series" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Force Episodes TMDb Refresh</label>
                                                    <div class="col-md-2">
                                                        <input name="force_update_episodes" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="asns">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Block All ISP's</label>
                                                    <div class="col-md-2">
                                                        <input name="block_all_isps" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Unblock All ISP's</label>
                                                    <div class="col-md-2">
                                                        <input name="unblock_all_isps" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Block All Servers</label>
                                                    <div class="col-md-2">
                                                        <input name="block_all_servers" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Unblock All Servers</label>
                                                    <div class="col-md-2">
                                                        <input name="unblock_all_servers" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Block All Education</label>
                                                    <div class="col-md-2">
                                                        <input name="block_all_education" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Unblock All Education</label>
                                                    <div class="col-md-2">
                                                        <input name="unblock_all_education" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Block All Businesses</label>
                                                    <div class="col-md-2">
                                                        <input name="block_all_businesses" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                    <div class="col-md-2"></div>
                                                    <label class="col-md-3 col-form-label">Unblock All Businesses</label>
                                                    <div class="col-md-2">
                                                        <input name="unblock_all_businesses" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-3 col-form-label">Flush Blocked ASN's</label>
                                                    <div class="col-md-2">
                                                        <input name="flush_blocked_asns" type="submit" class="btn btn-info" style="width:100%;" value="Run" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Additional tabs for Lines, MAG, Enigma, Logs, General, ASN's go here -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
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
        <?php if (!$rMobile || $rSettings['header_stats']): ?>
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

    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });

    <?php endif; ?>

    $(document).ready(function() {
        $("input[type='submit']").click(function(event) {
            event.preventDefault();
            var _this = $(this);
            new jBox("Confirm", {
                confirmButton: "Run",
                cancelButton: "Cancel",
                content: "Are you sure you want to run this tool? This can't be undone.",
                confirm: function() {
                    var rForm = [$(_this).attr("name")];
                    submitForm(window.rCurrentPage, rForm);
                }
            }).open();
        });
        $("form").submit(function(e) {
            e.preventDefault();
        });
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>