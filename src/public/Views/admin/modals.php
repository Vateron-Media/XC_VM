<?php if (count(get_included_files()) != 1): ?>
	<div class="modal fade bs-streams-modal-center" tabindex="-1" role="dialog" aria-labelledby="streamViewLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-xl">
			<div class="modal-content">
				<div class="modal-body">
					<table id="datatable-sources" class="table table-striped table-borderless mb-0" style="width:100%;">
						<thead>
							<tr>
								<th><?= $language::get('id') ?></th>
								<th></th>
								<th><?= $language::get('name') ?></th>
								<th><?= $language::get('server') ?></th>
								<th><?= $language::get('clients') ?></th>
								<th><?= $language::get('uptime') ?></th>
								<th><?= $language::get('actions') ?></th>
								<th><?= $language::get('actions') ?></th>
								<th><?= $language::get('stream_info') ?></th>
								<th><?= $language::get('stream_info') ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-epg-modal-center" tabindex="-1" role="dialog" aria-labelledby="epgViewLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-xl">
			<div class="modal-content">
				<div class="modal-body">
					<table id="datatable-epg" class="table table-striped table-borderless dt-responsive nowrap" style="width:100%;">
						<thead>
							<tr>
								<th class="text-center"><?= $language::get('time') ?></th>
								<th><?= $language::get('title') ?></th>
								<th><?= $language::get('description') ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td></td>
								<td></td>
								<td></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-live-modal-center" tabindex="-1" role="dialog" aria-labelledby="liveViewLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-xl">
			<div class="modal-content">
				<div class="modal-body">
					<table id="datatable-live" class="table table-striped table-borderless mb-0" style="width:100%;">
						<thead>
							<tr>
								<th class="text-center"><?= $language::get('id') ?></th>
								<th class="text-center"><?= $language::get('quality') ?></th>
								<th><?= $language::get('line') ?></th>
								<th><?= $language::get('stream') ?></th>
								<th><?= $language::get('server') ?></th>
								<th><?= $language::get('player') ?></th>
								<th><?= $language::get('isp') ?></th>
								<th class="text-center"><?= $language::get('ip') ?></th>
								<th class="text-center"><?= $language::get('duration') ?></th>
								<th class="text-center"><?= $language::get('output') ?></th>
								<th class="text-center"><?= $language::get('restreamer') ?></th>
								<th class="text-center"><?php echo $language::get('actions'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-whois-modal-center" tabindex="-1" role="dialog" aria-labelledby="whoisLabel" aria-hidden="true" style="display: none;">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="whoisLabel"></h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<table class="table mb-0" id="whois-table">
						<tbody>
							<tr>
								<th scope="row" class="bg-secondary text-center text-white" colspan="2"><?= $language::get('geolocation') ?></th>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('continent') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('country') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('city') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('postcode') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('lat_lng') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row" class="bg-secondary text-center text-white" colspan="2"><?= $language::get('isp') ?></th>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('isp_name') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('organisation') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('as_number') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('type') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row" class="bg-secondary text-center text-white" colspan="2"><?= $language::get('locale') ?></th>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('timezone') ?></th>
								<td></td>
							</tr>
							<tr>
								<th scope="row"><?= $language::get('local_time') ?></th>
								<td></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-logs-modal-center" tabindex="-1" role="dialog" aria-labelledby="clearLogsLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="clearLogsLabel"><?php echo $language::get('clear_logs'); ?></h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="form-group row mb-4">
						<label class="col-md-4 col-form-label" for="range_clear"><?php echo $language::get('date_range'); ?></label>
						<div class="col-md-4">
							<input type="text" class="form-control text-center date" id="range_clear_from" name="range_clear_from" data-toggle="date-picker" data-single-date-picker="true" autocomplete="off" placeholder="<?php echo $language::get('from'); ?>">
						</div>
						<div class="col-md-4">
							<input type="text" class="form-control text-center date" id="range_clear_to" name="range_clear_to" data-toggle="date-picker" data-single-date-picker="true" autocomplete="off" placeholder="<?php echo $language::get('to'); ?>">
						</div>
					</div>
					<div class="text-center">
						<input id="clear_logs" type="submit" class="btn btn-primary" value="<?php echo $language::get('clear'); ?>" style="width:100%" />
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade addModal" role="dialog" aria-labelledby="addLabel" aria-hidden="true" style="display: none;" data-username="" data-password="">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="addModal"><?php echo $language::get('select_series'); ?>:</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="col-12">
						<select id="add_series_id" class="form-control" data-toggle="select2"></select>
					</div>
					<div class="col-12 add-margin-top-20">
						<div class="input-group">
							<div class="input-group-append" style="width:100%">
								<button style="width:50%" class="btn btn-success waves-effect waves-light" type="button" onClick="addEpisode();"><i class="mdi mdi-plus-circle-outline"></i> <?php echo $language::get('add_episode'); ?></button>
								<button style="width:50%" class="btn btn-info waves-effect waves-light" type="button" onClick="addEpisodes();"><i class="mdi mdi-plus-circle-multiple-outline"></i> <?php echo $language::get('multiple_episodes'); ?></button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade downloadModal" role="dialog" aria-labelledby="downloadLabel" aria-hidden="true" style="display: none;" data-username="" data-password="">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="downloadModal">Download Playlist</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="form-group row">
						<label class="col-md-4 col-form-label" for="download_type"><?= $language::get('format') ?></label>
						<div class="col-8">
							<select id="download_type" class="form-control" data-toggle="select2">
								<?php
								$db->query('SELECT * FROM `output_devices` ORDER BY `device_id` ASC;');
								?>

								<?php foreach ($db->get_rows() as $rRow): ?>
									<?php if ($rRow['copy_text']): ?>
										<optgroup label="<?php echo htmlspecialchars($rRow['device_name']); ?>">
											<option data-text="<?php echo htmlspecialchars(str_replace('"', '\\"', $rRow['copy_text'])); ?>" value="<?php echo htmlspecialchars($rRow['device_key']); ?>?output=hls"><?php echo htmlspecialchars($rRow['device_name']); ?> - HLS </option>
											<option data-text="<?php echo htmlspecialchars(str_replace('"', '\\"', $rRow['copy_text'])); ?>" value="<?php echo htmlspecialchars($rRow['device_key']); ?>"><?php echo htmlspecialchars($rRow['device_name']); ?> - MPEGTS</option>
											<option data-text="<?php echo htmlspecialchars(str_replace('"', '\\"', $rRow['copy_text'])); ?>" value="<?php echo htmlspecialchars($rRow['device_key']); ?>?output=rtmp"><?php echo htmlspecialchars($rRow['device_name']); ?> - RTMP</option>
										</optgroup>
									<?php else: ?>
										<optgroup label="<?php echo htmlspecialchars($rRow['device_name']); ?>">
											<option value="<?php echo htmlspecialchars($rRow['device_key']); ?>?output=hls"><?php echo htmlspecialchars($rRow['device_name']); ?> - HLS </option>
											<option value="<?php echo htmlspecialchars($rRow['device_key']); ?>"><?php echo htmlspecialchars($rRow['device_name']); ?> - MPEGTS</option>
											<option value="<?php echo htmlspecialchars($rRow['device_key']); ?>?output=rtmp"><?php echo htmlspecialchars($rRow['device_name']); ?> - RTMP</option>
										</optgroup>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>
				<div class="form-group row">
					<label class="col-md-4 col-form-label" for="output_type"><?= $language::get('limit_output') ?></label>
					<div class="col-8">
						<select id="output_type" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder') ?>">
							<option value="live"><?= $language::get('live_streams') ?></option>
							<option value="movie"><?= $language::get('movies') ?></option>
							<option value="created_live"><?= $language::get('created_channels') ?></option>
							<option value="radio_streams"><?= $language::get('radio_stations') ?></option>
							<option value="series"><?= $language::get('tv_series') ?></option>
						</select>
					</div>
				</div>
				<div class="form-group row">
					<div class="col-12">
						<div class="input-group">
							<input type="text" class="form-control" id="download_url" value="">
							<div class="input-group-append">
								<button class="btn btn-warning waves-effect waves-light" type="button" onClick="copyDownload();"><i class="mdi mdi-content-copy"></i></button>
								<button class="btn btn-info waves-effect waves-light" type="button" onClick="doDownload();" id="download_button" disabled><i class="mdi mdi-download"></i></button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	</div>
	<div class="modal fade messageModal" role="dialog" aria-labelledby="messageModalLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="messageModalLabel"><?php echo $language::get('mag_event'); ?></h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="col-12">
						<select id="message_type" class="form-control" data-toggle="select2">
							<option value="" selected><?php echo $language::get('select_an_event'); ?>:</option>
							<optgroup label="">
								<option value="play_channel"><?php echo $language::get('play_channel'); ?></option>
								<option value="reload_portal"><?php echo $language::get('reload_portal'); ?></option>
								<option value="reboot"><?php echo $language::get('reboot_device'); ?></option>
								<option value="send_msg"><?php echo $language::get('send_message'); ?></option>
								<option value="cut_off"><?php echo $language::get('close_portal'); ?></option>
								<option value="reset_stb_lock"><?php echo $language::get('reset_stb_lock'); ?></option>
							</optgroup>
						</select>
					</div>
					<div class="col-12" style="margin-top:20px;display:none;" id="send_msg_form">
						<div class="form-group row mb-4">
							<div class="col-md-12">
								<textarea id="message" name="message" class="form-control" rows="3" placeholder="<?php echo $language::get('enter_a_custom_message'); ?>..."></textarea>
							</div>
						</div>
						<div class="form-group row mb-4">
							<label class="col-md-9 col-form-label" for="reboot_portal"><?php echo $language::get('reboot_on_confirmation'); ?></label>
							<div class="col-md-3">
								<input name="reboot_portal" id="reboot_portal" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
							</div>
						</div>
					</div>
					<div class="col-12" style="margin-top:20px;display:none;" id="play_channel_form">
						<div class="form-group row mb-4">
							<label class="col-md-3 col-form-label" for="selected_channel"><?php echo $language::get('channel'); ?></label>
							<div class="col-md-9">
								<select id="selected_channel" name="selected_channel" class="form-control" data-toggle="select2" style="width:100%;"></select>
							</div>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button disabled id="message_submit" type="button" class="btn btn-primary waves-effect"><?php echo $language::get('send_event'); ?></button>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-server-modal-center" tabindex="-1" role="dialog" aria-labelledby="restartServicesLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-xl">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="restartServicesLabel">Server Tools</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="form-group row">
						<div class="col-md-3">
							<input id="reinstall_server" type="submit" class="btn btn-light" value="Reinstall Server" style="width:100%" />
						</div>
						<div class="col-md-2">
							<input id="restart_services_ssh" type="submit" class="btn btn-light" value="Restart Services" style="width:100%" />
						</div>
						<div class="col-md-2">
							<input id="reboot_server_ssh" type="submit" class="btn btn-light" value="Reboot Server" style="width:100%" />
						</div>
						<div class="col-md-2">
							<input id="update_binaries" type="submit" class="btn btn-light" value="Update Binaries" style="width:100%" />
						</div>
						<div class="col-md-3">
							<input id="update_server" type="submit" class="btn btn-light" value="Update Server" style="width:100%" />
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-domains" tabindex="-1" role="dialog" aria-labelledby="modalLabel" aria-hidden="true" style="display: none;">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="modalLabel">Domain List</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<p class="sub-header">Ensure the following domains are entered in your reCAPTCHA V2 admin console, otherwise your resellers will be unable to login via their domain.</p>
					<div class="table-responsive">
						<table class="table mb-0">
							<thead>
								<tr>
									<th><?= $language::get('type_reseller') ?></th>
									<th><?= $language::get('domain_name') ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if (strlen($rServers[SERVER_ID]['server_ip']) > 0): ?>
									<tr>
										<td><?= $language::get('server_ip') ?></td>
										<td><?php echo $rServers[SERVER_ID]['server_ip']; ?></td>
									</tr>
								<?php endif; ?>
								<?php if (strlen($rServers[SERVER_ID]['domain_name']) > 0): ?>
									<tr>
										<td><?= $language::get('server_domain') ?></td>
										<td><?php echo $rServers[SERVER_ID]['domain_name']; ?></td>
									</tr>
								<?php endif; ?>
								<?php
								$db->query("SELECT `username`, `reseller_dns` FROM `users` WHERE `reseller_dns` <> '' ORDER BY `username` ASC;");

								if ($db->num_rows() > 0) {
									foreach ($db->get_rows() as $rRow) {
								?>
										<tr>
											<td><?php echo $rRow['username']; ?></td>
											<td><?php echo $rRow['reseller_dns']; ?></td>
										</tr>
								<?php
									}
								}
								?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-provider-streams-modal-center" tabindex="-1" role="dialog" aria-labelledby="providerStreamsLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-scrollable modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="providerStreamsLabel">Provider Streams</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<table id="datatable-provider-streams" class="table table-striped table-borderless dt-responsive">
						<thead>
							<tr>
								<th class="text-center"><?= $language::get('icon') ?></th>
								<th><?= $language::get('stream_name') ?></th>
								<th><?= $language::get('provider') ?></th>
								<th class="text-center"><?= $language::get('actions') ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-provider-movies-modal-center" tabindex="-1" role="dialog" aria-labelledby="providerMoviesLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-scrollable modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="providerMoviesLabel">Provider Movies</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<table id="datatable-provider-movies" class="table table-striped table-borderless dt-responsive">
						<thead>
							<tr>
								<th><?= $language::get('stream_name') ?></th>
								<th><?= $language::get('provider') ?></th>
								<th class="text-center"><?= $language::get('actions') ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-picon-modal-center" tabindex="-1" role="dialog" aria-labelledby="epgPiconLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-center modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="epgPiconLabel">Use the EPG icon for this stream?</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body text-center">
					<img id="epg-picon" src="" class="img-thumbnail" style="max-width: 400px; max-height: 250px;"><br /><br />
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary waves-effect" data-dismiss="modal"><?= $language::get('cancel') ?></button>
					<button type="button" class="btn btn-success waves-effect waves-light" id="epg_picon_save"><?= $language::get('use_icon') ?></button>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade creditsModal" role="dialog" aria-labelledby="creditsLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="creditsModal">Add / Remove Credits</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="form-group row">
						<label class="col-md-8 col-form-label" for="credits"><?= $language::get('credits') ?></label>
						<div class="col-md-4">
							<input type="text" class="form-control text-center" id="credits" onkeypress="return isNumberKey(event)" name="credits" value="">
						</div>
					</div>
					<div class="form-group row">
						<div class="col-md-12">
							<input type="text" class="form-control" id="credits_reason" name="credits_reason" placeholder="<?= $language::get('reason_for_adjustment') ?>" value="">
						</div>
					</div>
					<div class="form-group row">
						<div class="col-md-12">
							<button class="btn btn-info waves-effect waves-light" style="width:100%;" type="button" onClick="submitCredits();"><?= $language::get('adjust_credits') ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-programme" tabindex="-1" role="dialog" aria-labelledby="programmeLabel" aria-hidden="true" style="display: none;">
		<div class="modal-dialog modal-dialog-centered modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title"><span id="programmeLabel"></span> &nbsp;<small><span id="programmeStart"></span></small></h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<p class="sub-header" id="programmeDescription"></p>
					<button type="button" id="programmeRecord" class="btn btn-danger waves-effect"><i class="mdi mdi-record"></i> <?= $language::get('record') ?></button>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade fingerprintModal" role="dialog" aria-labelledby="fingerprintLabel" aria-hidden="true" style="display: none;" data-id="" data-type="">
		<div class="modal-dialog modal-dialog-centered modal-xl">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="fingerprintModal">Fingerprint</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<div class="form-group row">
						<label class="col-md-1 col-2 col-form-label text-center" for="mod_fingerprint_type"><?php echo $language::get('type'); ?></label>
						<div class="col-md-2 col-6">
							<select id="mod_fingerprint_type" class="form-control text-center" data-toggle="select2">
								<option value="1"><?php echo $language::get('activity_id'); ?></option>
								<option value="2"><?php echo $language::get('username'); ?></option>
								<option value="3"><?php echo $language::get('message'); ?></option>
							</select>
						</div>
						<label class="col-md-1 col-2 col-form-label text-center" for="mod_font_size"><?php echo $language::get('size'); ?></label>
						<div class="col-md-1 col-2">
							<input type="text" class="form-control text-center" id="mod_font_size" value="36" placeholder="">
						</div>
						<label class="col-md-1 col-2 col-form-label text-center" for="mod_font_color"><?php echo $language::get('colour'); ?></label>
						<div class="col-md-2 col-2">
							<input type="text" id="mod_font_color" class="form-control text-center" value="#ffffff">
						</div>
						<label class="col-md-1 col-2 col-form-label text-center" for="mod_position_x"><?php echo $language::get('position'); ?></label>
						<div class="col-md-1 col-2">
							<input type="text" class="form-control text-center" id="mod_position_x" value="10" placeholder="X">
						</div>
						<div class="col-md-1 col-2">
							<input type="text" class="form-control text-center" id="mod_position_y" value="10" placeholder="Y">
						</div>
						<div class="col-md-1 col-2">
							<button type="button" class="btn btn-info waves-effect waves-light" onClick="setModalFingerprint()">
								<i class="mdi mdi-fingerprint"></i>
							</button>
						</div>
						<div class="col-md-12 col-2" style="margin-top:10px;display:none;" id="mod_custom_message_div">
							<input type="text" class="form-control" id="mod_custom_message" value="" placeholder="<?php echo $language::get('custom_message'); ?>">
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-failures-modal-center" tabindex="-1" role="dialog" aria-labelledby="failuresLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-xl">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title" id="failuresLabel"><button onClick='clearLogs()' type='button' class='btn btn-secondary btn-xs waves-effect waves-light'><?= $language::get('clear_stream_logs') ?></button></h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body">
					<table id="datatable-stream" class="table table-striped table-borderless dt-responsive">
						<thead>
							<tr>
								<th><?= $language::get('server_name') ?></th>
								<th class="text-center"><?= $language::get('source') ?></th>
								<th class="text-center"><?= $language::get('action') ?></th>
								<th class="text-center"><?= $language::get('date') ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-proxies-modal-center" tabindex="-1" role="dialog" aria-labelledby="proxiesLabel" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<div class="modal-content">
				<div class="modal-body">
					<table id="datatable-sources" class="table table-striped table-borderless dt-responsive">
						<thead>
							<tr>
								<th class="text-center"><?= $language::get('id') ?></th>
								<th><?= $language::get('server_name') ?></th>
								<th class="text-center"><?= $language::get('server_ip') ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td></td>
								<td></td>
								<td></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	<div class="modal fade bs-addr-qr-modal-center" tabindex="-1" role="dialog" aria-labelledby="qrModal" aria-hidden="true" style="display: none;" data-id="">
		<div class="modal-dialog modal-dialog-centered modal-sm">
			<div class="modal-content">
				<div class="modal-header">
					<h4 class="modal-title">QR Code</h4>
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
				</div>
				<div class="modal-body p-0"> <!-- Added p-0 to remove padding -->
					<img id="qrImage" src="" alt="QR Code" class="img-fluid w-100"> <!-- Added w-100 for full width -->
				</div>
			</div>
		</div>
	</div>
<?php else:
	exit(); ?>
<?php endif; ?>