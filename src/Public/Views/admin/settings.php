<?php

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Reference\DeviceReference;
use XcVm\Core\Reference\GeoReference;
use XcVm\Core\Reference\LocaleReference;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\GeoIP\MaxMindUpdater;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Streaming\Codec\FfmpegBinaries; // Code reconstruction by Squallp
?>

<form id="settings-form">
	<div style="display:none"><input type="text"><input type="password"></div>

	<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
		<h4 class="mb-0"><?= $language::get('service_setup') ?></h4>
		<button type="submit" class="btn btn-primary"><?= $language::get('save_changes') ?></button>
	</div>

	<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			Settings have been updated.
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
	<?php endif; ?>

	<?php if (
		isset($rUpdate) &&
		is_array($rUpdate) &&
		!empty($rUpdate["version"]) &&
		(
			0 < version_compare($rUpdate["version"], XC_VM_VERSION) ||
			version_compare($rUpdate["version"], XC_VM_VERSION) == 0
		)
	): ?>
		<div class="card bg-info text-white mb-4">
			<div class="card-body py-3">
				<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
					<h6 class="text-white mb-0"><i class="icon-base ti tabler-cloud-download me-1"></i><?= $language::get('update_available') ?> — v<?= $rUpdate["version"] ?></h6>
					<div class="d-flex align-items-center gap-2">
						<a href="<?= str_replace('" ', '"', $rUpdate["url"]) ?>" class="btn btn-sm btn-outline-light" target="_blank"><?= $language::get('release_thread') ?> <i class="icon-base ti tabler-arrow-right"></i></a>
						<button type="button" class="btn btn-sm btn-light" onclick="UpdateServer()"><?= $language::get('update_server') ?></button>
					</div>
				</div>
				<?php if (!empty($rUpdate["changelog"]) && is_array($rUpdate["changelog"])): ?>
					<div class="small mt-2 pe-2" style="max-height:150px; overflow-y:auto;">
						<?php foreach ($rUpdate["changelog"] as $rItem): ?>
							<div class="fw-semibold mt-2"><?= $language::get('changelog_v') ?><?= $rItem["version"] ?></div>
							<ul class="mb-1 ps-3">
								<?php foreach ((is_array($rItem["changes"] ?? null) ? $rItem["changes"] : []) as $rChange): ?>
									<li><?= $rChange ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="card">
		<div class="card-body">
			<ul class="nav nav-pills flex-wrap mb-4" role="tablist">
				<li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#interface" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><span class="d-none d-sm-inline"><?= $language::get('general') ?></span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#security" role="tab"><i class="icon-base ti tabler-shield-lock me-1"></i><span class="d-none d-sm-inline"><?= $language::get('security') ?></span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#api" role="tab"><i class="icon-base ti tabler-code me-1"></i><span class="d-none d-sm-inline">API</span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#streaming" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><span class="d-none d-sm-inline"><?= $language::get('streaming') ?></span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#mag" role="tab"><i class="icon-base ti tabler-device-tablet me-1"></i><span class="d-none d-sm-inline"><?= $language::get('mag') ?></span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#webplayer" role="tab"><i class="icon-base ti tabler-world me-1"></i><span class="d-none d-sm-inline">Web Player</span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#logs" role="tab"><i class="icon-base ti tabler-file-text me-1"></i><span class="d-none d-sm-inline"><?= $language::get('logs') ?></span></button></li>
				<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#info" role="tab"><i class="icon-base ti tabler-file-text me-1"></i><span class="d-none d-sm-inline"><?= $language::get('info') ?></span></button></li>
				<?php if (Authorization::check("adv", "database") && defined('DB_ACCESS_ENABLED') && DB_ACCESS_ENABLED): ?>
					<li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#database" role="tab"><i class="icon-base ti tabler-file-text me-1"></i><span class="d-none d-sm-inline"><?= $language::get('database') ?></span></button></li>
				<?php endif; ?>
			</ul>
			<div class="tab-content p-4 border rounded">
				<div class="tab-pane fade show active" id="interface" role="tabpanel">
					<div class="row">
						<div class="col-12">

							<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="server_name">
									<?= $language::get('server_name') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('the_name_of_your_streaming_service') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="server_name" name="server_name" value="<?= htmlspecialchars($rSettings["server_name"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="default_timezone">
									<?= $language::get('server_timezone') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_timezone_for_the_admin_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<select name="default_timezone" id="default_timezone" class="form-control" data-toggle="select2">
										<?php foreach (AdminHelpers::TimeZoneList() as $rValue): ?>
											<option value="<?= $rValue['zone'] ?>" <?= $rSettings["default_timezone"] == $rValue['zone'] ? ' selected' : '' ?>>
												<?= $rValue['zone'] . " " . $rValue['diff_from_GMT'] ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="language">
									<?= $language::get('interface_language') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_language_for_the_admin_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<select name="language" id="language" class="form-control" data-toggle="select2">
										<?php foreach (Translator::available() as $rLangCode): ?>
											<option value="<?= htmlspecialchars($rLangCode) ?>" <?= ($rSettings["language"] ?? 'en') === $rLangCode ? ' selected' : '' ?>>
												<?= htmlspecialchars($rLangCode) ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="message_of_day">
									<?= $language::get('message_of_the_day') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('message_to_show_in_the_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="message_of_day" name="message_of_day" value="<?= htmlspecialchars($rSettings["message_of_day"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="default_entries">
									<?= $language::get('show_entries') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('number_of_table_entries_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="default_entries" id="default_entries" class="form-control" data-toggle="select2">
										<?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
											<option value="<?= $rShow ?>" <?= $rSettings["default_entries"] == $rShow ? ' selected' : '' ?>>
												<?= $rShow ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="fails_per_time">
									<?= $language::get('fails_per_time') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_track_stream_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fails_per_time" name="fails_per_time" value="<?= intval($rSettings["fails_per_time"]) ?>">
								</div>

								<!--
													<label class="col-md-4 col-form-label" for="fingerprint_max">
														Fingerprint Max
														<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('maximum_number_of_concurrent_fingerprint_tooltip') ?>"></i>
													</label>

													<div class="col-md-2">
														<select name="fingerprint_max" id="fingerprint_max" class="form-control" data-toggle="select2">
															<?php foreach ([0, 5, 10, 25, 50, 100] as $rShow): ?>
																<option value="<?= $rShow ?>"<?= $rSettings["fingerprint_max"] == $rShow ? ' selected' : '' ?>>
																	<?= $rShow ?>
																</option>
															<?php endforeach; ?>
														</select>
													</div>
													-->
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="date_format">
									<?= $language::get('date_format') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_date_format_to_use_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="date_format" name="date_format" value="<?= htmlspecialchars($rSettings["date_format"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="datetime_format">
									<?= $language::get('datetime_format') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_datetime_format_to_use_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="datetime_format" name="datetime_format" value="<?= htmlspecialchars($rSettings["datetime_format"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="streams_grouped">
									<?= $language::get('group_streams_table') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('toggle_to_group_multiple_servers_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="streams_grouped" id="streams_grouped" type="checkbox" <?= $rSettings["streams_grouped"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="js_navigate">
									<?= $language::get('seamless_navigation') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_seamless_navigation_by_utilising_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="js_navigate" id="js_navigate" type="checkbox" <?= $rSettings["js_navigate"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_tickets">
									<?= $language::get('show_tickets_icon') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_tickets_icon_in_the_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_tickets" id="show_tickets" type="checkbox" <?= $rSettings["show_tickets"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="hide_failures">
									<?= $language::get('disable_restart_counter') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('removes_the_restart_count_next_to_stream_uptime') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="hide_failures" id="hide_failures" type="checkbox" <?= $rSettings["hide_failures"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="cleanup">
									<?= $language::get('auto_cleanup_files') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_clean_up_redundant_files_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="cleanup" id="cleanup" type="checkbox" <?= $rSettings["cleanup"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="check_vod">
									<?= $language::get('check_vod_cron') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('check_that_vod_exists_periodically_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="check_vod" id="check_vod" type="checkbox" <?= $rSettings["check_vod"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_images">
									<?= $language::get('show_images_picons') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_channel_logos_and_vod_images_in_the_management_pages') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_images" id="show_images" type="checkbox" <?= $rSettings["show_images"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="group_buttons">
									<?= $language::get('group_buttons') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('group_action_buttons_into_a_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="group_buttons" id="group_buttons" type="checkbox" <?= $rSettings["group_buttons"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="modal_edit">
									<?= $language::get('quick_edit_modal') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_clicking_edit_open_in_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="modal_edit" id="modal_edit" type="checkbox" <?= $rSettings["modal_edit"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="mysql_sleep_kill">
									<?= $language::get('mysql_sleep_timeout') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_allow_mysql_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="mysql_sleep_kill" name="mysql_sleep_kill" value="<?= intval($rSettings["mysql_sleep_kill"]) ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="update_channel">
									<?= $language::get('update_channel') ?>
								</label>

								<div class="col-md-2">
									<select name="update_channel" id="update_channel" class="form-control" data-toggle="select2">
										<?php $rCurrentChannel = (($rSettings["update_channel"] ?? 'stable') === 'unstable') ? 'beta' : ($rSettings["update_channel"] ?? 'stable'); ?>
										<?php foreach (["stable" => "Stable", "beta" => "Beta"] as $rKey => $rValue): ?>
											<option value="<?= $rKey ?>" <?= $rCurrentChannel == $rKey ? ' selected' : '' ?>>
												<?= $rValue ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('dashboard') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="dashboard_stats">
									<?= $language::get('show_graphs') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_dashboard_statistic_graphs_for_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="dashboard_stats" id="dashboard_stats" type="checkbox" <?= $rSettings["dashboard_stats"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="dashboard_map">
									<?= $language::get('show_connections_map') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_connection_map_on_the_dashboard') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="dashboard_map" id="dashboard_map" type="checkbox" <?= $rSettings["dashboard_map"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="dashboard_display_alt">
									<?= $language::get('alternate_server_view') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('display_servers_on_the_dashboard_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="dashboard_display_alt" id="dashboard_display_alt" type="checkbox" <?= $rSettings["dashboard_display_alt"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="header_stats_sh">
									<?= $language::get('show_header_stats') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_server_statistics_in_header_menu') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="header_stats" id="header_stats_sh" type="checkbox" <?= $rSettings["header_stats"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="dashboard_status">
									<?= $language::get('show_service_status') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_warning_information_based_on_server_stats') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="dashboard_status" id="dashboard_status" type="checkbox" <?= $rSettings["dashboard_status"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="threshold_cpu">
									<?= $language::get('cpu_threshold_not_working') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_cpu_usage_is_above_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="threshold_cpu" name="threshold_cpu" value="<?= intval($rSettings["threshold_cpu"]) ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="threshold_mem">
									<?= $language::get('memory_threshold_not_working') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_memory_usage_is_above_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="threshold_mem" name="threshold_mem" value="<?= intval($rSettings["threshold_mem"]) ?>">
								</div>

								<label class="col-md-4 col-form-label" for="threshold_disk">
									<?= $language::get('disk_threshold_not_working') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_disk_usage_is_above_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="threshold_disk" name="threshold_disk" value="<?= intval($rSettings["threshold_disk"]) ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="threshold_network">
									<?= $language::get('network_threshold_not_working') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_network_usage_is_above_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="threshold_network" name="threshold_network" value="<?= intval($rSettings["threshold_network"]) ?>">
								</div>

								<label class="col-md-4 col-form-label" for="threshold_clients">
									<?= $language::get('clients_threshold_not_working') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_number_of_clients_as_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="threshold_clients" name="threshold_clients" value="<?= intval($rSettings["threshold_clients"]) ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('search') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="enable_search">
									<?= $language::get('enable_search') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('toggle_the_search_box_in_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="enable_search" id="enable_search" type="checkbox" <?= $rSettings["enable_search"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="search_items">
									<?= $language::get('number_of_items') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_many_search_results_to_display_maximum_of_100') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="search_items" name="search_items" value="<?= intval($rSettings["search_items"]) ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('reseller') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_trial">
									<?= $language::get('disable_trials') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('use_this_option_to_temporarily_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_trial" id="disable_trial" type="checkbox" <?= $rSettings["disable_trial"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="reseller_ssl_domain">
									<?= $language::get('ssl_custom_dns') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('use_https_in_playlist_downloads_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="reseller_ssl_domain" id="reseller_ssl_domain" type="checkbox" <?= $rSettings["reseller_ssl_domain"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('debug') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="debug_show_errors">
									<?= $language::get('debug_mode') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_clean_up_redundant_files_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="debug_show_errors" id="debug_show_errors" type="checkbox" <?= $rSettings["debug_show_errors"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="enable_debug_stalker">
									<?= $language::get('stalker_debug_mode') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_debug_mode_ministra_portal') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="enable_debug_stalker" id="enable_debug_stalker" type="checkbox" <?= $rSettings["enable_debug_stalker"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('recaptcha') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label">
									<?= $language::get('enable_recaptcha') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('click_here_to_show_active_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="recaptcha_enable" id="recaptcha_enable" type="checkbox" <?= $rSettings["recaptcha_enable"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="recaptcha_v2_site_key">
									<?= $language::get('recaptcha_v2_site_key') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('please_visit_httpsgooglecomrecaptchaadmin_to_obtain_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="recaptcha_v2_site_key" name="recaptcha_v2_site_key" value="<?= htmlspecialchars($rSettings["recaptcha_v2_site_key"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="recaptcha_v2_secret_key">
									<?= $language::get('recaptcha_v2_secret_key') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('please_visit_httpsgooglecomrecaptchaadmin_to_obtain_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="recaptcha_v2_secret_key" name="recaptcha_v2_secret_key" value="<?= htmlspecialchars($rSettings["recaptcha_v2_secret_key"] ?? '') ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('default_arguments') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="user_agent">
									<?= $language::get('user_agent') ?>
								</label>

								<div class="col-md-9">
									<input type="text" class="form-control" id="user_agent" name="user_agent" value="<?= htmlspecialchars($rStreamArguments["user_agent"]["argument_default_value"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="http_proxy">
									<?= $language::get('http_proxy') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('format_ipport') ?>"></i>
								</label>

								<div class="col-md-9">
									<input type="text" class="form-control" id="http_proxy" name="http_proxy" value="<?= htmlspecialchars($rStreamArguments["proxy"]["argument_default_value"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="cookie">
									<?= $language::get('cookie') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('format_keyvalue') ?>"></i>
								</label>

								<div class="col-md-9">
									<input type="text" class="form-control" id="cookie" name="cookie" value="<?= htmlspecialchars($rStreamArguments["cookie"]["argument_default_value"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="headers">
									<?= $language::get('headers') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('ffmpeg_headers_command') ?>"></i>
								</label>

								<div class="col-md-9">
									<input type="text" class="form-control" id="headers" name="headers" value="<?= htmlspecialchars($rStreamArguments["headers"]["argument_default_value"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="probesize_ondemand">
									<?= $language::get('on_demand_probesize') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('adjustable_probesize_for_ondemand_streams_tooltip') ?>"></i>
								</label>

								<div class="col-md-3">
									<input type="text" class="form-control text-center" id="probesize_ondemand" name="probesize_ondemand" value="<?= intval($rSettings["probesize_ondemand"]) ?>">
								</div>
							</div>

						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="security" role="tabpanel">
					<div class="row">
						<div class="col-12">

							<h5 class="card-title mb-4"><?= $language::get('ip_security') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="ip_subnet_match">
									<?= $language::get('match_subnet_of_ip') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('some_ip_s_change_quite_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="ip_subnet_match" id="ip_subnet_match" type="checkbox" <?= $rSettings["ip_subnet_match"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="ip_logout">
									<?= $language::get('logout_on_ip_change') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_destroy_sessions_if_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="ip_logout" id="ip_logout" type="checkbox" <?= $rSettings["ip_logout"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="restrict_same_ip">
									<?= $language::get('restrict_to_same_ip') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('tie_hls_connections_to_their_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="restrict_same_ip" id="restrict_same_ip" type="checkbox" <?= $rSettings["restrict_same_ip"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="rtmp_random">
									<?= $language::get('random_rtmp_ip') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('use_a_random_ip_for_rmtp_connections') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="rtmp_random" id="rtmp_random" type="checkbox" <?= $rSettings["rtmp_random"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disallow_2nd_ip_con">
									<?= $language::get('disallow_2nd_ip') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disallow_connection_from_different_ip_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disallow_2nd_ip_con" id="disallow_2nd_ip_con" type="checkbox" <?= $rSettings["disallow_2nd_ip_con"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disallow_2nd_ip_max">
									<?= $language::get('disallow_if_connections') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('maximum_amount_of_connections_a_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="disallow_2nd_ip_max" name="disallow_2nd_ip_max" value="<?= intval($rSettings["disallow_2nd_ip_max"]) ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('restream_prevention') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="restream_deny_unauthorised">
									<?= $language::get('xc_vm_detect_deny') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('deny_connections_from_nonrestreamers_who_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="restream_deny_unauthorised" id="restream_deny_unauthorised" type="checkbox" <?= $rSettings["restream_deny_unauthorised"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="detect_restream_block_user">
									<?= $language::get('xc_vm_detect_ban_lines') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('ban_lines_of_nonrestreamers_who_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="detect_restream_block_user" id="detect_restream_block_user" type="checkbox" <?= $rSettings["detect_restream_block_user"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="block_streaming_servers">
									<?= $language::get('block_hosting_servers') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_block_servers_from_server_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="block_streaming_servers" id="block_streaming_servers" type="checkbox" <?= $rSettings["block_streaming_servers"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="block_proxies">
									<?= $language::get('block_proxies_vpn_s') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_block_proxies_and_vpns_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="block_proxies" id="block_proxies" type="checkbox" <?= $rSettings["block_proxies"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('spam_prevention') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="flood_limit">
									<?= $language::get('flood_limit') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('number_of_attempts_before_ip_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="flood_limit" name="flood_limit" value="<?= htmlspecialchars($rSettings["flood_limit"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="flood_seconds">
									<?= $language::get('per_seconds') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('number_of_seconds_between_requests') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="flood_seconds" name="flood_seconds" value="<?= htmlspecialchars($rSettings["flood_seconds"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="auth_flood_limit">
									<?= $language::get('auth_flood_limit') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('number_of_attempts_before_connections_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="auth_flood_limit" name="auth_flood_limit" value="<?= htmlspecialchars($rSettings["auth_flood_limit"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="auth_flood_seconds">
									<?= $language::get('auth_flood_seconds') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('number_of_seconds_to_calculate_number_of_requests_for') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="auth_flood_seconds" name="auth_flood_seconds" value="<?= htmlspecialchars($rSettings["auth_flood_seconds"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="auth_flood_sleep">
									<?= $language::get('auth_flood_sleep') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_sleep_for_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="auth_flood_sleep" name="auth_flood_sleep" value="<?= htmlspecialchars($rSettings["auth_flood_sleep"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="flood_ips_exclude">
									<?= $language::get('flood_ip_exclusions') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('separate_each_ip_with_a_comma') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control" id="flood_ips_exclude" name="flood_ips_exclude" value="<?= htmlspecialchars($rSettings["flood_ips_exclude"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="auto_unban_ip">
									<?= $language::get('auto_unban_ip') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="Automatically remove automatic (flood/bruteforce) IP bans once the ban duration elapses. Manual bans are kept."></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="auto_unban_ip" id="auto_unban_ip" type="checkbox" <?= ($rSettings["auto_unban_ip"] ?? 0) == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="ban_duration_value">
									<?= $language::get('ban_duration') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="How long an automatic IP ban lasts before it is auto-removed."></i>
								</label>

								<div class="col-md-1">
									<input type="text" class="form-control text-center" id="ban_duration_value" name="ban_duration_value" value="<?= htmlspecialchars($rSettings["ban_duration_value"] ?? 24) ?>">
								</div>

								<div class="col-md-1">
									<select name="ban_duration_unit" id="ban_duration_unit" class="form-control">
										<?php foreach (["minutes", "hours", "days"] as $rU): ?>
											<option value="<?= $rU ?>" <?= ($rSettings["ban_duration_unit"] ?? 'hours') === $rU ? ' selected' : '' ?>><?= ucfirst($rU) ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="bruteforce_mac_attempts">
									<?= $language::get('detect_mac_bruteforce') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_detect_and_block_ip_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="bruteforce_mac_attempts" name="bruteforce_mac_attempts" value="<?= htmlspecialchars($rSettings["bruteforce_mac_attempts"] ?? '') ?: 0 ?>">
								</div>

								<label class="col-md-4 col-form-label" for="bruteforce_username_attempts">
									<?= $language::get('detect_username_bruteforce') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_detect_and_block_ip_tooltip_title') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="bruteforce_username_attempts" name="bruteforce_username_attempts" value="<?= htmlspecialchars($rSettings["bruteforce_username_attempts"] ?? '') ?: 0 ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="bruteforce_frequency">
									<?= $language::get('bruteforce_frequency') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('time_between_attempts_for_mac_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="bruteforce_frequency" name="bruteforce_frequency" value="<?= htmlspecialchars($rSettings["bruteforce_frequency"] ?? '') ?: 0 ?>">
								</div>

								<label class="col-md-4 col-form-label" for="login_flood">
									<?= $language::get('maximum_login_attempts') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_many_login_attempts_are_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="login_flood" name="login_flood" value="<?= htmlspecialchars($rSettings["login_flood"] ?? '') ?: 0 ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="max_simultaneous_downloads">
									<?= $language::get('max_simultaneous_downloads') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('max_number_of_simultaneous_epg_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="max_simultaneous_downloads" name="max_simultaneous_downloads" value="<?= htmlspecialchars($rSettings["max_simultaneous_downloads"] ?? '') ?>">
								</div>
							</div>

						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="api" role="tabpanel">
					<div class="row">
						<div class="col-12">

							<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="tmdb_api_key">
									<?= $language::get('tmdb_key') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('get_your_api_key_at_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="tmdb_api_key" name="tmdb_api_key" value="<?= htmlspecialchars($rSettings["tmdb_api_key"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="tmdb_language">
									<?= $language::get('tmdb_language') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_language_for_tmdb_requests_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<select name="tmdb_language" id="tmdb_language" class="form-control" data-toggle="select2">
										<?php foreach (LocaleReference::tmdbLanguages() as $rKey => $rLanguage): ?>
											<option value="<?= $rKey ?>" <?= $rSettings["tmdb_language"] == $rKey ? ' selected' : '' ?>>
												<?= $rLanguage ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="download_images">
									<?= $language::get('download_images') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('if_this_option_is_set_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="download_images" id="download_images" type="checkbox" <?= $rSettings["download_images"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="api_redirect">
									<?= $language::get('api_redirect') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('redirect_api_stream_requests_using_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="api_redirect" id="api_redirect" type="checkbox" <?= $rSettings["api_redirect"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="movie_year_append">
									<?= $language::get('append_movie_year') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_append_the_movie_year_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="movie_year_append" id="movie_year_append" class="form-control" data-toggle="select2">
										<?php foreach (["Brackets", "Hyphen", "Disabled"] as $rKey => $rValue): ?>
											<option value="<?= $rKey ?>" <?= $rSettings["movie_year_append"] == $rKey ? ' selected' : '' ?>>
												<?= $rValue ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="api_container">
									<?= $language::get('api_container') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_container_to_use_in_android_smart_tv_apps') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="api_container" id="api_container" class="form-control" data-toggle="select2">
										<?php foreach (["ts" => "MPEG-TS", "m3u8" => "HLS"] as $rKey => $rValue): ?>
											<option value="<?= $rKey ?>" <?= $rSettings["api_container"] == $rKey ? ' selected' : '' ?>>
												<?= $rValue ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="cache_playlists">
									<?= $language::get('cache_playlists_for') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('if_this_value_is_more_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="cache_playlists" name="cache_playlists" value="<?= intval($rSettings["cache_playlists"]) ?>">
								</div>

								<label class="col-md-4 col-form-label" for="playlist_from_mysql">
									<?= $language::get('grab_playlists_from_mysql') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_this_to_read_streams_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="playlist_from_mysql" id="playlist_from_mysql" type="checkbox" <?= $rSettings["playlist_from_mysql"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="force_epg_timezone">
									<?= $language::get('force_epg_to_utc_timezone') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('ensure_all_epg_is_generated_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="force_epg_timezone" id="force_epg_timezone" type="checkbox" <?= $rSettings["force_epg_timezone"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="keep_protocol">
									<?= $language::get('keep_request_protocol') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('keep_the_requested_protocol_http_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="keep_protocol" id="keep_protocol" type="checkbox" <?= $rSettings["keep_protocol"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="parse_type">
									<?= $language::get('vod_parser') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('whether_to_use_guessit_or_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="parse_type" id="parse_type" class="form-control" data-toggle="select2">
										<?php foreach (["guessit" => "GuessIt", "ptn" => "PTN"] as $rKey => $rValue): ?>
											<option value="<?= $rKey ?>" <?= $rSettings["parse_type"] == $rKey ? ' selected' : '' ?>>
												<?= $rValue ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="cloudflare">
									<?= $language::get('enable_cloudflare') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allow_cloudflare_ips_to_connect_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="cloudflare" id="cloudflare" type="checkbox" <?= $rSettings["cloudflare"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

						</div>
					</div>

					<div class="row">
						<div class="col-12">

							<h5 class="card-title mb-4"><?= $language::get('legacy_support') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="legacy_get">
									<?= $language::get('legacy_playlist_url') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('rewrite_getphp_requests_to_the_new_playlist_url') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="legacy_get" id="legacy_get" type="checkbox" <?= $rSettings["legacy_get"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="legacy_xmltv">
									<?= $language::get('legacy_xmltv_url') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('rewrite_xmltvphp_requests_to_the_new_epg_url') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="legacy_xmltv" id="legacy_xmltv" type="checkbox" <?= $rSettings["legacy_xmltv"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="legacy_panel_api">
									<?= $language::get('legacy_panel_api') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('rewrite_panel_apiphp_requests_to_the_new_xc_vm_player_api') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="legacy_panel_api" id="legacy_panel_api" type="checkbox" <?= $rSettings["legacy_panel_api"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="show_category_duplicates">
									<?= $language::get('duplicate_streams_in_legacy_apps') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('xcvm_was_the_first_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_category_duplicates" id="show_category_duplicates" type="checkbox" <?= $rSettings["show_category_duplicates"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('api_services') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="allowed_ips_admin">
									<?= $language::get('admin_streaming_ip_s') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allowed_ips_to_access_streaming_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="allowed_ips_admin" name="allowed_ips_admin" value="<?= htmlspecialchars($rSettings["allowed_ips_admin"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="api_ips">
									<?= $language::get('api_ip_s') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allowed_ips_to_access_the_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="api_ips" name="api_ips" value="<?= htmlspecialchars(is_array($rSettings["api_ips"] ?? '') ? implode(',', $rSettings["api_ips"]) : ($rSettings["api_ips"] ?? '')) ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="api_pass">
									<?= $language::get('api_password') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('password_required_to_access_the_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="password" class="form-control" id="api_pass" name="api_pass" value="<?= htmlspecialchars($rSettings["api_pass"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="platform_api_key">
									<?= $language::get('modules_api_key') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="Shared XC_VM platform API key for this account. Used by MAIN and all load balancers to download store modules. Get it from your account on the platform website."></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="platform_api_key" name="platform_api_key" autocomplete="off" spellcheck="false" value="<?= htmlspecialchars($rSettings["platform_api_key"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_xmltv">
									<?= $language::get('disable_epg_download_line') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_disallow_epg_downloads_in_xmltv_format') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_xmltv" id="disable_xmltv" type="checkbox" <?= $rSettings["disable_xmltv"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disable_xmltv_restreamer">
									<?= $language::get('disable_epg_download_restreamer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_disallow_epg_downloads_in_xmltv_format') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_xmltv_restreamer" id="disable_xmltv_restreamer" type="checkbox" <?= $rSettings["disable_xmltv_restreamer"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_playlist">
									<?= $language::get('disable_playlist_download_line') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_remove_the_ability_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_playlist" id="disable_playlist" type="checkbox" <?= $rSettings["disable_playlist"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disable_playlist_restreamer">
									<?= $language::get('disable_playlist_download_restreamer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_remove_the_ability_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_playlist_restreamer" id="disable_playlist_restreamer" type="checkbox" <?= $rSettings["disable_playlist_restreamer"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_player_api">
									<?= $language::get('disable_player_api') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_stop_android_apps_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_player_api" id="disable_player_api" type="checkbox" <?= $rSettings["disable_player_api"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disable_enigma2">
									<?= $language::get('disable_enigma2_api') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_stop_enigma_devices_from_connecting') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_enigma2" id="disable_enigma2" type="checkbox" <?= $rSettings["disable_enigma2"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_ministra">
									<?= $language::get('disable_ministra_api') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_to_stop_mag_devices_from_connecting') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_ministra" id="disable_ministra" type="checkbox" <?= $rSettings["disable_ministra"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="verify_host">
									<?= $language::get('verify_hosts') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('verify_domain_names_and_ips_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="verify_host" id="verify_host" type="checkbox" <?= $rSettings["verify_host"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('maxmind_geoip_api') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="maxmind_account_id"><?= $language::get('account_id') ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="Your MaxMind account number (found at maxmind.com -> My Account)"></i></label>
								<div class="col-md-2"><input type="text" class="form-control" id="maxmind_account_id" name="maxmind_account_id" value="<?= htmlspecialchars($rSettings["maxmind_account_id"] ?? '') ?>" placeholder="123456" autocomplete="off"></div>
								<label class="col-md-4 col-form-label" for="maxmind_license_key"><?= $language::get('license_key') ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="MaxMind license key with GeoIP Download permissions"></i></label>
								<div class="col-md-2"><input type="password" class="form-control" id="maxmind_license_key" name="maxmind_license_key" value="<?= htmlspecialchars($rSettings["maxmind_license_key"] ?? '') ?>" placeholder="****************" autocomplete="new-password"></div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="maxmind_editions"><?= $language::get('editions') ?> <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="GeoIP database editions to download. Leave empty to use GitHub fallback (GeoLite2 only)"></i></label>
								<div class="col-md-8">
									<?php
									$rSelectedEditions = json_decode($rSettings["maxmind_editions"] ?? '[]', true) ?: ["GeoLite2-Country", "GeoLite2-City"];
									$rAvailableEditions = class_exists("MaxMindUpdater")
										? MaxMindUpdater::availableEditions()
										: ["GeoLite2-Country" => "GeoLite2 Country", "GeoLite2-City" => "GeoLite2 City"];
									?>
									<select name="maxmind_editions[]" id="maxmind_editions" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder') ?>">
										<?php foreach ($rAvailableEditions as $rEditionKey => $rEditionLabel) { ?>
											<option value="<?= htmlspecialchars($rEditionKey) ?>" <?php if (in_array($rEditionKey, $rSelectedEditions)) {
																										echo ' selected';
																									} ?>><?= htmlspecialchars($rEditionLabel) ?></option>
										<?php } ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<div class="col-md-12 text-muted"><small><i class="mdi mdi-information-outline mr-1"></i>When MaxMind credentials are configured, <code>binaries</code> and <code>cron:maxmind</code> (every Tuesday) download selected databases from the MaxMind API. If credentials are empty, XC_VM falls back to GitHub GeoLite2 files. GeoLite2 editions are free with a MaxMind account; GeoIP2 editions require an active paid subscription.</small></div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('encryption') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="live_streaming_pass">
									<?= $language::get('system_api_encryption_key') ?>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="live_streaming_pass" name="live_streaming_pass" value="<?= htmlspecialchars(SettingsManager::getAll()["live_streaming_pass"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<div class="col-md-12 text-muted"><small><i class="mdi mdi-information-outline mr-1"></i>Server-wide secret used to encrypt stream access tokens (HLS / RTMP / portal) and stored HMAC API keys. This is not a password and is unrelated to Ministra. Changing it invalidates every existing encrypted token and stored API key.</small></div>
							</div>

						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="streaming" role="tabpanel">
					<div class="row">
						<div class="col-12">
							<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="enable_isp_lock">
									<?= $language::get('enable_isp_lock') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_disable_isp_lock_globally') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="enable_isp_lock" id="enable_isp_lock" type="checkbox" <?= $rSettings["enable_isp_lock"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="block_svp">
									<?= $language::get('enable_asn_lock') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enable_disable_asn_lock_globally') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="block_svp" id="block_svp" type="checkbox" <?= $rSettings["block_svp"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_ts">
									<?= $language::get('disable_mpeg_ts_output') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disable_mpeg_ts_for_all_clients_and_devices') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_ts" id="disable_ts" type="checkbox" <?= $rSettings["disable_ts"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disable_ts_allow_restream">
									<?= $language::get('allow_restreamers_mpeg_ts') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('override_to_allow_restreamers_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_ts_allow_restream" id="disable_ts_allow_restream" type="checkbox" <?= $rSettings["disable_ts_allow_restream"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_hls">
									<?= $language::get('disable_hls_output') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disable_hls_for_all_clients_and_devices') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_hls" id="disable_hls" type="checkbox" <?= $rSettings["disable_hls"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disable_hls_allow_restream">
									<?= $language::get('allow_restreamers_hls') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('override_to_allow_restreamers_to_tooltip_title') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_hls_allow_restream" id="disable_hls_allow_restream" type="checkbox" <?= $rSettings["disable_hls_allow_restream"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_rtmp">
									<?= $language::get('disable_rtmp_output') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disable_rtmp_for_all_clients_and_devices') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_rtmp" id="disable_rtmp" type="checkbox" <?= $rSettings["disable_rtmp"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disable_rtmp_allow_restream">
									<?= $language::get('allow_restreamers_rtmp') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('override_to_allow_restreamers_to_tooltip_title') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_rtmp_allow_restream" id="disable_rtmp_allow_restream" type="checkbox" <?= $rSettings["disable_rtmp_allow_restream"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="case_sensitive_line">
									<?= $language::get('case_sensitive_lines') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('case_sensitive_username_and_password') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="case_sensitive_line" id="case_sensitive_line" type="checkbox" <?= $rSettings["case_sensitive_line"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="county_override_1st">
									<?= $language::get('override_country_with_first') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('override_country_with_first_connected') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="county_override_1st" id="county_override_1st" type="checkbox" <?= $rSettings["county_override_1st"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="encrypt_hls">
									<?= $language::get('encrypt_hls_segments') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('encrypt_all_hls_streams_with_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="encrypt_hls" id="encrypt_hls" type="checkbox" <?= $rSettings["encrypt_hls"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="disallow_empty_user_agents">
									<?= $language::get('disallow_empty_ua') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('don_t_allow_connections_from_clients_with_no_user_agent') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disallow_empty_user_agents" id="disallow_empty_user_agents" type="checkbox" <?= $rSettings["disallow_empty_user_agents"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="shared_mount_prefixes">
									<?= $language::get('shared_mount_prefixes') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('shared_mount_prefixes_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<select class="form-control" id="shared_mount_prefixes" name="shared_mount_prefixes[]" multiple="multiple">
										<?php
										$rRaw = $rSettings["shared_mount_prefixes"] ?? [];
										$rMountPrefixes = is_array($rRaw) ? $rRaw : (json_decode($rRaw, true) ?: []);
										foreach ($rMountPrefixes as $rPrefix):
										?>
											<option value="<?= htmlspecialchars($rPrefix) ?>" selected><?= htmlspecialchars($rPrefix) ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="vod_bitrate_plus">
									<?= $language::get('vod_bitrate_buffer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('additional_buffer_when_streaming_vod') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="vod_bitrate_plus" name="vod_bitrate_plus" value="<?= htmlspecialchars($rSettings["vod_bitrate_plus"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="vod_limit_perc">
									<?= $language::get('vod_limit_at') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('limit_vod_after_x_has_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="vod_limit_perc" name="vod_limit_perc" value="<?= htmlspecialchars($rSettings["vod_limit_perc"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="user_auto_kick_hours">
									<?= $language::get('auto_kick_hours') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_kick_connections_that_are_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="user_auto_kick_hours" name="user_auto_kick_hours" value="<?= htmlspecialchars($rSettings["user_auto_kick_hours"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="use_mdomain_in_lists">
									<?= $language::get('use_domain_name_in_api') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('use_domain_name_in_lists') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="use_mdomain_in_lists" id="use_mdomain_in_lists" type="checkbox" <?= $rSettings["use_mdomain_in_lists"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="encrypt_playlist">
									<?= $language::get('encrypt_playlists_not_worked') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('encrypt_line_credentials_in_playlist_files') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="encrypt_playlist" id="encrypt_playlist" type="checkbox" <?= $rSettings["encrypt_playlist"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="encrypt_playlist_restreamer">
									<?= $language::get('encrypt_restreamer_playlists') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('encrypt_line_credentials_in_restreamer_playlist_files') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="encrypt_playlist_restreamer" id="encrypt_playlist_restreamer" type="checkbox" <?= $rSettings["encrypt_playlist_restreamer"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="restrict_playlists">
									<?= $language::get('restrictions_on_playlists_epg') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('verify_useragent_ip_restrictions_isp_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="restrict_playlists" id="restrict_playlists" type="checkbox" <?= $rSettings["restrict_playlists"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="ignore_invalid_users">
									<?= $language::get('ignore_invalid_credentials') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('enabling_this_option_will_make_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="ignore_invalid_users" id="ignore_invalid_users" type="checkbox" <?= $rSettings["ignore_invalid_users"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="client_prebuffer">
									<?= $language::get('client_prebuffer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_much_data_in_seconds_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="client_prebuffer" name="client_prebuffer" value="<?= htmlspecialchars($rSettings["client_prebuffer"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="restreamer_prebuffer">
									<?= $language::get('restreamer_prebuffer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_much_data_in_seconds_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="restreamer_prebuffer" name="restreamer_prebuffer" value="<?= htmlspecialchars($rSettings["restreamer_prebuffer"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="fanout_hls_window">
									<?= $language::get('fanout_hls_window') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: how many HLS segments the playlist lists (1-20)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_hls_window" name="fanout_hls_window" value="<?= htmlspecialchars($rSettings["fanout_hls_window"] ?? '') ?>">
								</div>
								<label class="col-md-4 col-form-label" for="fanout_default_prebuffer_sec">
									<?= $language::get('fanout_default_prebuffer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: fallback per-viewer join burst when a /live request carries no prebuffer (0-120s, 0 = current GOP)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_default_prebuffer_sec" name="fanout_default_prebuffer_sec" value="<?= htmlspecialchars($rSettings["fanout_default_prebuffer_sec"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="fanout_grace_sec">
									<?= $language::get('fanout_source_grace') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: seconds a source stays alive after the last viewer leaves (1-3600)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_grace_sec" name="fanout_grace_sec" value="<?= htmlspecialchars($rSettings["fanout_grace_sec"] ?? '') ?>">
								</div>
								<label class="col-md-4 col-form-label" for="fanout_write_timeout_sec">
									<?= $language::get('fanout_write_timeout') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: per-write deadline before a stalled live-TS viewer is dropped (1-600s)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_write_timeout_sec" name="fanout_write_timeout_sec" value="<?= htmlspecialchars($rSettings["fanout_write_timeout_sec"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="fanout_idle_buffer_grace_sec">
									<?= $language::get('fanout_idle_buffer_grace') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: no-viewer window before the ring collapses (0-3600s, 0 = gate off)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_idle_buffer_grace_sec" name="fanout_idle_buffer_grace_sec" value="<?= htmlspecialchars($rSettings["fanout_idle_buffer_grace_sec"] ?? '') ?>">
								</div>
								<label class="col-md-4 col-form-label" for="fanout_idle_buffer_ratio">
									<?= $language::get('fanout_idle_buffer_ratio') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: fraction of the buffer kept while a stream is unwatched (0.1-1)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_idle_buffer_ratio" name="fanout_idle_buffer_ratio" value="<?= htmlspecialchars($rSettings["fanout_idle_buffer_ratio"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="fanout_chunk_bytes">
									<?= $language::get('fanout_source_chunk_bytes') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: source read size for daemon-pulled streams, rounded to a multiple of 188 (188-4194304)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_chunk_bytes" name="fanout_chunk_bytes" value="<?= htmlspecialchars($rSettings["fanout_chunk_bytes"] ?? '') ?>">
								</div>
								<label class="col-md-4 col-form-label" for="fanout_max_gop_bytes">
									<?= $language::get('fanout_max_gop_bytes') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: cap on a single join-snapshot GOP for streams without keyframes (188-268435456)."></i>
								</label>
								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fanout_max_gop_bytes" name="fanout_max_gop_bytes" value="<?= htmlspecialchars($rSettings["fanout_max_gop_bytes"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="fanout_source_insecure">
									<?= $language::get('fanout_source_insecure_tls') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="xc_fanout: skip upstream TLS verification when pulling HTTPS sources (on by default; panels often pull self-signed upstreams)."></i>
								</label>
								<div class="col-md-2">
									<div class="form-check form-switch"><input name="fanout_source_insecure" id="fanout_source_insecure" type="checkbox" <?= ($rSettings["fanout_source_insecure"] ?? 1) == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="split_by">
									<?= $language::get('load_balancing') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('preferred_method_of_load_balancing_connections') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="split_by" id="split_by" class="form-control" data-toggle="select2">
										<option value="conn" <?= $rSettings["split_by"] == "conn" ? ' selected' : '' ?>>
											Connections
										</option>

										<option value="maxclients" <?= $rSettings["split_by"] == "maxclients" ? ' selected' : '' ?>>
											Max Clients
										</option>

										<option value="guar_band" <?= $rSettings["split_by"] == "guar_band" ? ' selected' : '' ?>>
											Guaranteed Network Speed
										</option>

										<option value="band" <?= $rSettings["split_by"] == "band" ? ' selected' : '' ?>>
											Detected Network Speed
										</option>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="restreamer_bypass_proxy">
									<?= $language::get('restreamer_bypass_proxy') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('route_restreamers_directly_to_load_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="restreamer_bypass_proxy" id="restreamer_bypass_proxy" type="checkbox" <?= $rSettings["restreamer_bypass_proxy"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="channel_number_type">
									<?= $language::get('channel_sorting_type') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('preferred_method_of_channel_sorting_in_playlists_and_apps') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="channel_number_type" id="channel_number_type" class="form-control" data-toggle="select2">
										<option value="bouquet_new" <?= $rSettings["channel_number_type"] == "bouquet_new" ? ' selected' : '' ?>>
											Bouquet
										</option>

										<option value="bouquet" <?= $rSettings["channel_number_type"] == "bouquet" ? ' selected' : '' ?>>
											Bouquet Legacy
										</option>

										<option value="manual" <?= $rSettings["channel_number_type"] == "manual" ? ' selected' : '' ?>>
											<?= $language::get('manual') ?>
										</option>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="vod_sort_newest">
									<?= $language::get('sort_vod_by_date') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('change_default_sorting_for_vod_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="vod_sort_newest" id="vod_sort_newest" type="checkbox" <?= $rSettings["vod_sort_newest"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="use_buffer">
									<?= $language::get('use_nginx_buffer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('sets_the_proxy_buffering_for_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="use_buffer" id="use_buffer" type="checkbox" <?= $rSettings["use_buffer"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="show_isps">
									<?= $language::get('log_client_isp_s') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('grab_isp_information_for_each_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_isps" id="show_isps" type="checkbox" <?= $rSettings["show_isps"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="online_capacity_interval">
									<?= $language::get('online_capacity_interval') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('interval_at_which_to_check_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="online_capacity_interval" name="online_capacity_interval" value="<?= htmlspecialchars($rSettings["online_capacity_interval"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="monitor_connection_status">
									<?= $language::get('monitor_connection_status') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('monitor_phps_connectionstatus_return_while_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="monitor_connection_status" id="monitor_connection_status" type="checkbox" <?= $rSettings["monitor_connection_status"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="restart_php_fpm">
									<?= $language::get('auto_restart_crashed_php_fpm') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('run_a_cron_that_restarts_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="restart_php_fpm" id="restart_php_fpm" type="checkbox" <?= $rSettings["restart_php_fpm"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="kill_rogue_ffmpeg">
									<?= $language::get('kill_rogue_ffmpeg_pid_s') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_enabled_ffmpeg_pids_will_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="kill_rogue_ffmpeg" id="kill_rogue_ffmpeg" type="checkbox" <?= $rSettings["kill_rogue_ffmpeg"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="create_expiration">
									<?= $language::get('redirect_expiration') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_in_seconds_before_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="create_expiration" name="create_expiration" value="<?= htmlspecialchars($rSettings["create_expiration"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="read_native_hls">
									<?= $language::get('hls_read_native') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('force_read_native_on_for_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="read_native_hls" id="read_native_hls" type="checkbox" <?= $rSettings["read_native_hls"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="read_buffer_size">
									<?= $language::get('read_buffer_size') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('amount_of_buffer_to_use_when_reading_files_in_chunks') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="read_buffer_size" name="read_buffer_size" value="<?= htmlspecialchars($rSettings["read_buffer_size"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="connection_sync_timer">
									<?= $language::get('redis_connection_sync_timer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('time_between_runs_of_the_redis_connection_sync_script') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="connection_sync_timer" name="connection_sync_timer" value="<?= htmlspecialchars($rSettings["connection_sync_timer"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="allow_cdn_access">
									<?= $language::get('allow_cdn_forwarding') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allow_xforwardedfor_to_forward_the_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="allow_cdn_access" id="allow_cdn_access" type="checkbox" <?= $rSettings["allow_cdn_access"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="stop_failures">
									<?= $language::get('max_failures') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_many_failures_before_exiting_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="stop_failures" name="stop_failures" value="<?= htmlspecialchars($rSettings["stop_failures"] ?? '') ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('on_demand_settings') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="on_demand_instant_off">
									<?= $language::get('instant_off') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_a_client_disconnects_from_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="on_demand_instant_off" id="on_demand_instant_off" type="checkbox" <?= $rSettings["on_demand_instant_off"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="on_demand_failure_exit">
									<?= $language::get('exit_on_failure') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('if_an_ondemand_stream_fails_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="on_demand_failure_exit" id="on_demand_failure_exit" type="checkbox" <?= $rSettings["on_demand_failure_exit"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="on_demand_wait_time">
									<?= $language::get('wait_timeout') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_should_the_client_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="on_demand_wait_time" name="on_demand_wait_time" value="<?= htmlspecialchars($rSettings["on_demand_wait_time"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="request_prebuffer">
									<?= $language::get('request_prebuffer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('when_you_request_a_stream_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="request_prebuffer" id="request_prebuffer" type="checkbox" <?= $rSettings["request_prebuffer"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="ondemand_balance_equal">
									<?= $language::get('balance_as_live') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('treat_ondemand_servers_equal_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="ondemand_balance_equal" id="ondemand_balance_equal" type="checkbox" <?= $rSettings["ondemand_balance_equal"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('ondemand_scanner') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="on_demand_checker">
									<?= $language::get('enable_scanner') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('periodically_probe_ondemand_streams_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="on_demand_checker" id="on_demand_checker" type="checkbox" <?= $rSettings["on_demand_checker"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="on_demand_scan_time">
									<?= $language::get('scan_time') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_often_to_scan_a_stream_in_seconds') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="on_demand_scan_time" name="on_demand_scan_time" value="<?= htmlspecialchars($rSettings["on_demand_scan_time"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="on_demand_max_probe">
									<?= $language::get('max_probe_time') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_many_seconds_to_probe_the_stream_for_before_cancelling') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="on_demand_max_probe" name="on_demand_max_probe" value="<?= htmlspecialchars($rSettings["on_demand_max_probe"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="on_demand_scan_keep">
									<?= $language::get('keep_logs_for') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_many_seconds_to_keep_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="on_demand_scan_keep" name="on_demand_scan_keep" value="<?= htmlspecialchars($rSettings["on_demand_scan_keep"] ?? '') ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('encoding_queue_settings') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="max_encode_movies">
									<?= $language::get('max_movie_encodes') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('maximum_number_of_movies_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="max_encode_movies" name="max_encode_movies" value="<?= htmlspecialchars($rSettings["max_encode_movies"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="max_encode_cc">
									<?= $language::get('max_channel_encodes') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('maximum_number_of_created_channels_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="max_encode_cc" name="max_encode_cc" value="<?= htmlspecialchars($rSettings["max_encode_cc"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="queue_loop">
									<?= $language::get('queue_loop_timer') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_wait_between_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="queue_loop" name="queue_loop" value="<?= htmlspecialchars($rSettings["queue_loop"] ?? '') ?>">
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('segment_settings') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="seg_time">
									<?= $language::get('segment_duration') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('duration_of_individual_segments_when_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="seg_time" name="seg_time" value="<?= htmlspecialchars($rSettings["seg_time"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="seg_list_size">
									<?= $language::get('list_size') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('number_of_segments_in_the_hls_playlist') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="seg_list_size" name="seg_list_size" value="<?= htmlspecialchars($rSettings["seg_list_size"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="seg_delete_threshold">
									<?= $language::get('delete_threshold') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_many_old_segments_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="seg_delete_threshold" name="seg_delete_threshold" value="<?= htmlspecialchars($rSettings["seg_delete_threshold"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="segment_wait_time">
									<?= $language::get('max_segment_wait_time') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('maximum_amount_of_seconds_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="segment_wait_time" name="segment_wait_time" value="<?= htmlspecialchars($rSettings["segment_wait_time"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="stream_max_analyze">
									<?= $language::get('analysis_duration') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_analyse_a_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="stream_max_analyze" name="stream_max_analyze" value="<?= htmlspecialchars($rSettings["stream_max_analyze"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="probesize">
									<?= $language::get('probe_size') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('amount_of_data_to_be_probed_in_bytes') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="probesize" name="probesize" value="<?= htmlspecialchars($rSettings["probesize"] ?? '') ?>">
								</div>
							</div>

							<?php
							$rFfmpegGpuBuilds = FfmpegBinaries::gpuCapable();
							$rCpuOpts = array_keys(FfmpegBinaries::available()) ?: ["8.0", "7.1", "4.0"];
							if (!empty($rSettings["ffmpeg_cpu"]) && !in_array($rSettings["ffmpeg_cpu"], $rCpuOpts, true)) {
								$rCpuOpts[] = $rSettings["ffmpeg_cpu"];
							}
							$rGpuOpts = array_keys($rFfmpegGpuBuilds);
							if (!empty($rSettings["ffmpeg_gpu"]) && !in_array($rSettings["ffmpeg_gpu"], $rGpuOpts, true)) {
								$rGpuOpts[] = $rSettings["ffmpeg_gpu"];
							}
							?>
							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="ffmpeg_cpu">
									<?= $language::get('ffmpeg_version_cpu') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('which_version_of_ffmpeg_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="ffmpeg_cpu" id="ffmpeg_cpu" class="form-control" data-toggle="select2">
										<?php foreach ($rCpuOpts as $rValue): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["ffmpeg_cpu"] == $rValue ? ' selected' : '' ?>>
												v<?= $rValue ?><?= isset($rFfmpegGpuBuilds[$rValue]) ? ' (GPU)' : '' ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="ffmpeg_gpu">
									<?= $language::get('ffmpeg_version_gpu') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('which_version_of_ffmpeg_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="ffmpeg_gpu" id="ffmpeg_gpu" class="form-control" data-toggle="select2">
										<option value="" <?= empty($rSettings["ffmpeg_gpu"]) ? ' selected' : '' ?>>Same as CPU</option>
										<?php foreach ($rGpuOpts as $rValue): ?>
											<option value="<?= $rValue ?>" <?= ($rSettings["ffmpeg_gpu"] ?? '') === $rValue ? ' selected' : '' ?>>
												v<?= $rValue ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="ffmpeg_warnings">
									<?= $language::get('ffmpeg_show_warnings') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('instruct_ffmpeg_to_save_warnings_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="ffmpeg_warnings" id="ffmpeg_warnings" type="checkbox" <?= $rSettings["ffmpeg_warnings"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="ignore_keyframes">
									<?= $language::get('ignore_keyframes') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allow_segments_to_start_on_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="ignore_keyframes" id="ignore_keyframes" type="checkbox" <?= $rSettings["ignore_keyframes"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="dts_legacy_ffmpeg">
									<?= $language::get('dts_use_ffmpeg_v4_0') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_switch_to_legacy_ffmpeg_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="dts_legacy_ffmpeg" id="dts_legacy_ffmpeg" type="checkbox" <?= $rSettings["dts_legacy_ffmpeg"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="php_loopback">
									<?= $language::get('loopback_streams_via_php') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('dont_use_ffmpeg_to_handle_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="php_loopback" id="php_loopback" type="checkbox" <?= $rSettings["php_loopback"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('stream_monitor_settings') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="audio_restart_loss">
									<?= $language::get('restart_on_audio_loss') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('restart_stream_periodically_if_no_audio_is_detected') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="audio_restart_loss" id="audio_restart_loss" type="checkbox" <?= $rSettings["audio_restart_loss"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="priority_backup">
									<?= $language::get('priority_backup') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('switch_back_to_the_first_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="priority_backup" id="priority_backup" type="checkbox" <?= $rSettings["priority_backup"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="probe_extra_wait">
									<?= $language::get('probe_duration') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_wait_after_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="probe_extra_wait" name="probe_extra_wait" value="<?= htmlspecialchars($rSettings["probe_extra_wait"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="stream_fail_sleep">
									<?= $language::get('stream_failure_sleep') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_to_wait_in_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="stream_fail_sleep" name="stream_fail_sleep" value="<?= htmlspecialchars($rSettings["stream_fail_sleep"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="fps_delay">
									<?= $language::get('fps_start_delay') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('how_long_in_seconds_to_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="fps_delay" name="fps_delay" value="<?= htmlspecialchars($rSettings["fps_delay"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="fps_check_type">
									<?= $language::get('fps_check_type') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('whether_to_use_progress_info_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="fps_check_type" id="fps_check_type" class="form-control" data-toggle="select2">
										<?php foreach (["Progress Info", "avg_frame_rate"] as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["fps_check_type"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="api_probe">
									<?= $language::get('probe_via_api') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('use_api_calls_to_probe_sources_from_xc_vm_servers') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="api_probe" id="api_probe" type="checkbox" <?= $rSettings["api_probe"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<h5 class="card-title mb-4"><?= $language::get('off_air_videos') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_not_on_air_video">
									<?= $language::get('stream_down_video') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_this_video_when_a_stream_isnt_on_air') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_not_on_air_video" id="show_not_on_air_video" type="checkbox" <?= $rSettings["show_not_on_air_video"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<div class="col-md-6">
									<input type="text" class="form-control" id="not_on_air_video_path" name="not_on_air_video_path" value="<?= htmlspecialchars($rSettings["not_on_air_video_path"] ?? '') ?>" placeholder="<?= $language::get('leave_blank_to_use_default_xc_vm_video') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_banned_video">
									<?= $language::get('banned_video') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_this_video_when_a_banned_line_accesses_a_stream') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_banned_video" id="show_banned_video" type="checkbox" <?= $rSettings["show_banned_video"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<div class="col-md-6">
									<input type="text" class="form-control" id="banned_video_path" name="banned_video_path" value="<?= htmlspecialchars($rSettings["banned_video_path"] ?? '') ?>" placeholder="<?= $language::get('leave_blank_to_use_default_xc_vm_video') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_expired_video">
									<?= $language::get('expired_video') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_this_video_when_an_expired_line_accesses_a_stream') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_expired_video" id="show_expired_video" type="checkbox" <?= $rSettings["show_expired_video"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<div class="col-md-6">
									<input type="text" class="form-control" id="expired_video_path" name="expired_video_path" value="<?= htmlspecialchars($rSettings["expired_video_path"] ?? '') ?>" placeholder="<?= $language::get('leave_blank_to_use_default_xc_vm_video') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_expiring_video">
									<?= $language::get('expiring_video') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_this_video_once_per_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_expiring_video" id="show_expiring_video" type="checkbox" <?= $rSettings["show_expiring_video"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<div class="col-md-6">
									<input type="text" class="form-control" id="expiring_video_path" name="expiring_video_path" value="<?= htmlspecialchars($rSettings["expiring_video_path"] ?? '') ?>" placeholder="<?= $language::get('leave_blank_to_use_default_xc_vm_video') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_connected_video">
									<?= $language::get('2nd_ip_connected_video') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_this_video_when_a_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_connected_video" id="show_connected_video" type="checkbox" <?= $rSettings["show_connected_video"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<div class="col-md-6">
									<input type="text" class="form-control" id="connected_video_path" name="connected_video_path" value="<?= htmlspecialchars($rSettings["connected_video_path"] ?? '') ?>" placeholder="<?= $language::get('leave_blank_to_use_default_xc_vm_video') ?>">
								</div>
							</div>

							<h5 class="card-title mb-4">
								<?= $language::get('allowed_countries') ?>
								<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('select_individual_countries_to_allow_tooltip') ?>"></i>
							</h5>

							<div class="form-group row mb-4">
								<div class="col-md-12">
									<select name="allow_countries[]" id="allow_countries" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder') ?>">
										<?php
										$rAllowedCountries = is_array($rSettings["allow_countries"])
											? $rSettings["allow_countries"]
											: json_decode($rSettings["allow_countries"], true);
										?>

										<?php foreach (GeoReference::geoCountries() as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= in_array($rValue, $rAllowedCountries) ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="mag" role="tabpanel">
					<div class="row">
						<div class="col-12">
							<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_all_category_mag">
									<?= $language::get('show_all_categories') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_all_category_on_mag_devices') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_all_category_mag" id="show_all_category_mag" type="checkbox" <?= $rSettings["show_all_category_mag"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="mag_container">
									<?= $language::get('default_container') ?>
								</label>

								<div class="col-md-2">
									<select name="mag_container" id="mag_container" class="form-control" data-toggle="select2">
										<?php foreach (["ts" => "TS", "m3u8" => "M3U8"] as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["mag_container"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="always_enabled_subtitles">
									<?= $language::get('always_enabled_subtitles') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('force_subtitles_to_be_enabled_at_all_times') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="always_enabled_subtitles" id="always_enabled_subtitles" type="checkbox" <?= $rSettings["always_enabled_subtitles"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="enable_connection_problem_indication">
									<?= $language::get('connection_problem_indiciation') ?>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="enable_connection_problem_indication" id="enable_connection_problem_indication" type="checkbox" <?= $rSettings["enable_connection_problem_indication"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="show_tv_channel_logo">
									<?= $language::get('show_channel_logos') ?>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_tv_channel_logo" id="show_tv_channel_logo" type="checkbox" <?= $rSettings["show_tv_channel_logo"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="show_channel_logo_in_preview">
									<?= $language::get('show_preview_channel_logos') ?>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="show_channel_logo_in_preview" id="show_channel_logo_in_preview" type="checkbox" <?= $rSettings["show_channel_logo_in_preview"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="playback_limit">
									<?= $language::get('playback_limit') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('show_warning_message_and_stop_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="playback_limit" name="playback_limit" value="<?= htmlspecialchars($rSettings["playback_limit"] ?? '') ?>">
								</div>

								<label class="col-md-4 col-form-label" for="tv_channel_default_aspect">
									<?= $language::get('default_aspect_ratio') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('set_the_default_aspect_ratio_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="tv_channel_default_aspect" id="tv_channel_default_aspect" class="form-control" data-toggle="select2">
										<?php foreach (["fit", "big", "opt", "exp", "cmb"] as $rValue): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["tv_channel_default_aspect"] == $rValue ? ' selected' : '' ?>>
												<?= $rValue ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="mag_default_type">
									<?= $language::get('default_theme_type') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('whether_to_use_modern_or_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="mag_default_type" id="mag_default_type" class="form-control" data-toggle="select2">
										<?php foreach (["Modern", "Legacy"] as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["mag_default_type"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<label class="col-md-4 col-form-label" for="stalker_theme">
									<?= $language::get('legacy_theme') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('default_ministra_theme_to_be_used_by_mag_devices') ?>"></i>
								</label>

								<div class="col-md-2">
									<select name="stalker_theme" id="stalker_theme" class="form-control" data-toggle="select2">
										<?php foreach (
											[
												"default"    => "Default",
												"digital"    => "Digital",
												"emerald"    => "Emerald",
												"cappucino"  => "Cappucino",
												"ocean_blue" => "Ocean Blue"
											] as $rValue => $rText
										): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["stalker_theme"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="mag_legacy_redirect">
									<?= $language::get('legacy_url_redirect') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('redirect_c_to_ministra_folder_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="mag_legacy_redirect" id="mag_legacy_redirect" type="checkbox" <?= $rSettings["mag_legacy_redirect"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="mag_keep_extension">
									<?= $language::get('keep_url_extension') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('keep_extension_of_live_streams_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="mag_keep_extension" id="mag_keep_extension" type="checkbox" <?= $rSettings["mag_keep_extension"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="mag_disable_ssl">
									<?= $language::get('disable_ssl') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('force_mag_s_to_use_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="mag_disable_ssl" id="mag_disable_ssl" type="checkbox" <?= $rSettings["mag_disable_ssl"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="mag_load_all_channels">
									<?= $language::get('load_channels_on_startup') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('load_all_channel_listings_on_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="mag_load_all_channels" id="mag_load_all_channels" type="checkbox" <?= $rSettings["mag_load_all_channels"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="disable_mag_token">
									<?= $language::get('disable_mag_token') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('disable_verification_of_mag_token_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="disable_mag_token" id="disable_mag_token" type="checkbox" <?= $rSettings["disable_mag_token"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="allowed_stb_types">
									<?= $language::get('allowed_stb_types') ?>
								</label>

								<div class="col-md-8">
									<select name="allowed_stb_types[]" id="allowed_stb_types" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder') ?>">
										<?php
										$rAllowedSTB = is_array($rSettings["allowed_stb_types"])
											? $rSettings["allowed_stb_types"]
											: json_decode($rSettings["allowed_stb_types"], true);
										?>

										<?php foreach ($rAllowedSTB as $rMAG): ?>
											<option selected value="<?= $rMAG ?>"><?= $rMAG ?></option>
										<?php endforeach; ?>

										<?php foreach (array_udiff(DeviceReference::magModels(), $rAllowedSTB, "strcasecmp") as $rMAG): ?>
											<option value="<?= $rMAG ?>"><?= $rMAG ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="allowed_stb_types_for_local_recording">
									<?= $language::get('allowed_stb_recording') ?>
								</label>

								<div class="col-md-8">
									<select name="allowed_stb_types_for_local_recording[]" id="allowed_stb_types_for_local_recording" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder') ?>">
										<?php $rAllowedRecordingSTB = json_decode($rSettings["allowed_stb_types_for_local_recording"], true); ?>

										<?php foreach ($rAllowedRecordingSTB as $rMAG): ?>
											<option selected value="<?= $rMAG ?>"><?= $rMAG ?></option>
										<?php endforeach; ?>

										<?php foreach (array_udiff(DeviceReference::magModels(), $rAllowedRecordingSTB, "strcasecmp") as $rMAG): ?>
											<option value="<?= $rMAG ?>"><?= $rMAG ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="test_download_url">
									<?= $language::get('speedtest_url') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('url_to_a_file_to_download_during_speedtest_on_mag_devices') ?>"></i>
								</label>

								<div class="col-md-8">
									<input type="text" class="form-control" id="test_download_url" name="test_download_url" value="<?= htmlspecialchars($rSettings["test_download_url"] ?? '') ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="mag_message">
									<?= $language::get('information_message') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('message_to_display_when_a_tooltip') ?>"></i>
								</label>

								<div class="col-md-8">
									<textarea rows="6" class="form-control" id="mag_message" name="mag_message"><?= htmlspecialchars(str_replace(["&lt;", "&gt;"], ["<", ">"], $rSettings["mag_message"])) ?></textarea>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="webplayer" role="tabpanel">
					<div class="row">
						<div class="col-12">
							<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="player_allow_playlist">
									<?= $language::get('allow_playlist_download') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allow_clients_to_generate_playlist_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="player_allow_playlist" id="player_allow_playlist" type="checkbox" <?= $rSettings["player_allow_playlist"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="player_allow_bouquet">
									<?= $language::get('allow_bouquet_ordering') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('allow_clients_to_reorder_their_bouquets_from_the_web_player') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="player_allow_bouquet" id="player_allow_bouquet" type="checkbox" <?= $rSettings["player_allow_bouquet"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="player_hide_incompatible">
									<?= $language::get('hide_incompatible_streams') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('hide_streams_that_arent_compatible_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="player_hide_incompatible" id="player_hide_incompatible" type="checkbox" <?= $rSettings["player_hide_incompatible"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-4 col-form-label" for="player_allow_hevc">
									<?= $language::get('mark_hevc_as_compatible') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('mark_hevc_as_compatible_there_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="player_allow_hevc" id="player_allow_hevc" type="checkbox" <?= $rSettings["player_allow_hevc"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="player_blur">
									<?= $language::get('background_blur_px') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('blur_the_background_images_by_x_pixels') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="player_blur" name="player_blur" value="<?= intval($rSettings["player_blur"]) ?>">
								</div>

								<label class="col-md-4 col-form-label" for="player_opacity">
									<?= $language::get('background_opacity') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('adjust_the_background_image_opacity_default_is_10') ?>"></i>
								</label>

								<div class="col-md-2">
									<input type="text" class="form-control text-center" id="player_opacity" name="player_opacity" value="<?= intval($rSettings["player_opacity"]) ?>">
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-4 col-form-label" for="extract_subtitles">
									<?= $language::get('extract_subtitles') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('automatically_extract_subtitles_from_movies_tooltip') ?>"></i>
								</label>

								<div class="col-md-2">
									<div class="form-check form-switch"><input name="extract_subtitles" id="extract_subtitles" type="checkbox" <?= $rSettings["extract_subtitles"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="logs" role="tabpanel">
					<div class="row">
						<div class="col-12">
							<h5 class="card-title mb-4"><?= $language::get('preferences') ?></h5>

							<?php
							$rLogKeepOptions = [
								"Forever",
								3600     => "1 Hour",
								21600    => "6 Hours",
								43200    => "12 Hours",
								86400    => "1 Day",
								259200   => "3 Days",
								604800   => "7 Days",
								1209600  => "14 Days",
								16934400 => "28 Days",
								15552000 => "180 Days",
								31536000 => "365 Days",
							];
							?>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="save_closed_connection">
									<?= $language::get('activity_logs') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"></i>
								</label>

								<div class="col-md-3">
									<div class="form-check form-switch"><input name="save_closed_connection" id="save_closed_connection" type="checkbox" <?= $rSettings["save_closed_connection"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-3 col-form-label" for="keep_activity">
									<?= $language::get('keep_logs_for') ?>
								</label>

								<div class="col-md-3">
									<select name="keep_activity" id="keep_activity" class="form-control" data-toggle="select2">
										<?php foreach ($rLogKeepOptions as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["keep_activity"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="client_logs_save">
									<?= $language::get('client_logs') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"></i>
								</label>

								<div class="col-md-3">
									<div class="form-check form-switch"><input name="client_logs_save" id="client_logs_save" type="checkbox" <?= $rSettings["client_logs_save"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-3 col-form-label" for="keep_client">
									<?= $language::get('keep_logs_for') ?>
								</label>

								<div class="col-md-3">
									<select name="keep_client" id="keep_client" class="form-control" data-toggle="select2">
										<?php foreach ($rLogKeepOptions as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["keep_client"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="save_login_logs">
									<?= $language::get('login_logs') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"></i>
								</label>

								<div class="col-md-3">
									<div class="form-check form-switch"><input name="save_login_logs" id="save_login_logs" type="checkbox" <?= $rSettings["save_login_logs"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-3 col-form-label" for="keep_login">
									<?= $language::get('keep_logs_for') ?>
								</label>

								<div class="col-md-3">
									<select name="keep_login" id="keep_login" class="form-control" data-toggle="select2">
										<?php foreach ($rLogKeepOptions as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["keep_login"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="stream_logs_save">
									<?= $language::get('settings_stream_error_logs') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"></i>
								</label>

								<div class="col-md-3">
									<div class="form-check form-switch"><input name="stream_logs_save" id="stream_logs_save" type="checkbox" <?= $rSettings["stream_logs_save"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-3 col-form-label" for="keep_errors">
									<?= $language::get('keep_logs_for') ?>
								</label>

								<div class="col-md-3">
									<select name="keep_errors" id="keep_errors" class="form-control" data-toggle="select2">
										<?php foreach ($rLogKeepOptions as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["keep_errors"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group row mb-4">
								<label class="col-md-3 col-form-label" for="save_restart_logs">
									<?= $language::get('stream_restart_logs') ?>
									<i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="<?= $language::get('activity_logs_are_saved_when_tooltip') ?>"></i>
								</label>

								<div class="col-md-3">
									<div class="form-check form-switch"><input name="save_restart_logs" id="save_restart_logs" type="checkbox" <?= $rSettings["save_restart_logs"] == 1 ? ' checked' : '' ?> class="form-check-input"></div>
								</div>

								<label class="col-md-3 col-form-label" for="keep_restarts">
									<?= $language::get('keep_logs_for') ?>
								</label>

								<div class="col-md-3">
									<select name="keep_restarts" id="keep_restarts" class="form-control" data-toggle="select2">
										<?php foreach ($rLogKeepOptions as $rValue => $rText): ?>
											<option value="<?= $rValue ?>" <?= $rSettings["keep_restarts"] == $rValue ? ' selected' : '' ?>>
												<?= $rText ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="tab-pane fade" id="info" role="tabpanel">
					<div class="row">
						<div class="col-12">
							<h4 class="card-title mb-4"><?= $language::get('versions') ?></h4>

							<?php
							// [label, value, bootstrap-bg-class, inline-bg-hex] — a distinct colour per row.
							$rVersions = [
								['Geolite2 Version', $GeoLite2, '', '#6f42c1'],
								['GeoIP2-ISP Version', $GeoISP, 'bg-info', ''],
								['PHP', phpversion(), 'bg-success', ''],
								['Nginx', $Nginx, 'bg-danger', ''],
								['Binaries', $BinVersion ?? 'N/A', 'bg-warning', ''],
								['OS', $BinOS ?? 'N/A', 'bg-secondary', ''],
								['Daemon (xc_fanout)', $FanoutVersion, 'bg-dark', ''],
								['xcvm_core', $XcvmCoreVersion, '', '#e83e8c'],
								['yt-dlp', $YtDlpVersion, '', '#20c997'],
							];
							?>
							<table class="table table-striped table-bordered">
								<tbody>
									<?php foreach (array_chunk($rVersions, 2) as $rPair): ?>
										<tr>
											<?php for ($i = 0; $i < 2; $i++): $rV = $rPair[$i] ?? null; ?>
												<td class="text-center" style="font-size: 0.85rem;"><?= $rV ? htmlspecialchars((string) $rV[0], ENT_QUOTES) : ''; ?></td>
												<td class="text-center">
													<?php if ($rV): ?>
														<span class="badge <?= $rV[2]; ?>" style="font-size: 0.8rem;<?= $rV[3] ? 'background:' . $rV[3] . ';color:#fff;' : ''; ?>"><?= htmlspecialchars((string) $rV[1], ENT_QUOTES); ?></span>
													<?php endif; ?>
												</td>
											<?php endfor; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<h4 class="card-title mb-4">FFmpeg</h4>

							<table class="table table-striped table-bordered">
								<thead class="thead-light">
									<tr>
										<th class="text-center" style="font-size: 0.85rem;"><?= $language::get('version') ?></th>
										<th class="text-center" style="font-size: 0.85rem;"><?= $language::get('build') ?></th>
										<th class="text-center" style="font-size: 0.85rem;">GPU</th>
										<th class="text-center" style="font-size: 0.85rem;"><?= $language::get('hardware_encoders') ?></th>
									</tr>
								</thead>

								<tbody>
									<?php $rFfmpegBuilds = FfmpegBinaries::available(); ?>
									<?php if (empty($rFfmpegBuilds)): ?>
										<tr>
											<td colspan="4" class="text-center" style="font-size: 0.85rem;">N/A</td>
										</tr>
									<?php else: ?>
										<?php foreach ($rFfmpegBuilds as $rVer => $rInfo): ?>
											<tr>
												<td class="text-center" style="font-size: 0.85rem;">
													<button type="button" class="btn btn-info btn-sm" style="font-size: 0.85rem;">v<?= htmlspecialchars($rVer) ?></button>
												</td>
												<td class="text-center" style="font-size: 0.8rem;"><?= htmlspecialchars($rInfo['banner']) ?></td>
												<td class="text-center">
													<?php if ($rInfo['gpu']): ?>
														<span class="badge badge-success">GPU</span>
													<?php else: ?>
														<span class="badge badge-secondary">CPU</span>
													<?php endif; ?>
												</td>
												<td class="text-center text-monospace small"><?= $rInfo['encoders'] ? htmlspecialchars(implode(', ', $rInfo['encoders'])) : '—' ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>

							<h4 class="card-title mb-4"><?= $language::get('support_project') ?></h4>

							<table class="table table-striped table-bordered text-center">
								<thead class="thead-light">
									<tr>
										<th><?= $language::get('name') ?></th>
										<th><?= $language::get('address') ?></th>
										<th style="width: 90px;"><?= $language::get('qr') ?></th>
										<th style="width: 90px;"><?= $language::get('copy') ?></th>
									</tr>
								</thead>

								<tbody>
									<tr>
										<td>
											<i class="icon-base ti tabler-currency-bitcoin text-warning"></i> Bitcoin (BTC)
										</td>

										<td class="text-monospace small">1EP3XFHVk1fF3kV6zSg7whZzQdUpVMcAQz</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#qrModal" onclick="showQR(this)">
												<i class="icon-base ti tabler-qrcode"></i>
											</button>
										</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-success" onclick="copyAddr(this)">
												<i class="icon-base ti tabler-copy"></i>
											</button>
										</td>
									</tr>

									<tr>
										<td>
											<i class="icon-base ti tabler-currency-ethereum text-info"></i> Ethereum (ETH)
										</td>

										<td class="text-monospace small">0x613411dB8cFbaeaCC3A075EF39F41DFaaab4E1B8</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-primary" onclick="showQR(this)">
												<i class="icon-base ti tabler-qrcode"></i>
											</button>
										</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-success" onclick="copyAddr(this)">
												<i class="icon-base ti tabler-copy"></i>
											</button>
										</td>
									</tr>

									<tr>
										<td>
											<i class="icon-base ti tabler-currency-litecoin text-secondary"></i> Litecoin (LTC)
										</td>

										<td class="text-monospace small">MFmn43WF2k2bsAQJe8rRmq2sKke95JmqC4</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-primary" onclick="showQR(this)">
												<i class="icon-base ti tabler-qrcode"></i>
											</button>
										</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-success" onclick="copyAddr(this)">
												<i class="icon-base ti tabler-copy"></i>
											</button>
										</td>
									</tr>

									<tr>
										<td>
											<i class="icon-base ti tabler-currency-dollar text-success"></i> USDT (ERC-20)
										</td>

										<td class="text-monospace small">0x034a2263a15Ade8606cC60181f12E5c2f0Ac59C6</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-primary" onclick="showQR(this)">
												<i class="icon-base ti tabler-qrcode"></i>
											</button>
										</td>

										<td>
											<button type="button" class="btn btn-sm btn-outline-success" onclick="copyAddr(this)">
												<i class="icon-base ti tabler-copy"></i>
											</button>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<?php if (Authorization::check("adv", "database") && defined('DB_ACCESS_ENABLED') && DB_ACCESS_ENABLED): ?>
					<div class="tab-pane fade" id="database" role="tabpanel">
						<div class="row">
							<iframe width="100%" height="650px" src="./database.php" style="overflow-x: hidden; border: 0;"></iframe>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</form>

<!-- Donation QR modal (populated by showQR) -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-sm">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><?= $language::get('qr_code') ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body text-center">
				<img id="qrImage" src="" alt="QR Code" style="max-width:100%">
			</div>
		</div>
	</div>
</div>

<?php

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');

?>

<script>
	(function() {
		var $ = window.jQuery;

		// select2
		if ($ && $.fn.select2) {
			$('select[data-toggle="select2"], select.select2')
				.not('#allowed_stb_types, #allowed_stb_types_for_local_recording, #shared_mount_prefixes')
				.select2({
					width: '100%'
				});
			$('#allowed_stb_types, #allowed_stb_types_for_local_recording').select2({
				width: '100%',
				tags: true
			});
			$('#shared_mount_prefixes').select2({
				width: '100%',
				tags: true,
				tokenSeparators: [','],
				placeholder: 'Add a mount path and press enter'
			});
		}

		// BS5 tooltips
		if (window.bootstrap) {
			document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
				new bootstrap.Tooltip(el);
			});
		}

		// numeric-only inputs
		['log_clear', 'vod_bitrate_plus', 'vod_limit_perc', 'user_auto_kick_hours', 'flood_limit', 'flood_seconds', 'auth_flood_seconds', 'auth_flood_limit', 'auth_flood_sleep', 'bruteforce_mac_attempts', 'bruteforce_username_attempts', 'bruteforce_frequency', 'login_flood', 'client_prebuffer', 'restreamer_prebuffer', 'fanout_hls_window', 'fanout_grace_sec', 'fanout_write_timeout_sec', 'fanout_chunk_bytes', 'fanout_max_gop_bytes', 'fanout_default_prebuffer_sec', 'fanout_idle_buffer_grace_sec', 'fanout_idle_buffer_ratio', 'read_buffer_size', 'stream_max_analyze', 'probesize', 'stream_start_delay', 'online_capacity_interval', 'on_demand_wait_time', 'seg_time', 'stream_fail_sleep', 'probe_extra_wait', 'seg_list_size', 'cpu_limit', 'mem_limit', 'playback_limit', 'connection_loop_per', 'connection_loop_count', 'max_simultaneous_downloads', 'cache_playlists', 'seg_delete_threshold', 'fails_per_time', 'create_expiration', 'max_encode_movies', 'max_encode_cc', 'queue_loop', 'player_blur', 'player_opacity', 'disallow_2nd_ip_max', 'probesize_ondemand', 'connection_sync_timer', 'segment_wait_time', 'on_demand_scan_time', 'on_demand_max_probe', 'on_demand_scan_keep', 'stop_failures', 'mysql_sleep_kill', 'threshold_cpu', 'threshold_mem', 'threshold_disk', 'threshold_network', 'threshold_clients'].forEach(function(id) {
			var el = document.getElementById(id);
			if (el) {
				el.addEventListener('input', function() {
					this.value = this.value.replace(/[^0-9]/g, '');
				});
			}
		});

		// settings form submit
		var form = document.getElementById('settings-form');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();
				var fd = new FormData(this);
				fd.append('submit_settings', '1');
				var btn = this.querySelector('button[type=submit]');
				if (btn) {
					btn.disabled = true;
				}
				fetch('post.php?action=settings', {
						method: 'POST',
						body: fd,
						headers: {
							'X-Requested-With': 'XMLHttpRequest'
						}
					})
					.then(function(r) {
						return r.text();
					})
					.then(function(t) {
						var d;
						try {
							d = JSON.parse(t);
						} catch (e) {
							d = {
								result: false
							};
						}
						if (btn) {
							btn.disabled = false;
						}
						if (d && d.result !== false) {
							(window.xcToast || function() {})('Settings saved.', 'success');
							setTimeout(function() {
								location.reload();
							}, 700);
						} else {
							(window.xcToast || function() {})('Error saving settings.', 'error');
						}
					})
					.catch(function() {
						if (btn) {
							btn.disabled = false;
						}
						(window.xcToast || function() {})('Error saving settings.', 'error');
					});
			});
		}

		// Address helpers (crypto-donation table on the Info tab).
		window.showQR = function(btnEl) {
			var row = btnEl.closest('tr');
			var addrCell = row ? row.querySelector('td.text-monospace') : null;
			if (!addrCell) {
				return;
			}
			var text = addrCell.textContent.trim();
			var img = document.getElementById('qrImage');
			if (img) {
				img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(text);
			}
			var modalEl = document.getElementById('qrModal') || document.querySelector('.bs-addr-qr-modal-center');
			if (modalEl && window.bootstrap) {
				bootstrap.Modal.getOrCreateInstance(modalEl).show();
			}
		};

		window.copyAddr = function(btnEl) {
			var row = btnEl.closest('tr');
			var addrCell = row ? row.querySelector('td.text-monospace') : null;
			if (!addrCell) {
				return;
			}
			var text = addrCell.textContent.trim();
			var tempInput = document.createElement('input');
			tempInput.value = text;
			document.body.appendChild(tempInput);
			tempInput.select();
			try {
				document.execCommand('copy');
				var icon = btnEl.querySelector('i');
				if (icon) {
					icon.classList.remove('tabler-copy');
					icon.classList.add('tabler-check', 'text-success');
					setTimeout(function() {
						icon.classList.remove('tabler-check', 'text-success');
						icon.classList.add('tabler-copy');
					}, 1000);
				}
			} catch (err) {
				console.error('Copy failed:', err);
			}
			document.body.removeChild(tempInput);
		};

		window.UpdateServer = function() {
			if ($ && $.getJSON) {
				$.getJSON('./api?action=server&sub=update&server_id=<?= SERVER_ID ?>', function(data) {
					(window.xcToast || function() {})(
						data && data.result === true ? 'Server is updating in the background...' : 'An error occured while processing your request.',
						'info'
					);
				});
			}
		};
	})();
</script>
</body>

</html>