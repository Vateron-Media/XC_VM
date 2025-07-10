<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rCategories = getCategories('movie');
$rTranscodeProfiles = getTranscodeProfiles();

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
	$rMovie = getStream(CoreUtilities::$rRequest['id']);

	if ($rMovie && $rMovie['type'] == 2) {
	} else {
		goHome();
	}
}

$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));
$activeStreamingServers = array();

if (isset($rMovie)) {
	$rMovie['properties'] = json_decode($rMovie['movie_properties'], true);
	$rStreamSys = getStreamSys(CoreUtilities::$rRequest['id']);

	foreach ($rServers as $rServer) {
		if (!($rServer['direct_source'] == 0 && $rServer['stream_status'] == 1)) {
		} else {
			$activeStreamingServers[] = intval($rServer['id']);
		}

		if (isset($rStreamSys[intval($rServer['id'])])) {
			$rParent = 'source';
		} else {
			$rParent = 'offline';
		}

		$rServerTree[] = array('id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
	}
} else {
	foreach ($rServers as $rServer) {
		$rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
	}
}

$_TITLE = 'Movie';
include 'header.php';
?>

<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'): ?> style="display: none;" <?php endif; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">
						<?php
						if (isset($rMovie['id'])):
							echo $rMovie['stream_display_name'];
						else:
							if (isset(CoreUtilities::$rRequest['import'])):
								echo $_['import_movies'];
							else:
								echo $_['add_movie'];
							endif;
						endif;
						?>
					</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_FAILURE): ?>
					<div class="alert alert-danger alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						<?php echo $_['movies_info_2']; ?>
					</div>
				<?php elseif (isset($_STATUS) && $_STATUS == STATUS_EXISTS_NAME): ?>
					<div class="alert alert-danger alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						<?php echo $_['movies_info_3']; ?>
					</div>
				<?php elseif (isset($_STATUS) && $_STATUS == STATUS_NO_SOURCES): ?>
					<div class="alert alert-danger alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						<?php echo $_['movies_info_4']; ?>
					</div>
				<?php endif; ?>
				<?php if (isset($rMovie['id'])): ?>
					<?php
					$rEncodeErrors = getEncodeErrors($rMovie['id']);
					foreach ($rEncodeErrors as $rServerID => $rEncodeError):
						if (in_array(intval($rServerID), $activeStreamingServers)):
					?>
							<div class="alert alert-warning alert-dismissible fade show" role="alert">
								<button type="button" class="close" data-dismiss="alert" aria-label="Close">
									<span aria-hidden="true">&times;</span>
								</button>
								<strong><?php echo $_['error_on_server']; ?> - <?php echo $rServers[$rServerID]['server_name']; ?></strong><br />
								<?php echo str_replace("\n", '<br/>', $rEncodeError); ?>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if (isset($rMovie['id'])): ?>
					<?php $rEncodeErrors = getEncodeErrors($rMovie['id']); ?>
					<?php foreach ($rEncodeErrors as $rServerID => $rEncodeError): ?>
						<?php if (in_array(intval($rServerID), $activeStreamingServers)): ?>
							<div class="alert alert-warning alert-dismissible fade show" role="alert">
								<button type="button" class="close" data-dismiss="alert" aria-label="Close">
									<span aria-hidden="true">&times;</span>
								</button>
								<strong><?php echo $_['error_on_server']; ?> - <?php echo $rServers[$rServerID]['server_name']; ?></strong><br />
								<?php echo str_replace("\n", '<br/>', $rEncodeError); ?>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>

				<div class="card">
					<div class="card-body">
						<form<?php if (isset(CoreUtilities::$rRequest['import'])): ?> enctype="multipart/form-data" <?php endif; ?> action="#" method="POST" data-parsley-validate="">
							<?php if (isset($rMovie['id'])): ?>
								<input type="hidden" name="edit" value="<?php echo $rMovie['id']; ?>" />
							<?php endif; ?>
							<input type="hidden" id="tmdb_id" name="tmdb_id" value="<?php if (isset($rMovie)): ?><?php echo htmlspecialchars(($rMovie['tmdb_id'] ?: $rMovie['properties']['tmdb_id'])); ?><?php endif; ?>" />
							<input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
							<input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="" />
							<input type="hidden" name="category_create_list" id="category_create_list" value="" />
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
										</a>
									</li>
									<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
										<li class="nav-item">
											<a href="#movie-information" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
												<i class="mdi mdi-movie-outline mr-1"></i>
												<span class="d-none d-sm-inline"><?php echo $_['information']; ?></span>
											</a>
										</li>
									<?php endif; ?>
									<li class="nav-item">
										<a href="#advanced-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-folder-alert-outline mr-1"></i>
											<span class="d-none d-sm-inline"><?php echo $_['advanced']; ?></span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-server-network mr-1"></i>
											<span class="d-none d-sm-inline"><?php echo $_['server']; ?></span>
										</a>
									</li>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="stream-details">
										<div class="row">
											<div class="col-12">
												<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="stream_display_name"><?php echo $_['movie_name']; ?></label>
														<div class="col-md-6">
															<input type="text" class="form-control" id="stream_display_name" name="stream_display_name" value="<?php if (isset($rMovie)) {
																																									echo htmlspecialchars($rMovie['stream_display_name']);
																																								} elseif (isset(CoreUtilities::$rRequest['title'])) {
																																									echo str_replace('"', '&quot;', CoreUtilities::$rRequest['title']);
																																								} ?>" required data-parsley-trigger="change">
														</div>
														<div class="col-md-2">
															<input type="text" placeholder="Year" class="form-control text-center" id="year" name="year" value="<?php if (isset($rMovie)) {
																																									echo htmlspecialchars($rMovie['year']);
																																								} ?>">
														</div>
													</div>
													<?php if (0 < strlen(CoreUtilities::$rSettings['tmdb_api_key'])): ?>
														<div class="form-group row mb-4">
															<label class="col-md-4 col-form-label" for="tmdb_search"><?php echo $_['tmdb_results']; ?></label>
															<div class="col-md-5">
																<select id="tmdb_search" class="form-control" data-toggle="select2"></select>
															</div>
															<div class="col-md-3">
																<select name="tmdb_language" id="tmdb_language" class="form-control" data-toggle="select2">
																	<?php
																	$rLanguageSet = (!empty($rMovie['tmdb_language']) ? $rMovie['tmdb_language'] : $rSettings['tmdb_language']);
																	foreach ($rTMDBLanguages as $rKey => $rLanguage) {
																		echo '<option value="' . $rKey . '"' . ($rKey == $rLanguageSet ? ' selected' : '') . '>' . $rLanguage . '</option>';
																	}
																	?>
																</select>
															</div>
														</div>
													<?php endif; ?>
													<div class="form-group row mb-4 stream-url">
														<label class="col-md-4 col-form-label" for="stream_source"><?php echo $_['movie_path_or_url']; ?></label>
														<div class="col-md-8 input-group">
															<input type="text" id="stream_source" name="stream_source" class="form-control" value="<?php echo isset($rMovie) ? $rMovieSource : (isset(CoreUtilities::$rRequest['path']) ? htmlspecialchars(CoreUtilities::$rRequest['path']) : ''); ?>" required data-parsley-trigger="change">
															<div class="input-group-append">
																<a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>
																<?php if (!$rMobile): ?>
																	<a href="javascript:void(0);" id="provider-streams" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-magnify"></i></a>
																<?php endif; ?>
															</div>
														</div>
													</div>
												<?php else: ?>
													<p class="sub-header">
														Importing Movies using this method will parse your M3U or folder and push the individual episodes through Watch Folder. If you have category and bouquet allocation set up in Watch Folder Settings then they will be used here too.
													</p>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="import_type"><?php echo $_['type']; ?></label>
														<div class="col-md-8">
															<div class="custom-control custom-radio mt-1">
																<span>
																	<input type="radio" id="import_type_1" name="customRadio" class="custom-control-input" checked>
																	<label class="custom-control-label" for="import_type_1"><?php echo $_['m3u']; ?></label>
																</span>
																<span style="padding-left:50px;">
																	<input type="radio" id="import_type_2" name="customRadio" class="custom-control-input">
																	<label class="custom-control-label" for="import_type_2"><?php echo $_['folder']; ?></label>
																</span>
															</div>
														</div>
													</div>
													<div id="import_m3uf_toggle">
														<div class="form-group row mb-4">
															<label class="col-md-4 col-form-label" for="m3u_file"><?php echo $_['m3u_file']; ?></label>
															<div class="col-md-8">
																<input type="file" id="m3u_file" name="m3u_file" />
															</div>
														</div>
													</div>
													<div id="import_folder_toggle" style="display:none;">
														<div class="form-group row mb-4">
															<label class="col-md-4 col-form-label" for="import_folder"><?php echo $_['folder']; ?></label>
															<div class="col-md-8 input-group">
																<input type="text" id="import_folder" name="import_folder" class="form-control" value="<?php echo $rMovieSource; ?>">
																<div class="input-group-append">
																	<a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>
																</div>
															</div>
														</div>
														<div class="form-group row mb-4">
															<label class="col-md-4 col-form-label" for="scan_recursive"><?php echo $_['scan_recursively']; ?></label>
															<div class="col-md-2">
																<input name="scan_recursive" id="scan_recursive" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
															</div>
														</div>
													</div>
												<?php endif; ?>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="category_id"><?php if (CoreUtilities::$rRequest['import']) {
																													echo 'Fallback ';
																												} ?>Categories</label>
													<div class="col-md-8">
														<select name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach (getCategories('movie') as $rCategory): ?>
																<option <?php if (isset($rMovie) && in_array(intval($rCategory['id']), json_decode($rMovie['category_id'], true))) {
																			echo 'selected ';
																		} ?>value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
															<?php endforeach; ?>
														</select>
														<div id="category_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
															<strong>New Categories:</strong> <span id="category_new"></span>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="bouquets"><?php if (CoreUtilities::$rRequest['import']) {
																												echo 'Fallback ';
																											} ?><?php echo $_['bouquets']; ?></label>
													<div class="col-md-8">
														<select name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?php echo $_['choose']; ?>...">
															<select name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?php echo $_['choose']; ?>...">
																<?php foreach (getBouquets() as $rBouquet): ?>
																	<option <?php if (isset($rMovie) && in_array($rMovie['id'], json_decode($rBouquet['bouquet_movies'], true))): ?>selected<?php endif; ?> value="<?php echo $rBouquet['id']; ?>"><?php echo $rBouquet['bouquet_name']; ?></option>
																<?php endforeach; ?>
															</select>
															<div id="bouquet_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
																<strong>New Bouquets:</strong> <span id="bouquet_new"></span>
															</div>
													</div>
												</div>
												<?php if (isset(CoreUtilities::$rRequest['import'])): ?>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="disable_tmdb">Disable TMDb <i title="Do not use TMDb to match the content." class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2">
															<input name="disable_tmdb" id="disable_tmdb" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
														<label class="col-md-4 col-form-label" for="ignore_no_match">Ignore No Match <i title="Add to database even if no TMDb match is found." class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2">
															<input name="ignore_no_match" id="ignore_no_match" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
													</div>
												<?php endif; ?>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="nextb list-inline-item float-right">
												<a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['next']; ?></a>
											</li>
										</ul>
									</div>
									<div class="tab-pane" id="movie-information">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="movie_image"><?php echo $_['poster_url']; ?></label>
													<div class="col-md-8 input-group">
														<input type="text" class="form-control" id="movie_image" name="movie_image" value="<?php if (isset($rMovie)) {
																																				echo htmlspecialchars($rMovie['properties']['movie_image']);
																																			} ?>">
														<div class="input-group-append">
															<a href="javascript:void(0)" onClick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="backdrop_path"><?php echo $_['backdrop_url']; ?></label>
													<div class="col-md-8 input-group">
														<input type="text" class="form-control" id="backdrop_path" name="backdrop_path" value="<?php if (isset($rMovie)) {
																																					echo htmlspecialchars($rMovie['properties']['backdrop_path'][0]);
																																				} ?>">
														<div class="input-group-append">
															<a href="javascript:void(0)" onClick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="plot"><?php echo $_['plot']; ?></label>
													<div class="col-md-8">
														<textarea rows="6" class="form-control" id="plot" name="plot"><?php if (isset($rMovie)) {
																															echo htmlspecialchars($rMovie['properties']['plot']);
																														} ?></textarea>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="cast"><?php echo $_['cast']; ?></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="cast" name="cast" value="<?php if (isset($rMovie)) {
																																	echo htmlspecialchars($rMovie['properties']['cast']);
																																} ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="director"><?php echo $_['director']; ?></label>
													<div class="col-md-3">
														<input type="text" class="form-control" id="director" name="director" value="<?php if (isset($rMovie)) {
																																			echo htmlspecialchars($rMovie['properties']['director']);
																																		} ?>">
													</div>
													<label class="col-md-2 col-form-label" for="genre"><?php echo $_['genres']; ?></label>
													<div class="col-md-3">
														<input type="text" class="form-control" id="genre" name="genre" value="<?php if (isset($rMovie)) {
																																	echo htmlspecialchars($rMovie['properties']['genre']);
																																} ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="release_date"><?php echo $_['release_date']; ?></label>
													<div class="col-md-3">
														<input type="text" class="form-control text-center" id="release_date" name="release_date" value="<?php if (isset($rMovie)) {
																																								echo htmlspecialchars($rMovie['properties']['release_date']);
																																							} ?>">
													</div>
													<label class="col-md-2 col-form-label" for="episode_run_time"><?php echo $_['runtime']; ?></label>
													<div class="col-md-3">
														<input type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="<?php if (isset($rMovie)) {
																																										echo htmlspecialchars($rMovie['properties']['episode_run_time']);
																																									} ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="youtube_trailer"><?php echo $_['youtube_trailer']; ?></label>
													<div class="col-md-3 input-group">
														<input type="text" class="form-control text-center" id="youtube_trailer" name="youtube_trailer" value="<?php if (isset($rMovie)) {
																																									echo htmlspecialchars($rMovie['properties']['youtube_trailer']);
																																								} ?>">
														<div class="input-group-append">
															<a href="javascript:void(0)" onClick="openYouTube(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
														</div>
													</div>
													<label class="col-md-2 col-form-label" for="rating"><?php echo $_['rating']; ?></label>
													<div class="col-md-3">
														<input type="text" class="form-control text-center" id="rating" name="rating" value="<?php if (isset($rMovie)) {
																																					echo htmlspecialchars($rMovie['properties']['rating']);
																																				} ?>">
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="country"><?php echo $_['country']; ?></label>
													<div class="col-md-8">
														<input type="text" class="form-control" id="country" name="country" value="<?php if (isset($rMovie)) {
																																		echo htmlspecialchars($rMovie['properties']['country']);
																																	} ?>">
													</div>
													<div class="col-md-2">
														<input name="direct_source" id="direct_source" type="checkbox" <?php if (isset($rMovie) && $rMovie['direct_source'] == 1) {
																															echo 'checked';
																														} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="direct_proxy">Direct Stream <i title="When using direct source, hide the original URL by proxying the movie through your servers. This will consume bandwidth but won't require the movie to be saved to your servers permanently. Make sure to set the correct target container." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="direct_proxy" id="direct_proxy" type="checkbox" <?php if (isset($rMovie) && $rMovie['direct_proxy'] == 1) {
																															echo 'checked';
																														} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="read_native"><?php echo $_['native_frames']; ?></label>
													<div class="col-md-2">
														<input name="read_native" id="read_native" type="checkbox" <?php if (isset($rMovie) && $rMovie['read_native'] == 1) {
																														echo 'checked';
																													} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="movie_symlink"><?php echo $_['create_symlink']; ?> <i title="<?php echo $_['episode_tooltip_2']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="movie_symlink" id="movie_symlink" type="checkbox" <?php if (isset($rMovie) && $rMovie['movie_symlink'] == 1) {
																															echo 'checked';
																														} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="remove_subtitles"><?php echo $_['remove_existing_subtitles']; ?> <i title="<?php echo $_['episode_tooltip_3']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="remove_subtitles" id="remove_subtitles" type="checkbox" <?php if (isset($rMovie) && $rMovie['remove_subtitles'] == 1) {
																																	echo 'checked';
																																} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="movie_symlink"><?php echo $_['create_symlink']; ?> <i title="<?php echo $_['episode_tooltip_2']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="movie_symlink" id="movie_symlink" type="checkbox" <?php if (isset($rMovie) && $rMovie['movie_symlink'] == 1) {
																															echo 'checked';
																														} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="remove_subtitles"><?php echo $_['remove_existing_subtitles']; ?> <i title="<?php echo $_['episode_tooltip_3']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="remove_subtitles" id="remove_subtitles" type="checkbox" <?php if (isset($rMovie) && $rMovie['remove_subtitles'] == 1) {
																																	echo 'checked';
																																} ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>

												<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
													<?php $rSubFile = ''; ?>
													<?php if (isset($rMovie)): ?>
														<?php $rSubData = json_decode($rMovie['movie_subtitles'], true); ?>
														<?php if (isset($rSubData['location'])): ?>
															<?php $rSubFile = 's:' . $rSubData['location'] . ':' . $rSubData['files'][0]; ?>
														<?php endif; ?>
													<?php endif; ?>
													<div class="form-group row mb-4 stream-url">
														<label class="col-md-4 col-form-label" for="movie_subtitles"><?php echo $_['subtitle_location']; ?> <i title="<?php echo $_['episode_tooltip_6']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-8 input-group">
															<input type="text" id="movie_subtitles" name="movie_subtitles" class="form-control" value="<?php echo htmlspecialchars($rSubFile); ?>">
															<div class="input-group-append">
																<a href="#file-browser" id="filebrowser-sub" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>
															</div>
														</div>
													</div>
												<?php endif; ?>

												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="transcode_profile_id"><?php echo $_['transcoding_profile']; ?> <i title="<?php echo $_['episode_tooltip_7']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-8">
														<select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">
															<option <?php if (!isset($rMovie) || (isset($rMovie) && intval($rMovie['transcode_profile_id']) == 0)) {
																		echo 'selected';
																	} ?> value="0"><?php echo $_['transcoding_disabled']; ?></option>
															<?php foreach ($rTranscodeProfiles as $rProfile): ?>
																<option <?php if (isset($rMovie) && intval($rMovie['transcode_profile_id']) == intval($rProfile['profile_id'])) {
																			echo 'selected';
																		} ?> value="<?php echo $rProfile['profile_id']; ?>"><?php echo $rProfile['profile_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="target_container"><?php echo $_['target_container']; ?> <i title="<?php echo $_['episode_tooltip_4']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<select name="target_container" id="target_container" class="form-control" data-toggle="select2">
															<?php foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer): ?>
																<option <?php if (isset($rMovie) && $rMovie['target_container'] == $rContainer) {
																			echo 'selected';
																		} ?> value="<?php echo $rContainer; ?>"><?php echo $rContainer; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
											</div>
											<ul class="list-inline wizard mb-0">
												<li class="prevb list-inline-item">
													<a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
												</li>
												<li class="nextb list-inline-item float-right">
													<a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['next']; ?></a>
												</li>
											</ul>
										</div>
									</div>

									<div class="tab-pane" id="advanced-details">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="direct_source"><?php echo $_['direct_source']; ?>
														<i title="<?php echo $_['episode_tooltip_1']; ?>" class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-2">
														<input name="direct_source" id="direct_source" type="checkbox" <?php if (isset($rMovie) && $rMovie['direct_source'] == 1) {
																															echo 'checked ';
																														} ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="direct_proxy">Direct Stream <i title="When using direct source, hide the original URL by proxying the movie through your servers. This will consume bandwidth but won't require the movie to be saved to your servers permanently. Make sure to set the correct target container." class="tooltip text-secondary far fa-circle"></i></label>
													<div class="col-md-2">
														<input name="direct_proxy" id="direct_proxy" type="checkbox" <?php if (isset($rMovie) && $rMovie['direct_proxy'] == 1) {
																															echo 'checked ';
																														} ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="read_native"><?php echo $_['native_frames']; ?></label>
													<div class="col-md-2">
														<input name="read_native" id="read_native" type="checkbox" <?php if (isset($rMovie) && $rMovie['read_native'] == 1) {
																														echo 'checked ';
																													} ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
													<label class="col-md-4 col-form-label" for="movie_symlink"><?php echo $_['create_symlink']; ?>
														<i title="<?php echo $_['episode_tooltip_2']; ?>" class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-2">
														<input name="movie_symlink" id="movie_symlink" type="checkbox" <?php if (isset($rMovie) && $rMovie['movie_symlink'] == 1) {
																															echo 'checked ';
																														} ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="remove_subtitles"><?php echo $_['remove_existing_subtitles']; ?>
														<i title="<?php echo $_['episode_tooltip_3']; ?>" class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-2">
														<input name="remove_subtitles" id="remove_subtitles" type="checkbox" <?php if (isset($rMovie) && $rMovie['remove_subtitles'] == 1) {
																																	echo 'checked ';
																																} ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
												<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
													<?php
													$rSubFile = '';
													if (isset($rMovie)) {
														$rSubData = json_decode($rMovie['movie_subtitles'], true);
														if (isset($rSubData['location'])) {
															$rSubFile = 's:' . $rSubData['location'] . ':' . $rSubData['files'][0];
														}
													}
													?>
													<div class="form-group row mb-4 stream-url">
														<label class="col-md-4 col-form-label" for="movie_subtitles"><?php echo $_['subtitle_location']; ?>
															<i title="<?php echo $_['episode_tooltip_6']; ?>" class="tooltip text-secondary far fa-circle"></i>
														</label>
														<div class="col-md-8 input-group">
															<input type="text" id="movie_subtitles" name="movie_subtitles" class="form-control" value="<?php echo htmlspecialchars($rSubFile); ?>">
															<div class="input-group-append">
																<a href="#file-browser" id="filebrowser-sub" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>
															</div>
														</div>
													</div>
												<?php endif; ?>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="transcode_profile_id"><?php echo $_['transcoding_profile']; ?>
														<i title="<?php echo $_['episode_tooltip_7']; ?>" class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-8">
														<select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">
															<option <?php if (isset($rMovie) && intval($rMovie['transcode_profile_id']) == 0) {
																		echo 'selected ';
																	} ?>value="0"><?php echo $_['transcoding_disabled']; ?></option>
															<?php foreach ($rTranscodeProfiles as $rProfile): ?>
																<option <?php if (isset($rMovie) && intval($rMovie['transcode_profile_id']) == intval($rProfile['profile_id'])) {
																			echo 'selected ';
																		} ?>value="<?php echo $rProfile['profile_id']; ?>"><?php echo $rProfile['profile_name']; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="target_container"><?php echo $_['target_container']; ?>
														<i title="<?php echo $_['episode_tooltip_4']; ?>" class="tooltip text-secondary far fa-circle"></i>
													</label>
													<div class="col-md-2">
														<select name="target_container" id="target_container" class="form-control" data-toggle="select2">
															<?php foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer): ?>
																<option <?php if (isset($rMovie) && $rMovie['target_container'] == $rContainer) {
																			echo 'selected ';
																		} ?>value="<?php echo $rContainer; ?>"><?php echo $rContainer; ?></option>
															<?php endforeach; ?>
														</select>
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="prevb list-inline-item">
												<a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
											</li>
											<li class="nextb list-inline-item float-right">
												<a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['next']; ?></a>
											</li>
										</ul>
									</div>

									<div class="tab-pane" id="load-balancing">
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="servers"><?php echo $_['server_tree']; ?></label>
													<div class="col-md-8">
														<div id="server_tree"></div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="restart_on_edit"><?php echo isset($rMovie) ? $_['reprocess_on_edit'] : $_['process_movie']; ?></label>
													<div class="col-md-2">
														<input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
													</div>
												</div>
											</div>
										</div>
										<ul class="list-inline wizard mb-0">
											<li class="prevb list-inline-item">
												<a href="javascript: void(0);" class="btn btn-secondary"><?php echo $_['prev']; ?></a>
											</li>
											<li class="list-inline-item float-right">
												<input name="submit_movie" type="submit" class="btn btn-primary" value="<?php echo isset($rMovie) ? 'Edit' : 'Add'; ?>" />
											</li>
										</ul>
									</div>
								</div>
								</form>
								<div id="file-browser" class="mfp-hide white-popup-block">
									<div class="col-12">
										<div class="form-group row mb-4">
											<label class="col-md-4 col-form-label" for="server_id"><?php echo htmlspecialchars($_['server_name']); ?></label>
											<div class="col-md-8">
												<select id="server_id" class="form-control" data-toggle="select2">
													<?php foreach (getStreamingServers() as $rServer): ?>
														<option value="<?php echo htmlspecialchars($rServer['id']); ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) echo ' selected'; ?>>
															<?php echo htmlspecialchars($rServer['server_name']); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</div>
										</div>
										<div class="form-group row mb-4">
											<label class="col-md-4 col-form-label" for="current_path"><?php echo htmlspecialchars($_['current_path']); ?></label>
											<div class="col-md-8 input-group">
												<input type="text" id="current_path" name="current_path" class="form-control" value="/">
												<div class="input-group-append">
													<button class="btn btn-primary waves-effect waves-light" type="button" id="changeDir"><i class="mdi mdi-chevron-right"></i></button>
												</div>
											</div>
										</div>
										<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
											<div class="form-group row mb-4">
												<label class="col-md-4 col-form-label" for="search"><?php echo htmlspecialchars($_['search_directory']); ?></label>
												<div class="col-md-8 input-group">
													<input type="text" id="search" name="search" class="form-control" placeholder="<?php echo htmlspecialchars($_['filter_files']); ?>...">
													<div class="input-group-append">
														<button class="btn btn-warning waves-effect waves-light" type="button" onClick="clearSearch()"><i class="mdi mdi-close"></i></button>
														<button class="btn btn-primary waves-effect waves-light" type="button" id="doSearch"><i class="mdi mdi-magnify"></i></button>
													</div>
												</div>
											</div>
										<?php endif; ?>
										<div class="form-group row mb-4">
											<div class="col-md-6">
												<table id="datatable" class="table">
													<thead>
														<tr>
															<th width="20px"></th>
															<th><?php echo htmlspecialchars($_['directory']); ?></th>
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
															<th><?php echo htmlspecialchars($_['filename']); ?></th>
														</tr>
													</thead>
													<tbody></tbody>
												</table>
											</div>
										</div>
										<?php if (isset(CoreUtilities::$rRequest['import'])): ?>
											<div class="float-right">
												<input id="select_folder" type="button" class="btn btn-info" value="Select" />
											</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php include 'footer.php'; ?>