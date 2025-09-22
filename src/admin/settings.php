<?php

include "session.php";
include "functions.php";

if (checkPermissions()) {
} else {
	goHome();
}

$rSettings = getSettings();
$rStreamArguments = getStreamArguments();
$_TITLE = "Settings";
include "header.php";
echo '<div class="wrapper boxed-layout-ext"';

if (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) || !strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) != "xmlhttprequest") {
	echo ' style="display: none;">';
}

?>
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
					<h4 class="page-title">Settings</h4>
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
					if (is_array($rUpdate) && $rUpdate["version"] && (0 < version_compare($rUpdate["version"], XC_VM_VERSION) || (version_compare($rUpdate["version"], XC_VM_VERSION) == 0))) {
					?>
						<div class="card-body" style="max-height: 250px;">
							<h5 class="card-title text-white">Update Available</h5>
							<p>Official Release v
								<?php
								echo $rUpdate["version"];
								?> is now available to download. If you're interested, head over to the <a class="text-white"
									href="https://github.com/Vateron-Media/XC_VM/blob/main/doc/en/update.md"><strong>XC_VM Doc</strong></a>
								for instructions on how to update.
							</p>
							<?php
							foreach ($rUpdate["changelog"] as $rItem) {
								echo '<h5 class="card-title text-white mt-4">Changelog - v';
								echo $rItem["version"];
								echo '</h5><ul>';

								foreach ($rItem["changes"] as $rChange) {
									echo '<li>';
									echo $rChange;
									echo '</li>';
								}
								echo '</ul>';
							}
							?>
							<br /><a href="
							<?= str_replace('" ', '"', $rUpdate["url"]) ?> " class="text-white font-weight-semibold text-uppercase">Go
								to
								Release
								Thread
								<i class="mdi mdi-arrow-right"></i></a>
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
											class="d-none d-sm-inline">General</span></a>
								</li>
								<li class="nav-item">
									<a href="#security" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
											class="mdi mdi mdi-shield-lock mr-1"></i><span
											class="d-none d-sm-inline">Security</span></a>
								</li>
								<li class="nav-item">
									<a href="#api" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
											class="mdi mdi-code-tags mr-1"></i><span
											class="d-none d-sm-inline">API</span></a>
								</li>
								<li class="nav-item">
									<a href="#streaming" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
											class="mdi mdi-play mr-1"></i><span
											class="d-none d-sm-inline">Streaming</span></a>
								</li>
								<li class="nav-item">
									<a href="#mag" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
											class="mdi mdi-tablet mr-1"></i><span
											class="d-none d-sm-inline">MAG</span></a>
								</li>
								<li class="nav-item">
									<a href="#webplayer" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
											class="mdi mdi-web mr-1"></i><span class="d-none d-sm-inline">Web
											Player</span></a>
								</li>
								<li class="nav-item">
									<a href="#logs" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
											class="mdi mdi-file-document-outline mr-1"></i><span
											class="d-none d-sm-inline">Logs</span></a>
								</li>
								<?php if (hasPermissions("adv", "database") && DEVELOPMENT) { ?>
									<li class="nav-item">
										<a href="#database" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i
												class="mdi mdi-file-document-outline mr-1"></i><span
												class="d-none d-sm-inline">Database</span></a>
									</li>
								<?php } ?>
							</ul>
							<div class="tab-content b-0 mb-0 pt-0">
								<div class="tab-pane" id="interface">
									<div class="row">
										<div class="col-12">
											<h5 class="card-title mb-4">Preferences</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="server_name">Server Name
													<i title="The name of your streaming service."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="text" class="form-control" id="server_name"
														name="server_name"
														value="<?= htmlspecialchars($rSettings["server_name"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="default_timezone">Server
													Timezone <i
														title="Default timezone for the Admin & Reseller Interface, this will be the default for all users unless they change their profile timezone."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<select name="default_timezone" id="default_timezone"
														class="form-control" data-toggle="select2">
														<?php
														foreach (TimeZoneList() as $rValue) {
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
												</div> <!-- <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="language">Interface Language <i title="Default language for the Admin & Reseller Interface, this will be the default for all users unless they change their profile language." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-8"> <select name="language" id="language" class="form-control" data-toggle="select2">';

														foreach (getLanguages() as $rLanguage) {
															echo '<option';

															if ($rSettings["language"] != $rLanguage["key"]) {
															} else {
																echo ' selected';
															}

															echo ' value="';
															echo $rLanguage["key"];
															echo '">';
															echo $rLanguage["language"];
															echo '</option>';
														}
														echo '</select></div></div> -->
												<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
														for="message_of_day">Message of the Day <i
															title="Message to show in the player API. Used by some android apps."
															class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8"><input type="text" class="form-control"
															id="message_of_day" name="message_of_day" value="';
														echo htmlspecialchars($rSettings["message_of_day"]);
														echo '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="default_entries">Show Entries <i title="Number of table entries to show by default in the Admin & Reseller Interface." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="default_entries" id="default_entries" class="form-control" data-toggle="select2">';
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
														echo '</select></div><label class="col-md-4 col-form-label" for="fails_per_time">Fails Per Time <i title="How long to track stream failures for on Streams view page. Fails per X seconds." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="fails_per_time" name="fails_per_time" value="';
														echo intval($rSettings["fails_per_time"]);
														echo '"></div><!--<label class="col-md-4 col-form-label" for="default_entries">Fingerprint Max <i title="Maximum number of concurrent fingerprint sessions. A higher limit will result in significant CPU usage during fingerprinting. Select 0 for no limit." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="fingerprint_max" id="fingerprint_max" class="form-control" data-toggle="select2">';
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
														echo '</select></div>--></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="date_format">Date Format <i title="Default date format to use. Please look up PHP date formatting before changing this." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="date_format" name="date_format" value="';
														echo htmlspecialchars($rSettings["date_format"]);
														echo '"></div><label class="col-md-4 col-form-label" for="datetime_format">Datetime Format <i title="Default datetime format to use. Please look up PHP date formatting before changing this." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="datetime_format" name="datetime_format" value="';
														echo htmlspecialchars($rSettings["datetime_format"]);
														echo '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="streams_grouped">Group Streams Table <i title="Toggle to group multiple servers per stream into a single row, this will reduce the amount of rows to display." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="streams_grouped" id="streams_grouped" type="checkbox"';
														if ($rSettings["streams_grouped"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="js_navigate">Seamless Navigation <i title="Enable seamless navigation by utilising javascript to load pages. Turned off on mobile devices." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="js_navigate" id="js_navigate" type="checkbox"';
														if ($rSettings["js_navigate"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_tickets">Show Tickets Icon <i title="Show tickets icon in the top right of the navigation menu. Turning this off will move Tickets to the Management menu." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_tickets" id="show_tickets" type="checkbox"';
														if ($rSettings["show_tickets"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="hide_failures">Disable Restart Counter <i title="Removes the restart count next to stream uptime." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="hide_failures" id="hide_failures" type="checkbox"';
														if ($rSettings["hide_failures"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="cleanup">Auto-Cleanup Files <i title="Automatically clean up redundant files in the background. Recommended." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="cleanup" id="cleanup" type="checkbox"';
														if ($rSettings["cleanup"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="check_vod">Check VOD Cron <i title="Check that VOD exists periodically, if not set it to not-encoded. Not recommended if you have a lot of VOD." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="check_vod" id="check_vod" type="checkbox"';
														if ($rSettings["check_vod"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_images">Show Images & Picons <i title="Show channel logos and VOD images in the management pages." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_images" id="show_images" type="checkbox"';
														if ($rSettings["show_images"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="group_buttons">Group Buttons <i title="Group action buttons into a drop-down list on compatible pages." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="group_buttons" id="group_buttons" type="checkbox"';
														if ($rSettings["group_buttons"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="modal_edit">Quick Edit Modal <i title="When clicking Edit, open in a modal without navigating away from the page." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="modal_edit" id="modal_edit" type="checkbox"';
														if ($rSettings["modal_edit"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="mysql_sleep_kill">MySQL Sleep Timeout <i title="How long to allow mysql connections to remain in Sleep before killing them. Set to 0 to disable." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="mysql_sleep_kill" name="mysql_sleep_kill" value="';
														echo intval($rSettings["mysql_sleep_kill"]);
														echo '"></div></div>    <h5 class="card-title mb-4">Dashboard</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dashboard_stats">Show Graphs <i title="Enable dashboard statistic graphs for System Resources, Network and Connections." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_stats" id="dashboard_stats" type="checkbox"';
														if ($rSettings["dashboard_stats"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="dashboard_map">Show Connections Map <i title="Show connection map on the dashboard." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_map" id="dashboard_map" type="checkbox"';
														if ($rSettings["dashboard_map"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dashboard_display_alt">Alternate Server View <i title="Display servers on the dashboard with an alternate layout, wide vs square layout." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_display_alt" id="dashboard_display_alt" type="checkbox"';
														if ($rSettings["dashboard_display_alt"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="header_stats_sh">Show Header Stats <i title="Show server statistics in header menu." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="header_stats" id="header_stats_sh" type="checkbox"';
														if ($rSettings["header_stats"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dashboard_status">Show Service Status <i title="Show warning information based on server stats." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dashboard_status" id="dashboard_status" type="checkbox"';
														if ($rSettings["dashboard_status"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="threshold_cpu">CPU Threshold % <i title="When CPU usage is above this percentage it will show as a warning in the service status box." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_cpu" name="threshold_cpu" value="';
														echo intval($rSettings["threshold_cpu"]);
														echo '"></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="threshold_mem">Memory Threshold % <i title="When memory usage is above this percentage it will show as a warning in the service status box." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_mem" name="threshold_mem" value="';
														echo intval($rSettings["threshold_mem"]);
														echo '"></div><label class="col-md-4 col-form-label" for="threshold_disk">Disk Threshold % <i title="When disk usage is above this percentage it will show as a warning in the service status box." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_disk" name="threshold_disk" value="';
														echo intval($rSettings["threshold_disk"]);
														echo '"></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="threshold_network">Network Threshold % <i title="When network usage is above this percentage it will show as a warning in the service status box." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_network" name="threshold_network" value="';
														echo intval($rSettings["threshold_network"]);
														echo '"></div><label class="col-md-4 col-form-label" for="threshold_clients">Clients Threshold % <i title="When number of clients as a percent of max server clients is above this percentage it will show as a warning in the service status box." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="threshold_clients" name="threshold_clients" value="';
														echo intval($rSettings["threshold_clients"]);
														echo '"></div>    </div><h5 class="card-title mb-4">Search</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="enable_search">Enable Search <i title="Toggle the search box in the top right of the header and allow the cache engine to write search queries to the database." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="enable_search" id="enable_search" type="checkbox"';
														if ($rSettings["enable_search"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="search_items">Number of Items <i title="How many search results to display. Maximum of 100." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="search_items" name="search_items" value="';
														echo intval($rSettings["search_items"]);
														echo '"></div>    </div>    <h5 class="card-title mb-4">Reseller</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="disable_trial">Disable Trials <i title="Use this option to temporarily disable generating trials for all lines." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disable_trial" id="disable_trial" type="checkbox"';
														if ($rSettings["disable_trial"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="reseller_ssl_domain">SSL Custom DNS <i title="Use HTTPS in playlist downloads if the main server has SSL on and the reseller has a custom DNS." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="reseller_ssl_domain" id="reseller_ssl_domain" type="checkbox"';
														if ($rSettings["reseller_ssl_domain"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <h5 class="card-title mb-4">Debug</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="debug_show_errors">Debug Mode <i title="Automatically clean up redundant files in the background. Recommended." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="debug_show_errors" id="debug_show_errors" type="checkbox"';
														if ($rSettings["debug_show_errors"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div> <h5 class="card-title mb-4">reCAPTCHA</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label">Enable reCAPTCHA <i title="Click here to show active domains for your servers and resellers that you should consider adding to reCAPTCHA." class="tooltip text-secondary far fa-circle" data-toggle="modal" data-target=".bs-domains"></i></label><div class="col-md-2"><input name="recaptcha_enable" id="recaptcha_enable" type="checkbox"';
														if ($rSettings["recaptcha_enable"] == 1) {
															echo ' checked ';
														} ?> data-plugin=" switchery" class="js-switch" data-color="#039cfd">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label"
													for="recaptcha_v2_site_key">reCAPTCHA V2 - Site Key <i
														title="Please visit https://google.com/recaptcha/admin to obtain your API keys."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="text" class="form-control" id="recaptcha_v2_site_key"
														name="recaptcha_v2_site_key"
														value="<?= htmlspecialchars($rSettings["recaptcha_v2_site_key"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label"
													for="recaptcha_v2_secret_key">reCAPTCHA V2 - Secret Key <i
														title="Please visit https://google.com/recaptcha/admin to obtain your API keys."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="text" class="form-control" id="recaptcha_v2_secret_key"
														name="recaptcha_v2_secret_key" value="<?= htmlspecialchars($rSettings["recaptcha_v2_secret_key"]) ?>">
												</div>
											</div>
											<h5 class="card-title mb-4">Default Arguments</h5>
											<div class="form-group row mb-4">
												<label class="col-md-3 col-form-label" for="user_agent">User
													Agent</label>
												<div class="col-md-9">
													<input type="text" class="form-control" id="user_agent"
														name="user_agent"
														value="<?= htmlspecialchars($rStreamArguments["user_agent"]["argument_default_value"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-3 col-form-label" for="http_proxy">HTTP Proxy
													<i title="Format: ip:port"
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-9">
													<input type="text" class="form-control" id="http_proxy"
														name="http_proxy"
														value="<?= htmlspecialchars($rStreamArguments["proxy"]["argument_default_value"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-3 col-form-label" for="cookie">Cookie <i
														title="Format: key=value;"
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-9">
													<input type="text" class="form-control" id="cookie" name="cookie"
														value="<?= htmlspecialchars($rStreamArguments["cookie"]["argument_default_value"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-3 col-form-label" for="headers">Headers <i
														title="FFmpeg -headers command."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-9">
													<input type="text" class="form-control" id="headers" name="headers"
														value="<?= htmlspecialchars($rStreamArguments["headers"]["argument_default_value"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-3 col-form-label" for="probesize_ondemand">On Demand Probesize <i
														title="Adjustable probesize for ondemand streams. Adjust this setting if you experience issues with no audio."
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
											<h5 class="card-title mb-4">IP Security</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="ip_subnet_match">Match
													Subnet of IP <i title="Some IP' s change quite often, however usually within the same /24 subnet. Enable this if you
															want to keep the IP security but loosen the IP matching a
															little. An example being IP 159.55.26.0 will verify as being
															the same as 159.55.26.255 instead of failing." class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="ip_subnet_match" id="ip_subnet_match" type="checkbox"
														<?php if ($rSettings["ip_subnet_match"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="ip_logout">Logout On IP Change <i title="Enable to destroy sessions if the IP changes during use, this will safeguard you from cookie attacks." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="ip_logout" id="ip_logout" type="checkbox"';
														if ($rSettings["ip_logout"] == 1) {
															echo ' checked ';
														} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="restrict_same_ip">Restrict
													to Same IP <i
														title="Tie HLS connections to their IP address. Turn this off if you're having issues with dynamic IP's."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="restrict_same_ip" id="restrict_same_ip" type="checkbox"
														<? if ($rSettings["restrict_same_ip"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="rtmp_random">Random RTMP IP <i title="Use a random IP for RMTP connections." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="rtmp_random" id="rtmp_random" type="checkbox"';
														if ($rSettings["rtmp_random"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="disallow_2nd_ip_con">Disallow 2nd IP <i title="Disallow connection from different IP when a connection is in use." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disallow_2nd_ip_con" id="disallow_2nd_ip_con" type="checkbox"';
														if ($rSettings["disallow_2nd_ip_con"] == 1) {
															echo ' checked ';
														} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div><label class="col-md-4 col-form-label"
													for="disallow_2nd_ip_max">Disallow if Connections <= <i
														title="Maximum amount of connections a line can have before Disallow 2nd IP is disabled. If you set this to 3, any line with 3 or less connections will be disconnected if they connect from a different IP."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input type="text"
														class="form-control text-center" id="disallow_2nd_ip_max"
														name="disallow_2nd_ip_max"
														value="<?= intval($rSettings["disallow_2nd_ip_max"]) ?>">
												</div>
											</div>
											<h5 class="card-title mb-4">Restream Prevention</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label"
													for="restream_deny_unauthorised">XC_VM Detect - Deny <i
														title="Deny connections from non-restreamers who are trying to use XC_VM to restream."
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
														title="Ban lines of non-restreamers who are trying to use XC_VM to restream."
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
														title="Automatically block servers from server hosting providers. This won't affect allowed restreamers."
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
														title="Automatically block proxies and VPN's based on their ASN. This won't affect allowed restreamers."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="block_proxies" id="block_proxies" type="checkbox" <?php if ($rSettings["block_proxies"] == 1) {
																														echo ' checked ';
																													} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<h5 class="card-title mb-4">Spam Prevention</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="flood_limit">Flood Limit
													<i title="Number of attempts before IP is blocked. Enter 0 to disable flood detection."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center" id="flood_limit"
														name="flood_limit"
														value="<?= htmlspecialchars($rSettings["flood_limit"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="flood_seconds">Per
													Seconds
													<i title="Number of seconds between requests."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="flood_seconds" name="flood_seconds"
														value="<?= htmlspecialchars($rSettings["flood_seconds"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="auth_flood_limit">Auth
													Flood
													Limit <i
														title="Number of attempts before connections are slowed down. Enter 0 to disable authorised flood detection.<br/><br/>This is separate to the normal Flood Limit as it only affects legitimate clients with valid credentials. As an example you can set this up so that after 30 connections in 10 seconds, the requests for the next 10 seconds will sleep for 1 second first to slow them down."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="auth_flood_limit" name="auth_flood_limit"
														value="<?= htmlspecialchars($rSettings["auth_flood_limit"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="auth_flood_seconds">Auth
													Flood Seconds <i
														title="Number of seconds to calculate number of requests for."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="auth_flood_seconds" name="auth_flood_seconds"
														value="<?= htmlspecialchars($rSettings["auth_flood_seconds"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="auth_flood_sleep">Auth
													Flood
													Sleep <i
														title="How long to sleep for when when the limit has been reached. The request will continue after this sleep."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="auth_flood_sleep" name="auth_flood_sleep"
														value="<?= htmlspecialchars($rSettings["auth_flood_sleep"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="flood_ips_exclude">Flood
													IP
													Exclusions <i title="Separate each IP with a comma."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control" id="flood_ips_exclude"
														name="flood_ips_exclude"
														value="<?= htmlspecialchars($rSettings["flood_ips_exclude"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label"
													for="bruteforce_mac_attempts">Detect MAC Bruteforce <i
														title="Automatically detect and block IP addresses trying to bruteforce MAG / Enigma devices. Enter 0 attempts to disable."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="bruteforce_mac_attempts" name="bruteforce_mac_attempts"
														value="<?= htmlspecialchars($rSettings["bruteforce_mac_attempts"]) ?: 0 ?>">
												</div>
												<label class="col-md-4 col-form-label"
													for="bruteforce_username_attempts">Detect Username Bruteforce <i
														title="Automatically detect and block IP addresses trying to bruteforce lines. Enter 0 attempts to disable."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="bruteforce_username_attempts"
														name="bruteforce_username_attempts"
														value="<?= htmlspecialchars($rSettings["bruteforce_username_attempts"]) ?: 0 ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label"
													for="bruteforce_frequency">Bruteforce Frequency <i
														title="Time between attempts for MAC and Username bruteforce. X attempts per X seconds."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="bruteforce_frequency" name="bruteforce_frequency"
														value="<?= htmlspecialchars($rSettings["bruteforce_frequency"]) ?: 0 ?>">
												</div>
												<label class="col-md-4 col-form-label" for="login_flood">Maximum
													Login
													Attempts <i
														title="How many login attempts are permitted before banning IP address. Use 0 for unlimited, or if you have other measures in place such as reCAPTCHA or access code."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center" id="login_flood"
														name="login_flood"
														value="<?= htmlspecialchars($rSettings["login_flood"]) ?: 0 ?>">
												</div>
											</div>
											<div class=" form-group row
															mb-4">
												<label class="col-md-4 col-form-label"
													for="max_simultaneous_downloads">Max Simultaneous Downloads <i
														title="Max number of simultaneous EPG & Playlist downloads per user (restreamers aren't affected). Any additional requests will be served a 429 Too Many Requests error. Set this to 0 to disable."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="max_simultaneous_downloads"
														name="max_simultaneous_downloads"
														value="<?= htmlspecialchars($rSettings["max_simultaneous_downloads"]) ?>">
												</div>
											</div>
										</div>
									</div>
								</div>
								<div class="tab-pane" id="api">
									<div class="row">
										<div class="col-12">
											<h5 class="card-title mb-4">Preferences</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="tmdb_api_key">TMDb Key
													<i title="Get your API key at <a href='https://www.themoviedb.org/settings/api'>https://www.themoviedb.org/settings/api</a>"
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="text" class="form-control" id="tmdb_api_key"
														name="tmdb_api_key"
														value="<?= htmlspecialchars($rSettings["tmdb_api_key"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="tmdb_language">TMDb
													Language
													<i title="Default language for TMDb requests, you can override this per movie or series."
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
														echo '</select></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="download_images">Download Images <i title="If this option is set, images from TMDb for example will be downloaded to the main server." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="download_images" id="download_images" type="checkbox"';

														if ($rSettings["download_images"] == 1) {
															echo ' checked ';
														}

														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="api_redirect">API Redirect <i title="Redirect API stream requests using AES encrypted tokens instead of defaulting the app to user / pass requests. This will be more widely used in the future and can remain disabled for now." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="api_redirect" id="api_redirect" type="checkbox"';

														if ($rSettings["api_redirect"] == 1) {
															echo ' checked ';
														}

														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="movie_year_append">Append Movie Year <i title="Automatically append the movie year when using TMDb or watch folder." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="movie_year_append" id="movie_year_append" class="form-control" data-toggle="select2">';

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
														echo '</select></div><label class="col-md-4 col-form-label" for="api_container">API Container <i title="Default container to use in Android / Smart TV apps." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="api_container" id="api_container" class="form-control" data-toggle="select2">';

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
														title="If this value is more than 0, playlists downloaded by clients will be cached to file for that many seconds. This can use a lot of disk space if you have a lot of clients, however will save a lot of resources in execution time."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="cache_playlists" name="cache_playlists"
														value="<?= intval($rSettings["cache_playlists"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="playlist_from_mysql">Grab
													Playlists from MySQL <i
														title="Enable this to read streams from MySQL instead of from the local cache. This may be faster when you have a significant amount of streams."
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
														title="Ensure all EPG is generated as UTC and times shown in API's are UTC. This will change the timezone in player API to UTC also to sync with apps."
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
														title="Keep the requested protocol (http or https) in playlists and streams."
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
													<i title="Whether to use GuessIt or PTN to parse filenames for Watch Folder etc. GuessIt is far better but uses more resources."
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
														title="Allow Cloudflare IP's to connect to your service and relay the true client IP to XC_VM."
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
											<h5 class="card-title mb-4">Legacy Support</h5>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="legacy_get">Legacy Playlist URL <i
														title="Rewrite get.php requests to the new playlist URL."
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
													URL <i title="Rewrite xmltv.php requests to the new epg URL."
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
														title="Rewrite panel_api.php requests to the new XC_VM Player API."
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
													<i title="XC_VM was the first to add multiple categories, which means most apps don't support it. The default behaviour of the API is to show the item once when ALL is requested, however apps tend to request all streams then filter them into categories themself. This option will change the default behaviour to show the stream duplicated for each additional category, therefore the stream shows correctly in each category. The downside is that when searching or displaying All category, the stream will be show up multiple times."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="show_category_duplicates" id="show_category_duplicates"
														type="checkbox" <?php if ($rSettings["show_category_duplicates"] == 1) {
																			echo ' checked ';
																		} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<h5 class="card-title mb-4">API Services</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="allowed_ips_admin">Admin
													Streaming IP's <i
														title="Allowed IP's to access streaming using the Live Streaming Pass. Separate each IP with a comma."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="text" class="form-control" id="allowed_ips_admin"
														name="allowed_ips_admin"
														value="<?= htmlspecialchars($rSettings["allowed_ips_admin"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="api_ips">API IP's <i
														title="Allowed IP's to access the XC_VM Admin API. Separate each IP with a comma."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="text" class="form-control" id="api_ips" name="api_ips"
														value="<?= htmlspecialchars($rSettings["api_ips"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="api_ips">API Password <i
														title="Password required to access the XC_VM Admin API. Leave blank to use IP whitelist only."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-8">
													<input type="password" class="form-control" id="api_pass"
														name="api_pass"
														value="<?= htmlspecialchars($rSettings["api_pass"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="disable_xmltv">Disable
													EPG
													Download - Line <i
														title="Enable to disallow EPG downloads in XMLTV format."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="disable_xmltv" id="disable_xmltv" type="checkbox" <?php if ($rSettings["disable_xmltv"] == 1) {
																														echo ' checked ';
																													} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div><label class="col-md-4 col-form-label"
													for="disable_xmltv_restreamer">Disable EPG Download - Restreamer
													<i title="Enable to disallow EPG downloads in XMLTV format."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_xmltv_restreamer"
														id="disable_xmltv_restreamer" type="checkbox" <?
																										if ($rSettings["disable_xmltv_restreamer"] == 1) {
																											echo ' checked ';
																										}
																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>    </div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="disable_playlist">Disable Playlist Download - Line <i title="Enable to remove the ability for lines to download their HLS / device playlists." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disable_playlist" id="disable_playlist" type="checkbox"';
																										if ($rSettings["disable_playlist"] == 1) {
																											echo ' checked ';
																										}
																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="disable_playlist_restreamer">Disable Playlist Download - Restreamer <i title="Enable to remove the ability for lines to download their HLS / device playlists." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="disable_playlist_restreamer" id="disable_playlist_restreamer" type="checkbox"';
																										if ($rSettings["disable_playlist_restreamer"] == 1) {
																											echo ' checked ';
																										} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="disable_player_api">Disable
													Player API <i
														title="Enable to stop Android Apps / Smart TV's from accessing the API."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="disable_player_api" id="disable_player_api"
														type="checkbox" <?php if ($rSettings["disable_player_api"] == 1) {
																			echo ' checked ';
																		} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div><label class="col-md-4 col-form-label"
													for="disable_enigma2">Disable Enigma2 API <i
														title="Enable to stop Enigma devices from connecting."
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
													Ministra API <i title="Enable to stop MAG devices from connecting."
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
														title="Verify domain names and IP's against allowed hosts in the database. This will include server IP's, domains and reseller DNS's."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="verify_host" id="verify_host" type="checkbox" <?php if ($rSettings["verify_host"] == 1) {
																													echo ' checked ';
																												} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<h5 class="card-title mb-4">Ministra</h5>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="live_streaming_pass">Streaming Password</label>
												<div class="col-md-8"><input type="text" class="form-control"
														id="live_streaming_pass" name="live_streaming_pass"
														value="<?= htmlspecialchars(CoreUtilities::$rSettings["live_streaming_pass"]) ?>">
												</div>
											</div>
										</div>
									</div>
								</div>
								<div class="tab-pane" id="streaming">
									<div class="row">
										<div class="col-12">
											<h5 class="card-title mb-4">Preferences</h5>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="enable_isp_lock">Enable ISP Lock <i
														title="Enable / Disable ISP lock globally."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="enable_isp_lock" id="enable_isp_lock"
														type="checkbox" <?php
																		if ($rSettings["enable_isp_lock"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
													class="col-md-4 col-form-label" for="block_svp">Enable ASN Lock <i
														title="Enable / Disable ASN lock globally."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="block_svp" id="block_svp"
														type="checkbox"';
																		if ($rSettings["block_svp"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="disable_ts">Disable MPEG-TS Output <i
														title="Disable MPEG-TS for all clients and devices."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_ts" id="disable_ts"
														type="checkbox"';
																		if ($rSettings["disable_ts"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
													class="col-md-4 col-form-label"
													for="disable_ts_allow_restream">Allow Restreamers - MPEG-TS <i
														title="Override to allow restreamers to still use MPEG-TS while it is disabled."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_ts_allow_restream"
														id="disable_ts_allow_restream" type="checkbox"';
																		if ($rSettings["disable_ts_allow_restream"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="disable_hls">Disable HLS Output <i
														title="Disable HLS for all clients and devices."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_hls" id="disable_hls"
														type="checkbox"';
																		if ($rSettings["disable_hls"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
													class="col-md-4 col-form-label"
													for="disable_hls_allow_restream">Allow Restreamers - HLS <i
														title="Override to allow restreamers to still use HLS while it is disabled."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_hls_allow_restream"
														id="disable_hls_allow_restream" type="checkbox"';
																		if ($rSettings["disable_hls_allow_restream"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="disable_rtmp">Disable RTMP Output <i
														title="Disable RTMP for all clients and devices."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_rtmp" id="disable_rtmp"
														type="checkbox"';
																		if ($rSettings["disable_rtmp"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
													class="col-md-4 col-form-label"
													for="disable_rtmp_allow_restream">Allow Restreamers - RTMP <i
														title="Override to allow restreamers to still use RTMP while it is disabled."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="disable_rtmp_allow_restream"
														id="disable_rtmp_allow_restream" type="checkbox"';
																		if ($rSettings["disable_rtmp_allow_restream"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="case_sensitive_line">Case Sensitive Lines <i
														title="Case sensitive username and password."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="case_sensitive_line"
														id="case_sensitive_line" type="checkbox"';
																		if ($rSettings["case_sensitive_line"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div><label
													class="col-md-4 col-form-label" for="county_override_1st">Override
													Country with First <i title="Override country with first connected."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="county_override_1st"
														id="county_override_1st" type="checkbox"';
																		if ($rSettings["county_override_1st"] == 1) {
																			echo ' checked ';
																		}
																		echo ' data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="encrypt_hls">Encrypt HLS Segments <i
														title="Encrypt all HLS streams with AES-256 while they are being watched. This will increase CPU usage but is more secure and packets cannot be analysed."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="encrypt_hls" id="encrypt_hls"
														type="checkbox"';
																		if ($rSettings["encrypt_hls"] == 1) {
																			echo ' checked ';
																		} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
												<label class="col-md-4 col-form-label"
													for="disallow_empty_user_agents">Disallow Empty UA <i
														title="Don' t allow connections from clients with no user-agent."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="disallow_empty_user_agents"
														id="disallow_empty_user_agents" type="checkbox" <?php if ($rSettings["disallow_empty_user_agents"] == 1) {
																											echo ' checked ';
																										}
																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="vod_bitrate_plus">VOD Bitrate Buffer <i title="Additional buffer when streaming VOD." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="vod_bitrate_plus" name="vod_bitrate_plus" value="';
																										echo htmlspecialchars($rSettings["vod_bitrate_plus"]);
																										echo '"></div><label class="col-md-4 col-form-label" for="vod_limit_perc">VOD Limit At % <i title="Limit VOD after x% has streamed. Use 0 to limit immediately and 100 to turn off entirely." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="vod_limit_perc" name="vod_limit_perc" value="';
																										echo htmlspecialchars($rSettings["vod_limit_perc"]);
																										echo '"></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="user_auto_kick_hours">Auto-Kick Hours <i title="Automatically kick connections that are online for more than X hours." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="user_auto_kick_hours" name="user_auto_kick_hours" value="';
																										echo htmlspecialchars($rSettings["user_auto_kick_hours"]);
																										echo '"></div><label class="col-md-4 col-form-label" for="use_mdomain_in_lists">Use Domain Name in API <i title="Use domain name in lists." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="use_mdomain_in_lists" id="use_mdomain_in_lists" type="checkbox"';
																										if ($rSettings["use_mdomain_in_lists"] == 1) {
																											echo ' checked ';
																										}
																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="encrypt_playlist">Encrypt Playlists <i title="Encrypt line credentials in playlist files." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="encrypt_playlist" id="encrypt_playlist" type="checkbox"';
																										if ($rSettings["encrypt_playlist"] == 1) {
																											echo ' checked ';
																										}
																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="encrypt_playlist_restreamer">Encrypt Restreamer Playlists <i title="Encrypt line credentials in restreamer playlist files." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="encrypt_playlist_restreamer" id="encrypt_playlist_restreamer" type="checkbox"';
																										if ($rSettings["encrypt_playlist_restreamer"] == 1) {
																											echo ' checked ';
																										}
																										echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="restrict_playlists">Restrictions on Playlists & EPG <i title="Verify user-agent, IP restrictions, ISP and country restrictions before allowing playlist / EPG download. If disabled the playlist can be downloaded from any IP but restrictions still apply to streams themselves." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="restrict_playlists" id="restrict_playlists" type="checkbox"';
																										if ($rSettings["restrict_playlists"] == 1) {
																											echo ' checked ';
																										} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
												<label class="col-md-4 col-form-label" for="ignore_invalid_users">Ignore
													Invalid Credentials <i
														title="Enabling this option will make authentication completely ignore a connection if the username and password are incorrect, this means the flood limit won't activate but will also quickly close an invalid connection much faster without loading any XC_VM functions or classes. If you have a lot of throughput, enabling this may save you some CPU usage."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="ignore_invalid_users" id="ignore_invalid_users"
														type="checkbox" <?php if ($rSettings["ignore_invalid_users"] == 1) {
																			echo ' checked ';
																		}
																		echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="client_prebuffer">Client Prebuffer <i title="How much data in seconds will be sent to the client when connecting to a stream. Larger values will create larger prebuffers." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="client_prebuffer" name="client_prebuffer" value="';
																		echo htmlspecialchars($rSettings["client_prebuffer"]);
																		echo '"></div><label class="col-md-4 col-form-label" for="restreamer_prebuffer">Restreamer Prebuffer <i title="How much data in seconds will be sent to the client when connecting to a stream. Larger values will create larger prebuffers." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="restreamer_prebuffer" name="restreamer_prebuffer" value="';
																		echo htmlspecialchars($rSettings["restreamer_prebuffer"]);
																		echo '"></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="split_by">Load Balancing <i title="Preferred method of load balancing connections." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="split_by" id="split_by" class="form-control" data-toggle="select2"><option';
																		if ($rSettings["split_by"] == "conn") {
																			echo ' selected';
																		} ?>
														value="conn">Connections</option>
													<option <?php if ($rSettings["split_by"] == "maxclients") {
																echo ' selected';
															} ?> value="maxclients">Max Clients </option>
													<option <?php if ($rSettings["split_by"] != "guar_band") {
															} else {
																echo ' selected';
															} ?> value="guar_band"> Network Speed
													</option>
													<option <?php if ($rSettings["split_by"] != "band") {
															} else {
																echo ' selected';
															} ?> value="band">Detected Network Speed
													</option>
													</select>
												</div>
												<label class="col-md-4 col-form-label"
													for="restreamer_bypass_proxy">Restreamer Bypass Proxy <i
														title="Route restreamers directly to load balancers instead of through proxies where proxy service has been enabled."
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
														title="Preferred method of channel sorting in playlists and apps."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<select name="channel_number_type" id="channel_number_type"
														class="form-control" data-toggle="select2">
														<option <?php if ($rSettings["channel_number_type"] != "bouquet_new") {
																} else {
																	echo ' selected';
																} ?>
															value="bouquet_new">Bouquet</option>
														<option <?php if ($rSettings["channel_number_type"] != "bouquet") {
																} else {
																	echo ' selected';
																} ?> value="bouquet">Legacy
														</option>
														<option <? if ($rSettings["channel_number_type"] != "manual") {
																} else {
																	echo ' selected';
																}
																echo ' value="manual">Manual</option></select></div><label class="col-md-4 col-form-label" for="vod_sort_newest">Sort VOD by Date <i title="Change default sorting for VOD to be by date added descending, showing newest first. This only works as expected if Channel Sorting Type is set to Bouquet, otherwise VOD order will be overwritten." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="vod_sort_newest" id="vod_sort_newest" type="checkbox"';
																if ($rSettings["vod_sort_newest"] == 1) {
																	echo ' checked ';
																}
																echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="use_buffer">Use Nginx Buffer <i title="Sets the proxy buffering for this connection. Setting this to “no” will allow unbuffered responses suitable for Comet and HTTP streaming applications. Setting this to “yes” will allow the response to be cached." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="use_buffer" id="use_buffer" type="checkbox"';
																if ($rSettings["use_buffer"] == 1) {
																	echo ' checked ';
																} ?>
															data-plugin="switchery" class="js-switch"
															data-color="#039cfd" />
												</div>
												<label class="col-md-4 col-form-label" for="show_isps">Log Client
													ISP's
													<i title="Grab ISP information for each client that connects and store it in the database."
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
														title="Interval at which to check server activity for connection limits."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="online_capacity_interval" name="online_capacity_interval"
														value="<?= htmlspecialchars($rSettings["online_capacity_interval"]) ?>">
												</div>
												<label class="col-md-4 col-form-label"
													for="monitor_connection_status">Monitor Connection Status <i
														title="Monitor PHP's connection_status() return while delivering stream and VOD content. This will abort the connection correctly when CONNECTION_NORMAL is not returned."
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
														title="Run a cron that restarts PHP-FPM if it crashes and errors are found."
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
														title="When enabled, ffmpeg PID's will be scanned every minute for streams that shouldn't be live and killed accordingly. This will also run when starting a stream to ensure any running instances are sufficiently removed."
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
														title="How long in seconds before a redirect from the main server to a load balancer will expire. If you get a lot of TOKEN_EXPIRED errors in your logs, increase this."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="create_expiration" name="create_expiration"
														value="<?= htmlspecialchars($rSettings["create_expiration"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="read_native_hls">HLS
													Read
													Native <i
														title="Force Read Native on for all HLS streams. Turn this off if you'd rather set it manually for each applicable stream."
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
												<label class="col-md-4 col-form-label" for="read_buffer_size">Read
													Buffer Size <i
														title="Amount of buffer to use when reading files in chunks."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="read_buffer_size" name="read_buffer_size"
														value="<?= htmlspecialchars($rSettings["read_buffer_size"]); ?>">
												</div>
												<label class="col-md-4 col-form-label" for="connection_sync_timer">Redis
													Connection Sync Timer <i
														title="Time between runs of the Redis Connection Sync script."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="connection_sync_timer" name="connection_sync_timer" value="<?= htmlspecialchars($rSettings["connection_sync_timer"]); ?>

">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="allow_cdn_access">Allow
													CDN
													/ Forwarding <i
														title="Allow X-Forwarded-For to forward the correct IP to XC_VM and turn off path encryption in favour of token based encryption when streaming.<br/>To set up allowed IP's for forwarding, follow the CDN setup tutorial on the Billing Panel. Advanced usage only."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="allow_cdn_access" id="allow_cdn_access" type="checkbox"
														<?php if ($rSettings["allow_cdn_access"] == 1) {
															echo ' checked ';
														}
														echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="stop_failures">Max Failures <i title="How many failures before exiting stream monitor. For example, if set to 3 then the stream monitor will allow 3 failures, break, then the monitor will be restarted by the streams Cron at the next minute marker. If set to 0 streams will continue to restart forever." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input type="text" class="form-control text-center" id="stop_failures" name="stop_failures" value="';
														echo htmlspecialchars($rSettings["stop_failures"]);
														echo '"></div>    </div>    <h5 class="card-title mb-4">On-Demand Settings</h5>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="on_demand_instant_off">Instant Off <i title="When a client disconnects from an on-demand stream, check the current total connections for that stream and turn it off if nobody is watching." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="on_demand_instant_off" id="on_demand_instant_off" type="checkbox"';
														if ($rSettings["on_demand_instant_off"] == 1) {
															echo ' checked ';
														} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
												<label class="col-md-4 col-form-label" for="on_demand_failure_exit">Exit
													on Failure <i
														title="If an on-demand stream fails to start, do not retry, cancel the stream and disconnect the client. It will retry on the next connection but will ensure it doesn't sit forever trying to start and using source connections for example."
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
														title="How long should the client wait for an on-demand stream to start. After this time has elapsed, the connection will close. This will also apply to normal streams while they're starting or not yet available."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="on_demand_wait_time" name="on_demand_wait_time"
														value="<?= htmlspecialchars($rSettings["on_demand_wait_time"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="request_prebuffer">Request
													Prebuffer <i
														title="When you request a stream on-demand, ask the provider to send a prebuffer so the stream starts quicker. This will only work if your provider is using XC_VM. The prebuffer will mean your source could be 10 seconds or so behind, but it will load significantly quicker.<br/><br/>On - URL means automatically append ?prebuffer=1 to the URL, On - Header sends the prebuffer request as a header instead. Header requests are only accepted when requesting from XC_VM v1.4.4+"
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
														title="Treat on-demand servers equal to live servers when load balancing, this will mean an on-demand server will be started up to load balance even if there's already a server live for that stream."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="ondemand_balance_equal" id="ondemand_balance_equal"
														type="checkbox" <?php if ($rSettings["ondemand_balance_equal"] == 1) {
																			echo ' checked ';
																		} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<h5 class="card-title mb-4">On-Demand Scanner</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="on_demand_checker">Enable
													Scanner <i
														title="Periodically probe on-demand streams to check their current status, resolution, codecs and FPS, as well as response time and log any errors incurred to the database. Streams will scan one at a time, per server, to avoid any connection issues."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="on_demand_checker" id="on_demand_checker"
														type="checkbox" <?php if ($rSettings["on_demand_checker"] == 1) {
																			echo ' checked ';
																		} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
												<label class="col-md-4 col-form-label" for="on_demand_scan_time">Scan
													Time <i title="How often to scan a stream in seconds."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="on_demand_scan_time" name="on_demand_scan_time"
														value="<?= htmlspecialchars($rSettings["on_demand_scan_time"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="on_demand_max_probe">Max
													Probe Time <i
														title="How many seconds to probe the stream for before cancelling."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="on_demand_max_probe" name="on_demand_max_probe"
														value="<?= htmlspecialchars($rSettings["on_demand_max_probe"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="on_demand_scan_keep">Keep
													Logs For <i
														title="How many seconds to keep logs for. This will affect your Up and Down statistics in the logs page. Default is 604800, 1 week."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="on_demand_scan_keep" name="on_demand_scan_keep"
														value="<?= htmlspecialchars($rSettings["on_demand_scan_keep"]) ?>">
												</div>
											</div>
											<h5 class="card-title mb-4">Encoding Queue Settings</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="max_encode_movies">Max
													Movie
													Encodes <i
														title="Maximum number of movies to encode at once, per server. If all of your content is symlinked, you can set this to a higher number, otherwise set it to how many encodes your servers can realistically perform at once without overloading."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="max_encode_movies" name="max_encode_movies"
														value="<?= htmlspecialchars($rSettings["max_encode_movies"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="max_encode_cc">Max
													Channel
													Encodes <i
														title="Maximum number of created channels to encode at once, per server. It's best to set this to 1 unless you're symlinking all created channels."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="max_encode_cc" name="max_encode_cc"
														value="<?= htmlspecialchars($rSettings["max_encode_cc"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="queue_loop">Queue Loop
													Timer
													<i title="How long to wait between queue checks. If you're symlinking content you should set this to 1 second."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center" id="queue_loop"
														name="queue_loop"
														value="<?= htmlspecialchars($rSettings["queue_loop"]) ?>">
												</div>
											</div>
											<h5 class="card-title mb-4">Segment Settings</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="seg_time">Segment
													Duration
													<i title="Duration of individual segments when using HLS. This cannot be guaranteed due to keyframes, but should work on most streams."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center" id="seg_time"
														name="seg_time"
														value="<?= htmlspecialchars($rSettings["seg_time"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="seg_list_size">List Size
													<i title="Number of segments in the HLS playlist."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="seg_list_size" name="seg_list_size"
														value="<?= htmlspecialchars($rSettings["seg_list_size"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="seg_delete_threshold">Delete
													Threshold <i
														title="How many old segments to keep when generating HLS playlist. Lowering this will lower RAM usage but it's good to keep a buffer for connecting clients. A 30 second prebuffer for example would need 3 x 10 second segments to work."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="seg_delete_threshold" name="seg_delete_threshold"
														value="<?= htmlspecialchars($rSettings["seg_delete_threshold"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="segment_wait_time">Max
													Segment Wait Time <i
														title="Maximum amount of seconds to wait for a new segment to be created before exiting the clients connection due to having no new data that can be delivered."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="segment_wait_time" name="segment_wait_time" value="<?= htmlspecialchars($rSettings["segment_wait_time"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="stream_max_analyze">Analysis
													Duration <i
														title="How long to analyse a stream, longer duration will increase sample accuracy. 5,000,000 microseconds = 5s."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="stream_max_analyze" name="stream_max_analyze"
														value="<?= htmlspecialchars($rSettings["stream_max_analyze"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="probesize">Probe Size <i
														title="Amount of data to be probed in bytes."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center" id="probesize"
														name="probesize"
														value="<?= htmlspecialchars($rSettings["probesize"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="ffmpeg_cpu">FFMPEG
													Version
													<i title="Which version of FFMPEG to use for movies, created channels and normal streams.<br/><br/>v4.0 - Legacy version from 2018, shipped with XC_VM originally.<br/>v4.3.2 & v4.4 compiled by XC_VM with all libraries from v4.0 plus many more. Compatible with DASH and NVENC."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<select name="ffmpeg_cpu" id="ffmpeg_cpu" class="form-control"
														data-toggle="select2">
														<?php
														foreach (["8.0", "7.1", "4.4", "4.3", "4.0"] as $rValue) {
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
														echo '</select></div><label class="col-md-4 col-form-label" for="segment_type">FFMPEG Segment Type <i title="Whether to use -f segment or -f hls when processing streams. -f hls is designed to be used with HLS streaming so should perform better. Using segment will disable delete threshold option. Segment will be used by default for on-demand streams to increase speed." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><select name="segment_type" id="segment_type" class="form-control" data-toggle="select2">';

														foreach (["-f hls", "-f segment"] as $rValue => $rText) {
															echo '<option ';

															if ($rSettings["segment_type"] != $rValue) {
															} else {
																echo 'selected ';
															}

														?> value="<?= $rValue ?>"><?= $rText ?></option><?php
																									}
																									echo '</select></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="ffmpeg_warnings">FFMPEG Show Warnings <i title="Instruct FFMPEG to save warnings to stream errors table. Turning this off will save only errors instead." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="ffmpeg_warnings" id="ffmpeg_warnings" type="checkbox"';

																									if ($rSettings["ffmpeg_warnings"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="ignore_keyframes">Ignore Keyframes <i title="Allow segments to start on frames other than keyframes. This improves behavior on some players when the time between keyframes is inconsistent, but may make things worse on others, and can cause some oddities during startup with blank screen until video kicks in." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="ignore_keyframes" id="ignore_keyframes" type="checkbox"';

																									if ($rSettings["ignore_keyframes"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="dts_legacy_ffmpeg">DTS - Use FFMPEG v4.0 <i title="Automatically switch to legacy FFMPEG v4.0 for streams with DTS audio, in some cases this has been known to fix desynchronised audio. Generate PTS needs to be turned off for this to function." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="dts_legacy_ffmpeg" id="dts_legacy_ffmpeg" type="checkbox"';

																									if ($rSettings["dts_legacy_ffmpeg"] == 1) {
																										echo ' checked ';
																									}
																										?> data-plugin="switchery" class="js-switch" data-color="#039cfd"/>
												</div>
												<label class="col-md-4 col-form-label" for="php_loopback">Loopback
													Streams via PHP <i
														title="Don't use FFMPEG to handle loopback streams, have PHP read them directly and generate HLS."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="php_loopback" id="php_loopback" type="checkbox" <?php if ($rSettings["php_loopback"] == 1) {
																														echo ' checked ';
																													} ?>
														data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<h5 class="card-title mb-4">Stream Monitor Settings</h5>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="audio_restart_loss">Restart on Audio Loss <i
														title="Restart stream periodically if no audio is detected."
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
														title="Switch back to the first source if it is detected as working again."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="priority_backup" id="priority_backup"
														type="checkbox" <? if ($rSettings["priority_backup"] == 1) {
																			echo ' checked ';
																		} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="probe_extra_wait">Probe Duration <i
														title="How long to wait after analyze duration before cancelling stream probe."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input type="text"
														class="form-control text-center" id="probe_extra_wait"
														name="probe_extra_wait"
														value="<?= htmlspecialchars($rSettings["probe_extra_wait"]); ?>">
												</div><label class=" col-md-4 col-form-label"
													for="stream_fail_sleep">Stream Failure Sleep <i
														title="How long to wait in seconds after a stream start failure before starting again."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input type="text"
														class="form-control text-center" id="stream_fail_sleep"
														name="stream_fail_sleep" value="
														<?= htmlspecialchars($rSettings["stream_fail_sleep"]) ?>"></div>
											</div>
											<div class=" form-group row
														mb-4"><label class="col-md-4 col-form-label" for="fps_delay">FPS
													Start Delay <i
														title="How long in seconds to wait before checking if FPS drops below threshold."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input type="text"
														class="form-control text-center" id="fps_delay" name="fps_delay"
														value="
														<?= htmlspecialchars($rSettings["fps_delay"]) ?>"></div><label class=" col-md-4 col-form-label"
													for="fps_check_type">FPS Check Type <i
														title="Whether to use progress info after the start delay to determine real FPS or probe the segment to return avg_frame_rate."
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
													via API <i title="Use API calls to probe sources from XC_VM servers."
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
											<h5 class="card-title mb-4">Off Air Videos</h5>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="show_not_on_air_video">Stream Down Video <i
														title="Show this video when a stream isn't on air."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="show_not_on_air_video"
														id="show_not_on_air_video" type="checkbox" <?php
																									if ($rSettings["show_not_on_air_video"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="not_on_air_video_path" name="not_on_air_video_path" value=" ';
																									echo htmlspecialchars($rSettings["not_on_air_video_path"]);
																									echo '" placeholder="Leave blank to use default XC_VM video."></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_banned_video">Banned Video <i title="Show this video when a banned line accesses a stream." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_banned_video" id="show_banned_video" type="checkbox"';

																									if ($rSettings["show_banned_video"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="banned_video_path" name="banned_video_path" value=" ';
																									echo htmlspecialchars($rSettings["banned_video_path"]);
																									echo '" placeholder="Leave blank to use default XC_VM video."></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_expired_video">Expired Video <i title="Show this video when an expired line accesses a stream." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_expired_video" id="show_expired_video" type="checkbox"';

																									if ($rSettings["show_expired_video"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="expired_video_path" name="expired_video_path" value=" ';
																									echo htmlspecialchars($rSettings["expired_video_path"]);
																									echo '" placeholder="Leave blank to use default XC_VM video."></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_expiring_video">Expiring Video <i title="Show this video once per day 7 days prior to a line expiring." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_expiring_video" id="show_expiring_video" type="checkbox"';

																									if ($rSettings["show_expiring_video"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="expiring_video_path" name="expiring_video_path" value=" ';
																									echo htmlspecialchars($rSettings["expiring_video_path"]);
																									echo '" placeholder="Leave blank to use default XC_VM video."></div></div>    <div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_connected_video">2nd IP Connected Video <i title="Show this video when a client connects but gets denied to already watching on another IP." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="show_connected_video" id="show_connected_video" type="checkbox"';

																									if ($rSettings["show_connected_video"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><div class="col-md-6"><input type="text" class="form-control" id="connected_video_path" name="connected_video_path" value=" ';
																									echo htmlspecialchars($rSettings["connected_video_path"]);
																									echo '" placeholder="Leave blank to use default XC_VM video."></div></div>    <h5 class="card-title mb-4">Allowed Countries <i title="Select individual countries to allow. This is a global geo-lock, selet All Countries to allow everyone." class="tooltip text-secondary far fa-circle"></i></h5>    <div class="form-group row mb-4"><div class="col-md-12">    <select name="allow_countries[]" id="allow_countries" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">';

																									foreach ($rGeoCountries as $rValue => $rText) {
																										echo '<option ';

																										if (in_array($rValue, json_decode($rSettings["allow_countries"], true))) {
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
											<h5 class="card-title mb-4">Preferences</h5>
											<div class="form-group row mb-4"><label class="col-md-4 col-form-label"
													for="show_all_category_mag">Show All Categories <i
														title="Show All category on MAG devices."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input name="show_all_category_mag"
														id="show_all_category_mag" type="checkbox" <?php
																									if ($rSettings["show_all_category_mag"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="mag_container">Default Container</label><div class="col-md-2"><select name="mag_container" id="mag_container" class="form-control" data-toggle="select2">';

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
																									echo '</select></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="always_enabled_subtitles">Always Enabled Subtitles <i title="Force subtitles to be enabled at all times." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="always_enabled_subtitles" id="always_enabled_subtitles" type="checkbox"';

																									if ($rSettings["always_enabled_subtitles"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="enable_connection_problem_indication">Connection Problem Indiciation</label><div class="col-md-2"><input name="enable_connection_problem_indication" id="enable_connection_problem_indication" type="checkbox"';

																									if ($rSettings["enable_connection_problem_indication"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="show_tv_channel_logo">Show Channel Logos</label><div class="col-md-2"><input name="show_tv_channel_logo" id="show_tv_channel_logo" type="checkbox"';

																									if ($rSettings["show_tv_channel_logo"] == 1) {
																										echo ' checked ';
																									}

																									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="show_channel_logo_in_preview">Show Preview Channel Logos</label><div class="col-md-2"><input name="show_channel_logo_in_preview" id="show_channel_logo_in_preview" type="checkbox"';

																									if ($rSettings["show_channel_logo_in_preview"] == 1) {
																										echo ' checked ';
																									}
																									?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" /></div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="playback_limit">Playback
													Limit <i
														title="Show warning message and stop stream after X hours of continuous playback."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2"><input type="text"
														class="form-control text-center" id="playback_limit"
														name="playback_limit"
														value="<?= htmlspecialchars($rSettings["playback_limit"]) ?>">
												</div>
												<label class="col-md-4 col-form-label"
													for="tv_channel_default_aspect">Default Aspect Ratio <i
														title="Set the default aspect ratio of streams. Fit being the recommended option."
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
														title="Whether to use Modern or Legacy theme by default for newly added devices."
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
													Theme <i title="Default Ministra theme to be used by MAG devices."
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
														title="Redirect /c to Ministra folder using symlinks. This will allow legacy devices to access the Ministra portal using the old address, however it isn 't recommended for security purposes. Root access is required so this will action within the next minute during the cron run."
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
														title="Keep extension of live streams, timeshift and VOD. Some older devices can't determine it for themselves and use the extension to select the playback method."
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
														title="Force MAG 's to use non-SSL URL's, you should think about removing support for old MAG devices that don 't support newer SSL protocols rather than disabling this."
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
														title="Load all channel listings on startup instead of when selecting a category. This may be useful for some legacy devices that don't adhere to Ministra standards."
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
														title="Disable verification of MAG token when streaming, reduces security but can have better compatibility."
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
														data-placeholder="Choose...">
														<?php
														foreach (json_decode($rSettings["allowed_stb_types"], true) as $rMAG) {
															echo '        <option selected value=" ';
															echo $rMAG;
															echo '">';
															echo $rMAG;
															echo '</option>        ';
														}

														foreach (array_udiff($rMAGs, json_decode($rSettings["allowed_stb_types"], true), "strcasecmp") as $rMAG) {
															echo '<option value=" ';
															echo $rMAG;
															echo '">';
															echo $rMAG;
															echo '</option>';
														}
														echo '</select></div></div><div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="allowed_stb_types_for_local_recording">Allowed STB Recording</label><div class="col-md-8"><select name="allowed_stb_types_for_local_recording[]" id="allowed_stb_types_for_local_recording" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">        ';

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
														title="URL to a file to download during speedtest on MAG devices."
														class="tooltip text-secondary far fa-circle"></i>
												</label>
												<div class="col-md-8"><input type="text" class="form-control"
														id="test_download_url" name="test_download_url"
														value="<?= htmlspecialchars($rSettings["test_download_url"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="mag_message">Information
													Message <i
														title="Message to display when a user selects Information in My Account tab. Text entered should be in HTML format, although newlines will be converted to <br/>."
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
											<h5 class="card-title mb-4">Preferences</h5>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="player_allow_playlist">Allow
													Playlist Download <i
														title="Allow clients to generate playlist URL's from the web player."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="player_allow_playlist" id="player_allow_playlist"
														type="checkbox" <?php if ($rSettings["player_allow_playlist"] == 1) {
																			echo ' checked ';
																		}
																		echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="player_allow_bouquet">Allow Bouquet Ordering <i title="Allow clients to reorder their bouquets from the web player." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="player_allow_bouquet" id="player_allow_bouquet" type="checkbox"';
																		if ($rSettings["player_allow_bouquet"] == 1) {
																			echo ' checked ';
																		} ?> data-plugin="switchery" class="js-switch"
														data-color="#039cfd" />
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label"
													for="player_hide_incompatible">Hide Incompatible Streams
													<i title="Hide streams that aren't compatible with most browsers, this will limit streams to H264 and AV1 mostly. This option will also hide streams and movies that aren't available."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input name="player_hide_incompatible" id="player_hide_incompatible"
														type="checkbox" <?php if ($rSettings["player_hide_incompatible"] == 1) {
																			echo ' checked ';
																		}
																		echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="player_allow_hevc">Mark HEVC as Compatible <i title="Mark HEVC as compatible, there are some browsers such as Edge and Safari that support HEVC, however most mainstream browsers such as Firefox and Chrome do not." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="player_allow_hevc" id="player_allow_hevc" type="checkbox"';
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
													px <i title="Blur the background images by X pixels."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center" id="player_blur"
														name="player_blur"
														value="<?= intval($rSettings["player_blur"]) ?>">
												</div>
												<label class="col-md-4 col-form-label" for="player_opacity">Background
													Opacity % <i
														title="Adjust the background image opacity. Default is 10%."
														class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-2">
													<input type="text" class="form-control text-center"
														id="player_opacity" name="player_opacity" value="<?= intval($rSettings["player_opacity"]) ?>">
												</div>
											</div>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="extract_subtitles">Extract
													Subtitles <i
														title="Automatically extract subtitles from movies and episodes while they're being processed. Allows for subtitles to be used in Web Player."
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
											<h5 class="card-title mb-4">Preferences</h5>
											<div class="form-group row mb-4">
												<label class="col-md-3 col-form-label"
													for="save_closed_connection">Activity Logs <i
														title="Activity logs are saved when an active connection is closed. This is useful information to keep and should be kept for as long as possible, however can build up if you have high throughput."
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
														title="Activity logs are saved when an active connection is closed. This is useful information to keep and should be kept for as long as possible, however can build up if you have high throughput."
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
													<i title="Activity logs are saved when an active connection is closed. This is useful information to keep and should be kept for as long as possible, however can build up if you have high throughput."
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
														title="Activity logs are saved when an active connection is closed. This is useful information to keep and should be kept for as long as possible, however can build up if you have high throughput."
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
														title="Activity logs are saved when an active connection is closed. This is useful information to keep and should be kept for as long as possible, however can build up if you have high throughput."
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
														?> value="
															<?= $rValue; ?>">
															<?= $rText ?>
															</option>
														<?php
														}
														?>
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
								<?php
								if (hasPermissions("adv", "database") && DEVELOPMENT) { ?>
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
include 'footer.php'; ?>
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

	<?php if (CoreUtilities::$rSettings['enable_search']): ?>
		$(document).ready(function() {
			initSearch();
		});

	<?php endif; ?>

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
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>