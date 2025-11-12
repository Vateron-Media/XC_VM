<?php
include 'session.php';
include 'functions.php';

if ($rThemes[$rUserInfo['theme']]['dark']) {
	$rColours = array(1 => array('secondary', '#7e8e9d', '#ffffff'), 2 => array('secondary', '#7e8e9d', '#ffffff'), 3 => array('secondary', '#7e8e9d', '#ffffff'), 4 => array('secondary', '#7e8e9d', '#ffffff'));
	$rColourMap = array(array('#7e8e9d', 'bg-map-dark-1'), array('#6c7b8a', 'bg-map-dark-2'), array('#5a6977', 'bg-map-dark-3'), array('#485765', 'bg-map-dark-4'), array('#374654', 'bg-map-dark-5'), array('#273643', 'bg-map-dark-6'));
} else {
	$rColours = array(1 => array('purple', '#675db7', '#675db7'), 2 => array('success', '#23b397', '#23b397'), 3 => array('pink', '#e36498', '#e36498'), 4 => array('info', '#56C3D6', '#56C3D6'));
	$rColourMap = array(array('#23b397', 'bg-success'), array('#56c2d6', 'bg-info'), array('#5089de', 'bg-primary'), array('#675db7', 'bg-purple'), array('#e36498', 'bg-pink'), array('#98a6ad', 'bg-secondary'));
}

if (!isset(CoreUtilities::$rRequest['server_id']) || isset($rServers[CoreUtilities::$rRequest['server_id']])) {
} else {
	goHome();
}

$rConnectionMap = array();
$rConnectionCount = 0;

if (isset(CoreUtilities::$rRequest['server_id'])) {
	$db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` WHERE (`server_id` = ? OR `proxy_id` = ?) GROUP BY `geoip_country_code` ORDER BY `count` DESC;', intval(CoreUtilities::$rRequest['server_id']), intval(CoreUtilities::$rRequest['server_id']));
} else {
	$db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` GROUP BY `geoip_country_code` ORDER BY `count` DESC;');
}

if (0 >= $db->num_rows()) {
} else {
	$i = 0;

	foreach ($db->get_rows() as $rRow) {
		if ($i < count($rColourMap)) {
			$rRow['colour'] = $rColourMap[$i];
		} else {
			$rRow['colour'] = $rColourMap[count($rColourMap) - 1];
		}

		if (isset($rCountryCodes[$rRow['geoip_country_code']])) {
			$rRow['name'] = $rCountryCodes[$rRow['geoip_country_code']];
		} else {
			$rRow['name'] = 'Unknown Country';
		}

		$rConnectionCount += $rRow['count'];
		$rConnectionMap[] = $rRow;
		$i++;
	}
}

if (isset(CoreUtilities::$rRequest['server_id'])) {
} else {
	$rLimit = 3600;
	$rTime = time();
	$rNearestRange = $rTime - $rLimit;
	$rServerStats = array();
	$db->query('SELECT * FROM `servers_stats` WHERE `time` >= ? ORDER BY `time` ASC;', $rNearestRange);

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rServerStats[intval($rRow['server_id'])][] = $rRow['cpu'];
		}
	}
}

$rOrderedServers = $rServers;
array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);

$rLicenseType = 'License';

$_TITLE = 'Dashboard';
include 'header.php';
?>

<div class="wrapper">
	<div class="container-fluid">
		<?php if (hasPermissions('adv', 'index')): ?>
			<div class="row">
				<div class="col-12">
					<div class="page-title-box">
						<div class="page-title-right" <?php if ($rMobile): ?>style="width: 100%" <?php endif; ?>>
							<ol class="breadcrumb m-0" style="width: <?php echo $rMobile ? '100%' : '250px'; ?>;">
								<select id="server_id" class="form-control">
									<option <?php if (!isset(CoreUtilities::$rRequest['server_id'])) echo 'selected'; ?> value="">All Servers</option>
									<?php foreach ($rServers as $rServerItem): ?>
										<?php if ($rServerItem['enabled']): ?>
											<option value="<?php echo $rServerItem['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server_id']) && CoreUtilities::$rRequest['server_id'] == $rServerItem['id']) echo 'selected'; ?>>
												<?php echo $rServerItem['server_name']; ?>
											</option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</ol>
						</div>
						<?php if (!$rMobile): ?>
							<h4 class="page-title">
								<?php if (isset(CoreUtilities::$rRequest['server_id'])): ?>
									<?php echo CoreUtilities::$rServers[intval(CoreUtilities::$rRequest['server_id'])]['server_name']; ?>&nbsp;
									<a href='server_view?id=<?php echo intval(CoreUtilities::$rRequest['server_id']); ?>' class='btn btn-light waves-effect waves-light btn-xs tooltip-right' title='View Server'><i class='mdi mdi-chart-line'></i></a>
									<a href='process_monitor?server=<?php echo intval(CoreUtilities::$rRequest['server_id']); ?>' class='btn btn-light waves-effect waves-light btn-xs tooltip-right' title='Process Monitor'><i class='mdi mdi-chart-bar'></i></a>
								<?php else: ?>
									Dashboard
								<?php endif; ?>
							</h4>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<div class="row mb-4">
				<div class="col-md-4">
					<?php if (hasPermissions('adv', 'live_connections')): ?><a href="./live_connections"><?php endif; ?>
						<div class="card cta-box <?php echo $rUserInfo['theme'] != 0 ? '' : 'bg-purple'; ?> text-white rounded-2">
							<div class="card-body active-connections">
								<div class="media align-items-center">
									<div class="col-3">
										<div class="avatar-sm bg-light">
											<i class="fe-zap avatar-title font-22 <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-purple'; ?>"></i>
										</div>
									</div>
									<div class="col-9">
										<div class="text-right">
											<h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
											<p class="text-white mb-1 text-truncate">Online Connections</p>
										</div>
									</div>
								</div>
							</div>
						</div>
						<?php if (hasPermissions('adv', 'live_connections')): ?>
						</a><?php endif; ?>
				</div>
				<div class="col-md-4">
					<?php if (hasPermissions('adv', 'live_connections')): ?>
						<a href="./live_connections">
						<?php endif; ?>

						<div class="card cta-box <?php echo $rUserInfo['theme'] != 0 ? '' : 'bg-success'; ?> text-white rounded-2">
							<div class="card-body online-users">
								<div class="media align-items-center">
									<div class="col-3">
										<div class="avatar-sm bg-light">
											<i class="fe-users avatar-title font-22 <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-success'; ?>"></i>
										</div>
									</div>
									<div class="col-9">
										<div class="text-right">
											<h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
											<p class="text-white mb-1 text-truncate">Active Lines</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<?php if (hasPermissions('adv', 'live_connections')): ?>
						</a>
					<?php endif; ?>
				</div>
				<div class="col-md-4">
					<?php if (hasPermissions('adv', 'streams')): ?>
						<a href="./streams?filter=1">
						<?php endif; ?>

						<div class="card cta-box <?php echo $rUserInfo['theme'] == 0 ? 'bg-info' : ''; ?> text-white rounded-2">
							<div class="card-body active-streams">
								<div class="media align-items-center">
									<div class="col-3">
										<div class="avatar-sm bg-light">
											<i class="fe-play avatar-title font-22 <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-info'; ?>"></i>
										</div>
									</div>
									<div class="col-9">
										<div class="text-right">
											<h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
											<p class="text-white mb-1 text-truncate">Live Streams</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<?php if (hasPermissions('adv', 'streams')): ?>
						</a>
					<?php endif; ?>
				</div>
				<div class="col-md-4">
					<?php if (hasPermissions('adv', 'streams')): ?>
						<a href="./streams?filter=2">
						<?php endif; ?>

						<div class="card cta-box <?php echo $rUserInfo['theme'] == 0 ? 'bg-pink' : ''; ?> text-white rounded-2">
							<div class="card-body offline-streams">
								<div class="media align-items-center">
									<div class="col-3">
										<div class="avatar-sm bg-light">
											<i class="fe-alert-triangle avatar-title font-22 <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-pink'; ?>"></i>
										</div>
									</div>
									<div class="col-9">
										<div class="text-right">
											<h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span></h3>
											<p class="text-white mb-1 text-truncate">Down Streams</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<?php if (hasPermissions('adv', 'streams')): ?>
						</a>
					<?php endif; ?>
				</div>
				<div class="col-md-4">
					<div class="card cta-box <?php echo $rUserInfo['theme'] == 0 ? 'bg-primary' : ''; ?> text-white rounded-2">
						<div class="card-body output-flow">
							<div class="media align-items-center">
								<div class="col-3">
									<div class="avatar-sm bg-light">
										<i class="fe-trending-up avatar-title font-22 <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-primary'; ?>"></i>
									</div>
								</div>
								<div class="col-9">
									<div class="text-right">
										<h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span> <small>Mbps</small></h3>
										<p class="text-white mb-1 text-truncate">Network Output</p>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="col-md-4">
					<div class="card cta-box <?php echo $rUserInfo['theme'] == 0 ? 'bg-danger' : ''; ?> text-white rounded-2">
						<div class="card-body input-flow">
							<div class="media align-items-center">
								<div class="col-3">
									<div class="avatar-sm bg-light">
										<i class="fe-trending-down avatar-title font-22 <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-danger'; ?>"></i>
									</div>
								</div>
								<div class="col-9">
									<div class="text-right">
										<h3 class="text-white my-1"><span data-plugin="counterup" class="entry">0</span> <small>Mbps</small></h3>
										<p class="text-white mb-1 text-truncate">Network Input</p>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php if ($rSettings['dashboard_status']): ?>
				<div class="col-xl-6">
					<div class="card" style="height: 390px; overflow:hidden;">
						<div class="card-body">
							<h4 class="header-title mb-4">Service Status</h4>
							<div style="max-height: 288px; overflow-y: scroll;">
								<div class="timeline-alt">
									<?php $rHasError = false; ?>

									<?php
									try {
										$rResult = $db->dbh->query("SELECT JSON_CONTAINS('0', 0, '\$') AS `json_test`;");
									} catch (Exception $e) {
										$rHasError = true;
									?>
										<div class="timeline-item">
											<i class="timeline-icon bg-danger"></i>
											<div class="timeline-item-info">
												<a href="javascript:void(0);" class="text-body font-weight-semibold mb-1 d-block bg"><strong>MariaDB Outdated!</strong></a>
												<small>You're using an old version of MariaDB. Please update to at least v10.5 in order for XC_VM to work correctly.</small><br />
												<p><br /></p>
											</div>
										</div>
									<?php
									}

									if (empty(CoreUtilities::$rSettings['status_uuid']) || CoreUtilities::$rSettings['status_uuid'] != md5(XC_VM_VERSION)) {
										$rHasError = true;
									?>
										<div class="timeline-item">
											<i class="timeline-icon bg-warning"></i>
											<div class="timeline-item-info">
												<a href="javascript:void(0);" class="text-body font-weight-semibold mb-1 d-block bg"><strong>Database Incomplete</strong></a>
												<small>Your database is outdated, please run <strong>/home/xc_vm/status</strong> as root user to update your tables.</small><br />
												<p><br /></p>
											</div>
										</div>
									<?php
									}

									if (!file_exists(CONFIG_PATH . 'signals.last') || time() - filemtime(CONFIG_PATH . 'signals.last') > 600) {
										$rHasError = true;
									?>
										<div class="timeline-item">
											<i class="timeline-icon bg-dark"></i>
											<div class="timeline-item-info">
												<a href="javascript:void(0);" class="text-body font-weight-semibold mb-1 d-block bg"><strong>Root Crons Missing</strong></a>
												<small>Root cronjob hasn't run recently, please check root crontab or run <strong>/home/xc_vm/status</strong></small><br />
												<p><br /></p>
											</div>
										</div>
									<?php
									}

									// Additional conditions and outputs similar to the above

									if (!$rHasError) {
									?>
										<div class="timeline-item">
											<i class="timeline-icon bg-dark"></i>
											<div class="timeline-item-info">
												<a href="#" class="text-body font-weight-semibold mb-1 d-block bg"><strong>No potential issues have been detected!</strong></a>
												<p><br /></p>
											</div>
										</div>
									<?php
									}
									?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<?php if (!$rMobile && $rSettings['dashboard_stats']): ?>
					<div class="col-xl-6">
						<div class="card">
							<div class="card-body">
								<h4 class="header-title mb-0">CPU & Memory</h4>
								<div id="cpu_chart-col" class="pt-3 show" dir="ltr">
									<div id="cpu_chart" class="apex-charts"></div>
								</div>
							</div>
						</div>
					</div>
					<div class="col-xl-6">
						<div class="card">
							<div class="card-body">
								<h4 class="header-title mb-0">Network Traffic</h4>
								<div id="network_chart-col" class="pt-3 show" dir="ltr">
									<div id="network_chart" class="apex-charts"></div>
								</div>
							</div>
						</div>
					</div>

					<?php if (!$rSettings['save_closed_connection']): ?>
						<!-- If saving closed connections is disabled, no output for connections chart. -->
					<?php else: ?>
						<div class="col-xl-6">
							<div class="card">
								<div class="card-body">
									<h4 class="header-title mb-0">Connections</h4>
									<div id="connections_chart-col" class="pt-3 show" dir="ltr">
										<div id="connections_chart" class="apex-charts"></div>
									</div>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<?php if ($rSettings['save_closed_connection'] && $rSettings['dashboard_map']): ?>
						<div class="col-xl-12">
							<div class="card">
								<div class="card-body">
									<h4 class="header-title mb-0">Connections by Location</h4>
									<div id="location-col" class="collapse pt-3 show">
										<div class="row">
											<div class="col-md-8 align-self-center">
												<div id="map" style="height: 450px" class="dash-map"></div>
											</div>
											<div class="col-md-4 align-self-center">
												<?php foreach (array_slice($rConnectionMap, 0, 5) as $rCountry): ?>
													<h5 class="mb-1 mt-0"><?php echo number_format($rCountry['count'], 0); ?> <small class="text-muted ml-2"><?php echo $rCountry['name']; ?></small></h5>
													<div class="progress-w-percent">
														<span class="progress-value font-weight-bold"><?php echo round($rCountry['count'] / $rConnectionCount * 100, 0); ?>% </span>
														<div class="progress progress-sm">
															<div class="progress-bar <?php echo $rCountry['colour'][1]; ?>" role="progressbar" style="width: <?php echo round($rCountry['count'] / $rConnectionCount * 100, 0); ?>%;" aria-valuenow="<?php echo round($rCountry['count'] / $rConnectionCount * 100, 0); ?>" aria-valuemin="0" aria-valuemax="100"></div>
														</div>
													</div>
												<?php endforeach; ?>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php if (!isset(CoreUtilities::$rRequest['server_id'])): ?>
					<?php $i = 0; ?>
					<?php foreach ($rOrderedServers as $rServer): ?>
						<?php if ($rServer['enabled'] && $rServer['server_online']): ?>
							<?php
							$i++;
							if ($i == 5) {
								$i = 1;
							}

							if ($rServer['server_type'] == 0) {
								if ($rServer['is_main']) {
									$rServerType = 'Main Server';
								} else {
									$rServerType = $rServer['enabled'] ? 'Load Balancer' : 'Server Disabled';
								}

								if ($rServer['enable_proxy'] && $rServer['enabled']) {
									$rServerType .= ' (proxied)';
								}
							} else {
								$rServerType = $rServer['enabled'] ? 'Proxy Server' : 'Proxy Disabled';
							}
							?>

							<?php if ($rSettings['dashboard_display_alt'] && !$rMobile): ?>
								<div class="col-xl-6 col-md-12">
									<a href="./server_view?id=<?php echo $rServer['id']; ?>">
										<div class="card-header bg-<?php echo $rColours[$i][0]; ?> py-3 text-white">
											<div class="float-right">
												<i class="mdi mdi-chart-line"></i>
											</div>
											<h5 class="card-title mb-0 text-white">
												<?php echo $rServer['server_name']; ?><br /><small><?php echo $rServerType; ?></small>
											</h5>
										</div>
									</a>
									<div class="card-header no-margin-bottom py-3 text-white<?php if ($rUserInfo['theme'] == 0) echo ' bg-white'; ?>">
										<div class="row">
											<div class="col-md-2 col-2">
												<h4 class="header-title"><?php echo $_['connections']; ?></h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<a href="live_connections?server=<?php echo $rServer['id']; ?>">
													<button id="s_<?php echo $rServer['id']; ?>_conns" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
												</a>
											</div>
											<div class="col-md-2 col-2">
												<h4 class="header-title"><?php echo $_['users']; ?></h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<a href="live_connections?server=<?php echo $rServer['id']; ?>">
													<button id="s_<?php echo $rServer['id']; ?>_users" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
												</a>
											</div>
											<div class="col-md-4 col-4">
												<div class="progress-w-left">
													<h4 class="progress-value header-title">CPU</h4>
													<div class="progress progress-xl">
														<div class="progress-bar" id="s_<?php echo $rServer['id']; ?>_cpu" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
													</div>
												</div>
											</div>
										</div>
										<div class="row">
											<div class="col-md-2 col-2">
												<h4 class="header-title">Streams&nbsp;Live</h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<a href="streams?server=<?php echo $rServer['id']; ?>&filter=1">
													<button id="s_<?php echo $rServer['id']; ?>_online" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
												</a>
											</div>
											<div class="col-md-2 col-2">
												<h4 class="header-title">Down</h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<a href="streams?server=<?php echo $rServer['id']; ?>&filter=2">
													<button id="s_<?php echo $rServer['id']; ?>_offline" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
												</a>
											</div>
											<div class="col-md-4 col-4">
												<div class="progress-w-left">
													<h4 class="progress-value header-title">MEM</h4>
													<div class="progress progress-xl">
														<div class="progress-bar" id="s_<?php echo $rServer['id']; ?>_mem" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
													</div>
												</div>
											</div>
										</div>
										<div class="row">
											<div class="col-md-2 col-2">
												<h4 class="header-title">Requests<small>&nbsp;/sec</small></small></h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<button id="s_<?php echo $rServer['id']; ?>_requests" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
											</div>
											<div class="col-md-2 col-2">
												<h4 class="header-title"><?php echo $_['uptime']; ?></h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<button id="s_<?php echo $rServer['id']; ?>_uptime" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0d 0h</button>
											</div>
											<div class="col-md-4 col-4">
												<div class="progress-w-left">
													<h4 class="progress-value header-title">IO</h4>
													<div class="progress progress-xl">
														<div class="progress-bar" id="s_<?php echo $rServer['id']; ?>_io" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
													</div>
												</div>
											</div>
										</div>
										<div class="row">
											<div class="col-md-2 col-2">
												<h4 class="header-title"><?php echo $_['input']; ?><small>&nbsp;(Mbps)</small></h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<button id="s_<?php echo $rServer['id']; ?>_input" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
											</div>
											<div class="col-md-2 col-2">
												<h4 class="header-title"><?php echo $_['output']; ?><small>&nbsp;(Mbps)</small></h4>
											</div>
											<div class="col-md-2 col-2 text-center">
												<button id="s_<?php echo $rServer['id']; ?>_output" type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min">0</button>
											</div>
											<div class="col-md-4 col-4">
												<div class="progress-w-left">
													<h4 class="progress-value header-title">DISK</h4>
													<div class="progress progress-xl">
														<div class="progress-bar" id="s_<?php echo $rServer['id']; ?>_fs" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
													</div>
												</div>
											</div>
										</div>
									</div>
									<div class="card-footer">
										<span data-plugin="peity-line" data-fill="<?php echo $rColours[$i][2]; ?>" data-stroke="<?php echo $rColours[$i][2]; ?>" data-width="100%" data-height="50" data-min="0" data-max="100"><?php echo implode(',', ($rServerStats[$rServer['id']] ?: array())); ?></span>
									</div>
								</div>
							<?php else: ?>
								<div class="col-xl-3 col-md-6">
									<a href="./server_view?id=<?php echo $rServer['id']; ?>">
										<div class="card-header bg-<?php echo $rColours[$i][0]; ?> py-3 text-white text-center">
											<h5 class="card-title mb-0 text-white">
												<?php echo $rServer['server_name']; ?><br /><small><?php echo $rServerType; ?></small>
											</h5>
										</div>
										<div class="card-header py-3 text-white<?php if ($rUserInfo['theme'] != 0) {
																				} else {
																					echo ' bg-white';
																				} ?>">
											<div class="row" style="margin-bottom:-20px;">
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['conns']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_conns">0</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['users']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_users">0</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['online']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_online">0</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['input']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_input">0 Mbps</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['output']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_output">0 Mbps</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['uptime']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_uptime">0d 0h</p>
												</div>
											</div>
										</div>
										<div class="card-box no-margin-bottom light-grey">
											<div class="row">
												<div class="col-md-4 col-4" align="center">
													<h4 class="header-title">CPU %</h4>
													<input class="knob" id="s_<?php echo $rServer['id']; ?>_cpu" data-plugin="knob" data-width="64" data-height="64" data-fgColor="<?php echo $rColours[$i][1]; ?>" data-bgColor="#e8e7f4" value="0" data-skin="tron" data-angleOffset="180" data-readOnly=true data-thickness=".15" />
												</div>
												<div class="col-md-4 col-4" align="center">
													<h4 class="header-title">MEM %</h4>
													<input class="knob" id="s_<?php echo $rServer['id']; ?>_mem" data-plugin="knob" data-width="64" data-height="64" data-fgColor="<?php echo $rColours[$i][1]; ?>" data-bgColor="#e8e7f4" value="0" data-skin="tron" data-angleOffset="180" data-readOnly=true data-thickness=".15" />
												</div>
												<div class="col-md-4 col-4" align="center">
													<h4 class="header-title">DISK %</h4>
													<input class="knob" id="s_<?php echo $rServer['id']; ?>_fs" data-plugin="knob" data-width="64" data-height="64" data-fgColor="<?php echo $rColours[$i][1]; ?>" data-bgColor="#e8e7f4" value="0" data-skin="tron" data-angleOffset="180" data-readOnly=true data-thickness=".15" />
												</div>
											</div>
										</div>
										<div class="card-footer">
											<span data-plugin="peity-line" data-fill="<?php echo $rColours[$i][2]; ?>" data-stroke="<?php echo $rColours[$i][2]; ?>" data-width="100%" data-height="50" data-min="0" data-max="100"><?php echo (is_array($rServerStats[$rServer['id']]) ? implode(',', $rServerStats[$rServer['id']]) : ''); ?></span>
										</div>
									</a>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					<?php endforeach; ?>

					<?php
					$i = 0;

					foreach ($rOrderedServers as $rServer) {
						if (!$rServer['enabled'] || $rServer['server_online']) {
						} else {
							$i++;

							if ($i != 5) {
							} else {
								$i = 1;
							}

							if ($rServer['server_type'] == 0) {
								if ($rServer['is_main']) {
									$rServerType = 'Main Server';
								} else {
									if ($rServer['enabled']) {
										$rServerType = 'Load Balancer';
									} else {
										$rServerType = 'Server Disabled';
									}
								}

								if (!($rServer['enable_proxy'] && $rServer['enabled'])) {
								} else {
									$rServerType .= ' (proxied)';
								}
							} else {
								if ($rServer['enabled']) {
									$rServerType = 'Proxy Server';
								} else {
									$rServerType = 'Proxy Disabled';
								}
							}

							if ($rSettings['dashboard_display_alt'] && !$rMobile) {
					?>
								<div class="col-xl-6 col-md-12">
									<a href="./server_view?id=<?php echo $rServer['id']; ?>">
										<div class="card-header <?php echo ($rUserInfo['theme'] == 1) ? 'bg-light' : 'bg-dark'; ?> py-3 text-white">
											<div class="float-right">
												<i class="mdi mdi-chart-line"></i>
											</div>
											<h5 class="card-title mb-0 text-white"><?php echo $rServer['server_name']; ?><br /><small><?php echo $rServerType; ?></small></h5>
										</div>
									</a>
									<div class="card-header no-margin-bottom py-3 text-white<?php if ($rUserInfo['theme'] != 0) {
																							} else {
																								echo ' bg-white';
																							} ?>">
										<div class="col-12 text-center" style="padding-top: 70px;">
											<a href="./server_view?id=<?php echo $rServer['id']; ?>">
												<i class="fe-alert-triangle avatar-title font-22 <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-danger'; ?>"></i>
												<h4 class="header-title <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-danger'; ?>">Server Offline</h4>
											</a>
										</div>
									</div>
									<div class="card-footer">
										<span data-plugin="peity-line" data-fill="<?php echo ($rUserInfo['theme'] == 1 ? '#434b56' : '#7e8e9d'); ?>" data-stroke="<?php echo ($rUserInfo['theme'] == 1 ? '#434b56' : '#7e8e9d'); ?>" data-width="100%" data-height="50" data-min="0" data-max="100"><?php echo implode(',', ($rServerStats[$rServer['id']] ?: array())); ?></span>
									</div>
								</div>
							<?php
							} else {
							?>
								<div class="col-xl-3 col-md-6">
									<a href="./server_view?id=<?php echo $rServer['id']; ?>">
										<div class="card-header <?php echo ($rUserInfo['theme'] == 1) ? 'bg-light' : 'bg-dark'; ?> py-3 text-white text-center">
											<h5 class="card-title mb-0 text-white"><?php echo $rServer['server_name']; ?><br /><small><?php echo $rServerType; ?></small></h5>
										</div>
										<div class="card-header py-3 text-white<?php if ($rUserInfo['theme'] != 0) {
																				} else {
																					echo ' bg-white';
																				} ?>">
											<div class="row" style="margin-bottom:-20px;">
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['conns']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_conns">0</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['users']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_users">0</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['online']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_online">0</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['input']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_input">0 Mbps</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['output']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_output">0 Mbps</p>
												</div>
												<div class="col-md-4 col-6" align="center">
													<h4 class="header-title"><?php echo $_['uptime']; ?></h4>
													<p class="sub-header" id="s_<?php echo $rServer['id']; ?>_uptime">0d 0h</p>
												</div>
											</div>
										</div>
										<div class="card-box no-margin-bottom light-grey">
											<div class="row">
												<?php if ($rServer['status'] == 3) { ?>
													<div class="col-12 text-center" style="padding-top: 15px;">
														<i class="mdi mdi-creation avatar-title font-22 <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-info'; ?>"></i>
														<h4 class="header-title <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-info'; ?>">Installing...</h4>
													</div>
												<?php } elseif ($rServer['status'] == 4) { ?>
													<div class="col-12 text-center" style="padding-top: 15px;">
														<i class="fe-alert-triangle avatar-title font-22 <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-warning'; ?>"></i>
														<h4 class="header-title <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-warning'; ?>">Install Failed!</h4>
													</div>
												<?php } else { ?>
													<div class="col-12 text-center" style="padding-top: 15px;">
														<i class="fe-alert-triangle avatar-title font-22 <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-danger'; ?>"></i>
														<h4 class="header-title <?php echo ($rUserInfo['theme'] == 1) ? 'text-white' : 'text-danger'; ?>">Server Offline</h4>
													</div>
												<?php } ?>
											</div>
										</div>
										<div class="card-footer">
											<span data-plugin="peity-line" data-fill="<?php echo ($rUserInfo['theme'] == 1 ? '#434b56' : '#7e8e9d'); ?>" data-stroke="<?php echo ($rUserInfo['theme'] == 1 ? '#434b56' : '#7e8e9d'); ?>" data-width="100%" data-height="50" data-min="0" data-max="100"><?php echo (is_array($rServerStats[$rServer['id']]) ? implode(',', $rServerStats[$rServer['id']]) : ''); ?></span>
										</div>
									</a>
								</div>
					<?php
							}
						}
					}
					?>
				<?php endif; ?>
			</div>
		<?php else: ?>
			<div class="alert alert-danger show text-center" role="alert" style="margin-top:20px;">
				<?php echo $_['dashboard_no_permissions']; ?><br />
				<?php echo $_['dashboard_nav_top']; ?>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php include 'footer.php';?>
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
echo '        ' . "\r\n\t\t" . 'rChart = null; rDates = null; rOptions = null;' . "\r\n\r\n" . '        ';

if (!$rMobile) {
	if ($rSettings['dashboard_stats']) {
		if ($rSettings['save_closed_connection']) {
			echo '        $("#map").vectorMap({' . "\r\n" . '            zoomOnScroll: false,' . "\r\n" . '            map: "world_mill_en",' . "\r\n" . '            backgroundColor: "transparent",' . "\r\n" . '            regionStyle: {' . "\r\n" . '                initial: {' . "\r\n" . '                    fill: "#f5f6f8"' . "\r\n" . '                }' . "\r\n" . '            },' . "\r\n" . '            series: {' . "\r\n" . '                regions: [{' . "\r\n" . '                    values: {' . "\r\n" . '                        ';

			foreach ($rConnectionMap as $rCountry) {
				echo '"' . $rCountry['geoip_country_code'] . '": "' . $rCountry['colour'][0] . '",';
			}
			echo '                    },' . "\r\n" . '                    attribute: "fill"' . "\r\n" . '                }]' . "\r\n" . '            }' . "\r\n" . '        });' . "\r\n" . '        ';
		}

		echo '        function getGraphStats(auto=true) {' . "\r\n" . '            if ((window.rCurrentPage != "dashboard") && (window.rCurrentPage != "index")) { return; }' . "\r\n" . '            var rStart = Date.now();' . "\r\n" . '            rURL = "./api?action=graph_stats';

		if (isset(CoreUtilities::$rRequest['server_id'])) {
			echo '&server_id=' . intval(CoreUtilities::$rRequest['server_id']);
		}

		echo '";' . "\r\n" . '            $.getJSON(rURL, function(data) {' . "\r\n" . '                rDates = data.dates;' . "\r\n" . '                rCPUOptions = {' . "\r\n" . '                    chart: {' . "\r\n" . '                        height: 300,' . "\r\n" . '                        type: "area",' . "\r\n" . '                        stacked: false,' . "\r\n" . '                        zoom: {' . "\r\n" . '                            enabled: false,' . "\r\n" . '                        },' . "\r\n" . '                        toolbar: {' . "\r\n" . '                            show: false' . "\r\n" . '                        },' . "\r\n" . '                        animations: {' . "\r\n" . '                            enabled: false' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    colors: ["#5089de", "#56c2d6", "#51b089"],' . "\r\n" . '                    dataLabels: {' . "\r\n" . '                        enabled: false' . "\r\n" . '                    },' . "\r\n" . '                    stroke: {' . "\r\n" . '                        width: [2],' . "\r\n" . '                        curve: "smooth"' . "\r\n" . '                    },' . "\r\n" . '                    series: [{' . "\r\n" . '                        name: "CPU Usage",' . "\r\n" . '                        data: data.cpu' . "\r\n" . '                    },' . "\r\n" . '                    {' . "\r\n" . '                        name: "Memory Usage",' . "\r\n" . '                        data: data.memory' . "\r\n" . '                    },' . "\r\n" . '                    {' . "\r\n" . '                        name: "IO Usage",' . "\r\n" . '                        data: data.io' . "\r\n" . '                    }],' . "\r\n" . '                    fill: {' . "\r\n" . '                        type: "gradient", ' . "\r\n" . '                        gradient: {' . "\r\n" . '                            opacityFrom: .6,' . "\r\n" . '                            opacityTo: .8' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    xaxis: {' . "\r\n" . '                        type: "datetime",' . "\r\n" . '                        min: rDates[0],' . "\r\n" . '                        max: rDates[1],' . "\r\n" . '                        range: 3600000,' . "\r\n" . '                        labels: {' . "\r\n" . '                            formatter: function(value, timestamp, opts) {' . "\r\n" . '                                var d = new Date(timestamp);' . "\r\n" . '                                return ("0"+d.getHours()).slice(-2) + ":" + ("0"+d.getMinutes()).slice(-2);' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    tooltip: {' . "\r\n" . '                      y: {' . "\r\n" . '                        formatter: function(value, { series, seriesIndex, dataPointIndex, w }) {' . "\r\n" . '                          return value + "%";' . "\r\n" . '                        }' . "\r\n" . '                      }' . "\r\n" . '                    }' . "\r\n" . '                };' . "\r\n" . '                if (typeof(rCPUChart) != "undefined") {' . "\r\n" . '                    rCPUChart.destroy();' . "\r\n" . '                    rCPUChart = undefined;' . "\r\n" . '                }' . "\r\n" . '                (rCPUChart = new ApexCharts(document.querySelector("#cpu_chart"), rCPUOptions)).render();' . "\r\n" . '                rNetworkOptions = {' . "\r\n" . '                    chart: {' . "\r\n" . '                        height: 300,' . "\r\n" . '                        type: "area",' . "\r\n" . '                        stacked: false,' . "\r\n" . '                        zoom: {' . "\r\n" . '                            enabled: false,' . "\r\n" . '                        },' . "\r\n" . '                        toolbar: {' . "\r\n" . '                            show: false' . "\r\n" . '                        },' . "\r\n" . '                        animations: {' . "\r\n" . '                            enabled: false' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    colors: ["#5089de", "#56c2d6"],' . "\r\n" . '                    dataLabels: {' . "\r\n" . '                        enabled: false' . "\r\n" . '                    },' . "\r\n" . '                    stroke: {' . "\r\n" . '                        width: [2],' . "\r\n" . '                        curve: "smooth"' . "\r\n" . '                    },' . "\r\n" . '                    series: [{' . "\r\n" . '                        name: "Input",' . "\r\n" . '                        data: data.input' . "\r\n" . '                    },' . "\r\n" . '                    {' . "\r\n" . '                        name: "Output",' . "\r\n" . '                        data: data.output' . "\r\n" . '                    }],' . "\r\n" . '                    fill: {' . "\r\n" . '                        type: "gradient", ' . "\r\n" . '                        gradient: {' . "\r\n" . '                            opacityFrom: .6,' . "\r\n" . '                            opacityTo: .8' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    xaxis: {' . "\r\n" . '                        type: "datetime",' . "\r\n" . '                        min: rDates[0],' . "\r\n" . '                        max: rDates[1],' . "\r\n" . '                        range: 3600000,' . "\r\n" . '                        labels: {' . "\r\n" . '                            formatter: function(value, timestamp, opts) {' . "\r\n" . '                                var d = new Date(timestamp);' . "\r\n" . '                                return ("0"+d.getHours()).slice(-2) + ":" + ("0"+d.getMinutes()).slice(-2);' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    tooltip: {' . "\r\n" . '                      y: {' . "\r\n" . '                        formatter: function(value, { series, seriesIndex, dataPointIndex, w }) {' . "\r\n" . '                          return value + " Mbps";' . "\r\n" . '                        }' . "\r\n" . '                      }' . "\r\n" . '                    }' . "\r\n" . '                };' . "\r\n" . '                if (typeof(rNetworkChart) != "undefined") {' . "\r\n" . '                    rNetworkChart.destroy();' . "\r\n" . '                    rNetworkChart = undefined;' . "\r\n" . '                }' . "\r\n" . '                (rNetworkChart = new ApexCharts(document.querySelector("#network_chart"), rNetworkOptions)).render();' . "\r\n" . '                rConnectionsOptions = {' . "\r\n" . '                    chart: {' . "\r\n" . '                        height: 300,' . "\r\n" . '                        type: "area",' . "\r\n" . '                        stacked: false,' . "\r\n" . '                        zoom: {' . "\r\n" . '                            enabled: false,' . "\r\n" . '                        },' . "\r\n" . '                        toolbar: {' . "\r\n" . '                            show: false' . "\r\n" . '                        },' . "\r\n" . '                        animations: {' . "\r\n" . '                            enabled: false' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    colors: ["#5089de", "#56c2d6", "#51b089"],' . "\r\n" . '                    dataLabels: {' . "\r\n" . '                        enabled: false' . "\r\n" . '                    },' . "\r\n" . '                    stroke: {' . "\r\n" . '                        width: [2],' . "\r\n" . '                        curve: "smooth"' . "\r\n" . '                    },' . "\r\n" . '                    series: [{' . "\r\n" . '                        name: "Online Streams",' . "\r\n" . '                        data: data.streams' . "\r\n" . '                    },' . "\r\n" . '                    {' . "\r\n" . '                        name: "Unique Users",' . "\r\n" . '                        data: data.users' . "\r\n" . '                    },' . "\r\n" . '                    {' . "\r\n" . '                        name: "Total Connections",' . "\r\n" . '                        data: data.connections' . "\r\n" . '                    }],' . "\r\n" . '                    fill: {' . "\r\n" . '                        type: "gradient", ' . "\r\n" . '                        gradient: {' . "\r\n" . '                            opacityFrom: .6,' . "\r\n" . '                            opacityTo: .8' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    xaxis: {' . "\r\n" . '                        type: "datetime",' . "\r\n" . '                        min: rDates[0],' . "\r\n" . '                        max: rDates[1],' . "\r\n" . '                        range: 3600000,' . "\r\n" . '                        labels: {' . "\r\n" . '                            formatter: function(value, timestamp, opts) {' . "\r\n" . '                                var d = new Date(timestamp);' . "\r\n" . '                                return ("0"+d.getHours()).slice(-2) + ":" + ("0"+d.getMinutes()).slice(-2);' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    },' . "\r\n" . '                    tooltip: {' . "\r\n" . '                      y: {' . "\r\n" . '                        formatter: function(value, { series, seriesIndex, dataPointIndex, w }) {' . "\r\n" . '                          return value;' . "\r\n" . '                        }' . "\r\n" . '                      }' . "\r\n" . '                    }' . "\r\n" . '                };' . "\r\n" . '                if (typeof(rConnectionsChart) != "undefined") {' . "\r\n" . '                    rConnectionsChart.destroy();' . "\r\n" . '                    rConnectionsChart = undefined;' . "\r\n" . '                }' . "\r\n" . '                (rConnectionsChart = new ApexCharts(document.querySelector("#connections_chart"), rConnectionsOptions)).render();' . "\r\n" . '                if (auto) {' . "\r\n" . '                    setTimeout(getGraphStats, 60000 - (Date.now() - rStart));' . "\r\n" . '                }' . "\r\n" . '            }).fail(function() {' . "\r\n" . '                if (auto) {' . "\r\n" . '                    setTimeout(getGraphStats, 60000);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        ';
	}
}

echo '        function getStats(auto=true) {' . "\r\n" . '            if ((window.rCurrentPage != "dashboard") && (window.rCurrentPage != "index")) { return; }' . "\r\n" . '            var rStart = Date.now();' . "\r\n" . '            rURL = "./api?action=stats';

if (isset(CoreUtilities::$rRequest['server_id'])) {
	echo '&server_id=' . intval(CoreUtilities::$rRequest['server_id']);
}

echo '";' . "\r\n" . '            $.getJSON(rURL, function(data) {' . "\r\n" . '                // Open Connections' . "\r\n" . '                var rCapacity = Math.ceil((data.open_connections / data.total_connections) * 100);' . "\r\n" . '                if (isNaN(rCapacity)) { rCapacity = 0; }' . "\r\n" . '                $(".active-connections .entry").html($.number(data.open_connections, 0));' . "\r\n" . '                $(".active-connections .entry-percentage").html($.number(data.total_connections, 0));' . "\r\n" . '                $(".active-connections .progress-bar").prop("aria-valuenow", rCapacity);' . "\r\n" . '                $(".active-connections .progress-bar").css("width", rCapacity.toString() + "%");' . "\r\n" . '                $(".active-connections .sr-only").html(rCapacity.toString() + "%");' . "\r\n" . '                // Online Users' . "\r\n" . '                var rCapacity = Math.ceil((data.online_users / data.total_users) * 100);' . "\r\n" . '                if (isNaN(rCapacity)) { rCapacity = 0; }' . "\r\n" . '                $(".online-users .entry").html($.number(data.online_users, 0));' . "\r\n" . '                $(".online-users .entry-percentage").html($.number(data.total_users, 0));' . "\r\n" . '                $(".online-users .progress-bar").prop("aria-valuenow", rCapacity);' . "\r\n" . '                $(".online-users .progress-bar").css("width", rCapacity.toString() + "%");' . "\r\n" . '                $(".online-users .sr-only").html(rCapacity.toString() + "%");' . "\r\n" . '                // Network Load - Input' . "\r\n" . '                var rCapacity = Math.ceil((Math.floor(data.bytes_received / 125000) / data.network_guaranteed_speed) * 100);' . "\r\n" . '                if (isNaN(rCapacity)) { rCapacity = 0; }' . "\r\n" . '                $(".input-flow .entry").html($.number(Math.floor(data.bytes_received / 125000), 0));' . "\r\n" . '                $(".input-flow .entry-percentage").html(rCapacity.toString() + "%");' . "\r\n" . '                $(".input-flow .progress-bar").prop("aria-valuenow", rCapacity);' . "\r\n" . '                $(".input-flow .progress-bar").css("width", rCapacity.toString() + "%");' . "\r\n" . '                $(".input-flow .sr-only").html(rCapacity.toString() + "%");' . "\r\n" . '                // Network Load - Output' . "\r\n" . '                var rCapacity = Math.ceil((Math.floor(data.bytes_sent / 125000) / data.network_guaranteed_speed) * 100);' . "\r\n" . '                if (isNaN(rCapacity)) { rCapacity = 0; }' . "\r\n" . '                $(".output-flow .entry").html($.number(Math.floor(data.bytes_sent / 125000), 0));' . "\r\n" . '                $(".output-flow .entry-percentage").html(rCapacity.toString() + "%");' . "\r\n" . '                $(".output-flow .progress-bar").prop("aria-valuenow", rCapacity);' . "\r\n" . '                $(".output-flow .progress-bar").css("width", rCapacity.toString() + "%");' . "\r\n" . '                $(".output-flow .sr-only").html(rCapacity.toString() + "%");' . "\r\n" . '                // Active Streams' . "\r\n" . '                var rCapacity = Math.ceil((data.total_running_streams / (data.offline_streams + data.total_running_streams)) * 100);' . "\r\n" . '                if (isNaN(rCapacity)) { rCapacity = 0; }' . "\r\n" . '                $(".active-streams .entry").html($.number(data.total_running_streams, 0));' . "\r\n" . '                $(".active-streams .entry-percentage").html($.number(data.offline_streams, 0));' . "\r\n" . '                $(".active-streams .progress-bar").prop("aria-valuenow", rCapacity);' . "\r\n" . '                $(".active-streams .progress-bar").css("width", rCapacity.toString() + "%");' . "\r\n" . '                $(".active-streams .sr-only").html(rCapacity.toString() + "%");' . "\r\n\t\t\t\t" . '$(".offline-streams .entry").html($.number(data.offline_streams, 0));' . "\r\n" . '                // CPU Usage' . "\r\n" . '                $(".cpu-usage .entry").html(data.cpu);' . "\r\n" . '                $(".cpu-usage .entry-percentage").html(data.cpu.toString() + "%");' . "\r\n" . '                $(".cpu-usage .progress-bar").prop("aria-valuenow", data.cpu);' . "\r\n" . '                $(".cpu-usage .progress-bar").css("width", data.cpu.toString() + "%");' . "\r\n" . '                $(".cpu-usage .sr-only").html(data.cpu.toString() + "%");' . "\r\n" . '                // Memory Usage' . "\r\n" . '                $(".mem-usage .entry").html(data.mem);' . "\r\n" . '                $(".mem-usage .entry-percentage").html(data.mem.toString() + "%");' . "\r\n" . '                $(".mem-usage .progress-bar").prop("aria-valuenow", data.mem);' . "\r\n" . '                $(".mem-usage .progress-bar").css("width", data.mem.toString() + "%");' . "\r\n" . '                $(".mem-usage .sr-only").html(data.mem.toString() + "%");' . "\r\n" . '                // Uptime' . "\r\n\t\t\t\t" . 'if (data.uptime) {' . "\r\n\t\t\t\t\t" . '$(".uptime .entry").html(data.uptime.split(" ").slice(0,2).join(" "));' . "\r\n\t\t\t\t" . '}' . "\r\n" . '                ';

if (!isset(CoreUtilities::$rRequest['server_id'])) {
	echo "\t\t\t\t" . '// Per Server' . "\r\n\t\t\t\t" . '$(data.servers).each(function(i) {' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_conns").html($.number(data.servers[i].open_connections, 0));' . "\r\n\t\t\t\t\t" . '$("#s_" + data.servers[i].server_id + "_users").html($.number(data.servers[i].online_users, 0));' . "\r\n\t\t\t\t\t" . '$("#s_" + data.servers[i].server_id + "_online").html($.number(data.servers[i].total_running_streams, 0));' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_offline").html($.number(data.servers[i].offline_streams, 0));' . "\r\n\t\t\t\t\t" . '$("#s_" + data.servers[i].server_id + "_input").html($.number(Math.floor(data.servers[i].bytes_received / 125000), 0));' . "\r\n\t\t\t\t\t" . '$("#s_" + data.servers[i].server_id + "_output").html($.number(Math.floor(data.servers[i].bytes_sent / 125000), 0));' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_requests").html($.number(data.servers[i].requests_per_second, 0));' . "\r\n" . '                    if (data.servers[i].uptime) {' . "\r\n\t\t\t\t\t\t" . '$("#s_" + data.servers[i].server_id + "_uptime").html(data.servers[i].uptime.split(" ").slice(0,2).join(" "));' . "\r\n\t\t\t\t\t" . '}' . "\r\n" . '                    ';

	if ($rSettings['dashboard_display_alt'] && !$rMobile) {
		echo '                    $("#s_" + data.servers[i].server_id + "_cpu").removeClass("bg-success").removeClass("bg-danger").removeClass("bg-warning");' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_mem").removeClass("bg-success").removeClass("bg-danger").removeClass("bg-warning");' . "\r\n\t\t\t\t\t" . '$("#s_" + data.servers[i].server_id + "_cpu").attr("aria-valuenow", data.servers[i].cpu);' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_cpu").css("width", data.servers[i].cpu + "%");' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_mem").attr("aria-valuenow", data.servers[i].mem);' . "\r\n" . '                    $("#s_" + data.servers[i].server_id + "_mem").css("width", data.servers[i].mem + "%");' . "\r\n" . '                    if (data.servers[i].server_type == 0) {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_io").removeClass("bg-success").removeClass("bg-danger").removeClass("bg-warning");' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_fs").removeClass("bg-success").removeClass("bg-danger").removeClass("bg-warning");' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_io").attr("aria-valuenow", data.servers[i].io);' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_io").css("width", data.servers[i].io + "%");' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_fs").attr("aria-valuenow", data.servers[i].fs);' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_fs").css("width", data.servers[i].fs + "%");' . "\r\n" . '                    }' . "\r\n" . '                    if (data.servers[i].cpu > 75) {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_cpu").addClass("bg-danger");' . "\r\n" . '                    } else if (data.servers[i].cpu > 50) {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_cpu").addClass("bg-warning");' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_cpu").addClass("bg-success");' . "\r\n" . '                    }' . "\r\n" . '                    if (data.servers[i].mem > 75) {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_mem").addClass("bg-danger");' . "\r\n" . '                    } else if (data.servers[i].mem > 50) {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_mem").addClass("bg-warning");' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#s_" + data.servers[i].server_id + "_mem").addClass("bg-success");' . "\r\n" . '                    }' . "\r\n" . '                    if (data.servers[i].server_type == 0) {' . "\r\n" . '                        if (data.servers[i].io > 75) {' . "\r\n" . '                            $("#s_" + data.servers[i].server_id + "_io").addClass("bg-danger");' . "\r\n" . '                        } else if (data.servers[i].io > 50) {' . "\r\n" . '                            $("#s_" + data.servers[i].server_id + "_io").addClass("bg-warning");' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#s_" + data.servers[i].server_id + "_io").addClass("bg-success");' . "\r\n" . '                        }' . "\r\n" . '                        if (data.servers[i].fs > 75) {' . "\r\n" . '                            $("#s_" + data.servers[i].server_id + "_fs").addClass("bg-danger");' . "\r\n" . '                        } else if (data.servers[i].fs > 50) {' . "\r\n" . '                            $("#s_" + data.servers[i].server_id + "_fs").addClass("bg-warning");' . "\r\n" . '                        } else {' . "\r\n" . '                            $("#s_" + data.servers[i].server_id + "_fs").addClass("bg-success");' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                    ';
	} else {
		echo "\t\t\t\t\t" . "\$(\"#s_\" + data.servers[i].server_id + \"_cpu\").val(data.servers[i].cpu).trigger('change');" . "\r\n\t\t\t\t\t" . "\$(\"#s_\" + data.servers[i].server_id + \"_mem\").val(data.servers[i].mem).trigger('change');" . "\r\n" . "                    \$(\"#s_\" + data.servers[i].server_id + \"_io\").val(data.servers[i].io).trigger('change');" . "\r\n" . "                    \$(\"#s_\" + data.servers[i].server_id + \"_fs\").val(data.servers[i].fs).trigger('change');" . "\r\n" . '                    ';
	}

	echo "\t\t\t\t" . '});' . "\r\n" . '                ';
}

echo '                if (auto) {' . "\r\n" . '                    if (Date.now() - rStart < 1000) {' . "\r\n" . '                        setTimeout(getStats, 1000 - (Date.now() - rStart));' . "\r\n" . '                    } else {' . "\r\n" . '                        getStats();' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            }).fail(function() {' . "\r\n" . '                if (auto) {' . "\r\n" . '                    setTimeout(getStats, 1000);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n" . "            \$('select').select2({width: '100%'});" . "\r\n" . '            getStats();' . "\r\n\t\t\t";

if (!$rMobile || $rSettings['dashboard_stats']) {
	echo "\t\t\t" . 'getGraphStats();' . "\r\n\t\t\t";
}

echo '            $("#server_id").change(function() {' . "\r\n" . '                if ($(this).val().length > 0) {' . "\r\n" . '                    navigate("./dashboard?server_id=" + $(this).val());' . "\r\n" . '                } else {' . "\r\n" . '                    navigate("./dashboard");' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n\t\t";
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