<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
	exit; // Ensure script execution stops if permissions are not met
}

$rCategories = getCategories('movie');
$rAudioCodecs = $rVideoCodecs = array();

$db->query('SELECT DISTINCT(`audio_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `audio_codec` IS NOT NULL AND `type` = 2 ORDER BY `audio_codec` ASC;');
foreach ($db->get_rows() as $rRow) {
	$rAudioCodecs[] = $rRow['audio_codec'];
}

$db->query('SELECT DISTINCT(`video_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `video_codec` IS NOT NULL AND `type` = 2 ORDER BY `video_codec` ASC;');
foreach ($db->get_rows() as $rRow) {
	$rVideoCodecs[] = $rRow['video_codec'];
}

$_TITLE = 'Movies';
include 'header.php';
?>

<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo 'style="display: none;"'; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title"><?php echo $_['movies']; ?></h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						Movie has been added / modified.
					</div>
				<?php elseif (isset($_STATUS) && $_STATUS == STATUS_SUCCESS_MULTI): ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						Movies are being imported in the background...
					</div>
				<?php endif; ?>

				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<div id="collapse_filters" class="form-group row mb-4 <?php if ($rMobile) echo 'collapse'; ?>">
							<div class="col-md-2">
								<input type="text" class="form-control" id="movies_search" value="<?php echo isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : ''; ?>" placeholder="<?php echo $_['search_movies']; ?>...">
							</div>
							<div class="col-md-2">
								<select id="movies_server" class="form-control" data-toggle="select2">
									<option value="" <?php if (!isset(CoreUtilities::$rRequest['server'])) echo ' selected'; ?>><?php echo $_['all_servers']; ?></option>
									<option value="-1" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == -1) echo ' selected'; ?>>No Servers</option>
									<?php foreach (getStreamingServers() as $rServer): ?>
										<option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) echo ' selected'; ?>>
											<?php echo $rServer['server_name']; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-2">
								<select id="movies_category_id" class="form-control" data-toggle="select2">
									<option value="" <?php if (!isset(CoreUtilities::$rRequest['category'])) echo ' selected'; ?>><?php echo $_['all_categories']; ?></option>
									<option value="-1" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1) echo ' selected'; ?>>No Categories</option>
									<?php foreach ($rCategories as $rCategory): ?>
										<option value="<?php echo $rCategory['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) echo ' selected'; ?>>
											<?php echo $rCategory['category_name']; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-2">
								<select id="movies_filter" class="form-control" data-toggle="select2">
									<option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) echo ' selected'; ?>><?php echo $_['no_filter']; ?></option>
									<option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) echo ' selected'; ?>><?php echo $_['encoded']; ?></option>
									<option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) echo ' selected'; ?>><?php echo $_['encoding']; ?></option>
									<option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) echo ' selected'; ?>><?php echo $_['down']; ?></option>
									<option value="4" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) echo ' selected'; ?>><?php echo $_['ready']; ?></option>
									<option value="5" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) echo ' selected'; ?>><?php echo $_['direct']; ?></option>
									<option value="6" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6) echo ' selected'; ?>><?php echo $_['no_tmdb_match']; ?></option>
									<option value="7" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 7) echo ' selected'; ?>>Duplicate</option>
									<option value="8" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 8) echo ' selected'; ?>>Transcoding</option>
								</select>
							</div>
							<div class="col-md-1">
								<select id="movies_audio" class="form-control" data-toggle="select2">
									<option value="" <?php if (!isset(CoreUtilities::$rRequest['audio'])) echo ' selected'; ?>>Audio</option>
									<option value="-1" <?php if (isset(CoreUtilities::$rRequest['audio']) && CoreUtilities::$rRequest['audio'] == '-1') echo ' selected'; ?>>None</option>
									<?php foreach ($rAudioCodecs as $rCodec): ?>
										<option value="<?php echo $rCodec; ?>" <?php if (isset(CoreUtilities::$rRequest['audio']) && CoreUtilities::$rRequest['audio'] == $rCodec) echo ' selected'; ?>>
											<?php echo $rCodec; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-1">
								<select id="movies_video" class="form-control" data-toggle="select2">
									<option value="" <?php if (!isset(CoreUtilities::$rRequest['video'])) echo ' selected'; ?>>Video</option>
									<option value="-1" <?php if (isset(CoreUtilities::$rRequest['video']) && CoreUtilities::$rRequest['video'] == '-1') echo ' selected'; ?>>None</option>
									<?php foreach ($rVideoCodecs as $rCodec): ?>
										<option value="<?php echo $rCodec; ?>" <?php if (isset(CoreUtilities::$rRequest['video']) && CoreUtilities::$rRequest['video'] == $rCodec) echo ' selected'; ?>>
											<?php echo $rCodec; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-1">
								<select id="movies_resolution" class="form-control" data-toggle="select2">
									<option value="" <?php if (!isset(CoreUtilities::$rRequest['resolution'])) echo ' selected'; ?>>Quality</option>
									<?php foreach (array(240, 360, 480, 576, 720, 1080, 1440, 2160) as $rResolution): ?>
										<option value="<?php echo $rResolution; ?>" <?php if (isset(CoreUtilities::$rRequest['resolution']) && CoreUtilities::$rRequest['resolution'] == $rResolution) echo ' selected'; ?>>
											<?php echo $rResolution; ?>p
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-1">
								<select id="movies_show_entries" class="form-control" data-toggle="select2">
									<?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
										<option value="<?php echo $rShow; ?>" <?php if ((isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || (!isset(CoreUtilities::$rRequest['entries']) && $rSettings['default_entries'] == $rShow)) echo ' selected'; ?>>
											<?php echo $rShow; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">
							<thead>
								<tr>
									<th class="text-center"><?php echo $_['id']; ?></th>
									<th class="text-center">Image</th>
									<th><?php echo $_['name']; ?></th>
									<?php if ($rSettings['streams_grouped'] == 1): ?>
										<th><?php echo $_['servers']; ?></th>
									<?php else: ?>
										<th><?php echo $_['server']; ?></th>
									<?php endif; ?>
									<th class="text-center"><?php echo $_['clients']; ?></th>
									<th class="text-center"><?php echo $_['status']; ?></th>
									<th class="text-center">TMDb</th>
									<th class="text-center"><?php echo $_['actions']; ?></th>
									<th class="text-center"><?php echo $_['player']; ?></th>
									<th class="text-center"><?php echo $_['stream_info']; ?></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include 'footer.php'; ?>