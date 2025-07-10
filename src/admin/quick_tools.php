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