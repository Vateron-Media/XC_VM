<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

if (isset(CoreUtilities::$rRequest['id'])) {
	if (!isset(CoreUtilities::$rRequest['import']) && hasPermissions('adv', 'edit_stream')) {
		$rStream = getStream(CoreUtilities::$rRequest['id']);

		if ($rStream && $rStream['type'] == 1) {
		} else {
			goHome();
		}
	} else {
		exit();
	}
}

$rEPGSources = getEPGSources();
$rStreamArguments = getStreamArguments();
$rTranscodeProfiles = getTranscodeProfiles();
$rOnDemand = array();
$rEPGJS = array(array());

foreach ($rEPGSources as $rEPG) {
	$rEPGJS[$rEPG['id']] = json_decode($rEPG['data'], true);
}
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));
$rAudioDevices = $rVideoDevices = array();

foreach ($rServers as $rServer) {
	$rVideoDevices[$rServer['id']] = $rServer['video_devices'];
	$rAudioDevices[$rServer['id']] = $rServer['audio_devices'];
}

if (isset($rStream)) {
	$rStreamOptions = getStreamOptions(CoreUtilities::$rRequest['id']);
	$rStreamSys = getStreamSys(CoreUtilities::$rRequest['id']);

	foreach ($rServers as $rServer) {
		if (isset($rStreamSys[intval($rServer['id'])])) {
			if ($rStreamSys[intval($rServer['id'])]['parent_id'] != 0) {
				$rParent = intval($rStreamSys[intval($rServer['id'])]['parent_id']);
			} else {
				$rParent = 'source';
			}

			if (!$rStreamSys[intval($rServer['id'])]['on_demand']) {
			} else {
				$rOnDemand[] = intval($rServer['id']);
			}
		} else {
			$rParent = 'offline';
		}

		$rServerTree[] = array('id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
	}

	if ($rStream['epg_api'] && 0 < strlen($rStream['channel_id'])) {
		$db->query('SELECT `name` FROM `epg_api` WHERE `callSign` = ?;', $rStream['channel_id']);

		if (0 < $db->num_rows()) {
			$rStream['epg_api_name'] = $db->get_row()['name'];
		} else {
			$rStream['epg_api_name'] = 'No longer available.';
		}
	}
} else {
	if (hasPermissions('adv', 'add_stream')) {


		foreach ($rServers as $rServer) {
			$rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
		}
	} else {
		exit();
	}
}

$_TITLE = 'Stream';
include 'header.php';
?>

<div class="wrapper boxed-layout-ext"
	<?php
	if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
		echo ' style="display: none;"';
	}
	?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right"><?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">
						<?php
						if (isset($rStream['id'])) {
							echo $rStream['stream_display_name'];
						} else {
							if (isset(CoreUtilities::$rRequest['import'])) {
								echo 'Import Streams';
							} else {
								echo 'Add Stream';
							}
						}
						?>

					</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form
							<?php if (isset(CoreUtilities::$rRequest['import'])): ?>
							enctype="multipart/form-data"
							<?php endif; ?>
							action="#"
							method="POST"
							id="stream_form"
							data-parsley-validate="">

							<?php if (isset($rStream["id"])): ?>
								<input type="hidden" name="edit" value="<?= htmlspecialchars($rStream["id"]) ?>" />
							<?php endif; ?>

							<input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
							<input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
							<input type="hidden" name="epg_api" id="epg_api" value="<?php echo (isset($rStream) ? $rStream['epg_api'] : 0); ?>" />
							<input type="hidden" name="external_push" id="external_push" value="" />
							<input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="" />

							<input type="hidden" name="category_create_list" id="category_create_list" value="" />
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item"><a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> <i class="mdi mdi-account-card-details-outline mr-1"></i><span class="d-none d-sm-inline">Details</span></a></li>

									<?php
									if (!isset(CoreUtilities::$rRequest['import'])) {
										echo ' <li class="nav-item"><a href="#stream-sources" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-arrow-up-down-bold-outline mr-1"></i><span class="d-none d-sm-inline">Sources</span></a></li> ';
									}

									echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item"><a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-folder-alert-outline mr-1"></i><span class="d-none d-sm-inline">Advanced</span></a></li>';

									if (isset(CoreUtilities::$rRequest['import'])) {
									} else {
										echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item"><a href="#stream-map" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-map mr-1"></i><span class="d-none d-sm-inline">Map</span></a></li>
									<li class="nav-item"><a href="#epg-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-television-guide mr-1"></i><span class="d-none d-sm-inline">EPG</span></a></li> ';

										if (!$rMobile) {
											echo ' <li class="nav-item"><a href="#rtmp-push" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-upload-network-outline mr-1"></i><span class="d-none d-sm-inline">RTMP Push</span></a></li> ';
										}
									}
									?>

									<li class=" nav-item"><a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-server-network mr-1"></i><span class="d-none d-sm-inline">Servers</span></a></li>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="stream-details">
										<div class="row">
											<div class="col-12">
												<?php
												if (!isset(CoreUtilities::$rRequest['import'])) {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="stream_display_name">Stream Name</label>
													<div class="col-md-9"><input type="text" class="form-control" id="stream_display_name" name="stream_display_name" value="';

													if (isset($rStream)) {
														echo htmlspecialchars($rStream['stream_display_name']);
													} else {
														if (isset(CoreUtilities::$rRequest['title'])) {
															echo str_replace('"', ' &quot;', CoreUtilities::$rRequest['title']);
														}
													}

													echo '" required data-parsley-trigger="change"></div></div><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="stream_icon">Stream Logo</label>                                                    <div class="col-md-9 input-group"><input type="text" class="form-control" id="stream_icon" name="stream_icon" value="';

													if (isset($rStream)) {
														echo htmlspecialchars($rStream['stream_icon']);
													} else {
														if (isset(CoreUtilities::$rRequest['icon'])) {
															echo str_replace('"', '&quot;', CoreUtilities::$rRequest['icon']);
														}
													}

													echo '"><div class="input-group-append"><a href="javascript:void(0)" onclick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a></div></div></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
												} else {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="m3u_file">M3U</label><div class="col-md-9"><input type="file" id="m3u_file" name="m3u_file" style="padding-top: 5px;" /></div></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
												}

												echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="category_id">Categories</label><div class="col-md-9">                                                        <select name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

												foreach (getCategories('live') as $rCategory) {
													echo '                                                            <option ';

													if (isset($rStream)) {
														if (in_array(intval($rCategory['id']), json_decode($rStream['category_id'], true))) {
															echo 'selected ';
														}
													}

													echo 'value="';
													echo $rCategory['id'];
													echo '">';
													echo $rCategory['category_name'];
													echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
												}
												echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>                                                        <div id="category_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">                                                            <strong>New Categories:</strong> <span id="category_new"></span>                                                        </div></div>                                                </div>                                                <div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="bouquets">Bouquets</label><div class="col-md-9"><select name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

												foreach (getBouquets() as $rBouquet) {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

													if (isset($rStream)) {
														if (in_array($rStream['id'], json_decode($rBouquet['bouquet_channels'], true))) {
															echo 'selected ';
														}
													}

													echo 'value="';
													echo $rBouquet['id'];
													echo '">';
													echo $rBouquet['bouquet_name'];
													echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
												}
												echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>                                                        <div id="bouquet_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">                                                            <strong>New Bouquets:</strong> <span id="bouquet_new"></span>                                                        </div></div></div><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="notes">Notes</label><div class="col-md-9"><textarea id="notes" name="notes" class="form-control" rows="3" placeholder="">';

												if (!isset($rStream)) {
												} else {
													echo htmlspecialchars($rStream['notes']);
												}

												echo '</textarea></div></div></div> </div> <ul class="list-inline wizard mb-0"><li class="nextb list-inline-item float-right"><a href="javascript: void(0);" class="btn btn-secondary">Next</a></li></ul></div>                                    ';

												if (isset(CoreUtilities::$rRequest['import'])) {
												} else {
													echo '                                    <div class="tab-pane" id="stream-sources"><div class="row"><div class="col-12">                                                <table id="datatable-sources" class="table table-striped table-borderless mb-0">                                                    <thead>                                                        <tr>                                                            <th>URL</th>                                                            ';

													if ($rMobile) {
													} else {
														echo '                                                            <th class="text-center">Stream Info</th>                                                            ';
													}

													echo '                                                        </tr>                                                    </thead>                                                    <tbody class="streams">                                                        ';

													if (isset($rStream)) {
														$rStreamSources = json_decode($rStream['stream_source'], true);

														if (!$rStreamSources) {
															$rStreamSources = array('');
														}
													} else {
														if (isset(CoreUtilities::$rRequest['url'])) {
															$rStreamSources = array(str_replace('"', '&quot;', CoreUtilities::$rRequest['url']));
														} else {
															$rStreamSources = array('');
														}
													}

													$i = 0;

													foreach ($rStreamSources as $rSource) {
														$i++;
														echo '                                                        <tr class="stream_info">                                                            <td class="input-group">                                                                <div class="input-group-append">                                                                    <button class="btn btn-secondary waves-effect waves-light btn-fixed-xs" onClick="moveUp(this);" type="button"><i class="mdi mdi-chevron-up"></i></button>                                                                    <button class="btn btn-secondary waves-effect waves-light btn-fixed-xs" onClick="moveDown(this);" type="button"><i class="mdi mdi-chevron-down"></i></button>                                                                </div>                                                                <input type="text" id="stream_source" name="stream_source[]" class="form-control" value="';
														echo htmlspecialchars($rSource);
														echo '">                                                                <div class="input-group-append">                                                                    <button class="btn btn-danger waves-effect waves-light btn-fixed-xs" onClick="removeStream(this);" type="button"><i class="mdi mdi-close"></i></button>                                                                </div>                                                            </td>                                                            ';

														if ($rMobile) {
														} else {
															echo '                                                            <td class="text-center" id="stream_info" style="width:380px;">' . "\n" . "                                                                <table class='table-data' style='width: 380px;' align='center'><tbody><tr><td colspan='5'>Not scanned</td></tr></tbody></table>" . "\n" . '                                                            </td>                                                            ';
														}

														echo '                                                        </tr>                                                        ';
													}
													echo '                                                    </tbody>                                                </table><div id="capture_form" style="display: none;"><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="capture_server_id">Capture Server</label><div class="col-md-9"><select name="capture_server_id" id="capture_server_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

													foreach ($rServers as $rServer) {
														echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
														echo $rServer['id'];
														echo '"';

														if (!(isset($rStream) && $rStream['capture_server_id'] == $rServer['id'])) {
														} else {
															echo ' selected';
														}

														echo '>';
														echo $rServer['server_name'];
														echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
													}
													echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select></div></div><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="video_device_id">Video Input</label><div class="col-md-9"><select name="video_device_id" id="video_device_id" class="form-control" data-toggle="select2"></select></div></div><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="audio_device_id">Audio Input</label><div class="col-md-9"><select name="audio_device_id" id="audio_device_id" class="form-control" data-toggle="select2"></select></div></div></div>                                            </div>                                        </div>                                        <ul class="list-inline wizard mb-0" style="padding-top: 30px;"><li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a>                                            </li><span id="source_form"><li class="list-inline-item"><a onClick="addStream();" class="btn btn-primary btn-pointer">Add Row</a></li>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

													if ($rMobile) {
													} else {
														echo '                                                <li class="list-inline-item">                                                    <button type="button" style="width: 100%" class="btn btn-pink btn-pointer" id="provider-streams">Providers</button></li><li class="list-inline-item"><a onClick="scanSources();" class="btn btn-info btn-pointer">Scan Sources</a></li>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
													}

													echo "\t\t\t\t\t\t\t\t\t\t\t" . '</span><li class="list-inline-item float-right"><!--<a onClick="toggleCapture();" class="btn btn-info btn-pointer">Toggle Mode</a>--><a href="javascript: void(0);" class="btn btn-secondary nextb">Next</a></li></ul>                                    </div>                                    ';
												}

												echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="advanced-options"><div class="row"><div class="col-12"><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="gen_timestamps">Generate PTS <i title="Allow FFmpeg to generate presentation timestamps for you to achieve better synchronization with the stream codecs. In some streams this can cause de-sync." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-3"><input name="gen_timestamps" id="gen_timestamps" type="checkbox" ';

												if (isset($rStream)) {
													if ($rStream['gen_timestamps'] != 1) {
													} else {
														echo 'checked ';
													}
												} else {
													echo 'checked ';
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="read_native">Native Frames <i title="You should always read live streams as non-native frames. However if you are streaming static video files, set this to true otherwise the encoding process will fail." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="read_native" id="read_native" type="checkbox" ';

												if (!isset($rStream)) {
												} else {
													if ($rStream['read_native'] != 1) {
													} else {
														echo 'checked ';
													}
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="stream_all">Stream All Codecs <i title="This option will stream all codecs from your stream. Some streams have more than one audio/video/subtitles channels." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-3"><input name="stream_all" id="stream_all" type="checkbox" ';

												if (!isset($rStream)) {
												} else {
													if ($rStream['stream_all'] != 1) {
													} else {
														echo 'checked ';
													}
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="allow_record">Allow Recording</label><div class="col-md-2"><input name="allow_record" id="allow_record" type="checkbox" ';

												if (isset($rStream)) {
													if ($rStream['allow_record'] != 1) {
													} else {
														echo 'checked ';
													}
												} else {
													echo 'checked ';
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div><div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<label class=\" col-md-3 col-form-label\" for=\"direct_source\">Direct Source <i title=\"Don't run source through XC_VM, just redirect instead.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3"><input name="direct_source" id="direct_source" type="checkbox" ';

												if (!isset($rStream)) {
												} else {
													if ($rStream[' direct_source'] != 1) {
													} else {
														echo 'checked ';
													}
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>                                                    <label class="col-md-4 col-form-label" for="direct_proxy">Direct Stream <i title="When using direct source, hide the original URL by proxying the live stream through your servers via UDP. MPEG-TS and HLS is supported as an input format, however only MPEG-TS is supported as an output format to clients.<br/><br/>Experimental! This may not work for all streams." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="direct_proxy" id="direct_proxy" type="checkbox" ';

												if (!isset($rStream)) {
												} else {
													if ($rStream['direct_proxy'] != 1) {
													} else {
														echo 'checked ';
													}
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>                                                ';

												if (!isset(CoreUtilities::$rRequest['import'])) {
												} else {
													echo '                                                <div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="add_source_as_backup">Add Source as Backup <i title="If an identical stream name is found, or the XMLTV ID matches an existing stream, the source will be added as a backup. The existing stream options will be kept." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-3"><input name="add_source_as_backup" id="add_source_as_backup" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div><label class="col-md-4 col-form-label" for="update_existing">Update Existing <i title="If the source exists, overwrite it with the new title and stream options." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-2"><input name="update_existing" id="update_existing" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div></div>                                                ';
												}

												echo '                                                <div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="fps_restart">Restart on FPS Drop <i title="Enable restart on FPS drop, set the threshold accordingly." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-3"><input name="fps_restart" id="fps_restart" type="checkbox" ';

												if (!isset($rStream)) {
												} else {
													if ($rStream['fps_restart'] != 1) {
													} else {
														echo 'checked ';
													}
												}

												echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<label class=\" col-md-4 col-form-label\" for=\"fps_threshold\">FPS Threshold % <i title=\"Stream will restart if it drops below x% of it's original FPS. Maximum of 100%.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2"><input type="text" class="form-control text-center" id="fps_threshold" name="fps_threshold" value="';

												if (isset($rStream)) {
													echo $rStream['fps_threshold'];
												} else {
													echo '90';
												}

												echo '"></div>
														</div>
														<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<label class=\"col-md-3 col-form-label\" for=\"custom_sid\">Custom Channel SID <i title=\"Here you can specify the SID of the channel in order to work with the epg on the enigma2 devices. You have to specify the code with the ':' but without the first number, 1 or 4097 . Example: if we have this code: '1:0:1:13f:157c:13e:820000:0:0:0:2097' then you have to add on this field: ':0:1:13f:157c:13e:820000:0:0:0:\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . ' <div class="col-md-9"><input type="text" class="form-control" id="custom_sid" name="custom_sid" value="';

												if (!isset($rStream)) {
												} else {
													echo htmlspecialchars($rStream['custom_sid']);
												}

												echo '"></div>
													</div>
													<div class="form-group row mb-4"> <label class="col-md-3 col-form-label" for="probesize_ondemand">On Demand Probesize <i title="Adjustable probesize for ondemand streams. Adjust this setting if you experience issues with no audio." class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-3"><input type="text" class="form-control text-center" id="probesize_ondemand" name="probesize_ondemand" value="';

												if (isset($rStream)) {
													echo $rStream['probesize_ondemand'];
												} else {
													echo $rSettings['probesize_ondemand'];
												}

												echo '"></div> <label class="col-md-4 col-form-label" for="delay_minutes">Minute Delay <i title="Delay stream by X minutes. Will not work with on demand streams." class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2"><input type="text" class="form-control text-center" id="delay_minutes" name="delay_minutes" value="';

												if (isset($rStream)) {
													echo $rStream['delay_minutes'];
												} else {
													echo '0';
												}

												echo '"></div>
													</div>
													<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="user_agent">User Agent</label>
														<div class="col-md-9"><input type="text" class="form-control" id="user_agent" name="user_agent" value="';

												if (isset($rStreamOptions[1])) {
													echo htmlspecialchars($rStreamOptions[1]['value']);
												} else {
													echo htmlspecialchars($rStreamArguments['user_agent']['argument_default_value']);
												}

												echo '"></div>
													</div>
													<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="http_proxy">HTTP Proxy <i title="Format: ip:port" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-9"><input type="text" class="form-control" id="http_proxy" name="http_proxy" value="';

												if (isset($rStreamOptions[2])) {
													echo htmlspecialchars($rStreamOptions[2]['value']);
												} else {
													echo htmlspecialchars($rStreamArguments['proxy']['argument_default_value']);
												}

												echo '"></div>
													</div>
													<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="cookie">Cookie <i title="Format: key=value;" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-9"><input type="text" class="form-control" id="cookie" name="cookie" value="';

												if (isset($rStreamOptions[17])) {
													echo htmlspecialchars($rStreamOptions[17]['value']);
												} else {
													echo htmlspecialchars($rStreamArguments['cookie']['argument_default_value']);
												}

												echo '"></div>
													</div>
													<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="headers">Headers <i title="FFmpeg -headers command." class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-9"><input type="text" class="form-control" id="headers" name="headers" value="';

												if (isset($rStreamOptions[19])) {
													echo htmlspecialchars($rStreamOptions[19]['value']);
												} else {
													echo htmlspecialchars($rStreamArguments['headers']['argument_default_value']);
												}

												echo '"></div>
													</div>
													<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="transcode_profile_id">Transcoding Profile <i title="Sometimes, in order to make a stream compatible with most devices, it must be transcoded. Please note that the transcode will only be applied to the server(s) that take the stream directly from the source, all other servers attached to the transcoding server will not transcode the stream." class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-9"><select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2"><option ';

												if (!isset($rStream)) {
												} else {
													if (intval($rStream[' transcode_profile_id']) != 0) {
													} else {
														echo 'selected ';
													}
												}

												echo 'value="0">Transcoding Disabled</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

												foreach ($rTranscodeProfiles as $rProfile) {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

													if (!isset($rStream)) {
													} else {
														if (intval($rStream['transcode_profile_id']) != intval($rProfile['profile_id'])) {
														} else {
															echo 'selected ';
														}
													}

													echo 'value="';
													echo $rProfile['profile_id'];
													echo '">';
													echo $rProfile['profile_name'];
													echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
												}
												echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select></div></div>                                                <div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="days_to_restart">Auto-Restart</label><div class="col-md-7">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
												$rAutoRestart = array('days' => array(), 'at' => '06:00');

												if (!isset($rStream)) {
												} else {
													if (!strlen($rStream['auto_restart'])) {
													} else {
														$rAutoRestart = json_decode($rStream['auto_restart'], true);

														if (isset($rAutoRestart['days'])) {
														} else {
															$rAutoRestart['days'] = array();
														}

														if (isset($rAutoRestart['at'])) {
														} else {
															$rAutoRestart['at'] = '06:00';
														}
													}
												}

												echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="days_to_restart" name="days_to_restart[]" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose ...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

												foreach (array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') as $rDay) {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
													echo $rDay;
													echo '"';

													if (in_array($rDay, $rAutoRestart['days'])) {
														echo ' selected';
													}

													echo '>';
													echo $rDay;
													echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
												}
												echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select></div><div class="col-md-2"><div class="input-group clockpicker" data-placement="top" data-align="top" data-autoclose="true"><input id="time_to_restart" name="time_to_restart" type="text" class="form-control text-center" value="';
												echo $rAutoRestart['at'];
												echo '"><div class="input-group-append"><span class="input-group-text"><i class="mdi mdi-clock-outline"></i></span></div></div></div></div>                                                <div class="form-group row mb-4">                                                    ';
												$rAdaptiveLink = (isset($rStream) ? (json_decode($rStream['adaptive_link'], true) ?: array()) : array());
												$rAdaptiveNames = array();

												if (0 >= count($rAdaptiveLink)) {
												} else {
													$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rAdaptiveLink)) . ');');

													foreach ($db->get_rows() as $rRow) {
														$rAdaptiveNames[$rRow['id']] = '[' . $rRow['id'] . '] ' . $rRow['stream_display_name'];
													}
												}
												?>
												<label class="col-md-3 col-form-label" for="adaptive_link">Adaptive Link <i title="Link multiple streams together when HLS is requested, the player will select a suitable stream based on the available bandwidth. The selected streams do not need to be in the line's bouquet, or any bouquet at all. MPEG-TS will play this stream normally." class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-9"><select name="adaptive_link[]" id="adaptive_link" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose ...">

														<?php foreach ($rAdaptiveLink as $rAdaptiveID): ?>
															<option value="<?= htmlspecialchars($rAdaptiveID, ENT_QUOTES) ?>" selected="selected">
																<?= htmlspecialchars($rAdaptiveNames[$rAdaptiveID], ENT_QUOTES) ?>
															</option>
														<?php endforeach; ?>


													</select></div>
											</div>
											<div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="title_sync">Sync Title <i title="Synchronise stream title with a provider stream. You need to add your strema providers to XC_VM in order for this to work, the title will be updated every time the cron runs." class="tooltip text-secondary far fa-circle"></i></label>
												<div class="col-md-7"><select id="title_sync" name="title_sync" class="form-control" data-toggle="select2">

														<?php
														if (isset($rStream['title_sync'])) {
															list($rProviderID, $Ef8a7f0abbf3ee36) = array_map('intval', explode('_', $rStream['title_sync']));
															$db->query('SELECT `stream_display_name` FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` = ?;', $rProviderID, $Ef8a7f0abbf3ee36);

															if ($db->num_rows() > 0) {
																echo '<option value="' . $rProviderID . '_' . $Ef8a7f0abbf3ee36 . '" selected="selected">[' . $Ef8a7f0abbf3ee36 . '] ' . $db->get_row()['stream_display_name'] . '</option>';
															}
														}
														?>
													</select></div>
												<div class="col-md-2"> <a href="javascript: void(0);" onClick="clearTitle();" class="btn btn-warning" style="width: 100%">Clear</a> </div>
											</div>
										</div>
									</div>
									<ul class="list-inline wizard mb-0">
										<li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a></li>
										<li class="nextb list-inline-item float-right"><a href="javascript: void(0);" class="btn btn-secondary">Next</a></li>
									</ul>
								</div>
								<?php

								if (isset(CoreUtilities::$rRequest['import'])) {
								} else {
									echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-map">
												<div class="row">
													<div class="col-12">
														<div class="alert bg-info text-white border-0" role="alert">Custom maps can only be applied to single source streams, if you have more than one and the active source changes, a custom map could prevent that source from working.</div>
														<table class="table table-striped table-borderless mb-4">
															<thead>
																<tr>
																	<th>Custom Map</th>
																</tr>
															</thead>
															<tbody>
																<tr>
																	<td class="input-group"> <input type="text" class="form-control" id="custom_map" name="custom_map" value="';

									if (!isset($rStream)) {
									} else {
										echo htmlspecialchars($rStream['custom_map']);
									}

									echo '">
																		<div class="input-group-append"> <button class="btn btn-primary waves-effect waves-light" id="load_maps" type="button"><i class="mdi mdi-magnify"></i></button> </div>
																	</td>
																</tr>
															</tbody>
														</table>
														<table id="datatable-map" class="table table-striped table-borderless mb-0">
															<thead><tr><th>#</th><th>Type</th><th>Information</th></tr>
															</thead>
															<tbody></tbody>
														</table>
													</div>
												</div>
												<ul class="list-inline wizard mb-0">
													<li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a></li>
													<li class="nextb list-inline-item float-right"><a href="javascript: void(0);" class="btn btn-secondary">Next</a></li>
												</ul>
											</div>
											<div class="tab-pane" id="epg-options">
												<div class="row">
													<div class="col-12">
														<ul class="nav nav-pills navtab-bg nav-justified">
															<li class="nav-item"> <a href="#quick-search" data-toggle="tab" aria-expanded="true" class="nav-link';

									if (isset($rStream)) {
									} else {
										echo ' active';
									}

									echo '"> Quick Search </a> </li>
															<li class="nav-item"> <a href="#xc_vm-epg" id="tab-xc_vm-epg" data-toggle="tab" aria-expanded="true" class="nav-link';

									if (!(isset($rStream) && $rStream['epg_api'])) {
									} else {
										echo ' active';
									}

									echo '"> XC_VM EPG (not worked)</a> </li>
															<li class="nav-item"> <a href="#xmltv-epg" id="tab-xml-epg" data-toggle="tab" aria-expanded="false" class="nav-link';

									if (!isset($rStream) || $rStream['epg_api']) {
									} else {
										echo ' active';
									}

									echo '"> XMLTV EPG </a> </li>
														</ul>
														<div class="tab-content" style="padding-top: 30px;">
															<div class="tab-pane';

									if (isset($rStream)) {
									} else {
										echo ' active';
									}

									echo '" id="quick-search">
																<div class="form-group row mb-4"> <label class="col-md-3 col-form-label" for="quick_search">Search EPG</label>
																	<div class="col-md-9"> <select id="quick_search" class="form-control" data-toggle="select2"></select> </div>
																</div>
															</div>
															<div class="tab-pane';

									if (!(isset($rStream) && $rStream['epg_api'])) {
									} else {
										echo ' active';
									}

									echo '" id="xc_vm-epg">
																<div class="form-group row mb-4"> <label class="col-md-3 col-form-label" for="epg_api_name">Channel Name</label>
																	<div class="col-md-5"> <input readonly id="epg_api_name" name="epg_api_name" type="text" class="form-control" value="';

									if (!(isset($rStream) && $rStream['epg_api'])) {
									} else {
										echo $rStream['epg_api_name'];
									}

									echo '"> </div>
																	<div class="col-md-2"> <input readonly id="epg_api_id" name="epg_api_id" type="text" class="form-control text-center" value="';

									if (!(isset($rStream) && $rStream['epg_api'])) {
									} else {
										echo $rStream['channel_id'];
									}

									echo '"> </div>
																	<div class="col-md-2"> <button type="button" style="width: 100%" class="btn btn-info waves-effect waves-light btn-xl" id="epg-api"><i class="mdi mdi-magnify"></i></button> </div>
																</div>
																<div class="table-responsive" id="table-epg-data" style="display: none; padding-bottom: 30px;">
																	<table class="table table-striped table-borderless mb-0">
																		<thead>
																			<tr>
																				<th class="text-center">Time</th>
																				<th>Title</th>
																				<th>Description</th>
																			</tr>
																		</thead>
																		<tbody></tbody>
																	</table>
																</div>
															</div>
															<div class="tab-pane';

									if (!isset($rStream) || $rStream['epg_api']) {
									} else {
										echo ' active';
									}

									echo '" id="xmltv-epg">
																<div class="form-group row mb-4"> <label class="col-md-4 col-form-label" for="epg_id">EPG Source</label>
																	<div class="col-md-8"> <select name="epg_id" id="epg_id" class="form-control" data-toggle="select2">
																			<option ';

									if (!isset($rStream)) {
									} else {
										if (intval($rStream[' epg_id']) != 0) {
										} else {
											echo 'selected ';
										}
									}

									echo 'value="0">No EPG</option>                                                                    ';

									foreach ($rEPGSources as $rEPG) {
										echo '                                                                    <option ';

										if (!isset($rStream)) {
										} else {
											if (intval($rStream['epg_id']) != $rEPG['id']) {
											} else {
												echo 'selected ';
											}
										}

										echo 'value="';
										echo $rEPG['id'];
										echo '">';
										echo $rEPG['epg_name'];
										echo '</option>                                                                    ';
									}
									echo '                                                                </select>                                                            </div>                                                        </div>                                                        <div class="form-group row mb-4">                                                            <label class="col-md-4 col-form-label" for="channel_id">EPG Channel ID</label>                                                            <div class="col-md-8">                                                                <select name="channel_id" id="channel_id" class="form-control" data-toggle="select2">                                                                ';

									if (!isset($rStream)) {
									} else {
										foreach (json_decode($rEPGSources[intval($rStream['epg_id'])]['data'], true) as $rKey => $rEPGChannel) {
											echo '
																			<option value="';
											echo $rKey;
											echo '"';

											if ($rStream[' channel_id'] != $rKey) {
											} else {
												echo ' selected';
											}

											echo '>';
											echo $rEPGChannel['display_name'];
											echo '</option>                                                                    ';
										}
									}

									echo '                                                                </select>                                                            </div>                                                        </div>                                                        <div class="form-group row mb-4">                                                            <label class="col-md-4 col-form-label" for="epg_lang">EPG Language</label>                                                            <div class="col-md-4">                                                                <select name="epg_lang" id="epg_lang" class="form-control" data-toggle="select2">                                                                ';

									if (!isset($rStream)) {
									} else {
										foreach (json_decode($rEPGSources[intval($rStream['epg_id'])]['data'], true)[$rStream['channel_id']]['langs'] as $rID => $rLang) {
											echo '
																			<option value="';
											echo $rLang;
											echo '"';

											if ($rStream[' epg_lang'] != $rLang) {
											} else {
												echo ' selected';
											}

											echo '>';
											echo $rLang;
											echo '</option>                                                                    ';
										}
									}

									echo '                                                                </select>                                                            </div>                                                            <label class="col-md-2 col-form-label" for="epg_offset">Minute Offset</label>                                                            <div class="col-md-2">                                                                <input type="text" class="form-control text-center" id="epg_offset" name="epg_offset" value="';

									if (isset($rStream)) {
										echo (intval($rStream['epg_offset']) ?: 0);
									} else {
										echo '0';
									}

									echo '" required data-parsley-trigger="change">                                                            </div>                                                        </div>                                                    </div>                                                </div></div> </div> <ul class="list-inline wizard mb-0"><li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a></li><li class="nextb list-inline-item float-right"><a href="javascript: void(0);" class="btn btn-secondary">Next</a></li></ul></div>                                    <div class="tab-pane" id="rtmp-push"><div class="row"><div class="col-12">                                                <div class="form-group row mb-4">                                                    <label class="col-md-9 col-form-label" for="rtmp_output">Output RTMP <i title="Feed stream to the RTMP server for output to RTMP clients." class="tooltip text-secondary far fa-circle"></i></label>                                                    <div class="col-md-3">                                                        <input name="rtmp_output" id="rtmp_output" type="checkbox" ';

									if (!isset($rStream)) {
									} else {
										if ($rStream['rtmp_output'] != 1) {
										} else {
											echo 'checked ';
										}
									}

									echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>                                                    </div>                                                </div>                                                <div class="alert bg-info text-white border-0" role="alert">RTMP Push will allow you to push your streams to RTMP servers, such as the one that runs with XC_VM. The `Push From` server needs to be enabled in the servers tab for this to be activated.</div>                                                <table id="datatable-rtmp" class="table table-striped table-borderless mb-0">                                                    <thead>                                                        <tr>                                                            <th>Push From</th>                                                            <th>RTMP URL</th>                                                        </tr>                                                    </thead>                                                    <tbody class="rtmp">                                                        ';

									if (isset($rStream)) {
										$rRTMPPush = json_decode($rStream['external_push'], true);

										if ($rRTMPPush) {
										} else {
											$rRTMPPush = array(array(''));
										}
									} else {
										$rRTMPPush = array(array(''));
									}

									$i = 0;

									foreach ($rRTMPPush as $rServerID => $rSources) {
										foreach ($rSources as $rSource) {
											echo ' <tr class="rtmp_info">
																					<td class="rtmp_server"> <select id="rtmp_push_';
											echo $i;
											echo '" class="form-control" data-toggle="select2"> ';

											foreach ($rServers as $rServer) {
												echo ' <option value="';
												echo $rServer['id'];
												echo '"';

												if (!(isset($rStream) && $rServerID == $rServer[' id'])) {
												} else {
													echo ' selected';
												}

												echo '>';
												echo $rServer['server_name'];
												echo '</option>                                                                            ';
											}
											echo '                                                                        </select>                                                                    </td>                                                                    <td class="input-group">                                                                        <input type="text" class="form-control" value="';
											echo htmlspecialchars($rSource);
											echo '">                                                                        <div class="input-group-append">                                                                            <button class="btn btn-danger waves-effect waves-light btn-fixed-xs" onClick="removeRTMP(this);" type="button"><i class="mdi mdi-close"></i></button>                                                                        </div>                                                                    </td>                                                                </tr>                                                            ';
											$i++;
										}
									}
									echo '                                                    </tbody>                                                </table>                                            </div>                                        </div>                                        <ul class="list-inline wizard mb-0" style="padding-top: 30px;"><li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a>                                            </li><li class="list-inline-item float-right">                                                <a onClick="addRTMP();" class="btn btn-info btn-pointer">Add RTMP URL</a><a href="javascript: void(0);" class="btn nextb btn-secondary">Next</a></li></ul>                                    </div>';
								}

								echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="load-balancing"><div class="row"><div class="col-12"><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="servers">Server Tree</label><div class="col-md-9"><div id="server_tree"></div></div></div><div class="form-group row mb-4">                                                    <label class="col-md-3 col-form-label" for="on_demand">On-Demand Servers</label><div class="col-md-3">                                                        <select name="on_demand[]" id="on_demand" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

								foreach ($rServers as $rServer) {
									echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
									echo $rServer['id'];
									echo '"';

									if (!(isset($rStream) && in_array($rServer['id'], $rOnDemand))) {
									} else {
										echo ' selected';
									}

									echo '>';
									echo $rServer['server_name'];
									echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
								}
								echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select></div>' . "\n" . "                                                    <label class=\" col-md-3 col-form-label\" for=\"llod\">Low Latency On-Demand <i title=\"<strong>LLOD v2 - FFMPEG</strong><br />The first source is selected without probing and passed directly to FFMPEG for processing. If the first source is down, the stream will not start.<br /><br />LLOD v3 - PHP<br />A bespoke segment parser developed by XC_VM to take incoming MPEG-TS streams and identify keyframes in order to segment the stream to deliver back to the client. Very fast, still experimental however. This method doesn't suffer from issues such as lost audio as ffmpeg isn't involved at all but does not support anything but MPEG-TS sources.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3"><select name="llod" id="llod" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

								foreach (array('Disabled', 'LLOD v2 - FFMPEG', 'LLOD v3 - PHP') as $rValue => $rText) {
									echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
									echo $rValue;
									echo '"';

									if (!(isset($rStream) && $rStream[' llod'] == $rValue)) {
									} else {
										echo ' selected';
									}

									echo '>';
									echo $rText;
									echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
								}
								echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select></div></div><div class="form-group row mb-4">                                                    <label class="col-md-3 col-form-label" for="tv_archive_server_id">Timeshift Server</label><div class="col-md-3"><select name="tv_archive_server_id" id="tv_archive_server_id" class="form-control" data-toggle="select2"><option value="0">Disabled</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

								foreach ($rServers as $rServer) {
									echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
									echo $rServer['id'];
									echo '"';

									if (!(isset($rStream) && $rStream['tv_archive_server_id'] == $rServer['id'])) {
									} else {
										echo ' selected';
									}

									echo '>';
									echo $rServer['server_name'];
									echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
								}
								echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select></div>                                                    <label class="col-md-3 col-form-label" for="tv_archive_duration">Timeshift Days</label><div class="col-md-3"><input type="text" class="form-control text-center" id="tv_archive_duration" name="tv_archive_duration" value="';

								if (isset($rStream)) {
									echo $rStream['tv_archive_duration'];
								} else {
									echo '0';
								}

								echo '"></select></div>                                                </div><div class="form-group row mb-4">                                                    <label class="col-md-3 col-form-label" for="vframes_server_id">Thumbnail Server</label><div class="col-md-3"><select name="vframes_server_id" id="vframes_server_id" class="form-control" data-toggle="select2"><option value="0">Disabled</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

								foreach ($rServers as $rServer) {
									echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
									echo $rServer['id'];
									echo '"';

									if (!(isset($rStream) && $rStream['vframes_server_id'] == $rServer['id'])) {
									} else {
										echo ' selected';
									}

									echo '>';
									echo $rServer['server_name'];
									echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
								}
								?>

								</select>
							</div><label class="col-md-3 col-form-label" for="restart_on_edit">
								<?php
								if (isset($rStream['id'])) {
									echo 'Restart on Edit';
								} else {
									echo 'Start Stream Now';
								}
								?>


							</label>
							<div class="col-md-3"><input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" /></div>
					</div>
				</div>
			</div>
			<ul class="list-inline wizard mb-0">
				<li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a></li>
				<li class="list-inline-item float-right"><input name="submit_stream" type="submit" class="btn btn-primary" value="Save" /></li>
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
include 'footer.php';
