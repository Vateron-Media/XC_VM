<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$rCategories = getCategories('movie');
$rTranscodeProfiles = getTranscodeProfiles();

if (isset(CoreUtilities::$rRequest['id'])) {
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

	// Getting and extracting the path to a movie
	$streamSourceJson = $rMovie['stream_source'] ?? '';
	$rMovieSource = json_decode($streamSourceJson, true);
	if (!is_array($rMovieSource)) {
		$rMovieSource = [''];
	}
	$rSource = $rMovieSource[0] ?? '';
	if (str_starts_with($rSource, 's:')) {
		$parts = explode(':', $rSource, 3);
		$rPathSources = (count($parts) >= 3) ? urldecode($parts[2]) : '';
	} else {
		$rPathSources = $rSource;
	}

	foreach ($rServers as $rServer) {
		if ($rServer['direct_source'] == 0 && $rServer['stream_status'] == 1) {
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
															<input type="text" id="stream_source" name="stream_source" class="form-control" value="<?php echo isset($rMovie) ? $rPathSources : (isset(CoreUtilities::$rRequest['path']) ? htmlspecialchars(CoreUtilities::$rRequest['path']) : ''); ?>" required data-parsley-trigger="change">
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
																<input type="text" id="import_folder" name="import_folder" class="form-control" value="<?php echo $rPathSources; ?>">
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
														<select name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?php echo $_['choose']; ?>...">
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
													<label class="col-md-4 col-form-label" 
														for="bouquets"><?php if (CoreUtilities::$rRequest['import']) {echo 'Fallback ';	} ?><?php echo $_['bouquets']; ?></label>
													<div class="col-md-8">
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

			<?php endif; 
		echo '        ' . "\r\n\t\t" . 'var changeTitle = false;' . "\r\n\r\n\t\t" . 'function selectDirectory(elem) {' . "\r\n\t\t\t" . 'window.currentDirectory += elem + "/";' . "\r\n\t\t\t" . '$("#current_path").val(window.currentDirectory);' . "\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectParent() {' . "\r\n\t\t\t" . '$("#current_path").val(window.currentDirectory.split("/").slice(0,-2).join("/") + "/");' . "\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectFile(rFile) {' . "\r\n\t\t\t" . "if (\$('li.nav-item .active').attr('href') == \"#stream-details\") {" . "\r\n\t\t\t\t" . '$("#stream_source").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);' . "\r\n\t\t\t\t" . "var rExtension = rFile.substr((rFile.lastIndexOf('.')+1));" . "\r\n\t\t\t\t" . "if (\$(\"#target_container option[value='\" + rExtension + \"']\").length > 0) {" . "\r\n\t\t\t\t\t" . "\$(\"#target_container\").val(rExtension).trigger('change');" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#movie_subtitles").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$.magnificPopup.close();' . "\r\n\t\t" . '}' . "\r\n" . '        function openYouTube(elem) {' . "\r\n" . '            rPath = $(elem).parent().parent().find("input").val();' . "\r\n" . '            if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'http://www.youtube.com/watch?v=' + rPath," . "\r\n" . "                        type: 'iframe'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . 'function openImage(elem) {' . "\r\n\t\t\t" . 'rPath = $(elem).parent().parent().find("input").val();' . "\r\n\t\t\t" . 'if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rPath)," . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearSearch() {' . "\r\n\t\t\t" . '$("#search").val("");' . "\r\n\t\t\t" . '$("#doSearch").click();' . "\r\n\t\t" . '}' . "\r\n" . '        function addStream(rName, rURL) {' . "\r\n" . '            $("#stream_source").val(rURL);' . "\r\n" . '            $("#stream_display_name").val(rName).trigger("change");' . "\r\n" . '            $(".bs-provider-movies-modal-center").modal("hide");' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            $("#category_id").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#category_id').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#category_create").show();' . "\r\n" . "                    \$(\"#category_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#category_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#category_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n" . '            $("#bouquets").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#bouquets').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#bouquet_create").show();' . "\r\n" . "                    \$(\"#bouquet_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#bouquet_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#bouquet_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'scrollY: "250px",' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]},' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . '"language": {' . "\r\n\t\t\t\t\t" . '"emptyTable": ""' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-files").DataTable({' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'scrollY: "250px",' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]},' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"language": {' . "\r\n\t\t\t\t\t" . '"emptyTable": "';
		echo $_['no_compatible_file'];
		echo '"' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#doSearch").click(function() {' . "\r\n\t\t\t\t" . "\$('#datatable-files').DataTable().search(\$(\"#search\").val()).draw();" . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#select_folder").click(function() {' . "\r\n\t\t\t\t" . '$("#import_folder").val("s:" + $("#server_id").val() + ":" + window.currentDirectory);' . "\r\n\t\t\t\t" . '$.magnificPopup.close();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#changeDir").click(function() {' . "\r\n\t\t\t\t" . '$("#search").val("");' . "\r\n\t\t\t\t" . 'window.currentDirectory = $("#current_path").val();' . "\r\n\t\t\t\t" . 'if (window.currentDirectory.substr(-1) != "/") {' . "\r\n\t\t\t\t\t" . 'window.currentDirectory += "/";' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$("#current_path").val(window.currentDirectory);' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().clear();' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().row.add(["", "';
		echo $_['loading'];
		echo '..."]);' . "\r\n\t\t\t\t" . '$("#datatable").DataTable().draw(true);' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().clear();' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().row.add(["", "';
		echo $_['please_wait'];
		echo '..."]);' . "\r\n\t\t\t\t" . '$("#datatable-files").DataTable().draw(true);' . "\r\n\t\t\t\t" . "if (\$('li.nav-item .active').attr('href') == \"#stream-details\") {" . "\r\n\t\t\t\t\t" . 'rFilter = "video";' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'rFilter = "subs";' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '$.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=" + rFilter, function(data) {' . "\r\n\t\t\t\t\t" . '$("#datatable").DataTable().clear();' . "\r\n\t\t\t\t\t" . '$("#datatable-files").DataTable().clear();' . "\r\n\t\t\t\t\t" . 'if (window.currentDirectory != "/") {' . "\r\n\t\t\t\t\t\t" . "\$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-subdirectory-arrow-left'></i>\", \"";
		echo $_['parent_directory'];
		echo '"]);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t" . '$(data.data.dirs).each(function(id, dir) {' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-folder-open-outline'></i>\", dir]);" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable").DataTable().draw(true);' . "\r\n\t\t\t\t\t\t" . '$(data.data.files).each(function(id, dir) {' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#datatable-files\").DataTable().row.add([\"<i class='mdi mdi-file-video'></i>\", dir]);" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t" . '$("#datatable-files").DataTable().draw(true);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#datatable').on('click', 'tbody > tr', function() {" . "\r\n\t\t\t\t" . 'if ($(this).find("td").eq(1).html() == "';
		echo $_['parent_directory'];
		echo '") {' . "\r\n\t\t\t\t\t" . 'selectParent();' . "\r\n\t\t\t\t" . '} else if ($(this).find("td").eq(1).html() != "';
		echo $_['loading'];
		echo '...") {' . "\r\n\t\t\t\t\t" . 'selectDirectory($(this).find("td").eq(1).html());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#datatable-files').on('click', 'tbody > tr', function() {" . "\r\n\t\t\t\t" . 'selectFile($(this).find("td").eq(1).html());' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#server_tree').on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            if (parent.id > 0 && $("#direct_proxy").is(":checked")) { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser").magnificPopup({' . "\r\n\t\t\t\t" . "type: 'inline'," . "\r\n\t\t\t\t" . 'preloader: false,' . "\r\n\t\t\t\t" . "focus: '#server_id'," . "\r\n\t\t\t\t" . 'callbacks: {' . "\r\n\t\t\t\t\t" . 'beforeOpen: function() {' . "\r\n\t\t\t\t\t\t" . 'if ($(window).width() < 830) {' . "\r\n\t\t\t\t\t\t\t" . 'this.st.focus = false;' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . "this.st.focus = '#server_id';" . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser-sub").magnificPopup({' . "\r\n\t\t\t\t" . "type: 'inline'," . "\r\n\t\t\t\t" . 'preloader: false,' . "\r\n\t\t\t\t" . "focus: '#server_id'," . "\r\n\t\t\t\t" . 'callbacks: {' . "\r\n\t\t\t\t\t" . 'beforeOpen: function() {' . "\r\n\t\t\t\t\t\t" . 'if ($(window).width() < 830) {' . "\r\n\t\t\t\t\t\t\t" . 'this.st.focus = false;' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . "this.st.focus = '#server_id';" . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser").on("mfpOpen", function() {' . "\r\n\t\t\t\t" . 'clearSearch();' . "\r\n\t\t\t\t" . "\$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n\t\t\t\t" . '$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#filebrowser-sub").on("mfpOpen", function() {' . "\r\n\t\t\t\t" . 'clearSearch();' . "\r\n\t\t\t\t" . "\$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n\t\t\t\t" . '$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#server_id").change(function() {' . "\r\n\t\t\t\t" . '$("#current_path").val("/");' . "\r\n\t\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#direct_source").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#direct_proxy").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#movie_symlink").change(function() {' . "\r\n\t\t\t\t" . 'evaluateSymlink();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#stream_source").change(function() {' . "\r\n\t\t\t\t" . 'checkSymlink();' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#datatable-provider-movies").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "provider_streams",' . "\r\n" . '                        d.type = "movie"' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [2]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"order": [[ 0, "asc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-provider-movies").css("width", "100%");' . "\r\n" . '            $("#provider-streams").click(function() {' . "\r\n\t\t\t\t" . '$("#datatable-provider-movies").DataTable().search($("#stream_display_name").val()).draw();' . "\r\n" . '                $(".bs-provider-movies-modal-center").modal("show");' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'function evaluateDirectSource() {' . "\r\n\t\t\t\t" . '$(["movie_symlink", "read_native", "transcode_profile_id", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if ($("#direct_source").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n" . '                $(["direct_proxy"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if (!$("#direct_source").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n" . '            function checkSymlink() {' . "\r\n" . '                if (($("#movie_symlink").is(":checked")) && ($("#stream_source").val()) && (!$("#stream_source").val().startsWith("s:")) && (!$("#stream_source").val().startsWith("/"))) {' . "\r\n" . '                    $.toast("Please ensure the source is a local file before symlinking.");' . "\r\n" . '                    setSwitch(window.rSwitches["movie_symlink"], false);' . "\r\n" . '                }' . "\r\n" . '            }' . "\r\n\t\t\t" . 'function evaluateSymlink() {' . "\r\n" . '                if ($("#direct_source").is(":checked")) { return; }' . "\r\n" . '                checkSymlink();' . "\r\n\t\t\t\t" . '$(["direct_source", "direct_proxy", "read_native", "remove_subtitles", "target_container", "transcode_profile_id", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if ($("#movie_symlink").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#tmdb_language").change(function() {' . "\r\n" . '                $("#stream_display_name").trigger("change");' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#stream_display_name").change(function() {' . "\r\n\t\t\t\t" . 'if (!window.changeTitle) {' . "\r\n\t\t\t\t\t" . "\$(\"#tmdb_search\").empty().trigger('change');" . "\r\n\t\t\t\t\t" . 'if ($("#stream_display_name").val()) {' . "\r\n\t\t\t\t\t\t" . '$.getJSON("./api?action=tmdb_search&type=movie&term=" + encodeURIComponent($("#stream_display_name").val()) + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {' . "\r\n\t\t\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t\t\t" . 'if (data.data.length > 0) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'newOption = new Option("';
		echo $_['found_results'];
		echo "\".replace('{num}', data.data.length), -1, true, true);" . "\r\n\t\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'newOption = new Option("';
		echo $_['no_results_found'];
		echo '", -1, true, true);' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t" . "\$(\"#tmdb_search\").append(newOption).trigger('change');" . "\r\n\t\t\t\t\t\t\t\t" . '$(data.data).each(function(id, item) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (item.release_date) {' . "\r\n" . '                                        ';

		if ($rSettings['movie_year_append'] == 0) {
			echo "\t\t\t\t\t\t\t\t\t\t" . 'rTitle = item.title + " (" + item.release_date.substring(0, 4) + ")";' . "\r\n" . '                                        ';
		} else {
			if ($rSettings['movie_year_append'] == 1) {
				echo '                                        rTitle = item.title + " - " + item.release_date.substring(0, 4);' . "\r\n" . '                                        ';
			} else {
				echo '                                        rTitle = item.title;' . "\r\n" . '                                        ';
			}
		}

		echo "\t\t\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rTitle = item.title;' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'newOption = new Option(rTitle, item.id, true, true);' . "\r\n\t\t\t\t\t\t\t\t\t" . '$("#tmdb_search").append(newOption);' . "\r\n\t\t\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . 'newOption = new Option("';
		echo $_['no_results_found'];
		echo '", -1, true, true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . "\$(\"#tmdb_search\").val(-1).trigger('change');" . "\r\n\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'window.changeTitle = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#tmdb_search").change(function() {' . "\r\n\t\t\t\t" . 'if (($("#tmdb_search").val()) && ($("#tmdb_search").val() > -1)) {' . "\r\n\t\t\t\t\t" . '$.getJSON("./api?action=tmdb&type=movie&id=" + encodeURIComponent($("#tmdb_search").val()) + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {' . "\r\n\t\t\t\t\t\t" . 'if (data.result == true) {' . "\r\n\t\t\t\t\t\t\t" . 'window.changeTitle = true;' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.release_date) {' . "\r\n" . '                                $("#year").val(data.data.release_date.substr(0, 4));' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n" . '                                $("#year").val("");' . "\r\n" . '                            }' . "\r\n\t\t\t\t\t\t\t" . '$("#stream_display_name").val(data.data.title);' . "\r\n\t\t\t\t\t\t\t" . '$("#movie_image").val("");' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.poster_path) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#movie_image").val("https://image.tmdb.org/t/p/w600_and_h900_bestv2" + data.data.poster_path);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '$("#backdrop_path").val("");' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.backdrop_path) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#backdrop_path").val("https://image.tmdb.org/t/p/w1280" + data.data.backdrop_path);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '$("#release_date").val(data.data.release_date);' . "\r\n\t\t\t\t\t\t\t" . '$("#episode_run_time").val(data.data.runtime);' . "\r\n\t\t\t\t\t\t\t" . '$("#youtube_trailer").val("");' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.trailer) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#youtube_trailer").val(data.data.trailer);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . 'rCast = "";' . "\r\n\t\t\t\t\t\t\t" . 'rMemberID = 0;' . "\r\n\t\t\t\t\t\t\t" . '$(data.data.credits.cast).each(function(id, member) {' . "\r\n\t\t\t\t\t\t\t\t" . 'rMemberID += 1;' . "\r\n\t\t\t\t\t\t\t\t" . 'if (rMemberID <= 5) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (rCast) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rCast += ", ";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rCast += member.name;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t\t" . '$("#cast").val(rCast);' . "\r\n\t\t\t\t\t\t\t" . 'rGenres = "";' . "\r\n\t\t\t\t\t\t\t" . 'rGenreID = 0;' . "\r\n\t\t\t\t\t\t\t" . '$(data.data.genres).each(function(id, genre) {' . "\r\n\t\t\t\t\t\t\t\t" . 'rGenreID += 1;' . "\r\n\t\t\t\t\t\t\t\t" . 'if (rGenreID <= 3) {' . "\r\n\t\t\t\t\t\t\t\t\t" . 'if (rGenres) {' . "\r\n\t\t\t\t\t\t\t\t\t\t" . 'rGenres += ", ";' . "\r\n\t\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t\t\t" . 'rGenres += genre.name;' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '});' . "\r\n\t\t\t\t\t\t\t" . '$("#genre").val(rGenres);' . "\r\n" . '                            rDirectors = "";' . "\r\n" . '                            rDirectorID = 0;' . "\r\n\t\t\t\t\t\t\t" . '$(data.data.credits.crew).each(function(id, member) {' . "\r\n\t\t\t\t\t\t\t\t" . 'if ((member.department == "Directing") || (member.known_for_department == "Directing")) {' . "\r\n" . '                                    rDirectorID += 1;' . "\r\n" . '                                    if (rDirectorID <= 3) {' . "\r\n" . '                                        if (rDirectors) {' . "\r\n" . '                                            rDirectors += ", ";' . "\r\n" . '                                        }' . "\r\n" . '                                        rDirectors += member.name;' . "\r\n" . '                                    }' . "\r\n\t\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '});' . "\r\n" . '                            $("#director").val(rDirectors);' . "\r\n\t\t\t\t\t\t\t" . '$("#country").val("");' . "\r\n\t\t\t\t\t\t\t" . '$("#plot").val(data.data.overview);' . "\r\n\t\t\t\t\t\t\t" . 'if (data.data.production_countries) {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#country").val(data.data.production_countries[0].name);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t\t" . '$("#rating").val(data.data.vote_average);' . "\r\n\t\t\t\t\t\t\t" . '$("#tmdb_id").val(data.data.id);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t";

		if (!(isset($rMovie) || isset(CoreUtilities::$rRequest['title']))) {
		} else {
			echo "\t\t\t" . "\$(\"#stream_display_name\").trigger('change');" . "\r\n\t\t\t";
		}

		echo "\t\t\t" . '$("#import_type_1").click(function() {' . "\r\n\t\t\t\t" . '$("#import_m3uf_toggle").show();' . "\r\n\t\t\t\t" . '$("#import_folder_toggle").hide();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#import_type_2").click(function() {' . "\r\n\t\t\t\t" . '$("#import_m3uf_toggle").hide();' . "\r\n\t\t\t\t" . '$("#import_folder_toggle").show();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t\r\n\t\t\t" . '$("#runtime").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#year").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t\r\n\t\t\t" . '$("#changeDir").click();' . "\r\n\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . 'evaluateSymlink();' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                rSubmit = true;' . "\r\n\t\t\t\t";

		if (!isset(CoreUtilities::$rRequest['import'])) {
			echo "\t\t\t\t" . 'if ($("#stream_display_name").val().length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
			echo $_['enter_movie_name'];
			echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t" . 'if ($("#stream_source").val().length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
			echo $_['enter_movie_source'];
			echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t";
		} else {
			echo "\t\t\t\t" . 'if (($("#m3u_file").val().length == 0) && ($("#import_folder").val().length == 0)) {' . "\r\n\t\t\t\t\t" . '$.toast("';
			echo $_['select_m3u_file'];
			echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t\t";
		}

		echo "\t\t\t\t" . "\$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>