<?php

if (!isset($__settingsViewMode)):

	include "session.php";
	include "functions.php";

	if (!PageAuthorization::checkPermissions()) {
		AdminHelpers::goHome();
	}

	$rSettings = SettingsManager::getAll();
	$rStreamArguments = StreamConfigRepository::getStreamArguments();

	$GeoLite2 = json_decode(file_get_contents(BIN_PATH . "maxmind/version.json"), true)["geolite2_version"];
	$GeoISP = json_decode(file_get_contents(BIN_PATH . "maxmind/version.json"), true)["geoisp_version"];
	$Nginx = trim(shell_exec(BIN_PATH . "nginx/sbin/nginx -v 2>&1 | cut -d'/' -f2"));

	$_TITLE = "Settings";
	require_once __DIR__ . '/../layouts/admin.php';
	renderUnifiedLayoutHeader('admin');

endif; // !$__settingsViewMode
?>

<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'): ?> style="display: none;" <?php endif; ?>>
	<div class="container-fluid">
		<form action="#" method="POST">
			<div style="display:none;">
				<input type="text">
				<input type="password">
			</div>
			<!-- Chrome tries to autofill username / password, fool it into filling this in instead. -->
			<div class="row">
				<div class="col-12">
					<div class="page-title-box">
						<div class="page-title-right">
							<input name="submit_settings" type="submit" class="btn btn-primary" value="Save Changes" />
						</div>
						<h4 class="page-title"><?= $language::get('settings') ?></h4>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-xl-12">
					<?php
					if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) {
					?>
						<div class="alert alert-success alert-dismissible fade show" role="alert">
							<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span
									aria-hidden="true">&times;</span></button>Settings have been updated.
						</div>
					<?php
					} ?>
					<div class="card bg-info text-white cta-box">
						<?php
						if (isset($rUpdate) && is_array($rUpdate) && !empty($rUpdate["version"]) && (0 < version_compare($rUpdate["version"], XC_VM_VERSION) || (version_compare($rUpdate["version"], XC_VM_VERSION) == 0))) {
						?>
							<div class="card-body" style="max-height: 250px;">
								<h5 class="card-title text-white"><?= $language::get('update_available') ?></h5>
								<p>Official Release v <?= $rUpdate["version"]; ?> is now available to download.</p>
								<?php
								if (!empty($rUpdate["changelog"]) && is_array($rUpdate["changelog"])) {
									foreach ($rUpdate["changelog"] as $rItem) {
										echo '<h5 class="card-title text-white mt-1">Changelog - v';
										echo $rItem["version"];
										echo '</h5><ul>';

										foreach ((is_array($rItem["changes"] ?? null) ? $rItem["changes"] : []) as $rChange) {
											echo '<li>';
											echo $rChange;
											echo '</li>';
										}
										echo '</ul>';
									}
								}
								?>
								<br />
								<a href="<?= str_replace('" ', '"', $rUpdate["url"]) ?> " class="text-white font-weight-semibold text-uppercase">Go to Release Thread <i class="mdi mdi-arrow-right"></i></a>
								<br />
								<br />
								<button type="button" class="btn btn-light" onclick="UpdateServer()"><?= $language::get('update_server') ?></button>
							</div>
						<?php } ?>
					</div>
					<div class="card">
						<div class="card-body">
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#interface" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-account-card-details-outline mr-1"></i><span
												class="d-none d-sm-inline"><?= $language::get('general') ?></span></a>
									</li>
									<li class="nav-item">
										<a href="#security" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi mdi-shield-lock mr-1"></i><span
												class="d-none d-sm-inline"><?= $language::get('security') ?></span></a>
									</li>
									<li class="nav-item">
										<a href="#api" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-code-tags mr-1"></i><span
												class="d-none d-sm-inline">API</span></a>
									</li>
									<li class="nav-item">
										<a href="#streaming" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-play mr-1"></i><span
												class="d-none d-sm-inline"><?= $language::get('streaming') ?></span></a>
									</li>
									<li class="nav-item">
										<a href="#mag" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-tablet mr-1"></i><span
												class="d-none d-sm-inline"><?= $language::get('mag') ?></span></a>
									</li>
									<li class="nav-item">
										<a href="#webplayer" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-web mr-1"></i><span class="d-none d-sm-inline">Web
												Player</span></a>
									</li>
									<li class="nav-item">
										<a href="#logs" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-file-document-outline mr-1"></i><span
												class="d-none d-sm-inline"><?= $language::get('logs') ?></span></a>
									</li>
									<li class="nav-item">
										<a href="#info" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-file-document-outline mr-1"></i><span
												class="d-none d-sm-inline"><?= $language::get('info') ?></span></a>
									</li>
									<?php if (Authorization::check("adv", "database") && DEVELOPMENT) { ?>
										<li class="nav-item">
											<a href="#database" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
													class="mdi mdi-file-document-outline mr-1"></i><span
													class="d-none d-sm-inline"><?= $language::get('database') ?></span></a>
										</li>
									<?php } ?>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="interface">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="server_name">Server Name
														<i title="<?= $language::get('the_name_of_your_streaming_service') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="server_name"
															name="server_name"
															value="<?= htmlspecialchars($rSettings["server_name"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="default_timezone">Server
														Timezone <i
															title="<?= $language::get('default_timezone_for_the_admin_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="default_timezone" id="default_timezone"
															class="form-control" data-toggle="select2">
															<?php
															foreach (AdminHelpers::TimeZoneList() as $rValue) {
																echo '<option ';

																if ($rSettings["default_timezone"] == $rValue['zone']) {
																	echo ' selected ';
																}

																echo ' value="';
																echo $rValue['zone'];
																echo '">';
																echo $rValue['zone'] . " " . $rValue['diff_from_GMT'];
																echo '</option>';
															}
															echo '</select></div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="language">Interface Language
														<i title="' . $language::get('default_language_for_the_admin_tooltip') . '"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="language" id="language" class="form-control" data-toggle="select2">';
															foreach (Translator::available() as $rLangCode) {
																echo '<option';
																if (($rSettings["language"] ?? 'en') === $rLangCode) {
																	echo ' selected';
																}
																echo ' value="' . htmlspecialchars($rLangCode) . '">';
																echo htmlspecialchars($rLangCode);
																echo '</option>';
															}
															echo '</select></div></div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="message_of_day">Message of the Day <i
															title="' . $language::get('message_to_show_in_the_tooltip') . '"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8"><input type="text" class="form-control"
															id="message_of_day" name="message_of_day" value="';
															echo htmlspecialchars($rSettings["message_of_day"] ?? '');
															echo '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="default_entries">Show Entries <i title="' . $language::get('number_of_table_entries_to_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="default_entries" id="default_entries" class="form-control" data-toggle="select2">';
															foreach ([10, 25, 50, 250, 500, 1000] as $rShow) {
																echo '    <option';
																if (
																	$rSettings["default_entries"]
																	!= $rShow
																) {
																} else {
																	echo ' selected';
																}
																echo ' value="';
																echo $rShow;
																echo '">';
																echo $rShow;
																echo '</option>';
															}
															echo '</select></div><label class="col-md-4 col-form-label" for="fails_per_time">Fails Per Time <i title="' . $language::get('how_long_to_track_stream_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="fails_per_time" name="fails_per_time" value="';
															echo intval($rSettings["fails_per_time"]);
															echo '"></div><!--<label class="col-md-4 col-form-label" for="default_entries">Fingerprint Max <i title="' . $language::get('maximum_number_of_concurrent_fingerprint_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="fingerprint_max" id="fingerprint_max" class="form-control" data-toggle="select2">';
															foreach ([0, 5, 10, 25, 50, 100] as $rShow) {
																echo '<option';
																if ($rSettings["fingerprint_max"] != $rShow) {
																} else {
																	echo ' selected';
																}
																echo ' value="';
																echo
																$rShow;
																echo '">';
																echo $rShow;
																echo '</option>';
															}
															echo '</select></div>--></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="date_format">Date Format <i title="' . $language::get('default_date_format_to_use_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="date_format" name="date_format" value="';
															echo htmlspecialchars($rSettings["date_format"] ?? '');
															echo '"></div><label class="col-md-4 col-form-label" for="datetime_format">Datetime Format <i title="' . $language::get('default_datetime_format_to_use_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="datetime_format" name="datetime_format" value="';
															echo htmlspecialchars($rSettings["datetime_format"] ?? '');
															echo '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="streams_grouped">Group Streams Table <i title="' . $language::get('toggle_to_group_multiple_servers_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="streams_grouped" id="streams_grouped" type="checkbox"';
															if ($rSettings["streams_grouped"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="js_navigate">Seamless Navigation <i title="' . $language::get('enable_seamless_navigation_by_utilising_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="js_navigate" id="js_navigate" type="checkbox"';
															if ($rSettings["js_navigate"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_tickets">Show Tickets Icon <i title="' . $language::get('show_tickets_icon_in_the_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_tickets" id="show_tickets" type="checkbox"';
															if ($rSettings["show_tickets"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="hide_failures">Disable Restart Counter <i title="' . $language::get('removes_the_restart_count_next_to_stream_uptime') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="hide_failures" id="hide_failures" type="checkbox"';
															if ($rSettings["hide_failures"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="cleanup">Auto-Cleanup Files <i title="' . $language::get('automatically_clean_up_redundant_files_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="cleanup" id="cleanup" type="checkbox"';
															if ($rSettings["cleanup"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="check_vod">Check VOD Cron <i title="' . $language::get('check_that_vod_exists_periodically_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="check_vod" id="check_vod" type="checkbox"';
															if ($rSettings["check_vod"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_images">Show Images & Picons <i title="' . $language::get('show_channel_logos_and_vod_images_in_the_management_pages') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_images" id="show_images" type="checkbox"';
															if ($rSettings["show_images"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="group_buttons">Group Buttons <i title="' . $language::get('group_action_buttons_into_a_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="group_buttons" id="group_buttons" type="checkbox"';
															if ($rSettings["group_buttons"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="modal_edit">Quick Edit Modal <i title="' . $language::get('when_clicking_edit_open_in_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="modal_edit" id="modal_edit" type="checkbox"';
															if ($rSettings["modal_edit"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="mysql_sleep_kill">MySQL Sleep Timeout <i title="' . $language::get('how_long_to_allow_mysql_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="mysql_sleep_kill" name="mysql_sleep_kill" value="';
															echo intval($rSettings["mysql_sleep_kill"]);
															echo '"></div></div>'; ?>

															<div class="form-group row mb-4">
																<label class="col-md-4 col-form-label" for="update_channel"><?= $language::get('update_channel') ?></label>
																<div class="col-md-2">
																	<select name="update_channel" id="update_channel" class="form-control"
																		data-toggle="select2">
																		<?
																		foreach (["stable" => "Stable", "unstable" => "Unstable"] as $rKey => $rValue) {
																			echo '<option';

																			if ($rSettings["update_channel"] == $rKey) {
																				echo ' selected';
																			}

																			echo ' value="';
																			echo $rKey;
																			echo '">';
																			echo $rValue;
																			echo '</option>';
																		}
																		?>
																	</select>
																</div>
															</div>

															<?php echo '<h5 class="card-title mb-4">' . $language::get('dashboard') . '</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dashboard_stats">Show Graphs <i title="' . $language::get('enable_dashboard_statistic_graphs_for_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_stats" id="dashboard_stats" type="checkbox"';
															if ($rSettings["dashboard_stats"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="dashboard_map">Show Connections Map <i title="' . $language::get('show_connection_map_on_the_dashboard') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_map" id="dashboard_map" type="checkbox"';
															if ($rSettings["dashboard_map"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dashboard_display_alt">Alternate Server View <i title="' . $language::get('display_servers_on_the_dashboard_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_display_alt" id="dashboard_display_alt" type="checkbox"';
															if ($rSettings["dashboard_display_alt"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="header_stats_sh">Show Header Stats <i title="' . $language::get('show_server_statistics_in_header_menu') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="header_stats" id="header_stats_sh" type="checkbox"';
															if ($rSettings["header_stats"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dashboard_status">Show Service Status <i title="' . $language::get('show_warning_information_based_on_server_stats') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_status" id="dashboard_status" type="checkbox"';
															if ($rSettings["dashboard_status"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="threshold_cpu">CPU Threshold (not working)% <i title="' . $language::get('when_cpu_usage_is_above_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_cpu" name="threshold_cpu" value="';
															echo intval($rSettings["threshold_cpu"]);
															echo '"></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="threshold_mem">Memory Threshold (not working)% <i title="' . $language::get('when_memory_usage_is_above_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_mem" name="threshold_mem" value="';
															echo intval($rSettings["threshold_mem"]);
															echo '"></div><label class="col-md-4 col-form-label" for="threshold_disk">Disk Threshold (not working)% <i title="' . $language::get('when_disk_usage_is_above_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_disk" name="threshold_disk" value="';
															echo intval($rSettings["threshold_disk"]);
															echo '"></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="threshold_network">Network Threshold (not working)% <i title="' . $language::get('when_network_usage_is_above_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_network" name="threshold_network" value="';
															echo intval($rSettings["threshold_network"]);
															echo '"></div><label class="col-md-4 col-form-label" for="threshold_clients">Clients Threshold (not working)% <i title="' . $language::get('when_number_of_clients_as_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_clients" name="threshold_clients" value="';
															echo intval($rSettings["threshold_clients"]);
															echo '"></div>    </div><h5 class="card-title mb-4">' . $language::get('search') . '</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="enable_search">Enable Search <i title="' . $language::get('toggle_the_search_box_in_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="enable_search" id="enable_search" type="checkbox"';
															if ($rSettings["enable_search"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="search_items">Number of Items <i title="' . $language::get('how_many_search_results_to_display_maximum_of_100') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="search_items" name="search_items" value="';
															echo intval($rSettings["search_items"]);
															echo '"></div>    </div>    <h5 class="card-title mb-4">' . $language::get('reseller') . '</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="disable_trial">Disable Trials <i title="' . $language::get('use_this_option_to_temporarily_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disable_trial" id="disable_trial" type="checkbox"';
															if ($rSettings["disable_trial"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="reseller_ssl_domain">SSL Custom DNS <i title="' . $language::get('use_https_in_playlist_downloads_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="reseller_ssl_domain" id="reseller_ssl_domain" type="checkbox"';
															if ($rSettings["reseller_ssl_domain"] == 1) {
																echo ' checked ';
															}


															?>



															data-plugin="switchery" class="js-switch" data-color="#039cfd"/>
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('debug') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="debug_show_errors">Debug Mode <i title="<?= $language::get('automatically_clean_up_redundant_files_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="debug_show_errors" id="debug_show_errors" type="checkbox" <?= $rSettings["debug_show_errors"] == 1 ? 'checked' : '' ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
													<label class="col-md-4 col-form-label" for="enable_debug_stalker">Stalker Debug Mode <i title="<?= $language::get('enable_debug_mode_ministra_portal') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="enable_debug_stalker" id="enable_debug_stalker" type="checkbox" <?= $rSettings["enable_debug_stalker"] == 1 ? 'checked' : '' ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('recaptcha') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label">Enable reCAPTCHA <i title="<?= $language::get('click_here_to_show_active_tooltip') ?>" class="tooltip text-secondary far fa-circle" data-toggle="modal" data-target=".bs-domains"></i></label>
													<div class="col-md-2"><input name="recaptcha_enable" id="recaptcha_enable" type="checkbox" <?= $rSettings["recaptcha_enable"] == 1  ? 'checked' : '' ?> data-plugin=" switchery" class="js-switch" data-color="#039cfd">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="recaptcha_v2_site_key">reCAPTCHA V2 - Site Key <i
															title="<?= $language::get('please_visit_httpsgooglecomrecaptchaadmin_to_obtain_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="recaptcha_v2_site_key"
															name="recaptcha_v2_site_key"
															value="<?= htmlspecialchars($rSettings["recaptcha_v2_site_key"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="recaptcha_v2_secret_key">reCAPTCHA V2 - Secret Key <i
															title="<?= $language::get('please_visit_httpsgooglecomrecaptchaadmin_to_obtain_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="recaptcha_v2_secret_key"
															name="recaptcha_v2_secret_key" value="<?= htmlspecialchars($rSettings["recaptcha_v2_secret_key"] ?? '') ?>">
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('default_arguments') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="user_agent">User
														Agent</label>
													<div class="col-md-9">
														<input type="text" class="form-control" id="user_agent"
															name="user_agent"
															value="<?= htmlspecialchars($rStreamArguments["user_agent"]["argument_default_value"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="http_proxy">HTTP Proxy
														<i title="<?= $language::get('format_ipport') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-9">
														<input type="text" class="form-control" id="http_proxy"
															name="http_proxy"
															value="<?= htmlspecialchars($rStreamArguments["proxy"]["argument_default_value"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="cookie">Cookie <i
															title="<?= $language::get('format_keyvalue') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-9">
														<input type="text" class="form-control" id="cookie" name="cookie"
															value="<?= htmlspecialchars($rStreamArguments["cookie"]["argument_default_value"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="headers">Headers <i
															title="<?= $language::get('ffmpeg_headers_command') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-9">
														<input type="text" class="form-control" id="headers" name="headers"
															value="<?= htmlspecialchars($rStreamArguments["headers"]["argument_default_value"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="probesize_ondemand">On Demand Probesize <i
															title="<?= $language::get('adjustable_probesize_for_ondemand_streams_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-3">
														<input type="text" class="form-control text-center"
															id="probesize_ondemand" name="probesize_ondemand"
															value="<?= intval($rSettings["probesize_ondemand"]) ?>">
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="security">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('ip_security') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="ip_subnet_match">Match
														Subnet of IP <i title="<?= $language::get('some_ip_s_change_quite_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="ip_subnet_match" id="ip_subnet_match" type="checkbox"
															<?php if ($rSettings["ip_subnet_match"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="ip_logout">Logout On IP Change <i title="' . $language::get('enable_to_destroy_sessions_if_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="ip_logout" id="ip_logout" type="checkbox"';
															if ($rSettings["ip_logout"] == 1) {
																echo ' checked ';
															} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="restrict_same_ip">Restrict
														to Same IP <i
															title="<?= $language::get('tie_hls_connections_to_their_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="restrict_same_ip" id="restrict_same_ip" type="checkbox"
															<? if ($rSettings["restrict_same_ip"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="rtmp_random">Random RTMP IP <i title="' . $language::get('use_a_random_ip_for_rmtp_connections') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="rtmp_random" id="rtmp_random" type="checkbox"';
															if ($rSettings["rtmp_random"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="disallow_2nd_ip_con">Disallow 2nd IP <i title="' . $language::get('disallow_connection_from_different_ip_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disallow_2nd_ip_con" id="disallow_2nd_ip_con" type="checkbox"';
															if ($rSettings["disallow_2nd_ip_con"] == 1) {
																echo ' checked ';
															} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div><label class="col-md-4 col-form-label"
														for="disallow_2nd_ip_max">Disallow if Connections <= <i
															title="<?= $language::get('maximum_amount_of_connections_a_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text"
															class="form-control text-center" id="disallow_2nd_ip_max"
															name="disallow_2nd_ip_max"
															value="<?= intval($rSettings["disallow_2nd_ip_max"]) ?>">
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('restream_prevention') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="restream_deny_unauthorised">XC_VM Detect - Deny <i
															title="<?= $language::get('deny_connections_from_nonrestreamers_who_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-2"><input name="restream_deny_unauthorised"
															id="restream_deny_unauthorised" type="checkbox" <?php
																											if ($rSettings["restream_deny_unauthorised"] == 1) {
																												echo ' checked ';
																											} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label" for="detect_restream_block_user">XC_VM
														Detect - Ban Lines <i
															title="<?= $language::get('ban_lines_of_nonrestreamers_who_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="detect_restream_block_user"
															id="detect_restream_block_user" type="checkbox" <?php
																											if ($rSettings["detect_restream_block_user"] == 1) {
																												echo ' checked ';
																											} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="block_streaming_servers">Block Hosting Servers <i
															title="<?= $language::get('automatically_block_servers_from_server_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="block_streaming_servers" id="block_streaming_servers"
															type="checkbox" <?php if ($rSettings["block_streaming_servers"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="block_proxies">Block
														Proxies
														/ VPN's <i
															title="<?= $language::get('automatically_block_proxies_and_vpns_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="block_proxies" id="block_proxies" type="checkbox" <?php if ($rSettings["block_proxies"] == 1) {
																															echo ' checked ';
																														} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('spam_prevention') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="flood_limit">Flood Limit
														<i title="<?= $language::get('number_of_attempts_before_ip_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="flood_limit"
															name="flood_limit"
															value="<?= htmlspecialchars($rSettings["flood_limit"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="flood_seconds">Per
														Seconds
														<i title="<?= $language::get('number_of_seconds_between_requests') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="flood_seconds" name="flood_seconds"
															value="<?= htmlspecialchars($rSettings["flood_seconds"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="auth_flood_limit">Auth
														Flood
														Limit <i
															title="<?= $language::get('number_of_attempts_before_connections_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="auth_flood_limit" name="auth_flood_limit"
															value="<?= htmlspecialchars($rSettings["auth_flood_limit"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="auth_flood_seconds">Auth
														Flood Seconds <i
															title="<?= $language::get('number_of_seconds_to_calculate_number_of_requests_for') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="auth_flood_seconds" name="auth_flood_seconds"
															value="<?= htmlspecialchars($rSettings["auth_flood_seconds"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="auth_flood_sleep">Auth
														Flood
														Sleep <i
															title="<?= $language::get('how_long_to_sleep_for_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="auth_flood_sleep" name="auth_flood_sleep"
															value="<?= htmlspecialchars($rSettings["auth_flood_sleep"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="flood_ips_exclude">Flood
														IP
														Exclusions <i title="<?= $language::get('separate_each_ip_with_a_comma') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control" id="flood_ips_exclude"
															name="flood_ips_exclude"
															value="<?= htmlspecialchars($rSettings["flood_ips_exclude"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="bruteforce_mac_attempts">Detect MAC Bruteforce <i
															title="<?= $language::get('automatically_detect_and_block_ip_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="bruteforce_mac_attempts" name="bruteforce_mac_attempts"
															value="<?= htmlspecialchars($rSettings["bruteforce_mac_attempts"] ?? '') ?: 0 ?>">
													</div>
													<label class="col-md-4 col-form-label"
														for="bruteforce_username_attempts">Detect Username Bruteforce <i
															title="<?= $language::get('automatically_detect_and_block_ip_tooltip_title') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="bruteforce_username_attempts"
															name="bruteforce_username_attempts"
															value="<?= htmlspecialchars($rSettings["bruteforce_username_attempts"] ?? '') ?: 0 ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="bruteforce_frequency">Bruteforce Frequency <i
															title="<?= $language::get('time_between_attempts_for_mac_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="bruteforce_frequency" name="bruteforce_frequency"
															value="<?= htmlspecialchars($rSettings["bruteforce_frequency"] ?? '') ?: 0 ?>">
													</div>
													<label class="col-md-4 col-form-label" for="login_flood">Maximum
														Login
														Attempts <i
															title="<?= $language::get('how_many_login_attempts_are_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="login_flood"
															name="login_flood"
															value="<?= htmlspecialchars($rSettings["login_flood"] ?? '') ?: 0 ?>">
													</div>
												</div>
												<div class=" form-group row
															mb-4">
													<label class="col-md-4 col-form-label"
														for="max_simultaneous_downloads">Max Simultaneous Downloads <i
															title="<?= $language::get('max_number_of_simultaneous_epg_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="max_simultaneous_downloads"
															name="max_simultaneous_downloads"
															value="<?= htmlspecialchars($rSettings["max_simultaneous_downloads"] ?? '') ?>">
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="api">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="tmdb_api_key">TMDb Key
														<i title="<?= $language::get('get_your_api_key_at_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="tmdb_api_key"
															name="tmdb_api_key"
															value="<?= htmlspecialchars($rSettings["tmdb_api_key"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="tmdb_language">TMDb
														Language
														<i title="<?= $language::get('default_language_for_tmdb_requests_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="tmdb_language" id="tmdb_language" class="form-control"
															data-toggle="select2">
															<?php
															foreach ($rTMDBLanguages as $rKey => $rLanguage) {
																echo '<option';

																if ($rSettings["tmdb_language"] != $rKey) {
																} else {
																	echo ' selected';
																}

																echo ' value="';
																echo $rKey;
																echo '">';
																echo $rLanguage;
																echo '</option>';
															}
															echo '</select></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="download_images">Download Images <i title="' . $language::get('if_this_option_is_set_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="download_images" id="download_images" type="checkbox"';

															if ($rSettings["download_images"] == 1) {
																echo ' checked ';
															}

															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="api_redirect">API Redirect <i title="' . $language::get('redirect_api_stream_requests_using_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="api_redirect" id="api_redirect" type="checkbox"';

															if ($rSettings["api_redirect"] == 1) {
																echo ' checked ';
															}

															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="movie_year_append">Append Movie Year <i title="' . $language::get('automatically_append_the_movie_year_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="movie_year_append" id="movie_year_append" class="form-control" data-toggle="select2">';

															foreach (["Brackets", "Hyphen", "Disabled"] as $rKey => $rValue) {
																echo '<option';

																if ($rSettings["movie_year_append"] != $rKey) {
																} else {
																	echo ' selected';
																}

																echo ' value="';
																echo $rKey;
																echo '">';
																echo $rValue;
																echo '</option>';
															}
															echo '</select></div><label class="col-md-4 col-form-label" for="api_container">API Container <i title="' . $language::get('default_container_to_use_in_android_smart_tv_apps') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="api_container" id="api_container" class="form-control" data-toggle="select2">';

															foreach (["ts" => "MPEG-TS", "m3u8" => "HLS"] as $rKey => $rValue) {
																echo '<option';

																if ($rSettings["api_container"] != $rKey) {
																} else {
																	echo ' selected';
																}

																echo ' value="';
																echo $rKey;
																echo '">';
																echo $rValue;
																echo '</option>';
															}
															?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="cache_playlists">Cache
														Playlists for <i
															title="<?= $language::get('if_this_value_is_more_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="cache_playlists" name="cache_playlists"
															value="<?= intval($rSettings["cache_playlists"]) ?>">
													</div>
													<label class="col-md-4 col-form-label" for="playlist_from_mysql">Grab
														Playlists from MySQL <i
															title="<?= $language::get('enable_this_to_read_streams_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="playlist_from_mysql" id="playlist_from_mysql"
															type="checkbox" <?php if ($rSettings["playlist_from_mysql"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery"
															class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="force_epg_timezone">Force
														EPG to UTC Timezone <i
															title="<?= $language::get('ensure_all_epg_is_generated_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="force_epg_timezone" id="force_epg_timezone"
															type="checkbox" <? if ($rSettings["force_epg_timezone"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="keep_protocol">Keep
														Request
														Protocol <i
															title="<?= $language::get('keep_the_requested_protocol_http_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="keep_protocol" id="keep_protocol" type="checkbox" <? if ($rSettings["keep_protocol"] == 1) {
																															echo ' checked ';
																														} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="parse_type">VOD Parser
														<i title="<?= $language::get('whether_to_use_guessit_or_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<select name="parse_type" id="parse_type" class="form-control"
															data-toggle="select2">
															<?
															foreach (["guessit" => "GuessIt", "ptn" => "PTN"] as $rKey => $rValue) {
																echo '<option';

																if ($rSettings["parse_type"] != $rKey) {
																} else {
																	echo ' selected';
																}

																echo ' value="';
																echo $rKey;
																echo '">';
																echo $rValue;
																echo '</option>';
															}
															?>
														</select>
													</div>
													<label class="col-md-4 col-form-label" for="cloudflare">Enable
														Cloudflare <i
															title="<?= $language::get('allow_cloudflare_ips_to_connect_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="cloudflare" id="cloudflare" type="checkbox" <?php if ($rSettings["cloudflare"] == 1) {
																														echo ' checked ';
																													} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
											</div>
										</div>
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('legacy_support') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="legacy_get">Legacy Playlist URL <i
															title="<?= $language::get('rewrite_getphp_requests_to_the_new_playlist_url') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="legacy_get" id="legacy_get"
															type="checkbox" <?php
																			if ($rSettings["legacy_get"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="legacy_xmltv">Legacy
														XMLTV
														URL <i title="<?= $language::get('rewrite_xmltvphp_requests_to_the_new_epg_url') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="legacy_xmltv" id="legacy_xmltv" type="checkbox" <?php if ($rSettings["legacy_xmltv"] == 1) {
																															echo ' checked ';
																														} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="legacy_panel_api">Legacy
														Panel API <i
															title="<?= $language::get('rewrite_panel_apiphp_requests_to_the_new_xc_vm_player_api') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="legacy_panel_api" id="legacy_panel_api" type="checkbox"
															<?php if ($rSettings["legacy_panel_api"] == 1) {
																echo ' checked ';
															} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label"
														for="show_category_duplicates">Duplicate Streams in Legacy Apps
														<i title="<?= $language::get('xcvm_was_the_first_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="show_category_duplicates" id="show_category_duplicates"
															type="checkbox" <?php if ($rSettings["show_category_duplicates"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('api_services') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="allowed_ips_admin">Admin
														Streaming IP's <i
															title="<?= $language::get('allowed_ips_to_access_streaming_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="allowed_ips_admin"
															name="allowed_ips_admin"
															value="<?= htmlspecialchars($rSettings["allowed_ips_admin"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="api_ips">API IP's <i
															title="<?= $language::get('allowed_ips_to_access_the_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="api_ips" name="api_ips"
															value="<?= htmlspecialchars(is_array($rSettings["api_ips"] ?? '') ? implode(',', $rSettings["api_ips"]) : ($rSettings["api_ips"] ?? '')) ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="api_ips">API Password <i
															title="<?= $language::get('password_required_to_access_the_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="password" class="form-control" id="api_pass"
															name="api_pass"
															value="<?= htmlspecialchars($rSettings["api_pass"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="disable_xmltv">Disable
														EPG
														Download - Line <i
															title="<?= $language::get('enable_to_disallow_epg_downloads_in_xmltv_format') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="disable_xmltv" id="disable_xmltv" type="checkbox" <?php if ($rSettings["disable_xmltv"] == 1) {
																															echo ' checked ';
																														} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div><label class="col-md-4 col-form-label"
														for="disable_xmltv_restreamer">Disable EPG Download - Restreamer
														<i title="<?= $language::get('enable_to_disallow_epg_downloads_in_xmltv_format') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_xmltv_restreamer"
															id="disable_xmltv_restreamer" type="checkbox" <?
																											if ($rSettings["disable_xmltv_restreamer"] == 1) {
																												echo ' checked ';
																											}
																											echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="disable_playlist">Disable Playlist Download - Line <i title="' . $language::get('enable_to_remove_the_ability_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disable_playlist" id="disable_playlist" type="checkbox"';
																											if ($rSettings["disable_playlist"] == 1) {
																												echo ' checked ';
																											}
																											echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="disable_playlist_restreamer">Disable Playlist Download - Restreamer <i title="' . $language::get('enable_to_remove_the_ability_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disable_playlist_restreamer" id="disable_playlist_restreamer" type="checkbox"';
																											if ($rSettings["disable_playlist_restreamer"] == 1) {
																												echo ' checked ';
																											} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="disable_player_api">Disable
														Player API <i
															title="<?= $language::get('enable_to_stop_android_apps_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="disable_player_api" id="disable_player_api"
															type="checkbox" <?php if ($rSettings["disable_player_api"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div><label class="col-md-4 col-form-label"
														for="disable_enigma2">Disable Enigma2 API <i
															title="<?= $language::get('enable_to_stop_enigma_devices_from_connecting') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_enigma2" id="disable_enigma2"
															type="checkbox" <?php
																			if ($rSettings["disable_enigma2"] == 1) {
																				echo ' checked ';
																			}
																			?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="disable_ministra">Disable
														Ministra API <i title="<?= $language::get('enable_to_stop_mag_devices_from_connecting') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_ministra"
															id="disable_ministra" type="checkbox" <?php
																									if ($rSettings["disable_ministra"] == 1) {
																										echo ' checked ';
																									} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="verify_host">Verify
														Hosts <i
															title="<?= $language::get('verify_domain_names_and_ips_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="verify_host" id="verify_host" type="checkbox" <?php if ($rSettings["verify_host"] == 1) {
																														echo ' checked ';
																													} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('ministra') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="live_streaming_pass">Streaming Password</label>
													<div class="col-md-8"><input type="text" class="form-control"
															id="live_streaming_pass" name="live_streaming_pass"
															value="<?= htmlspecialchars(SettingsManager::getAll()["live_streaming_pass"] ?? '') ?>">
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="streaming">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="enable_isp_lock">Enable ISP Lock <i
															title="<?= $language::get('enable_disable_isp_lock_globally') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="enable_isp_lock" id="enable_isp_lock"
															type="checkbox" <?php if ($rSettings["enable_isp_lock"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label" for="block_svp">Enable ASN Lock <i
															title="<?= $language::get('enable_disable_asn_lock_globally') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="block_svp" id="block_svp"
															type="checkbox" <?php if ($rSettings["block_svp"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="disable_ts">Disable MPEG-TS Output <i
															title="<?= $language::get('disable_mpeg_ts_for_all_clients_and_devices') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_ts" id="disable_ts"
															type="checkbox" <?php if ($rSettings["disable_ts"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label"
														for="disable_ts_allow_restream">Allow Restreamers - MPEG-TS <i
															title="<?= $language::get('override_to_allow_restreamers_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_ts_allow_restream"
															id="disable_ts_allow_restream" type="checkbox" <?php if ($rSettings["disable_ts_allow_restream"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="disable_hls">Disable HLS Output <i
															title="<?= $language::get('disable_hls_for_all_clients_and_devices') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_hls" id="disable_hls"
															type="checkbox" <?php if ($rSettings["disable_hls"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label"
														for="disable_hls_allow_restream">Allow Restreamers - HLS <i
															title="<?= $language::get('override_to_allow_restreamers_to_tooltip_title') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_hls_allow_restream"
															id="disable_hls_allow_restream" type="checkbox" <?php if ($rSettings["disable_hls_allow_restream"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="disable_rtmp">Disable RTMP Output <i
															title="<?= $language::get('disable_rtmp_for_all_clients_and_devices') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_rtmp" id="disable_rtmp"
															type="checkbox" <?php if ($rSettings["disable_rtmp"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label"
														for="disable_rtmp_allow_restream">Allow Restreamers - RTMP <i
															title="<?= $language::get('override_to_allow_restreamers_to_tooltip_title') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_rtmp_allow_restream"
															id="disable_rtmp_allow_restream" type="checkbox" <?php if ($rSettings["disable_rtmp_allow_restream"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="case_sensitive_line">Case Sensitive Lines <i
															title="<?= $language::get('case_sensitive_username_and_password') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="case_sensitive_line"
															id="case_sensitive_line" type="checkbox" <?php if ($rSettings["case_sensitive_line"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label" for="county_override_1st">Override
														Country with First <i title="<?= $language::get('override_country_with_first_connected') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="county_override_1st"
															id="county_override_1st" type="checkbox" <?php if ($rSettings["county_override_1st"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="encrypt_hls">Encrypt HLS Segments <i
															title="<?= $language::get('encrypt_all_hls_streams_with_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="encrypt_hls" id="encrypt_hls"
															type="checkbox" <?php if ($rSettings["encrypt_hls"] == 1)  echo ' checked '; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label"
														for="disallow_empty_user_agents">Disallow Empty UA <i
															title="<?= $language::get('don_t_allow_connections_from_clients_with_no_user_agent') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="disallow_empty_user_agents"
															id="disallow_empty_user_agents" type="checkbox" <?php if ($rSettings["disallow_empty_user_agents"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="vod_bitrate_plus">VOD Bitrate Buffer <i title="<?= $language::get('additional_buffer_when_streaming_vod') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text" class="form-control text-center" id="vod_bitrate_plus" name="vod_bitrate_plus" value="<?php echo htmlspecialchars($rSettings["vod_bitrate_plus"] ?? ''); ?>"></div><label class="col-md-4 col-form-label" for="vod_limit_perc">VOD Limit At % <i title="<?= $language::get('limit_vod_after_x_has_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text" class="form-control text-center" id="vod_limit_perc" name="vod_limit_perc" value="<?php echo htmlspecialchars($rSettings["vod_limit_perc"] ?? ''); ?>"></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="user_auto_kick_hours">Auto-Kick Hours <i title="<?= $language::get('automatically_kick_connections_that_are_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text" class="form-control text-center" id="user_auto_kick_hours" name="user_auto_kick_hours" value="<?php echo htmlspecialchars($rSettings["user_auto_kick_hours"] ?? ''); ?>"></div><label class="col-md-4 col-form-label" for="use_mdomain_in_lists">Use Domain Name in API <i title="<?= $language::get('use_domain_name_in_lists') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="use_mdomain_in_lists" id="use_mdomain_in_lists" type="checkbox" <?php if ($rSettings["use_mdomain_in_lists"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="encrypt_playlist">Encrypt Playlists (Not worked) <i title="<?= $language::get('encrypt_line_credentials_in_playlist_files') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="encrypt_playlist" id="encrypt_playlist" type="checkbox" <?php if ($rSettings["encrypt_playlist"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label class="col-md-4 col-form-label" for="encrypt_playlist_restreamer">Encrypt Restreamer Playlists <i title="<?= $language::get('encrypt_line_credentials_in_restreamer_playlist_files') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="encrypt_playlist_restreamer" id="encrypt_playlist_restreamer" type="checkbox" <?php if ($rSettings["encrypt_playlist_restreamer"] == 1) echo ' checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="restrict_playlists">Restrictions on Playlists & EPG <i title="<?= $language::get('verify_useragent_ip_restrictions_isp_tooltip') ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="restrict_playlists" id="restrict_playlists" type="checkbox" <?php if ($rSettings["restrict_playlists"] == 1) echo ' checked'; ?> data-plugin=" switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="ignore_invalid_users">Ignore
														Invalid Credentials <i
															title="<?= $language::get('enabling_this_option_will_make_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="ignore_invalid_users" id="ignore_invalid_users"
															type="checkbox" <?php if ($rSettings["ignore_invalid_users"] == 1) {
																				echo ' checked ';
																			}
																			echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="client_prebuffer">Client Prebuffer <i title="' . $language::get('how_much_data_in_seconds_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="client_prebuffer" name="client_prebuffer" value="';
																			echo htmlspecialchars($rSettings["client_prebuffer"] ?? '');
																			echo '"></div><label class="col-md-4 col-form-label" for="restreamer_prebuffer">Restreamer Prebuffer <i title="' . $language::get('how_much_data_in_seconds_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="restreamer_prebuffer" name="restreamer_prebuffer" value="';
																			echo htmlspecialchars($rSettings["restreamer_prebuffer"] ?? '');
																			echo '"></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="split_by">Load Balancing <i title="' . $language::get('preferred_method_of_load_balancing_connections') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="split_by" id="split_by" class="form-control" data-toggle="select2"><option';
																			if ($rSettings["split_by"] == "conn") {
																				echo ' selected';
																			} ?>' . $language::get('valueconnconnections') . '</option>
														<option <?php if ($rSettings["split_by"] == "maxclients") {
																	echo ' selected';
																} ?><?= $language::get('valuemaxclientsmax_clients') ?></option>
														<option <?php if ($rSettings["split_by"] != "guar_band") {
																} else {
																	echo ' selected';
																} ?><?= $language::get('valueguar_band_network_speed') ?></option>
														<option <?php if ($rSettings["split_by"] != "band") {
																} else {
																	echo ' selected';
																} ?><?= $language::get('valuebanddetected_network_speed') ?></option>
														</select>
													</div>
													<label class="col-md-4 col-form-label"
														for="restreamer_bypass_proxy">Restreamer Bypass Proxy <i
															title="<?= $language::get('route_restreamers_directly_to_load_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="restreamer_bypass_proxy" id="restreamer_bypass_proxy"
															type="checkbox" <?php if ($rSettings["restreamer_bypass_proxy"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="channel_number_type">Channel
														Sorting Type <i
															title="<?= $language::get('preferred_method_of_channel_sorting_in_playlists_and_apps') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<select name="channel_number_type" id="channel_number_type"
															class="form-control" data-toggle="select2">
															<option <?php if ($rSettings["channel_number_type"] != "bouquet_new") {
																	} else {
																		echo ' selected';
																	} ?><?= $language::get('valuebouquet_newbouquet') ?></option>
															<option <?php if ($rSettings["channel_number_type"] != "bouquet") {
																	} else {
																		echo ' selected';
																	} ?><?= $language::get('valuebouquetlegacy') ?></option>
															<option <? if ($rSettings["channel_number_type"] != "manual") {
																	} else {
																		echo ' selected';
																	}
																	echo ' value="manual"><?= $language::get('manual') ?></option></select></div><label class="col-md-4 col-form-label" for="vod_sort_newest">Sort VOD by Date <i title="' . $language::get('change_default_sorting_for_vod_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="vod_sort_newest" id="vod_sort_newest" type="checkbox"';
																	if ($rSettings["vod_sort_newest"] == 1) {
																		echo ' checked ';
																	}
																	echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="use_buffer">Use Nginx Buffer <i title="' . $language::get('sets_the_proxy_buffering_for_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="use_buffer" id="use_buffer" type="checkbox"';
																	if ($rSettings["use_buffer"] == 1) {
																		echo ' checked ';
																	} ?>
																data-plugin="switchery" class="js-switch"
																data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="show_isps">Log Client
														ISP's
														<i title="<?= $language::get('grab_isp_information_for_each_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="show_isps" id="show_isps" type="checkbox" <?php if ($rSettings["show_isps"] == 1) {
																													echo ' checked ';
																												} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="online_capacity_interval">Online Capacity Interval <i
															title="<?= $language::get('interval_at_which_to_check_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="online_capacity_interval" name="online_capacity_interval"
															value="<?= htmlspecialchars($rSettings["online_capacity_interval"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label"
														for="monitor_connection_status">Monitor Connection Status <i
															title="<?= $language::get('monitor_phps_connectionstatus_return_while_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="monitor_connection_status"
															id="monitor_connection_status" type="checkbox" <?php if ($rSettings["monitor_connection_status"] == 1) {
																												echo ' checked ';
																											} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="restart_php_fpm">Auto-Restart Crashed PHP-FPM <i
															title="<?= $language::get('run_a_cron_that_restarts_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="restart_php_fpm" id="restart_php_fpm" type="checkbox"
															<?php if ($rSettings["restart_php_fpm"] == 1) {
																echo ' checked ';
															} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="kill_rogue_ffmpeg">Kill
														Rogue FFMPEG PID's <i
															title="<?= $language::get('when_enabled_ffmpeg_pids_will_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="kill_rogue_ffmpeg" id="kill_rogue_ffmpeg"
															type="checkbox" <?php if ($rSettings["kill_rogue_ffmpeg"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="create_expiration">Redirect
														Expiration <i
															title="<?= $language::get('how_long_in_seconds_before_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="create_expiration" name="create_expiration"
															value="<?= htmlspecialchars($rSettings["create_expiration"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="read_native_hls">HLS
														Read
														Native <i
															title="<?= $language::get('force_read_native_on_for_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="read_native_hls" id="read_native_hls" type="checkbox"
															<?php if ($rSettings["read_native_hls"] == 1) {
																echo ' checked ';
															} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="read_buffer_size">Read Buffer Size <i
															title="<?= $language::get('amount_of_buffer_to_use_when_reading_files_in_chunks') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="read_buffer_size" name="read_buffer_size"
															value="<?= htmlspecialchars($rSettings["read_buffer_size"] ?? ''); ?>">
													</div>
													<label class="col-md-4 col-form-label" for="connection_sync_timer">Redis Connection Sync Timer <i
															title="<?= $language::get('time_between_runs_of_the_redis_connection_sync_script') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="connection_sync_timer" name="connection_sync_timer" value="<?= htmlspecialchars($rSettings["connection_sync_timer"] ?? ''); ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="allow_cdn_access">Allow CDN / Forwarding <i
															title="<?= $language::get('allow_xforwardedfor_to_forward_the_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="allow_cdn_access" id="allow_cdn_access" type="checkbox"
															<?php if ($rSettings["allow_cdn_access"] == 1) {
																echo ' checked ';
															}
															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="stop_failures">Max Failures <i title="' . $language::get('how_many_failures_before_exiting_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="stop_failures" name="stop_failures" value="';
															echo htmlspecialchars($rSettings["stop_failures"] ?? '');
															echo '"></div>    </div>    <h5 class="card-title mb-4">' . $language::get('on_demand_settings') . '</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="on_demand_instant_off">Instant Off <i title="' . $language::get('when_a_client_disconnects_from_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="on_demand_instant_off" id="on_demand_instant_off" type="checkbox"';
															if ($rSettings["on_demand_instant_off"] == 1) {
																echo ' checked ';
															} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="on_demand_failure_exit">Exit
														on Failure <i
															title="<?= $language::get('if_an_ondemand_stream_fails_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="on_demand_failure_exit" id="on_demand_failure_exit"
															type="checkbox" <?php if ($rSettings["on_demand_failure_exit"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="on_demand_wait_time">Wait
														Timeout <i
															title="<?= $language::get('how_long_should_the_client_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="on_demand_wait_time" name="on_demand_wait_time"
															value="<?= htmlspecialchars($rSettings["on_demand_wait_time"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="request_prebuffer">Request
														Prebuffer <i
															title="<?= $language::get('when_you_request_a_stream_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="request_prebuffer" id="request_prebuffer"
															type="checkbox" <? if ($rSettings["request_prebuffer"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="ondemand_balance_equal">Balance As Live <i
															title="<?= $language::get('treat_ondemand_servers_equal_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="ondemand_balance_equal" id="ondemand_balance_equal"
															type="checkbox" <?php if ($rSettings["ondemand_balance_equal"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('ondemand_scanner') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="on_demand_checker">Enable
														Scanner <i
															title="<?= $language::get('periodically_probe_ondemand_streams_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="on_demand_checker" id="on_demand_checker"
															type="checkbox" <?php if ($rSettings["on_demand_checker"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="on_demand_scan_time">Scan
														Time <i title="<?= $language::get('how_often_to_scan_a_stream_in_seconds') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="on_demand_scan_time" name="on_demand_scan_time"
															value="<?= htmlspecialchars($rSettings["on_demand_scan_time"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="on_demand_max_probe">Max
														Probe Time <i
															title="<?= $language::get('how_many_seconds_to_probe_the_stream_for_before_cancelling') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="on_demand_max_probe" name="on_demand_max_probe"
															value="<?= htmlspecialchars($rSettings["on_demand_max_probe"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="on_demand_scan_keep">Keep
														Logs For <i
															title="<?= $language::get('how_many_seconds_to_keep_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="on_demand_scan_keep" name="on_demand_scan_keep"
															value="<?= htmlspecialchars($rSettings["on_demand_scan_keep"] ?? '') ?>">
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('encoding_queue_settings') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="max_encode_movies">Max
														Movie
														Encodes <i
															title="<?= $language::get('maximum_number_of_movies_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="max_encode_movies" name="max_encode_movies"
															value="<?= htmlspecialchars($rSettings["max_encode_movies"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="max_encode_cc">Max
														Channel
														Encodes <i
															title="<?= $language::get('maximum_number_of_created_channels_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="max_encode_cc" name="max_encode_cc"
															value="<?= htmlspecialchars($rSettings["max_encode_cc"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="queue_loop">Queue Loop
														Timer
														<i title="<?= $language::get('how_long_to_wait_between_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="queue_loop"
															name="queue_loop"
															value="<?= htmlspecialchars($rSettings["queue_loop"] ?? '') ?>">
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('segment_settings') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="seg_time">Segment
														Duration
														<i title="<?= $language::get('duration_of_individual_segments_when_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="seg_time"
															name="seg_time"
															value="<?= htmlspecialchars($rSettings["seg_time"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="seg_list_size">List Size
														<i title="<?= $language::get('number_of_segments_in_the_hls_playlist') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="seg_list_size" name="seg_list_size"
															value="<?= htmlspecialchars($rSettings["seg_list_size"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="seg_delete_threshold">Delete
														Threshold <i
															title="<?= $language::get('how_many_old_segments_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="seg_delete_threshold" name="seg_delete_threshold"
															value="<?= htmlspecialchars($rSettings["seg_delete_threshold"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="segment_wait_time">Max
														Segment Wait Time <i
															title="<?= $language::get('maximum_amount_of_seconds_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="segment_wait_time" name="segment_wait_time" value="<?= htmlspecialchars($rSettings["segment_wait_time"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="stream_max_analyze">Analysis
														Duration <i
															title="<?= $language::get('how_long_to_analyse_a_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="stream_max_analyze" name="stream_max_analyze"
															value="<?= htmlspecialchars($rSettings["stream_max_analyze"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label" for="probesize">Probe Size <i
															title="<?= $language::get('amount_of_data_to_be_probed_in_bytes') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="probesize"
															name="probesize"
															value="<?= htmlspecialchars($rSettings["probesize"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="ffmpeg_cpu">FFMPEG
														Version
														<i title="<?= $language::get('which_version_of_ffmpeg_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<select name="ffmpeg_cpu" id="ffmpeg_cpu" class="form-control"
															data-toggle="select2">
															<?php
															foreach (["8.0", "7.1", "4.0"] as $rValue) {
																echo '<option ';

																if ($rSettings["ffmpeg_cpu"] == $rValue) {
																	echo 'selected ';
																}

																echo 'value="';
																echo $rValue;
																echo '">v';
																echo $rValue;
																echo '</option>';
															}
															echo '</select></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="ffmpeg_warnings">FFMPEG Show Warnings <i title="' . $language::get('instruct_ffmpeg_to_save_warnings_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="ffmpeg_warnings" id="ffmpeg_warnings" type="checkbox"';

															if ($rSettings["ffmpeg_warnings"] == 1) {
																echo ' checked ';
															}

															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="ignore_keyframes">Ignore Keyframes <i title="' . $language::get('allow_segments_to_start_on_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="ignore_keyframes" id="ignore_keyframes" type="checkbox"';

															if ($rSettings["ignore_keyframes"] == 1) {
																echo ' checked ';
															}

															echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dts_legacy_ffmpeg">DTS - Use FFMPEG v4.0 <i title="' . $language::get('automatically_switch_to_legacy_ffmpeg_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dts_legacy_ffmpeg" id="dts_legacy_ffmpeg" type="checkbox"';

															if ($rSettings["dts_legacy_ffmpeg"] == 1) {
																echo ' checked ';
															}
															?> data-plugin="switchery" class="js-switch" data-color="#039cfd"/>
													</div>
													<label class="col-md-4 col-form-label" for="php_loopback">Loopback
														Streams via PHP <i
															title="<?= $language::get('dont_use_ffmpeg_to_handle_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="php_loopback" id="php_loopback" type="checkbox" <?php if ($rSettings["php_loopback"] == 1) {
																															echo ' checked ';
																														} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('stream_monitor_settings') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="audio_restart_loss">Restart on Audio Loss <i
															title="<?= $language::get('restart_stream_periodically_if_no_audio_is_detected') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="audio_restart_loss"
															id="audio_restart_loss" type="checkbox" <?
																									if ($rSettings["audio_restart_loss"] == 1) {
																										echo ' checked ';
																									} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div><label
														class="col-md-4 col-form-label" for="priority_backup">Priority
														Backup <i
															title="<?= $language::get('switch_back_to_the_first_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="priority_backup" id="priority_backup"
															type="checkbox" <? if ($rSettings["priority_backup"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="probe_extra_wait">Probe Duration <i
															title="<?= $language::get('how_long_to_wait_after_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text"
															class="form-control text-center" id="probe_extra_wait"
															name="probe_extra_wait" value="<?= htmlspecialchars($rSettings["probe_extra_wait"] ?? ''); ?>">
													</div><label class=" col-md-4 col-form-label"
														for="stream_fail_sleep">Stream Failure Sleep <i
															title="<?= $language::get('how_long_to_wait_in_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text"
															class="form-control text-center" id="stream_fail_sleep"
															name="stream_fail_sleep" value="<?= htmlspecialchars($rSettings["stream_fail_sleep"] ?? '') ?>"></div>
												</div>
												<div class=" form-group row
														mb-4"><label class="col-md-4 col-form-label" for="fps_delay">FPS
														Start Delay <i
															title="<?= $language::get('how_long_in_seconds_to_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text"
															class="form-control text-center" id="fps_delay" name="fps_delay"
															value="<?= htmlspecialchars($rSettings["fps_delay"] ?? '') ?>"></div><label class=" col-md-4 col-form-label"
														for="fps_check_type">FPS Check Type <i
															title="<?= $language::get('whether_to_use_progress_info_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><select name="fps_check_type" id="fps_check_type"
															class="form-control" data-toggle="select2">

															<?php foreach (["Progress Info", "avg_frame_rate"] as $rValue => $rText) {
																echo '
																<option ';

																if ($rSettings["fps_check_type"] != $rValue) {
																} else {
																	echo 'selected ';
																}

															?> value="<?= $rValue ?>"><?= $rText ?></option><?php
																										}
																											?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="api_probe">Probe
														via API <i title="<?= $language::get('use_api_calls_to_probe_sources_from_xc_vm_servers') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="api_probe" id="api_probe" type="checkbox" <?php
																												if ($rSettings["api_probe"] == 1) {
																													echo ' checked ';
																												}
																												?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<h5 class="card-title mb-4"><?= $language::get('off_air_videos') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="show_not_on_air_video">Stream Down Video <i
															title="<?= $language::get('show_this_video_when_a_stream_isnt_on_air') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="show_not_on_air_video"
															id="show_not_on_air_video" type="checkbox" <?php
																										if ($rSettings["show_not_on_air_video"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="not_on_air_video_path" name="not_on_air_video_path" value=" ';
																										echo htmlspecialchars($rSettings["not_on_air_video_path"] ?? '');
																										echo '" placeholder="' . $language::get('leave_blank_to_use_default_xc_vm_video') . '"></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_banned_video">Banned Video <i title="' . $language::get('show_this_video_when_a_banned_line_accesses_a_stream') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_banned_video" id="show_banned_video" type="checkbox"';

																										if ($rSettings["show_banned_video"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="banned_video_path" name="banned_video_path" value=" ';
																										echo htmlspecialchars($rSettings["banned_video_path"] ?? '');
																										echo '" placeholder="' . $language::get('leave_blank_to_use_default_xc_vm_video') . '"></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_expired_video">Expired Video <i title="' . $language::get('show_this_video_when_an_expired_line_accesses_a_stream') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_expired_video" id="show_expired_video" type="checkbox"';

																										if ($rSettings["show_expired_video"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="expired_video_path" name="expired_video_path" value=" ';
																										echo htmlspecialchars($rSettings["expired_video_path"] ?? '');
																										echo '" placeholder="' . $language::get('leave_blank_to_use_default_xc_vm_video') . '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_expiring_video">Expiring Video <i title="' . $language::get('show_this_video_once_per_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_expiring_video" id="show_expiring_video" type="checkbox"';

																										if ($rSettings["show_expiring_video"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="expiring_video_path" name="expiring_video_path" value=" ';
																										echo htmlspecialchars($rSettings["expiring_video_path"] ?? '');
																										echo '" placeholder="' . $language::get('leave_blank_to_use_default_xc_vm_video') . '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_connected_video">2nd IP Connected Video <i title="' . $language::get('show_this_video_when_a_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_connected_video" id="show_connected_video" type="checkbox"';

																										if ($rSettings["show_connected_video"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="connected_video_path" name="connected_video_path" value=" ';
																										echo htmlspecialchars($rSettings["connected_video_path"] ?? '');
																										echo '" placeholder="' . $language::get('leave_blank_to_use_default_xc_vm_video') . '"></div></div>    <h5 class="card-title mb-4">Allowed Countries <i title="' . $language::get('select_individual_countries_to_allow_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></h5>    <div class="form-group row mb-4"><div class="col-md-12">    <select name="allow_countries[]" id="allow_countries" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="' . $language::get('choose_placeholder') . '">';

																										foreach ($rGeoCountries as $rValue => $rText) {
																											echo '<option ';

																											if (in_array($rValue, is_array($rSettings["allow_countries"]) ? $rSettings["allow_countries"] : json_decode($rSettings["allow_countries"], true))) {
																												echo 'selected ';
																											}
																											echo 'value=" ';
																											echo $rValue;
																											echo '">';
																											echo $rText;
																											echo '</option>';
																										}
																										?> </select></div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="mag">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="show_all_category_mag">Show All Categories <i
															title="<?= $language::get('show_all_category_on_mag_devices') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="show_all_category_mag"
															id="show_all_category_mag" type="checkbox" <?php
																										if ($rSettings["show_all_category_mag"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="mag_container">' . $language::get('default_container') . '</label><div class="col-md-2"><select name="mag_container" id="mag_container" class="form-control" data-toggle="select2">';

																										foreach (["ts" => "TS", "m3u8" => "M3U8"] as $rValue => $rText) {
																											echo '<option ';

																											if ($rSettings["mag_container"] != $rValue) {
																											} else {
																												echo 'selected ';
																											}

																											echo 'value=" ';
																											echo $rValue;
																											echo '">';
																											echo $rText;
																											echo '</option>';
																										}
																										echo '</select></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="always_enabled_subtitles">Always Enabled Subtitles <i title="' . $language::get('force_subtitles_to_be_enabled_at_all_times') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="always_enabled_subtitles" id="always_enabled_subtitles" type="checkbox"';

																										if ($rSettings["always_enabled_subtitles"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="enable_connection_problem_indication">' . $language::get('connection_problem_indiciation') . '</label><div class="col-md-2"><input name="enable_connection_problem_indication" id="enable_connection_problem_indication" type="checkbox"';

																										if ($rSettings["enable_connection_problem_indication"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_tv_channel_logo">' . $language::get('show_channel_logos') . '</label><div class="col-md-2"><input name="show_tv_channel_logo" id="show_tv_channel_logo" type="checkbox"';

																										if ($rSettings["show_tv_channel_logo"] == 1) {
																											echo ' checked ';
																										}

																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="show_channel_logo_in_preview">' . $language::get('show_preview_channel_logos') . '</label><div class="col-md-2"><input name="show_channel_logo_in_preview" id="show_channel_logo_in_preview" type="checkbox"';

																										if ($rSettings["show_channel_logo_in_preview"] == 1) {
																											echo ' checked ';
																										}
																										?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="playback_limit">Playback
														Limit <i
															title="<?= $language::get('show_warning_message_and_stop_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input type="text"
															class="form-control text-center" id="playback_limit"
															name="playback_limit"
															value="<?= htmlspecialchars($rSettings["playback_limit"] ?? '') ?>">
													</div>
													<label class="col-md-4 col-form-label"
														for="tv_channel_default_aspect">Default Aspect Ratio <i
															title="<?= $language::get('set_the_default_aspect_ratio_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-2"><select name="tv_channel_default_aspect"
															id="tv_channel_default_aspect" class="form-control"
															data-toggle="select2"><?php
																					foreach (["fit", "big", "opt", "exp", "cmb"] as $rValue) {
																						echo '<option ';
																						if ($rSettings["tv_channel_default_aspect"] == $rValue) {
																							echo 'selected ';
																						}

																						echo 'value=" ';
																						echo $rValue;
																						echo '">';
																						echo $rValue;
																						echo '</option>';
																					}
																					?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="mag_default_type">Default Theme Type <i
															title="<?= $language::get('whether_to_use_modern_or_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><select name="mag_default_type"
															id="mag_default_type" class="form-control"
															data-toggle="select2">
															<?php
															foreach (["Modern", "Legacy"] as $rValue => $rText) {
																echo '<option ';

																if ($rSettings["mag_default_type"] != $rValue) {
																} else {
																	echo 'selected ';
																}

																echo 'value=" ';
																echo $rValue;
																echo '">';
																echo $rText;
																echo '</option>';
															}
															?> </select></div>
													<label class="col-md-4 col-form-label" for="stalker_theme">Legacy
														Theme <i title="<?= $language::get('default_ministra_theme_to_be_used_by_mag_devices') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><select name="stalker_theme" id="stalker_theme"
															class="form-control" data-toggle="select2">
															<?php
															foreach (["default" => "Default", "digital" => "Digital", "emerald" => "Emerald", "cappucino" => "Cappucino", "ocean_blue" => "Ocean Blue",] as $rValue => $rText) {
																echo '<option ';

																if ($rSettings["stalker_theme"] != $rValue) {
																} else {
																	echo 'selected ';
																}

																echo 'value=" ';
																echo $rValue;
																echo '">';
																echo $rText;
																echo '</option>';
															}
															?>
														</select></div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="mag_legacy_redirect">Legacy
														URL Redirect <i
															title="<?= $language::get('redirect_c_to_ministra_folder_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="mag_legacy_redirect"
															id="mag_legacy_redirect" type="checkbox" <?php
																										if ($rSettings["mag_legacy_redirect"] == 1) {
																											echo ' checked ';
																										}
																										?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="mag_keep_extension">Keep
														URL Extension <i
															title="<?= $language::get('keep_extension_of_live_streams_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="mag_keep_extension"
															id="mag_keep_extension" type="checkbox" <?php
																									if ($rSettings["mag_keep_extension"] == 1) {
																										echo ' checked ';
																									}
																									?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="mag_disable_ssl">Disable
														SSL <i
															title="<?= $language::get('force_mag_s_to_use_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="mag_disable_ssl" id="mag_disable_ssl"
															type="checkbox" <?php
																			if ($rSettings["mag_disable_ssl"] == 1) {
																				echo ' checked ';
																			}
																			?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
													<label class="col-md-4 col-form-label" for="mag_load_all_channels">Load
														Channels on Startup <i
															title="<?= $language::get('load_all_channel_listings_on_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="mag_load_all_channels"
															id="mag_load_all_channels" type="checkbox" <?php
																										if ($rSettings["mag_load_all_channels"] == 1) {
																											echo ' checked ';
																										}
																										?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="disable_mag_token">Disable
														MAG Token <i
															title="<?= $language::get('disable_verification_of_mag_token_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2"><input name="disable_mag_token"
															id="disable_mag_token" type="checkbox" <?php
																									if ($rSettings["disable_mag_token"] == 1) {
																										echo ' checked ';
																									}
																									?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" /></div>
												</div>
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="allowed_stb_types">Allowed
														STB Types</label>
													<div class="col-md-8"><select name="allowed_stb_types[]"
															id="allowed_stb_types" class="form-control select2-multiple"
															data-toggle="select2" multiple="multiple"
															data-placeholder="<?= $language::get('choose_placeholder') ?>">
															<?php
															$rAllowedSTB = is_array($rSettings["allowed_stb_types"]) ? $rSettings["allowed_stb_types"] : json_decode($rSettings["allowed_stb_types"], true);
															foreach ($rAllowedSTB as $rMAG) {
																echo '        <option selected value=" ';
																echo $rMAG;
																echo '">';
																echo $rMAG;
																echo '</option>        ';
															}

															foreach (array_udiff($rMAGs, $rAllowedSTB, "strcasecmp") as $rMAG) {
																echo '<option value=" ';
																echo $rMAG;
																echo '">';
																echo $rMAG;
																echo '</option>';
															}
															echo '</select></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="allowed_stb_types_for_local_recording">' . $language::get('allowed_stb_recording') . '</label><div class="col-md-8"><select name="allowed_stb_types_for_local_recording[]" id="allowed_stb_types_for_local_recording" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="' . $language::get('choose_placeholder') . '">        ';

															foreach (json_decode($rSettings["allowed_stb_types_for_local_recording"], true) as $rMAG) {
																echo '        <option selected value=" ';
																echo $rMAG;
																echo '">';
																echo $rMAG;
																echo '</option>        ';
															}

															foreach (array_udiff($rMAGs, json_decode($rSettings["allowed_stb_types_for_local_recording"], true), "strcasecmp") as $rMAG) {
																echo '<option value=" ';
																echo $rMAG;
																echo '">';
																echo $rMAG;
																echo '</option>';
															}
															?>
														</select></div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="test_download_url">Speedtest
														URL <i
															title="<?= $language::get('url_to_a_file_to_download_during_speedtest_on_mag_devices') ?>"
															class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-8"><input type="text" class="form-control"
															id="test_download_url" name="test_download_url"
															value="<?= htmlspecialchars($rSettings["test_download_url"] ?? '') ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="mag_message">Information
														Message <i
															title="<?= $language::get('message_to_display_when_a_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-8">
														<textarea rows="6" class="form-control" id="mag_message"
															name="mag_message">
															<?= htmlspecialchars(str_replace(["&lt;", "&gt;"], ["
																		<", ">"], $rSettings["mag_message"])) ?> </textarea>
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="webplayer">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="player_allow_playlist">Allow
														Playlist Download <i
															title="<?= $language::get('allow_clients_to_generate_playlist_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="player_allow_playlist" id="player_allow_playlist"
															type="checkbox" <?php if ($rSettings["player_allow_playlist"] == 1) {
																				echo ' checked ';
																			}
																			echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="player_allow_bouquet">Allow Bouquet Ordering <i title="' . $language::get('allow_clients_to_reorder_their_bouquets_from_the_web_player') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="player_allow_bouquet" id="player_allow_bouquet" type="checkbox"';
																			if ($rSettings["player_allow_bouquet"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label"
														for="player_hide_incompatible">Hide Incompatible Streams
														<i title="<?= $language::get('hide_streams_that_arent_compatible_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="player_hide_incompatible" id="player_hide_incompatible"
															type="checkbox" <?php if ($rSettings["player_hide_incompatible"] == 1) {
																				echo ' checked ';
																			}
																			echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="player_allow_hevc">Mark HEVC as Compatible <i title="' . $language::get('mark_hevc_as_compatible_there_tooltip') . '" class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="player_allow_hevc" id="player_allow_hevc" type="checkbox"';
																			if ($rSettings["player_allow_hevc"] == 1) {
																				echo ' checked ';
																			} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="player_blur">Background
														Blur
														px <i title="<?= $language::get('blur_the_background_images_by_x_pixels') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center" id="player_blur"
															name="player_blur"
															value="<?= intval($rSettings["player_blur"]) ?>">
													</div>
													<label class="col-md-4 col-form-label" for="player_opacity">Background
														Opacity % <i
															title="<?= $language::get('adjust_the_background_image_opacity_default_is_10') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input type="text" class="form-control text-center"
															id="player_opacity" name="player_opacity" value="<?= intval($rSettings["player_opacity"]) ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="extract_subtitles">Extract
														Subtitles <i
															title="<?= $language::get('automatically_extract_subtitles_from_movies_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="extract_subtitles" id="extract_subtitles"
															type="checkbox" <?php if ($rSettings["extract_subtitles"] == 1) {
																				echo ' checked ';
																			}
																			?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="logs">
										<div class="row">
											<div class="col-12">
												<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label"
														for="save_closed_connection">Activity Logs <i
															title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-3"><input name="save_closed_connection"
															id="save_closed_connection" type="checkbox" <?php if ($rSettings["save_closed_connection"] == 1) {
																											echo ' checked ';
																										}
																										?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="keep_activity">Keep Logs
														For</label>
													<div class="col-md-3"><select name="keep_activity" id="keep_activity"
															class="form-control" data-toggle="select2">
															<?php
															foreach (["Forever", 3600 => "1 Hour", 21600 => "6 Hours", 43200 => "12 Hours", 86400 => "1 Day", 259200 => "3 Days", 604800 => "7 Days", 1209600 => "14 Days", 16934400 => "28 Days", 15552000 => "180 Days", 31536000 => "365 Days",] as $rValue => $rText) {
																echo '<option ';

																if ($rSettings["keep_activity"] == $rValue) {
																	echo 'selected ';
																}

															?> value="<?= $rValue ?>"><?= $rText ?>
																</option>
															<?php
															}
															?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="client_logs_save">Client
														Logs <i
															title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-3">
														<input name="client_logs_save" id="client_logs_save" type="checkbox"
															<?php
															if ($rSettings["client_logs_save"] == 1) {
																echo ' checked ';
															}
															?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="keep_client">Keep Logs
														For</label>
													<div class="col-md-3">
														<select name="keep_client" id="keep_client" class="form-control"
															data-toggle="select2">
															<?php
															foreach (["Forever", 3600 => "1 Hour", 21600 => "6 Hours", 43200 => "12 Hours", 86400 => "1 Day", 259200 => "3 Days", 604800 => "7 Days", 1209600 => "14 Days", 16934400 => "28 Days", 15552000 => "180 Days", 31536000 => "365 Days",] as $rValue => $rText) {
																echo '
																		<option ';

																if ($rSettings["keep_client"] != $rValue) {
																} else {
																	echo 'selected ';
																}

															?> value="<?= $rValue ?>"><?= $rText ?>
																</option>
															<?php
															} ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="save_login_logs">Login Logs
														<i title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-3">
														<input name="save_login_logs" id="save_login_logs" type="checkbox"
															<?php
															if ($rSettings["save_login_logs"] == 1) {
																echo ' checked ';
															}
															?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="keep_login">Keep Logs
														For</label>
													<div class="col-md-3">
														<select name="keep_login" id="keep_login" class="form-control"
															data-toggle="select2">
															<?php
															foreach (["Forever", 3600 => "1 Hour", 21600 => "6 Hours", 43200 => "12 Hours", 86400 => "1 Day", 259200 => "3 Days", 604800 => "7 Days", 1209600 => "14 Days", 16934400 => "28 Days", 15552000 => "180 Days", 31536000 => "365 Days",] as $rValue => $rText) {
																echo '
																		<option ';

																if ($rSettings["keep_login"] != $rValue) {
																} else {
																	echo 'selected ';
																}

															?> value="<?= $rValue ?>"><?= $rText ?>
																</option><?php
																		} ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="stream_logs_save">Stream
														Error Logs <i
															title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-3">
														<input name="stream_logs_save" id="stream_logs_save" type="checkbox"
															<?php
															if ($rSettings["stream_logs_save"] == 1) {
																echo ' checked ';
															}
															?> data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="keep_errors">Keep Logs
														For</label>
													<div class="col-md-3">
														<select name="keep_errors" id="keep_errors" class="form-control"
															data-toggle="select2">
															<?php
															foreach (["Forever", 3600 => "1 Hour", 21600 => "6 Hours", 43200 => "12 Hours", 86400 => "1 Day", 259200 => "3 Days", 604800 => "7 Days", 1209600 => "14 Days", 16934400 => "28 Days", 15552000 => "180 Days", 31536000 => "365 Days",] as $rValue => $rText) {
																echo '
																		<option ';

																if ($rSettings["keep_errors"] == $rValue) {
																	echo 'selected ';
																}
															?> value="<?= $rValue ?>"><?= $rText ?>
																</option>
															<?php
															} ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-3 col-form-label" for="save_restart_logs">Stream
														Restart Logs <i
															title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-3">
														<input name="save_restart_logs" id="save_restart_logs"
															type="checkbox" <?php if ($rSettings["save_restart_logs"] == 1) {
																				echo ' checked ';
																			} ?> data-plugin="switchery"
															class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-3 col-form-label" for="keep_restarts">Keep
														Logs For</label>
													<div class="col-md-3"><select name="keep_restarts" id="keep_restarts"
															class="form-control" data-toggle="select2">
															<?php
															foreach (["Forever", 3600 => "1 Hour", 21600 => "6 Hours", 43200 => "12 Hours", 86400 => "1 Day", 259200 => "3 Days", 604800 => "7 Days", 1209600 => "14 Days", 16934400 => "28 Days", 15552000 => "180 Days", 31536000 => "365 Days",] as $rValue => $rText) {
																echo '<option ';

																if ($rSettings["keep_restarts"] == $rValue) {
																	echo 'selected ';
																}
															?> value="<?= $rValue; ?>"><?= $rText ?></option>
															<?php
															}
															?>
														</select>
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="tab-pane" id="info">
										<div class="row">
											<div class="col-12">
												<h4 class="card-title mb-4"><?= $language::get('versions') ?></h4>
												<table class="table table-striped table-bordered">
													<tbody>
														<tr>
															<td class="text-center" style="font-size: 0.85rem;">Geolite2 Version</td>
															<td class="text-center">
																<button type="button" class="btn btn-pink btn-sm" style="font-size: 0.85rem;"><?= $GeoLite2 ?></button>
															</td>
															<td class="text-center" style="font-size: 0.85rem;">GeoIP2-ISP Version</td>
															<td class="text-center">
																<button type="button" class="btn btn-warning btn-sm" style="font-size: 0.85rem;"><?= $GeoISP ?></button>
															</td>
														</tr>
														<tr>
															<td class="text-center" style="font-size: 0.85rem;">PHP</td>
															<td class="text-center">
																<button type="button" class="btn btn-info btn-sm" style="font-size: 0.85rem;"><?= phpversion() ?></button>
															</td>
															<td class="text-center" style="font-size: 0.85rem;">Nginx</td>
															<td class="text-center">
																<button type="button" class="btn btn-danger btn-sm" style="font-size: 0.85rem;"><?= $Nginx ?></button>
															</td>
														</tr>
													</tbody>
												</table>

												<h4 class="card-title mb-4"><?= $language::get('support_project') ?></h4>
												<table class="table table-striped table-bordered text-center">
													<thead class="thead-light">
														<tr>
															<th><?= $language::get('name') ?></th>
															<th><?= $language::get('address') ?></th>
															<th style="width:90px;"><?= $language::get('qr') ?></th>
															<th style="width:90px;"><?= $language::get('copy') ?></th>
														</tr>
													</thead>
													<tbody>
														<tr>
															<td><i class="fab fa-bitcoin text-warning"></i> Bitcoin (BTC)</td>
															<td class="text-monospace small">1EP3XFHVk1fF3kV6zSg7whZzQdUpVMcAQz</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-primary"
																	data-toggle="modal"
																	data-target="#qrModal"
																	onclick="showQR(this)">
																	<i class="fas fa-qrcode"></i>
																</button>
															</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-success"
																	onclick="copyAddr(this)">
																	<i class="fas fa-copy"></i>
																</button>
															</td>
														</tr>

														<tr>
															<td><i class="fab fa-ethereum text-info"></i> Ethereum (ETH)</td>
															<td class="text-monospace small">0x613411dB8cFbaeaCC3A075EF39F41DFaaab4E1B8</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-primary"
																	onclick="showQR(this)">
																	<i class="fas fa-qrcode"></i>
																</button>
															</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-success"
																	onclick="copyAddr(this)">
																	<i class="fas fa-copy"></i>
																</button>
															</td>
														</tr>

														<tr>
															<td><i class="fas fa-coins text-secondary"></i> Litecoin (LTC)</td>
															<td class="text-monospace small">MFmn43WF2k2bsAQJe8rRmq2sKke95JmqC4</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-primary"
																	onclick="showQR(this)">
																	<i class="fas fa-qrcode"></i>
																</button>
															</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-success"
																	onclick="copyAddr(this)">
																	<i class="fas fa-copy"></i>
																</button>
															</td>
														</tr>

														<tr>
															<td><i class="fas fa-dollar-sign text-success"></i> USDT (ERC-20)</td>
															<td class="text-monospace small">0x034a2263a15Ade8606cC60181f12E5c2f0Ac59C6</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-primary"
																	onclick="showQR(this)">
																	<i class="fas fa-qrcode"></i>
																</button>
															</td>
															<td>
																<button type="button" class="btn btn-sm btn-outline-success"
																	onclick="copyAddr(this)">
																	<i class="fas fa-copy"></i>
																</button>
															</td>
														</tr>
													</tbody>

												</table>
											</div>
										</div>
									</div>
									<?php
									if (Authorization::check("adv", "database") && DEVELOPMENT) { ?>
										<div class="tab-pane" id="database">
											<div class="row">
												<iframe width="100%" height="650px" src="./database.php"
													style="overflow-x:hidden;border:0px;"></iframe>
											</div> <!-- end row -->
										</div>
									<?php
									} ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>
<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin'); ?>
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

	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$("#datatable-backups").css("width", "100%");
		$("#allowed_stb_types").select2({
			width: '100%',
			tags: true
		});
		$("#allowed_stb_types_for_local_recording").select2({
			width: '100%',
			tags: true
		});
		$("#log_clear").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#vod_bitrate_plus").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#vod_limit_perc").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#user_auto_kick_hours").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#flood_limit").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#flood_seconds").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#auth_flood_seconds").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#auth_flood_limit").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#auth_flood_sleep").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#bruteforce_mac_attempts").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#bruteforce_username_attempts").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#bruteforce_frequency").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#login_flood").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#client_prebuffer").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#restreamer_prebuffer").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#read_buffer_size").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#stream_max_analyze").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#probesize").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#stream_start_delay").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#online_capacity_interval").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#on_demand_wait_time").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#seg_time").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#stream_fail_sleep").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#probe_extra_wait").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#seg_list_size").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#cpu_limit").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#mem_limit").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#playback_limit").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#connection_loop_per").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#connection_loop_count").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#max_simultaneous_downloads").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#cache_playlists").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#seg_delete_threshold").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#fails_per_time").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#create_expiration").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#max_encode_movies").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#max_encode_cc").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#queue_loop").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#player_blur").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#player_opacity").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#disallow_2nd_ip_max").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#probesize_ondemand").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#connection_sync_timer").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#segment_wait_time").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#on_demand_scan_time").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#on_demand_max_probe").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#on_demand_scan_keep").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#stop_failures").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#mysql_sleep_kill").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#threshold_cpu").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#threshold_mem").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#threshold_disk").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#threshold_network").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#threshold_clients").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("form").submit(function(e) {
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("form")[0]));
		});
	});

	function showQR(btnEl) {
		// Find the table row
		const row = btnEl.closest('tr');
		// Get the address text from the cell with class text-monospace
		const addrCell = row.querySelector('td.text-monospace');
		if (!addrCell) return;

		const text = addrCell.textContent.trim();

		// Set the address as the QR code image source
		const img = document.getElementById('qrImage');
		img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' +
			encodeURIComponent(text);

		$(".bs-addr-qr-modal-center").modal("show");
	}

	function copyAddr(btnEl) {
		// Find the table row
		const row = btnEl.closest('tr');
		// Find the cell containing the address
		const addrCell = row.querySelector('td.text-monospace');
		if (!addrCell) return;

		const text = addrCell.textContent.trim();

		// Create a temporary input field to copy the text
		const tempInput = document.createElement('input');
		tempInput.value = text;
		document.body.appendChild(tempInput);
		tempInput.select();

		try {
			document.execCommand('copy');

			// Change the icon to a checkmark
			const icon = btnEl.querySelector('i');
			icon.classList.remove('fa-copy');
			icon.classList.add('fa-check', 'text-success');

			// Revert the icon back after 1 second
			setTimeout(() => {
				icon.classList.remove('fa-check', 'text-success');
				icon.classList.add('fa-copy');
			}, 1000);
		} catch (err) {
			console.error('Copy failed:', err);
		}

		document.body.removeChild(tempInput);
	}

	function UpdateServer() {
		$.getJSON("./api?action=server&sub=update&server_id=<?= SERVER_ID ?>", function(data) {
			if (data.result === true) {
				$.toast("Server is updating in the background...");
			} else {
				$.toast("An error occured while processing your request.");
			}
		});
	};
	<?php if (SettingsManager::getAll()['enable_search']): ?>
		$(document).ready(function() {
			initSearch();
		});
	<?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>