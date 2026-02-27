<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
										echo ' style="display: none;"';
									} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?= isset($rFolder) ? 'Edit' : 'Add'; ?> Folder</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form action="#" method="POST" data-parsley-validate="">
							<?php if (isset($rFolder)) : ?>
								<input type="hidden" name="edit" value="<?= intval($rFolder['id']); ?>" />
							<?php endif; ?>
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#folder-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline">Details</span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#settings" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-wrench mr-1"></i>
											<span class="d-none d-sm-inline">Settings</span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#override" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-movie mr-1"></i>
											<span class="d-none d-sm-inline">Overrides</span>
										</a>
									</li>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<!-- Tab 1: Details -->
									<div class="tab-pane" id="folder-details">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="folder_type">Folder Type</label>
													<div class="col-md-8">
														<select id="folder_type" name="folder_type" class="form-control" data-toggle="select2">
															<?php foreach (array('movie' => 'Movies', 'series' => 'TV Series') as $rKey => $rType) : ?>
																<option value="<?= $rKey; ?>" <?php if (isset($rFolder) && $rFolder['type'] == $rKey) echo ' selected'; ?>><?= $rType; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="server_id">Server Name</label>
													<div class="col-md-8">
														<select id="server_id" name="server_id" class="form-control" data-toggle="select2">
															<?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer) : ?>
																<option value="<?= $rServer['id']; ?>" <?php if (isset($rFolder) && $rFolder['server_id'] == $rServer['id']) echo ' selected'; ?>><?= $rServer['server_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="selected_path">Selected Path</label>
													<div class="col-md-8 input-group">
														<input type="text" id="selected_path" name="selected_path" class="form-control" value="<?= isset($rFolder) ? $rFolder['directory'] : '/'; ?>" required data-parsley-trigger="change">
														<div class="input-group-append">
															<button class="btn btn-primary waves-effect waves-light" type="button" id="changeDir"><i class="mdi mdi-chevron-right"></i></button>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="rclone_dir">Rclone Path <i title="Enter the Rclone path here to scan the folder using the Rclone API, would be quicker for remote drives.<br/><br/>You need to modify the rclone.conf file in the config folder with the correct mount information for this to work." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<input type="text" id="rclone_dir" name="rclone_dir" class="form-control" value="<?= isset($rFolder) ? $rFolder['rclone_dir'] : ''; ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="active">Enabled</label>
													<div class="col-md-2">
														<input name="active" id="active" type="checkbox" <?php if (!isset($rFolder) || $rFolder['active']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<div class="col-md-6">
														<table id="datatable" class="table">
															<thead>
																<tr>
																	<th width="20px"></th>
																	<th>Directory</th>
																</tr>
															</thead>
															<tbody></tbody>
														</table>
													</div>
													<div class="col-md-6">
														<table id="datatable-files" class="table">
															<thead>
																<tr>
																	<th width="20px"></th>
																	<th>Filename</th>
																</tr>
															</thead>
															<tbody></tbody>
														</table>
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="nextb list-inline-item float-right">
												<a href="javascript: void(0);" class="btn btn-secondary">Next</a>
											</li>
										</ul>
									</div>
									<!-- Tab 2: Settings -->
									<div class="tab-pane" id="settings">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="disable_tmdb">Disable TMDb <i title="Do not use TMDb to match the content." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="disable_tmdb" id="disable_tmdb" type="checkbox" <?php if (isset($rFolder) && $rFolder['disable_tmdb']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="ignore_no_match">Ignore No Match <i title="Add to database even if no TMDb match is found." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="ignore_no_match" id="ignore_no_match" type="checkbox" <?php if (isset($rFolder) && $rFolder['ignore_no_match']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="read_native">Native Frames <i title="Read input video at native frame rate." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="read_native" id="read_native" type="checkbox" <?php if (isset($rFolder) && $rFolder['read_native']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="movie_symlink">Create Symlink <i title="Generate a symlink to the original file instead of encoding. File needs to exist on all selected servers." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="movie_symlink" id="movie_symlink" type="checkbox" <?php if (isset($rFolder) && $rFolder['movie_symlink']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="auto_encode">Auto-Encode <i title="Start encoding as soon as the movie is added." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="auto_encode" id="auto_encode" type="checkbox" <?php if (!isset($rFolder) || $rFolder['auto_encode']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="ffprobe_input">Probe Input <i title="Use ffmpeg to probe input files to ensure broken / incomplete files aren't added. Will increase load." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="ffprobe_input" id="ffprobe_input" type="checkbox" <?php if (!isset($rFolder) || $rFolder['ffprobe_input']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="auto_subtitles">Auto-Add Subtitles <i title="Automatically embed subtitles of the same name in the same folder." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="auto_subtitles" id="auto_subtitles" type="checkbox" <?php if (isset($rFolder) && $rFolder['auto_subtitles']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="auto_upgrade">Auto-Upgrade Quality <i title="Automatically upgrade quality if the system finds a new file with better quality that has the same TMDb ID." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="auto_upgrade" id="auto_upgrade" type="checkbox" <?php if (isset($rFolder) && $rFolder['auto_upgrade']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="extract_metadata">Extract Metadata <i title="Use ffprobe to extract metadata information of the file and use that instead of the filename for matching against TMDb." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="extract_metadata" id="extract_metadata" type="checkbox" <?php if (isset($rFolder) && $rFolder['extract_metadata']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="duplicate_tmdb">Allow TMDb Duplicates <i title="Disable checks for duplicates using the TMDb ID. Turn this on if you want to add duplicates based on different file locations. Auto-upgrade won't work if you enable this." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="duplicate_tmdb" id="duplicate_tmdb" type="checkbox" <?php if (isset($rFolder) && $rFolder['duplicate_tmdb']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="remove_subtitles">Remove Existing Subtitles <i title="Remove existing subtitles from file before encoding. You can't remove hardcoded subtitles using this method." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="remove_subtitles" id="remove_subtitles" type="checkbox" <?php if (isset($rFolder) && $rFolder['remove_subtitles']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="target_container"><?= $language::get('target_container'); ?> <i title="Which container to use when transcoding files." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<select name="target_container" id="target_container" class="form-control" data-toggle="select2">
															<?php foreach (array('auto', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer) : ?>
																<option <?php if (isset($rFolder) && $rFolder['target_container'] == $rContainer) echo 'selected '; ?>value="<?= $rContainer; ?>"><?= $rContainer; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="transcode_profile_id">Transcoding Profile <i title="Select a transcoding profile to autoamtically encode videos." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">
															<option <?php if (isset($rFolder) && intval($rFolder['transcode_profile_id']) == 0) echo 'selected '; ?>value="0">Transcoding Disabled</option>
															<?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile) : ?>
																<option <?php if (isset($rFolder) && intval($rFolder['transcode_profile_id']) == intval($rProfile['profile_id'])) echo 'selected '; ?>value="<?= $rProfile['profile_id']; ?>"><?= $rProfile['profile_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="prevb list-inline-item">
												<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
											</li>
											<li class="nextb list-inline-item float-right">
												<a href="javascript: void(0);" class="btn btn-secondary">Next</a>
											</li>
										</ul>
									</div>
									<!-- Tab 3: Overrides -->
									<div class="tab-pane" id="override">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4" id="category_movie" <?php if (isset($rFolder) && $rFolder['type'] != 'movie') echo ' style="display: none;"'; ?>>
													<label class="col-md-4 col-form-label" for="category_id_movie">Override Category <i title="Ignore category allocation and force category allocation." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="category_id_movie" id="category_id_movie" class="form-control select2" data-toggle="select2">
															<option <?php if (isset($rFolder) && intval($rFolder['category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
															<?php foreach (getCategories('movie') as $rCategory) : ?>
																<option <?php if (isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4" id="category_series" <?php if (!isset($rFolder) || $rFolder['type'] != 'series') echo ' style="display: none;"'; ?>>
													<label class="col-md-4 col-form-label" for="category_id_series">Override Category <i title="Ignore category allocation and force category allocation." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="category_id_series" id="category_id_series" class="form-control select2" data-toggle="select2">
															<option <?php if (isset($rFolder) && intval($rFolder['category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
															<?php foreach (getCategories('series') as $rCategory) : ?>
																<option <?php if (isset($rFolder) && intval($rFolder['category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="bouquets">Override Bouquets <i title="Ignore category allocation and force bouquet allocation." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) : ?>
																<option <?php if (isset($rFolder) && in_array(intval($rBouquet['id']), (array) json_decode($rFolder['bouquets'], true))) echo 'selected '; ?>value="<?= intval($rBouquet['id']); ?>"><?= $rBouquet['bouquet_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4" id="fb_category_movie" <?php if (isset($rFolder) && $rFolder['type'] != 'movie') echo ' style="display: none;"'; ?>>
													<label class="col-md-4 col-form-label" for="fb_category_id_movie">Fallback Category <i title="Add to this category if the Genre isn't found in the category allocation list." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="fb_category_id_movie" id="fb_category_id_movie" class="form-control select2" data-toggle="select2">
															<option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
															<?php foreach (getCategories('movie') as $rCategory) : ?>
																<option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4" id="fb_category_series" <?php if (!isset($rFolder) || $rFolder['type'] != 'series') echo ' style="display: none;"'; ?>>
													<label class="col-md-4 col-form-label" for="fb_category_id_series">Fallback Category <i title="Add to this category if the Genre isn't found in the category allocation list." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="fb_category_id_series" id="fb_category_id_series" class="form-control select2" data-toggle="select2">
															<option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == 0) echo 'selected '; ?>value="0">Do Not Use</option>
															<?php foreach (getCategories('series') as $rCategory) : ?>
																<option <?php if (isset($rFolder) && intval($rFolder['fb_category_id']) == intval($rCategory['id'])) echo 'selected '; ?>value="<?= intval($rCategory['id']); ?>"><?= $rCategory['category_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="fb_bouquets">Fallback Bouquets <i title="Add to these bouquets if the Genre isn't found in the category allocation list." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="fb_bouquets[]" id="fb_bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach ((is_array($rBouquets ?? null) ? $rBouquets : []) as $rBouquet) : ?>
																<option <?php if (isset($rFolder) && in_array(intval($rBouquet['id']), (array) json_decode($rFolder['fb_bouquets'], true))) echo 'selected '; ?>value="<?= intval($rBouquet['id']); ?>"><?= $rBouquet['bouquet_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="fallback_title">Fallback to Folder Name <i title="If the title of the file isn't matched with TMDb, try to match the folder name instead." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="fallback_title" id="fallback_title" type="checkbox" <?php if (isset($rFolder) && $rFolder['fallback_title']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="allowed_extensions">Allowed Extensions <i title="Allow scanning of the following extensions only. An empty list will allow all extensions." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="allowed_extensions[]" id="allowed_extensions" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rExtension) : ?>
																<option <?php if (isset($rFolder) && in_array($rExtension, (array) json_decode($rFolder['allowed_extensions'], true))) echo 'selected '; ?>value="<?= $rExtension; ?>"><?= $rExtension; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="language">Force TMDB Language</label>
													<div class="col-md-8">
														<select name="language" id="language" class="form-control" data-toggle="select2">
															<option value="">Do Not Force</option>
															<?php foreach (is_array($rTMDBLanguages ?? null) ? array_slice($rTMDBLanguages, 1, count($rTMDBLanguages) - 1) : [] as $rKey => $rLanguage) : ?>
																<option<?php if (isset($rFolder) && $rFolder['language'] == $rKey) echo ' selected'; ?> value="<?= $rKey; ?>"><?= $rLanguage; ?></option>
																<?php endforeach; ?>
														</select>
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="prevb list-inline-item">
												<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
											</li>
											<li class="list-inline-item float-right">
												<input name="submit_folder" type="submit" class="btn btn-primary" value="<?= isset($rFolder) ? 'Edit' : 'Add'; ?>" />
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