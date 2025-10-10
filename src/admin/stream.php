<?php

include 'session.php';
include 'functions.php';


if (!checkPermissions()) {
	goHome();
}

if (isset(CoreUtilities::$rRequest['id'])) {
	if (!isset(CoreUtilities::$rRequest['import']) && hasPermissions('adv', 'edit_stream')) {
		$rStream = getStream(CoreUtilities::$rRequest['id']);

		if (!$rStream && $rStream['type'] != 1) {
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

									echo '<li class="nav-item"><a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-folder-alert-outline mr-1"></i><span class="d-none d-sm-inline">Advanced</span></a></li>';

									if (isset(CoreUtilities::$rRequest['import'])) {
									} else {
										echo '<li class="nav-item"><a href="#stream-map" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"><i class="mdi mdi-map mr-1"></i><span class="d-none d-sm-inline">Map</span></a></li>
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

												echo '<div class="tab-pane" id="advanced-options"><div class="row"><div class="col-12"><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="gen_timestamps">Generate PTS <i title="Allow FFmpeg to generate presentation timestamps for you to achieve better synchronization with the stream codecs. In some streams this can cause de-sync." class="tooltip text-secondary far fa-circle"></i></label><div class="col-md-3"><input name="gen_timestamps" id="gen_timestamps" type="checkbox" ';

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
													if ($rStream['direct_source'] != 1) {
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
													if (intval($rStream['transcode_profile_id']) != 0) {
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

												if (count($rAdaptiveLink) > 0) {
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

								if (!isset(CoreUtilities::$rRequest['import'])) {
									echo '<div class="tab-pane" id="stream-map">
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

									if (isset($rStream)) {
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

									if (!isset($rStream)) {
										echo ' active';
									}

									echo '"> Quick Search </a> </li>
															<li class="nav-item"> <a href="#xmltv-epg" id="tab-xml-epg" data-toggle="tab" aria-expanded="false" class="nav-link';

									if (isset($rStream)) {
										echo ' active';
									}

									echo '"> XMLTV EPG </a> </li>
														</ul>
														<div class="tab-content" style="padding-top: 30px;">
															<div class="tab-pane';

									if (!isset($rStream)) {
										echo ' active';
									}

									echo '" id="quick-search">
																<div class="form-group row mb-4"> <label class="col-md-3 col-form-label" for="quick_search">Search EPG</label>
																	<div class="col-md-9"> <select id="quick_search" class="form-control" data-toggle="select2"></select> </div>
																</div>
															</div>
															
															<div class="tab-pane';

									if (isset($rStream)) {
										echo ' active';
									}

									echo '" id="xmltv-epg">
																<div class="form-group row mb-4"> <label class="col-md-4 col-form-label" for="epg_id">EPG Source</label>
																	<div class="col-md-8"> <select name="epg_id" id="epg_id" class="form-control" data-toggle="select2">
																			<option ';

									if (!isset($rStream)) {
									} else {
										if (intval($rStream['epg_id']) != 0) {
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

									if (isset($rStream)) {
										foreach (json_decode($rEPGSources[intval($rStream['epg_id'])]['data'], true) as $rKey => $rEPGChannel) {
											echo '
																			<option value="';
											echo $rKey;
											echo '"';

											if ($rStream['channel_id'] == $rKey) {
												echo ' selected';
											}

											echo '>';
											echo $rEPGChannel['display_name'];
											echo '</option>                                                                    ';
										}
									}

									echo '                                                                </select>                                                            </div>                                                        </div>                                                        <div class="form-group row mb-4">                                                            <label class="col-md-4 col-form-label" for="epg_lang">EPG Language</label>                                                            <div class="col-md-4">                                                                <select name="epg_lang" id="epg_lang" class="form-control" data-toggle="select2">                                                                ';

									if (isset($rStream)) {
										foreach (json_decode($rEPGSources[intval($rStream['epg_id'])]['data'], true)[$rStream['channel_id']]['langs'] as $rID => $rLang) {
											echo '
																			<option value="';
											echo $rLang;
											echo '"';

											if ($rStream['epg_lang'] == $rLang) {
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

												if (!(isset($rStream) && $rServerID == $rServer['id'])) {
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

								echo '<div class="tab-pane" id="load-balancing"><div class="row"><div class="col-12"><div class="form-group row mb-4"><label class="col-md-3 col-form-label" for="servers">Server Tree</label><div class="col-md-9"><div id="server_tree"></div></div></div><div class="form-group row mb-4">                                                    <label class="col-md-3 col-form-label" for="on_demand">On-Demand Servers</label><div class="col-md-3">                                                        <select name="on_demand[]" id="on_demand" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

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

									if (!(isset($rStream) && $rStream['llod'] == $rValue)) {
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

			<?php
		echo '        ' . "\r\n\t\t" . 'var rEPG = ';
		echo json_encode($rEPGJS);
		echo ';' . "\r\n" . '        var rVideoDevices = ';
		echo json_encode($rVideoDevices);
		echo ';' . "\r\n\t\t" . 'var rAudioDevices = ';
		echo json_encode($rAudioDevices);
		echo ';' . "\r\n\t\t\r\n" . '        function clearTitle() {' . "\r\n" . '            $("#title_sync").val("").trigger("change");' . "\r\n" . '        }' . "\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).parent().parent().find("input").val();' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rImage)," . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function scanSources() {' . "\r\n\t\t\t" . '$(".streams .stream_info").each(function() {' . "\r\n" . '                if ($(this).find("input").val()) {' . "\r\n" . '                    var rStream = this;' . "\r\n\t\t\t\t\t" . 'var rServer = "";' . "\r\n\t\t\t\t\t" . "if (\$('#server_tree').jstree(true).get_json('source', {flat:true})[1] !== undefined) {" . "\r\n\t\t\t\t\t\t" . "rServer = \$('#server_tree').jstree(true).get_json('source', {flat:true})[1][\"id\"];" . "\r\n\t\t\t\t\t" . '}' . "\r\n" . "                    \$(rStream).find(\"#stream_info\").html(\"<table class='table-data' style='width: 380px;' align='center'><tbody><tr><td colspan='5'>Probing source...</td></tr></tbody></table>\");" . "\r\n" . '                    $.get("./api?action=probe_stream&url=" + encodeURIComponent($(this).find("input").val()) + "&user_agent=" + encodeURIComponent($("#user_agent").val()) + "&proxy=" + encodeURIComponent($("#http_proxy").val()) + "&cookies=" + encodeURIComponent($("#cookie").val()) + "&headers=" + encodeURIComponent($("#headers").val()) + "&server=" + rServer, function(data) {' . "\r\n" . '                        $(rStream).find("#stream_info").html(data);' . "\r\n" . '                    });' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function moveUp(elem) {' . "\r\n\t\t\t" . 'if ($(elem).parent().parent().parent().prevAll().length > 0) {' . "\r\n\t\t\t\t" . "\$(elem).parent().parent().parent().insertBefore(\$('.streams>tr').eq(\$(elem).parent().parent().parent().prevAll().length-1));" . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function moveDown(elem) {' . "\r\n\t\t\t" . 'if ($(elem).parent().parent().parent().prevAll().length < $(".streams>tr").length) {' . "\r\n\t\t\t\t" . "\$(elem).parent().parent().parent().insertAfter(\$('.streams>tr').eq(\$(elem).parent().parent().parent().prevAll().length+1));" . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function addStream(rURL = null) {' . "\r\n" . '            if ($(".stream_info:last input").val().length > 0) {' . "\r\n" . '                $(".stream_info:first").clone().appendTo(".streams");' . "\r\n" . '            } else if (rURL == null) {' . "\r\n" . '                $.toast("You already have an empty row.");' . "\r\n" . '            }' . "\r\n" . "            \$(\".stream_info:last .table-data\").html(\"<tbody><tr><td colspan='4'>Not scanned</td></tr></tbody>\");" . "\r\n" . '            if (rURL) {' . "\r\n" . '                $(".stream_info:last input").val(rURL);' . "\r\n" . '                $(".bs-provider-streams-modal-center").modal("hide");' . "\r\n" . '            } else {' . "\r\n" . '                $(".stream_info:last input").val("");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function removeStream(elem) {' . "\r\n\t\t\t" . "if (\$('.stream_info').length > 1) {" . "\r\n\t\t\t\t" . '$(elem).parent().parent().parent().remove();' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$(elem).parent().parent().parent().find("#stream_source").val("");' . "\r\n" . "                \$(elem).parent().parent().parent().find(\".table-data\").html(\"<tbody><tr><td colspan='4'>Not scanned</td></tr></tbody>\");" . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        function addRTMP() {' . "\r\n\t\t\t" . '$(".rtmp_info:first").clone().appendTo(".rtmp");' . "\r\n" . "            \$(\".rtmp_info:last .rtmp_server\").html('<select id=\"rtmp_push_' + Math.random().toString(16).substring(2) + '\" class=\"form-control\" data-toggle=\"select2\">";

		foreach ($rServers as $rServer) {
			echo '<option value="';
			echo $rServer['id'];
			echo '"';

			if (!(isset($rStream) && $rServerID == $rServer['id'])) {
			} else {
				echo ' selected';
			}

			echo '>';
			echo $rServer['server_name'];
			echo '</option>';
		}
		echo "</select>');" . "\r\n" . "            \$(\".rtmp_info:last select\").select2({width: '100%'});" . "\r\n\t\t\t" . '$(".rtmp_info:last input").val("");' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function removeRTMP(elem) {' . "\r\n\t\t\t" . "if (\$('.rtmp_info').length > 1) {" . "\r\n\t\t\t\t" . '$(elem).parent().parent().parent().remove();' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$(elem).parent().parent().parent().find("input").val("");' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectEPGSource() {' . "\r\n\t\t\t" . '$("#channel_id").empty();' . "\r\n\t\t\t" . '$("#epg_lang").empty();' . "\r\n\t\t\t" . 'if (rEPG[$("#epg_id").val()]) {' . "\r\n\t\t\t\t" . '$.each(rEPG[$("#epg_id").val()], function(key, data) {' . "\r\n\t\t\t\t\t" . '$("#channel_id").append(new Option(data["display_name"], key, false, false));' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . 'selectEPGID();' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectEPGID() {' . "\r\n\t\t\t" . '$("#epg_lang").empty();' . "\r\n\t\t\t" . 'if (rEPG[$("#epg_id").val()][$("#channel_id").val()]) {' . "\r\n\t\t\t\t" . '$.each(rEPG[$("#epg_id").val()][$("#channel_id").val()]["langs"], function(i, data) {' . "\r\n\t\t\t\t\t" . '$("#epg_lang").append(new Option(data, data, false, false));' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleCapture() {' . "\r\n\t\t\t" . 'if ($("#capture_form").is(":visible")) {' . "\r\n\t\t\t\t" . '$("#capture_form").hide();' . "\r\n\t\t\t\t" . '$("#datatable-sources").show();' . "\r\n\t\t\t\t" . '$("#source_form").show();' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#source_form").hide();' . "\r\n\t\t\t\t" . '$("#datatable-sources").hide();' . "\r\n\t\t\t\t" . '$("#capture_form").show();' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        function evaluateServers() {' . "\r\n" . '            var rVVal = $("#vframes_server_id").val();' . "\r\n" . '            var rTVal = $("#tv_archive_server_id").val();' . "\r\n" . '            var rOVal = $("#on_demand").val();' . "\r\n" . '            $("#on_demand").empty();' . "\r\n" . '            $("#vframes_server_id").empty().append(new Option("Disabled", 0));' . "\r\n" . '            $("#tv_archive_server_id").empty().append(new Option("Disabled", 0));' . "\r\n" . "            \$(\$('#server_tree').jstree(true).get_json('source', {flat:true})).each(function(index, value) {" . "\r\n" . '                if (value.parent != "#") {' . "\r\n" . '                    $("#vframes_server_id").append(new Option(value.text, value.id));' . "\r\n" . '                    $("#tv_archive_server_id").append(new Option(value.text, value.id));' . "\r\n" . '                    $("#on_demand").append(new Option(value.text, value.id));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#vframes_server_id").val(rVVal).trigger("change");' . "\r\n" . '            if (!$("#vframes_server_id").val()) {' . "\r\n" . '                $("#vframes_server_id").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '            $("#tv_archive_server_id").val(rTVal).trigger("change");' . "\r\n" . '            if (!$("#tv_archive_server_id").val()) {' . "\r\n" . '                $("#tv_archive_server_id").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '            $("#on_demand").val(rOVal).trigger("change");' . "\r\n" . '            if (!$("#on_demand").val()) {' . "\r\n" . '                $("#on_demand").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function evaluateDirectSource() {' . "\r\n" . '            $(["llod", "fps_restart", "fps_threshold", "adaptive_link", "custom_sid", "read_native", "gen_timestamps", "stream_all", "allow_record", "rtmp_output", "delay_minutes", "custom_ffmpeg", "probesize_ondemand", "transcode_profile_id", "custom_map", "days_to_restart", "time_to_restart", "on_demand", "tv_archive_duration", "tv_archive_server_id", "vframes_server_id", "restart_on_edit"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if ($("#direct_source").is(":checked")) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $(["direct_proxy"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if (!$("#direct_source").is(":checked")) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $(["user_agent", "http_proxy", "cookie", "headers"]).each(function(rID, rElement) {' . "\r\n" . '                if ($(rElement)) {' . "\r\n" . '                    if (($("#direct_proxy").is(":checked")) || (!$("#direct_source").is(":checked"))) {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            window.rSwitches[rElement].enable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        if (window.rSwitches[rElement]) {' . "\r\n" . '                            setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                            window.rSwitches[rElement].disable();' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#" + rElement).prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . '            $("#stream_sources_list").nestable({maxDepth: 1});' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});;" . "\r\n" . "            \$('#adaptive_link').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'adaptivelist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for a stream...'" . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#title_sync').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'titlesync'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for a stream...'" . "\r\n\t\t\t" . '});            ' . "\r\n" . '            $("#category_id").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#category_id').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#category_create").show();' . "\r\n" . "                    \$(\"#category_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#category_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#category_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n" . '            $("#bouquets").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#bouquets').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#bouquet_create").show();' . "\r\n" . "                    \$(\"#bouquet_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#bouquet_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#bouquet_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#epg_id").on("select2:select", function(e) { ' . "\r\n\t\t\t\t" . 'selectEPGSource();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#channel_id").on("select2:select", function(e) { ' . "\r\n\t\t\t\t" . 'selectEPGID();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$(".clockpicker").clockpicker();' . "\r\n\t\t\t" . "\$('#server_tree').on('redraw.jstree', function (e, data) {" . "\r\n" . '                evaluateServers();' . "\r\n" . "            }).on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n" . '                            if (parent.id > 0 && $("#direct_proxy").is(":checked")) { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n" . '                    ' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#direct_source").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#direct_proxy").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#quick_search').select2({" . "\r\n" . '              ajax: {' . "\r\n" . "                url: './api'," . "\r\n" . "                dataType: 'json'," . "\r\n" . '                data: function (params) {' . "\r\n" . '                  return {' . "\r\n" . '                    search: params.term,' . "\r\n" . "                    action: 'epglist'," . "\r\n" . '                    page: params.page' . "\r\n" . '                  };' . "\r\n" . '                },' . "\r\n" . '                processResults: function (data, params) {' . "\r\n" . '                  params.page = params.page || 1;' . "\r\n" . '                  return {' . "\r\n" . '                    results: data.items,' . "\r\n" . '                    pagination: {' . "\r\n" . '                        more: (params.page * 100) < data.total_count' . "\r\n" . '                    }' . "\r\n" . '                  };' . "\r\n" . '                },' . "\r\n" . '                cache: true' . "\r\n" . '              },' . "\r\n" . "              placeholder: 'Search...'" . "\r\n" . '            });' . "\r\n" . '            $("#quick_search").change(function() {' . "\r\n" . '                if ($(this).val()) {' . "\r\n" . '                    var rData = $("#quick_search").select2("data")[0];' . "\r\n" . '                    if (rData) {' . "\r\n" . '                        if (rData.type == 0) {' . "\r\n" . '                            $("#epg_id").val(rData.epg_id).trigger("change");' . "\r\n" . '                            selectEPGSource();' . "\r\n" . '                            $("#channel_id").val(rData.id).trigger("change");' . "\r\n" . '                            selectEPGID();' . "\r\n" . '                            $("#epg_lang").val(rData.lang).trigger("change");' . "\r\n" . '                            $("#tab-xml-epg").click();' . "\r\n" . '                            if ($("#stream_display_name").val().length == 0) {' . "\r\n" . '                                $("#stream_display_name").val(rData.text);' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#quick_search").val("").trigger("change");' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#load_maps").click(function() {' . "\r\n\t\t\t\t" . 'rURL = $("#stream_source:eq(0)").val();' . "\r\n\t\t\t\t" . 'if (rURL) {' . "\r\n\t\t\t\t\t" . '$.toast("Stream map has started, this can take a while depending on how many streams are present.");' . "\r\n\t\t\t\t\t" . '$("#datatable-map").DataTable().clear().draw();' . "\r\n\t\t\t\t\t" . 'var rServer = "";' . "\r\n\t\t\t\t\t" . "if (\$('#server_tree').jstree(true).get_json('source', {flat:true})[1] !== undefined) {" . "\r\n\t\t\t\t\t\t" . "rServer = \$('#server_tree').jstree(true).get_json('source', {flat:true})[1][\"id\"];" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$.getJSON("./api?action=probe_stream&map=1&url=" + encodeURIComponent(rURL) + "&user_agent=" + encodeURIComponent($("#user_agent").val()) + "&proxy=" + encodeURIComponent($("#http_proxy").val()) + "&cookies=" + encodeURIComponent($("#cookie").val()) + "&headers=" + encodeURIComponent($("#headers").val()) + "&server=" + rServer, function(data) {' . "\r\n\t\t\t\t\t\t" . '$(data.streams).each(function(id, array) {' . "\r\n\t\t\t\t\t\t\t" . 'if (array.codec_type == "video") {' . "\r\n\t\t\t\t\t\t\t\t" . 'rString = array.codec_name.toUpperCase();' . "\r\n\t\t\t\t\t\t\t\t" . 'if (array.profile) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " (" + array.profile + ")";' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if (array.pix_fmt) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.pix_fmt;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.width) && (array.height)) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.width + "x" + array.height;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.avg_frame_rate) && (array.avg_frame_rate.split("/")[0] > 0)) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.avg_frame_rate.split("/")[0] + " fps";' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . '$("#datatable-map").DataTable().row.add([array.index, "Video", rString]);' . "\r\n\t\t\t\t\t\t\t" . '} else if (array.codec_type == "audio") {' . "\r\n\t\t\t\t\t\t\t\t" . 'rString = array.codec_name.toUpperCase();' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.tags) && (array.tags.language)) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.tags.language.toUpperCase();' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.sample_rate) && (array.sample_rate > 0)) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.sample_rate + " Hz";' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if (array.channel_layout) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.channel_layout;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if (array.sample_fmt) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.sample_fmt;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.bit_rate) || ((array.tags) && (array.tags.variant_bitrate))) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (array.bit_rate) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rString += " - " + Math.ceil(array.bit_rate / 1000) + " kb/s";' . "\r\n\t\t\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rString += " - " + Math.ceil(array.tags.variant_bitrate / 1000) + " vbr";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . 'if (array.disposition) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (array.disposition.visual_impaired) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rString += " - Visual Impaired";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (array.disposition.hearing_impaired) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rString += " - Hearing Impaired";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (array.disposition.dub) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rString += " - Dub";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . '$("#datatable-map").DataTable().row.add([array.index, "Audio", rString]);' . "\r\n\t\t\t\t\t\t\t" . '} else if (array.codec_type == "subtitle") {' . "\r\n\t\t\t\t\t\t\t\t" . 'rString = array.codec_long_name.toUpperCase();' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.tags) && (array.tags.language)) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + array.tags.language.toUpperCase();' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . '$("#datatable-map").DataTable().row.add([array.index, "Subtitle", rString]);' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . 'rString = array.codec_long_name.toUpperCase();' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((array.tags) && (array.tags.variant_bitrate)) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rString += " - " + Math.ceil(array.tags.variant_bitrate / 1000) + " vbr";' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . '$("#datatable-map").DataTable().row.add([array.index, "Data", rString]);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable-map").DataTable().draw();' . "\r\n\t\t\t\t\t\t" . 'if (data.streams) {' . "\r\n\t\t\t\t\t\t\t" . '$.toast("Stream map complete. Please select relevant streams from the table.");' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . "\$.toast(\"Stream mapping didn't return any information.\");" . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}).fail(function() {' . "\r\n\t\t\t\t\t\t" . '$.toast("An error occured while mapping streams.");' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#epg_offset").inputFilter(function(value) { return /^-?[0-9]\\d*(\\.\\d+)?$/.test(value); });' . "\r\n" . '            $("#fps_threshold").inputFilter(function(value) { return /^\\d*$/.test(value) && (value === "" || parseInt(value) <= 100); });' . "\r\n\t\t\t" . '$("#datatable-map").DataTable({' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1]},' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'select: {' . "\r\n\t\t\t\t\t" . "style: 'multi'" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . "}).on('select', function (e, dt, type, indexes) {" . "\r\n\t\t\t\t" . 'var i; var rMap = "";' . "\r\n\t\t\t\t" . "for (i = 0; i < \$(\"#datatable-map\").DataTable().rows('.selected').data().length; i++) {" . "\r\n\t\t\t\t\t" . "rMap += \"-map 0:\" + \$(\"#datatable-map\").DataTable().rows('.selected').data()[i][0] + \" \";" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$("#custom_map").val(rMap.trim());' . "\r\n\t\t\t" . "}).on('deselect', function (e, dt, type, indexes) {" . "\r\n\t\t\t\t" . 'var i; var rMap = "";' . "\r\n\t\t\t\t" . "for (i = 0; i < \$(\"#datatable-map\").DataTable().rows('.selected').data().length; i++) {" . "\r\n\t\t\t\t\t" . "rMap += \"-map 0:\" + \$(\"#datatable-map\").DataTable().rows('.selected').data()[i][0] + \" \";" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$("#custom_map").val(rMap.trim());' . "\r\n\t\t\t" . '});';
		echo '            $("#datatable-provider-streams").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "provider_streams",' . "\r\n" . '                        d.type = "live"' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"order": [[ 0, "asc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-provider-streams").css("width", "100%");' . "\r\n" . '            $("#provider-streams").click(function() {' . "\r\n" . '                $("#datatable-provider-streams").DataTable().search($("#stream_display_name").val()).draw();' . "\r\n" . '                $(".bs-provider-streams-modal-center").modal("show");' . "\r\n" . '            });' . "\r\n" . '            $("#epg_picon_save").click(function() {' . "\r\n" . '                $("#stream_icon").val($("#epg-picon").attr("src")).trigger("change");' . "\r\n" . '                $(".bs-picon-modal-center").modal("hide");' . "\r\n" . '                $.toast("Stream icon has been set.");' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#capture_server_id").change(function() {' . "\r\n\t\t\t\t" . 'if (window.rVideoDevices[$("#capture_server_id").val()]) {' . "\r\n\t\t\t\t\t" . '// Coming soon! :)' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . 'if (window.rAudioDevices[$("#capture_server_id").val()]) {' . "\r\n\t\t\t\t\t" . '// Coming soon! :)' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'evaluateDirectSource();' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                rSubmit = true;' . "\r\n\t\t\t\t";

		if (!isset(CoreUtilities::$rRequest['import'])) {
			echo "\t\t\t\t" . 'if ($("#stream_display_name").val().length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Enter a stream name.");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t";
		} else {
			echo "\t\t\t\t" . 'if ($("#m3u_file").val().length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Please select a M3U file to upload.");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t";
		}

		echo "\t\t\t\t" . "\$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));";

		if (!isset(CoreUtilities::$rRequest['import'])) {
			echo '                var rRTMPPush = {};' . "\r\n" . '                $(".rtmp_info").each(function() {' . "\r\n" . '                    rServerID = $(this).find("select").val();' . "\r\n" . '                    rSource = $(this).find("input").val();' . "\r\n" . '                    if (rServerID > 0 && rSource.length > 0) {' . "\r\n" . '                        if (!rRTMPPush[rServerID]) {' . "\r\n" . '                            rRTMPPush[rServerID] = [];' . "\r\n" . '                        }' . "\r\n" . '                        rRTMPPush[rServerID].push(rSource);' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '                $("#external_push").val(JSON.stringify(rRTMPPush));' . "\r\n" . '                ';
		}

		echo '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
