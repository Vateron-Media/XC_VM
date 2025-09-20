<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
	goHome();
} else {
	$rSeriesArr = getSerie(CoreUtilities::$rRequest['id']);
	if (!isset($rSeriesArr)) {
		goHome();
	}
}

if (isset($rSeriesArr) && isset(CoreUtilities::$rRequest['import'])) {
	unset(CoreUtilities::$rRequest['import']);
}

$rServerTree = [
	['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
	['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]]
];

foreach ($rServers as $rServer) {
	$rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'TV Series';
include 'header.php';
?>

<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">
						<?php if (isset($rSeriesArr['id'])) {
							echo $rSeriesArr['title'];
						} elseif (isset(CoreUtilities::$rRequest['import'])) {
							echo 'Import Series';
						} else {
							echo 'Add Series';
						} ?>
					</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form<?php if (isset(CoreUtilities::$rRequest['import'])) echo ' enctype="multipart/form-data"'; ?> action="#" method="POST" data-parsley-validate="">
							<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
								<?php if (isset($rSeriesArr)): ?>
									<input type="hidden" name="edit" value="<?php echo $rSeriesArr['id']; ?>" />
								<?php endif; ?>

								<input type="hidden" id="tmdb_id" name="tmdb_id"
									value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['tmdb_id']) : ''; ?>" />
							<?php else: ?>
								<input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
							<?php endif; ?>
							<input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="" />
							<input type="hidden" name="category_create_list" id="category_create_list" value="" />
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-account-card-details-outline mr-1"></i>
											<span class="d-none d-sm-inline">Details</span>
										</a>
									</li>
									<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
										<li class="nav-item">
											<a href="#movie-information" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
												<i class="mdi mdi-movie-outline mr-1"></i>
												<span class="d-none d-sm-inline">Information</span>
											</a>
										</li>
									<?php else: ?>
										<li class="nav-item">
											<a href="#advanced-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
												<i class="mdi mdi-folder-alert-outline mr-1"></i>
												<span class="d-none d-sm-inline">
													<?php echo $_['advanced']; ?>
												</span>
											</a>
										</li>
										<li class="nav-item">
											<a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
												<i class="mdi mdi-server-network mr-1"></i>
												<span class="d-none d-sm-inline">
													<?php echo $_['server']; ?>
												</span>
											</a>
										</li>
									<?php endif; ?>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">
									<div class="tab-pane" id="stream-details">
										<div class="row">
											<div class="col-12">
												<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="title">Series Name</label>
														<div class="col-md-5">
															<input type="text" class="form-control" id="title" name="title" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['title']) : ''; ?>" required data-parsley-trigger="change">
														</div>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" placeholder="Year" id="year" name="year" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['year']) : ''; ?>">
														</div>
													</div>
													<?php if (strlen(CoreUtilities::$rSettings['tmdb_api_key']) > 0): ?>
														<div class="form-group row mb-4">
															<label class="col-md-4 col-form-label" for="tmdb_search">TMDb Results</label>
															<div class="col-md-5">
																<select id="tmdb_search" class="form-control" data-toggle="select2"></select>
															</div>
															<div class="col-md-3">
																<select name="tmdb_language" id="tmdb_language" class="form-control" data-toggle="select2">
																	<?php
																	$selectedLang = !empty($rSeriesArr['tmdb_language'])
																		? $rSeriesArr['tmdb_language']
																		: $rSettings['tmdb_language'];
																	?>

																	<?php foreach ($rTMDBLanguages as $langCode => $langName): ?>
																		<option value="<?php echo htmlspecialchars($langCode); ?>"
																			<?php echo ($langCode == $selectedLang) ? 'selected' : ''; ?>>
																			<?php echo htmlspecialchars($langName); ?>
																		</option>
																	<?php endforeach; ?>
																</select>
															</div>
														</div>
													<?php endif;
												else: ?>
													<p class="sub-header">
														Importing Series using this method will parse your M3U or folder and push the individual episodes through Watch Folder. If you have category and bouquet allocation set up in Watch Folder Settings then they will be used here too.
													</p>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="import_type"><?php echo $_['type']; ?></label>
														<div class="col-md-8">
															<div class="custom-control custom-radio mt-1">
																<span>
																	<input type="radio" id="import_type_1" name="import_type" class="custom-control-input" checked>
																	<label class="custom-control-label" for="import_type_1"><?php echo $_['m3u']; ?></label>
																</span>
																<span style="padding-left:50px;">
																	<input type="radio" id="import_type_2" name="import_type" class="custom-control-input">
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
																<input type="text" id="import_folder" name="import_folder" class="form-control" value="<?php echo $A54349e51a0595df; ?>">
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
													<label class="col-md-4 col-form-label" for="category_id"><?php echo (isset(CoreUtilities::$rRequest['import']) ? 'Fallback ' : ''); ?>Categories</label>
													<div class="col-md-8">
														<select name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach (getCategories('series') as $category): ?>
																<?php
																$selected = '';
																if (isset($rSeriesArr)) {
																	$seriesCategories = json_decode($rSeriesArr['category_id'], true);
																	if (in_array((int) $category['id'], $seriesCategories)) {
																		$selected = 'selected';
																	}
																}
																?>
																<option value="<?php echo htmlspecialchars($category['id']); ?>" <?php echo $selected; ?>>
																	<?php echo htmlspecialchars($category['category_name']); ?>
																</option>
															<?php endforeach; ?>
														</select>
														<div id="category_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
															<strong>New Categories:</strong> <span id="category_new"></span>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-md-4 col-form-label" for="bouquets"><?php echo (isset(CoreUtilities::$rRequest['import']) ? 'Fallback ' : ''); ?>Bouquets</label>
													<div class="col-md-8">
														<select name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
															<?php foreach (getBouquets() as $bouquet): ?>
																<?php
																$selected = '';
																if (isset($rSeriesArr)) {
																	$seriesBouquets = json_decode($bouquet['bouquet_series'], true);
																	if (in_array($rSeriesArr['id'], $seriesBouquets)) {
																		$selected = 'selected';
																	}
																}
																?>
																<option value="<?php echo htmlspecialchars($bouquet['id']); ?>" <?php echo $selected; ?>>
																	<?php echo htmlspecialchars($bouquet['bouquet_name']); ?>
																</option>
															<?php endforeach; ?>
														</select>
														<div id="bouquet_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
															<strong>New Bouquets:</strong> <span id="bouquet_new"></span>
														</div>
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
									<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
										<div class="tab-pane" id="movie-information">
											<div class="row">
												<div class="col-12">
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="cover">Poster URL</label>
														<div class="col-md-8 input-group">
															<input type="text" class="form-control" id="cover" name="cover" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['cover']) : ''; ?>">
															<div class="input-group-append">
																<a href="javascript:void(0)" onClick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
															</div>
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="backdrop_path">Backdrop URL</label>
														<div class="col-md-8 input-group">
															<input type="text" class="form-control" id="backdrop_path" name="backdrop_path" value="<?php echo isset($rSeriesArr) ? htmlspecialchars(json_decode($rSeriesArr['backdrop_path'], true)[0] ?? '') : ''; ?>">
															<div class="input-group-append">
																<a href="javascript:void(0)" onClick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
															</div>
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="plot">Plot</label>
														<div class="col-md-8">
															<textarea rows="6" class="form-control" id="plot" name="plot"><?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['plot']) : ''; ?></textarea>
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="cast">Cast</label>
														<div class="col-md-8">
															<input type="text" class="form-control" id="cast" name="cast" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['cast']) : ''; ?>">
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="director">Director</label>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" id="director" name="director" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['director']) : ''; ?>">
														</div>
														<label class="col-md-2 col-form-label" for="genre">Genres</label>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" id="genre" name="genre" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['genre']) : ''; ?>">
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="release_date">Release Date</label>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" id="release_date" name="release_date" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['release_date']) : ''; ?>">
														</div>
														<label class="col-md-2 col-form-label" for="episode_run_time">Runtime</label>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['episode_run_time']) : ''; ?>">
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="youtube_trailer">Youtube Trailer</label>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" id="youtube_trailer" name="youtube_trailer" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['youtube_trailer']) : ''; ?>">
														</div>
														<label class="col-md-2 col-form-label" for="rating">Rating</label>
														<div class="col-md-3">
															<input type="text" class="form-control text-center" id="rating" name="rating" value="<?php echo isset($rSeriesArr) ? htmlspecialchars($rSeriesArr['rating']) : ''; ?>">
														</div>
													</div>
												</div>
											</div>
											<ul class="list-inline wizard mb-0">
												<li class="prevb list-inline-item">
													<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
												</li>
												<li class="list-inline-item float-right">
													<input name="submit_series" type="submit" class="btn btn-primary" value="<?php echo isset($rSeriesArr) ? 'Edit' : 'Add'; ?>" />
												</li>
											</ul>
										</div>
									<?php else: ?>
										<div class="tab-pane" id="advanced-details">
											<div class="row">
												<div class="col-12">
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="direct_source"><?php echo $_['direct_source']; ?>
															<i title="<?php echo $_['episode_tooltip_1']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2">
															<input name="direct_source" id="direct_source" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
														<label class="col-md-4 col-form-label" for="read_native"><?php echo $_['native_frames']; ?></label>
														<div class="col-md-2">
															<input name="read_native" id="read_native" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="movie_symlink"><?php echo $_['create_symlink']; ?>
															<i title="<?php echo $_['episode_tooltip_2']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2">
															<input name="movie_symlink" id="movie_symlink" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
														<label class="col-md-4 col-form-label" for="remove_subtitles"><?php echo $_['remove_existing_subtitles']; ?> <i title="<?php echo $_['episode_tooltip_3']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2">
															<input name="remove_subtitles" id="remove_subtitles" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="transcode_profile_id"><?php echo $_['transcoding_profile']; ?><i title="<?php echo $_['episode_tooltip_7']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-8">
															<select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">
																<option value="0"><?php echo $_['transcoding_disabled']; ?></option>
																<?php foreach ($rTranscodeProfiles as $profile): ?>
																	<option value="<?php echo htmlspecialchars($profile['profile_id']); ?>">
																		<?php echo htmlspecialchars($profile['profile_name']); ?>
																	</option>
																<?php endforeach; ?>
															</select>
														</div>
													</div>
													<div class="form-group row mb-4">
														<label class="col-md-4 col-form-label" for="target_container"><?php echo $_['target_container']; ?><i title="<?php echo $_['episode_tooltip_4']; ?>" class="tooltip text-secondary far fa-circle"></i></label>
														<div class="col-md-2">
															<select name="target_container" id="target_container" class="form-control" data-toggle="select2">
																<?php foreach (['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'] as $format): ?>
																	<option value="<?php echo htmlspecialchars($format); ?>">
																		<?php echo htmlspecialchars($format); ?>
																	</option>
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
														<label class="col-md-4 col-form-label" for="restart_on_edit">Process Episodes</label>
														<div class="col-md-2">
															<input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
														</div>
													</div>
												</div>
											</div>
											<ul class="list-inline wizard mb-0">
												<li class="prevb list-inline-item">
													<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
												</li>
												<li class="list-inline-item float-right">
													<input name="submit_series" type="submit" class="btn btn-primary" value="Import" />
												</li>
											</ul>
										</div>
									<?php endif; ?>
								</div>
							</div>
							</form>
							<div id="file-browser" class="mfp-hide white-popup-block">
								<div class="col-12">
									<div class="form-group row mb-4">
										<label class="col-md-4 col-form-label" for="server_id"><?php echo $_['server_name']; ?></label>
										<div class="col-md-8">
											<select id="server_id" class="form-control" data-toggle="select2">
												<?php foreach (getStreamingServers() as $server): ?>
													<option value="<?php echo htmlspecialchars($server['id']); ?>">
														<?php echo htmlspecialchars($server['server_name']); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
									</div>
									<div class="form-group row mb-4">
										<label class="col-md-4 col-form-label" for="current_path"><?php echo $_['current_path']; ?></label>
										<div class="col-md-8 input-group">
											<input type="text" id="current_path" name="current_path" class="form-control" value="/">
											<div class="input-group-append">
												<button class="btn btn-primary waves-effect waves-light" type="button" id="changeDir"><i class="mdi mdi-chevron-right"></i></button>
											</div>
										</div>
									</div>
									<div class="form-group row mb-4">
										<div class="col-md-6">
											<table id="datatable" class="table">
												<thead>
													<tr>
														<th width="20px"></th>
														<th><?php echo $_['directory']; ?></th>
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
														<th><?php echo $_['filename']; ?></th>
													</tr>
												</thead>
												<tbody></tbody>
											</table>
										</div>
									</div>
									<div class="float-right">
										<input id="select_folder" type="button" class="btn btn-info" value="Select" />
									</div>
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

	<?php endif; ?>

	var changeTitle = false;

	function selectDirectory(elem) {
		window.currentDirectory += elem + "/";
		$("#current_path").val(window.currentDirectory);
		$("#changeDir").click();
	}

	function selectParent() {
		$("#current_path").val(window.currentDirectory.split("/").slice(0, -2).join("/") + "/");
		$("#changeDir").click();
	}

	function clearSearch() {
		$("#search").val("");
		$("#doSearch").click();
	}

	function selectFile(rFile) {
		if ($('li.nav-item .active').attr('href') == "#stream-details") {
			$("#stream_source").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);
			var rExtension = rFile.substr((rFile.lastIndexOf('.') + 1));
			if ($("#target_container option[value='" + rExtension + "']").length > 0) {
				$("#target_container").val(rExtension).trigger('change');
			}
		} else {
			$("#movie_subtitles").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);
		}
		$.magnificPopup.close();
	}

	function openImage(elem) {
		rPath = $(elem).parent().parent().find("input").val();
		if (rPath) {
			$.magnificPopup.open({
				items: {
					src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rPath),
					type: 'image'
				}
			});
		}
	}
	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$("#category_id").select2({
			width: '100%',
			tags: true
		}).on("change", function(e) {
			rData = $('#category_id').select2('data');
			rAdded = [];
			for (i = 0; i < rData.length; i++) {
				if (!rData[i].selected) {
					rAdded.push(rData[i].text);
				}
			}
			if (rAdded.length > 0) {
				$("#category_create").show();
				$("#category_new").html(rAdded.join(', '));
			} else {
				$("#category_create").hide();
			}
			$("#category_create_list").val(JSON.stringify(rAdded));
		});
		$("#bouquets").select2({
			width: '100%',
			tags: true
		}).on("change", function(e) {
			rData = $('#bouquets').select2('data');
			rAdded = [];
			for (i = 0; i < rData.length; i++) {
				if (!rData[i].selected) {
					rAdded.push(rData[i].text);
				}
			}
			if (rAdded.length > 0) {
				$("#bouquet_create").show();
				$("#bouquet_new").html(rAdded.join(', '));
			} else {
				$("#bouquet_create").hide();
			}
			$("#bouquet_create_list").val(JSON.stringify(rAdded));
		});
		$("#datatable").DataTable({
			responsive: false,
			paging: false,
			bInfo: false,
			searching: false,
			scrollY: "250px",
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}, ],
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			"language": {
				"emptyTable": ""
			}
		});
		$("#datatable-files").DataTable({
			responsive: false,
			paging: false,
			bInfo: false,
			searching: true,
			scrollY: "250px",
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}, ],
			"language": {
				"emptyTable": "<?php echo $_['no_compatible_file']; ?>"
			}
		});
		$("#select_folder").click(function() {
			$("#import_folder").val("s:" + $("#server_id").val() + ":" + window.currentDirectory);
			$.magnificPopup.close();
		});
		$("#changeDir").click(function() {
			$("#search").val("");
			window.currentDirectory = $("#current_path").val();
			if (window.currentDirectory.substr(-1) != "/") {
				window.currentDirectory += "/";
			}
			$("#current_path").val(window.currentDirectory);
			$("#datatable").DataTable().clear();
			$("#datatable").DataTable().row.add(["", "<?php echo $_['loading']; ?>..."]);
			$("#datatable").DataTable().draw(true);
			$("#datatable-files").DataTable().clear();
			$("#datatable-files").DataTable().row.add(["", "<?php echo $_['please_wait']; ?>..."]);
			$("#datatable-files").DataTable().draw(true);
			if ($('li.nav-item .active').attr('href') == "#stream-details") {
				rFilter = "video";
			} else {
				rFilter = "subs";
			}
			$.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=" + rFilter, function(data) {
				$("#datatable").DataTable().clear();
				$("#datatable-files").DataTable().clear();
				if (window.currentDirectory != "/") {
					$("#datatable").DataTable().row.add(["<i class='mdi mdi-subdirectory-arrow-left'></i>", "<?php echo $_['parent_directory']; ?>"]);
				}
				if (data.result == true) {
					$(data.data.dirs).each(function(id, dir) {
						$("#datatable").DataTable().row.add(["<i class='mdi mdi-folder-open-outline'></i>", dir]);
					});
					$("#datatable").DataTable().draw(true);
					$(data.data.files).each(function(id, dir) {
						$("#datatable-files").DataTable().row.add(["<i class='mdi mdi-file-video'></i>", dir]);
					});
					$("#datatable-files").DataTable().draw(true);
				}
			});
		});
		$('#datatable').on('click', 'tbody > tr', function() {
			if ($(this).find("td").eq(1).html() == "<?php echo $_['parent_directory']; ?>") {
				selectParent();
			} else if ($(this).find("td").eq(1).html() != "<?php echo $_['loading']; ?>...") {
				selectDirectory($(this).find("td").eq(1).html());
			}
		});
		$('#datatable-files').on('click', 'tbody > tr', function() {
			selectFile($(this).find("td").eq(1).html());
		});
		$('#server_tree').on('select_node.jstree', function(e, data) {
			if (data.node.parent == "offline") {
				$('#server_tree').jstree("move_node", data.node.id, "#source", "last");
			} else {
				$('#server_tree').jstree("move_node", data.node.id, "#offline", "first");
			}
		}).jstree({
			'core': {
				'check_callback': function(op, node, parent, position, more) {
					switch (op) {
						case 'move_node':
							if ((node.id == "offline") || (node.id == "source")) {
								return false;
							}
							if (parent.id != "offline" && parent.id != "source") {
								return false;
							}
							if (parent.id == "#") {
								return false;
							}
							return true;
					}
				},
				'data': <?php echo json_encode(($rServerTree ?: array())); ?>
			},
			"plugins": ["dnd"]
		});
		$("#filebrowser").magnificPopup({
			type: 'inline',
			preloader: false,
			focus: '#server_id',
			callbacks: {
				beforeOpen: function() {
					if ($(window).width() < 830) {
						this.st.focus = false;
					} else {
						this.st.focus = '#server_id';
					}
				}
			}
		});
		$("#filebrowser").on("mfpOpen", function() {
			clearSearch();
			$($.fn.dataTable.tables(true)).css('width', '100%');
			$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();
		});
		$("#server_id").change(function() {
			$("#current_path").val("/");
			$("#changeDir").click();
		});
		$("#direct_source").change(function() {
			evaluateDirectSource();
		});
		$("#movie_symlink").change(function() {
			evaluateSymlink();
		});

		function evaluateDirectSource() {
			$(["movie_symlink", "read_native", "transcode_profile_id", "target_container", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {
				if ($(rElement)) {
					if ($("#direct_source").is(":checked")) {
						if (window.rSwitches[rElement]) {
							setSwitch(window.rSwitches[rElement], false);
							window.rSwitches[rElement].disable();
						} else {
							$("#" + rElement).prop("disabled", true);
						}
					} else {
						if (window.rSwitches[rElement]) {
							window.rSwitches[rElement].enable();
						} else {
							$("#" + rElement).prop("disabled", false);
						}
					}
				}
			});
		}

		function evaluateSymlink() {
			if ($("#direct_source").is(":checked")) {
				return;
			}
			$(["direct_source", "read_native", "transcode_profile_id", "target_container", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {
				if ($(rElement)) {
					if ($("#movie_symlink").is(":checked")) {
						if (window.rSwitches[rElement]) {
							setSwitch(window.rSwitches[rElement], false);
							window.rSwitches[rElement].disable();
						} else {
							$("#" + rElement).prop("disabled", true);
						}
					} else {
						if (window.rSwitches[rElement]) {
							window.rSwitches[rElement].enable();
						} else {
							$("#" + rElement).prop("disabled", false);
						}
					}
				}
			});
		}
		$("#import_type_1").click(function() {
			$("#import_m3uf_toggle").show();
			$("#import_folder_toggle").hide();
		});
		$("#import_type_2").click(function() {
			$("#import_m3uf_toggle").hide();
			$("#import_folder_toggle").show();
		});
		$("#title").change(function() {
			if (!window.changeTitle) {
				$("#tmdb_search").empty().trigger('change');
				if ($("#title").val()) {
					$.getJSON("./api?action=tmdb_search&type=series&term=" + encodeURIComponent($(" #title").val()) + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {
						if (data.result == true) {
							if (data.data.length > 0) {
								newOption = new Option("Found " + data.data.length + " results", -1, true, true);
							} else {
								newOption = new Option("No results found", -1, true, true);
							}
							$("#tmdb_search").append(newOption).trigger('change');
							$(data.data).each(function(id, item) {
								if (item.first_air_date) {
									<?php
									switch ($rSettings['movie_year_append']) {
										case 0:
											echo 'rTitle = item.name + " (" + item.first_air_date.substring(0, 4) + ")";' . "\r\n";
											break;
										case 1:
											echo 'rTitle = item.name + " - " + item.first_air_date.substring(0, 4);' . "\r\n";
											break;
										default:
											echo 'rTitle = item.name;' . "\r\n";
											break;
									}
									?>
								} else {
									rTitle = item.name;
								}
								newOption = new Option(rTitle, item.id, true, true);
								$("#tmdb_search").append(newOption);
							});
						} else {
							newOption = new Option("No results found", -1, true, true);
						}
						$("#tmdb_search").val(-1).trigger('change');
					});
				}
			} else {
				window.changeTitle = false;
			}
		});
		$("#tmdb_search").change(function() {
			if (($("#tmdb_search").val()) && ($("#tmdb_search").val() > -1)) {
				$.getJSON("./api?action=tmdb&type=series&id=" + encodeURIComponent($("#tmdb_search").val()) + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {
					if (data.result == true) {
						window.changeTitle = true;
						$("#title").val(data.data.name);
						if (data.data.first_air_date) {
							$("#year").val(data.data.first_air_date.substr(0, 4));
						} else {
							$("#year").val("");
						}
						$("#cover").val("");
						if (data.data.poster_path) {
							$("#cover").val("https://image.tmdb.org/t/p/w600_and_h900_bestv2" + data.data.poster_path);
						}
						$("#backdrop_path").val("");
						if (data.data.backdrop_path) {
							$("#backdrop_path").val("https://image.tmdb.org/t/p/w1280" + data.data.backdrop_path);
						}
						$("#release_date").val(data.data.first_air_date);
						$("#episode_run_time").val(data.data.episode_run_time[0]);
						$("#youtube_trailer").val("");
						if (data.data.trailer) {
							$("#youtube_trailer").val(data.data.trailer);
						}
						rCast = "";
						rMemberID = 0;
						$(data.data.credits.cast).each(function(id, member) {
							rMemberID += 1;
							if (rMemberID <= 5) {
								if (rCast) {
									rCast += ", ";
								}
								rCast += member.name;
							}
						});
						$("#cast").val(rCast);
						rGenres = "";
						rGenreID = 0;
						$(data.data.genres).each(function(id, genre) {
							rGenreID += 1;
							if (rGenreID <= 3) {
								if (rGenres) {
									rGenres += ", ";
								}
								rGenres += genre.name;
							}
						});
						$("#genre").val(rGenres);
						rDirectors = "";
						rDirectorID = 0;
						$(data.data.credits.crew).each(function(id, member) {
							if ((member.department == "Directing") || (member.known_for_department == "Directing")) {
								rDirectorID += 1;
								if (rDirectorID <= 3) {
									if (rDirectors) {
										rDirectors += ", ";
									}
									rDirectors += member.name;
								}
							}
						});
						$("#director").val(rDirectors);
						$("#plot").val(data.data.overview);
						$("#rating").val(data.data.vote_average);
						$("#tmdb_id").val($("#tmdb_search").val());
					}
				});
			}
		});
		$("#episode_run_time").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#year").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});

		$("#changeDir").click();
		evaluateDirectSource();
		evaluateSymlink();
		<?php if (isset($rSeriesArr)): ?>
			$("#title").trigger("change");
		<?php endif; ?>
		$("form").submit(function(e) {
			e.preventDefault();
			<?php if (!isset(CoreUtilities::$rRequest['import'])): ?>
				if ($("#title").val().length === 0) {
					$.toast("Enter a series name.");
				} else {
					$(':input[type="submit" ]').prop('disabled', true);
					submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);
				}
			<?php else: ?>
				if (($("#m3u_file").val().length === 0) && ($("#import_folder").val().length === 0)) {
					$.toast("<?php echo addslashes($_['select_m3u_file']); ?>");
				} else {
					$("#server_tree_data").val(
						JSON.stringify($('#server_tree').jstree(true).get_json('source', {
							flat: true
						}))
					);
					$(':input[type="submit" ]').prop('disabled', true);
					submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);
				}
			<?php endif; ?>
		});
	});
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>