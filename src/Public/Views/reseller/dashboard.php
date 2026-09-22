<?php

/**
 * Reseller Dashboard (Bootstrap 5). Content-only markup rendered inside the
 * reseller new-UI shell (reseller/header.php + footer.php).
 *
 * Live tiles keep the legacy data contract: ./api?action=dashboard feeds
 * the .active-connections / .online-users / .active-accounts / .credits tiles.
 *
 * Features:
 * 1. World map (jsVectorMap) showing live client connections and top countries.
 * 2. Detailed Live Client Connections responsive DataTable with kill action.
 * 3. Recent Activity & Expiring Lines responsive DataTables.
 * 4. Dedicated Bottom Media Showcase (Movies, Streams, Series, Episodes)
 *    rendered in responsive DataTables.
 *
 * Strict constraint: ZERO custom CSS, ZERO <style> tags, ZERO inline styles.
 */

$xmCreditsAssigned = count($rRegisteredUsers) > 1;

// Live stat tiles: [wrapClass, icon, accent, label, link|null].
$xmTiles = [
    ['active-connections', 'ti tabler-plug-connected', 'primary', $language::get('connections'),           !empty($rPermissions['reseller_client_connection_logs']) ? 'live_connections' : null],
    ['online-users',       'ti tabler-users',          'success', $language::get('lines_online'),           !empty($rPermissions['reseller_client_connection_logs']) ? 'live_connections' : null],
    ['active-accounts',    'ti tabler-circle-check',   'info',    $language::get('dashboard_active_lines'), null],
    ['credits',            'ti tabler-coin',           'warning', $xmCreditsAssigned ? $language::get('assigned_credits') : $language::get('total_credits'), !empty($rPermissions['create_sub_resellers']) ? 'users' : null],
];

$rCanVod = !empty($rCanVod) || !empty($rPermissions['can_view_vod']);
$rDateFormat = !empty($rSettings['date_format']) ? $rSettings['date_format'] . ' H:i' : 'd/m/Y H:i';
$xmResolveCategoryName = function ($rawCatId, array $cats): string {
    if (is_string($rawCatId) && str_starts_with($rawCatId, '[')) {
        $decoded = json_decode($rawCatId, true);
        $rawCatId = is_array($decoded) ? ($decoded[0] ?? null) : $rawCatId;
    } elseif (is_array($rawCatId)) {
        $rawCatId = $rawCatId[0] ?? null;
    }
    return $cats[$rawCatId]['category_name'] ?? '-';
};
?>

<!-- Page header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h4 class="mb-0"><?= htmlspecialchars($language::get('welcome') . ' ' . ($rUserInfo['username'] ?? '')); ?></h4>
</div>

<?php if (!empty($rNotice)): ?>
    <div class="card mb-4">
        <div class="card-body"><?= $rNotice; ?></div>
    </div>
<?php endif; ?>

<!-- Stat tiles -->
<div class="row g-4 mb-4">
    <?php foreach ($xmTiles as [$rWrap, $rIcon, $rAccent, $rLabel, $rLink]): ?>
        <div class="col-sm-6 col-xl-3">
            <?php if ($rLink): ?><a href="<?= htmlspecialchars($rLink, ENT_QUOTES); ?>" class="text-body text-decoration-none"><?php endif; ?>
            <div class="card h-100">
                <div class="card-body d-flex justify-content-between align-items-center <?= $rWrap; ?>">
                    <div class="card-title mb-0">
                        <h5 class="mb-1 me-2"><span class="entry">0</span></h5>
                        <p class="mb-0"><?= htmlspecialchars($rLabel); ?></p>
                    </div>
                    <div class="card-icon">
                        <span class="badge bg-label-<?= $rAccent; ?> rounded p-2">
                            <i class="icon-base <?= $rIcon; ?> icon-26px"></i>
                        </span>
                    </div>
                </div>
            </div>
            <?php if ($rLink): ?></a><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Connections by Location (World Map + Top Countries) -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="icon-base ti tabler-world icon-22px text-primary"></i>
            <span><?= htmlspecialchars($language::get('dashboard_connections_by_location') ?: 'Connections by Location'); ?></span>
        </h5>
        <span class="badge bg-label-primary"><?= number_format($rConnectionCount); ?> <?= htmlspecialchars($language::get('connections') ?: 'Connections'); ?></span>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-lg-8 mb-4 mb-lg-0">
                <div id="map" class="dashboard-map"></div>
            </div>
            <div class="col-lg-4 align-self-center">
                <?php if ($rConnectionCount > 0): ?>
                    <h6 class="text-body-secondary mb-3"><?= htmlspecialchars($language::get('top_connected_countries') ?: 'Top Connected Countries'); ?></h6>
                    <?php foreach (array_slice($rConnectionMap, 0, 7) as $rCountry):
                        $rPct = (int) round($rCountry['count'] / $rConnectionCount * 100);
                        $rBar = $rCountry['colour'][1] ?? 'bg-primary';
                        $rIso = strtolower((string) ($rCountry['geoip_country_code'] ?? ''));
                    ?>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-medium d-flex align-items-center">
                                <?php if ($rIso): ?>
                                    <img src="assets/img/countries/<?= htmlspecialchars($rIso, ENT_QUOTES); ?>.png" class="me-2 rounded-circle" width="18" height="18" loading="lazy" alt="">
                                <?php endif; ?>
                                <?= htmlspecialchars($rCountry['name']); ?>
                            </span>
                            <span class="text-body-secondary"><?= number_format($rCountry['count']); ?> &middot; <?= $rPct; ?>%</span>
                        </div>
                        <div class="progress mb-3 dashboard-loc-progress">
                            <div class="progress-bar <?= htmlspecialchars($rBar, ENT_QUOTES); ?>" role="progressbar" data-width="<?= $rPct; ?>" aria-valuenow="<?= $rPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-5 text-body-secondary">
                        <i class="icon-base ti tabler-world-off icon-48px mb-2"></i>
                        <p class="mb-0"><?= htmlspecialchars($language::get('no_data_available') ?: 'No connection distribution data available'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Live Client Connections -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 d-flex align-items-center gap-2">
            <i class="icon-base ti tabler-activity icon-22px text-success"></i>
            <span><?= htmlspecialchars($language::get('live_connections') ?: 'Live Connections'); ?></span>
        </h5>
        <?php if (!empty($rPermissions['reseller_client_connection_logs'])): ?>
            <a href="live_connections" class="btn btn-sm btn-primary">
                <?= htmlspecialchars($language::get('view_all') ?: 'View All'); ?>
                <i class="icon-base ti tabler-arrow-right ms-1"></i>
            </a>
        <?php endif; ?>
    </div>
    <div class="card-datatable table-responsive pb-6">
        <table id="dashboard-live-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
            <thead>
                <tr>
                    <th class="text-center"><?= htmlspecialchars($language::get('status') ?: 'Status'); ?></th>
                    <th><?= htmlspecialchars($language::get('client') ?: 'Client'); ?></th>
                    <th><?= htmlspecialchars($language::get('stream') ?: 'Stream'); ?></th>
                    <th><?= htmlspecialchars($language::get('country') ?: 'Country'); ?></th>
                    <th><?= htmlspecialchars($language::get('ip') ?: 'IP'); ?></th>
                    <th><?= htmlspecialchars($language::get('player') ?: 'Player'); ?></th>
                    <th class="text-center"><?= htmlspecialchars($language::get('divergence') ?: 'Divergence'); ?></th>
                    <th class="text-center"><?= htmlspecialchars($language::get('duration') ?: 'Duration'); ?></th>
                    <th><?= htmlspecialchars($language::get('owner') ?: 'Owner'); ?></th>
                    <?php if (!empty($rPermissions['reseller_client_connection_logs'])): ?>
                        <th class="text-center"><?= htmlspecialchars($language::get('action') ?: 'Action'); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rLiveConnections as $rLive):
                    $rClientLabel = $rLive['client_name'] ?: ('Line #' . $rLive['user_id']);
                    if (!empty($rLive['is_mag'])) {
                        $rClientUrl = 'mag?id=' . intval($rLive['mag_id'] ?: $rLive['user_id']);
                        $rDevIcon = 'ti tabler-device-tv';
                    } elseif (!empty($rLive['is_e2'])) {
                        $rClientUrl = 'enigma?id=' . intval($rLive['device_id'] ?: $rLive['user_id']);
                        $rDevIcon = 'ti tabler-device-desktop';
                    } else {
                        $rClientUrl = 'line?id=' . intval($rLive['user_id']);
                        $rDevIcon = 'ti tabler-user';
                    }
                    $rStreamTitle = $rLive['stream_display_name'] ?: ('Stream #' . $rLive['stream_id']);
                    $rCountryCode = strtoupper((string) ($rLive['geoip_country_code'] ?? ''));
                    $rCountryName = $rCountryCodes[$rCountryCode] ?? $rCountryCode;
                    $rCountryFlag = strtolower($rCountryCode);
                    $rPlayer = trim(explode('(', (string) ($rLive['user_agent'] ?? ''))[0]);
                    if (!$rPlayer) {
                        $rPlayer = strtoupper((string) ($rLive['container'] ?? ''));
                    }
                    $rDiv = intval($rLive['divergence'] ?? 0);
                    $rDivColor = $rDiv <= 50 ? 'text-success' : ($rDiv <= 80 ? 'text-warning' : 'text-danger');
                    $rDuration = time() - intval($rLive['date_start'] ?? time());
                    $rDuration = max(0, $rDuration);
                    $rHours = floor($rDuration / 3600);
                    $rMins = floor(($rDuration % 3600) / 60);
                    $rSecs = $rDuration % 60;
                    $rDurationFormatted = sprintf('%02d:%02d:%02d', $rHours, $rMins, $rSecs);
                ?>
                    <tr>
                        <td class="text-center">
                            <span class="badge bg-label-success d-inline-flex align-items-center gap-1">
                                <i class="icon-base ti tabler-point-filled"></i>
                                <?= htmlspecialchars($language::get('dashboard_online') ?: 'Online'); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?= htmlspecialchars($rClientUrl, ENT_QUOTES); ?>" class="text-body fw-medium d-inline-flex align-items-center gap-1">
                                <i class="icon-base <?= $rDevIcon; ?> text-body-secondary"></i>
                                <?= htmlspecialchars($rClientLabel); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($rCanVod && !empty($rLive['stream_id'])): ?>
                                <a href="stream_view?id=<?= intval($rLive['stream_id']); ?>" class="text-body"><?= htmlspecialchars($rStreamTitle); ?></a>
                            <?php else: ?>
                                <span class="text-body"><?= htmlspecialchars($rStreamTitle); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rCountryFlag): ?>
                                <span class="d-inline-flex align-items-center gap-1">
                                    <img src="assets/img/countries/<?= htmlspecialchars($rCountryFlag, ENT_QUOTES); ?>.png" width="16" height="16" loading="lazy" alt="" class="rounded-circle">
                                    <small><?= htmlspecialchars($rCountryName ?: $rCountryCode); ?></small>
                                </span>
                            <?php else: ?>
                                <span class="text-body-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-label-secondary font-monospace"><?= htmlspecialchars($rLive['user_ip'] ?? '-'); ?></span>
                        </td>
                        <td>
                            <small class="text-body"><?= htmlspecialchars($rPlayer ?: '-'); ?></small>
                        </td>
                        <td class="text-center">
                            <i class="icon-base ti tabler-square-filled <?= $rDivColor; ?>" title="<?= (100 - $rDiv); ?>%"></i>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-label-info font-monospace"><?= $rDurationFormatted; ?></span>
                        </td>
                        <td>
                            <span class="text-body"><?= htmlspecialchars($rLive['reseller_owner'] ?? '-'); ?></span>
                        </td>
                        <?php if (!empty($rPermissions['reseller_client_connection_logs'])):
                            $rUuid = (string) ($rLive['activity_id'] ?? $rLive['uuid'] ?? '');
                        ?>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill-btn" data-uuid="<?= htmlspecialchars($rUuid, ENT_QUOTES); ?>" title="<?= htmlspecialchars($language::get('kill') ?: 'Kill'); ?>">
                                    <i class="icon-base ti tabler-hammer"></i>
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Middle Section: Recent Activity & Expiring Lines -->
<div class="row g-4 mb-4">
    <!-- Recent Activity -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-history icon-22px text-info"></i>
                    <a href="user_logs" class="text-body"><?= htmlspecialchars($language::get('recent_activity')); ?></a>
                </h5>
                <a href="user_logs" class="btn btn-sm btn-label-secondary">
                    <?= htmlspecialchars($language::get('view_all') ?: 'View All'); ?>
                </a>
            </div>
            <div class="card-datatable table-responsive pb-6">
                <table id="dashboard-activity-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
                    <thead>
                        <tr>
                            <th class="text-center"><?= htmlspecialchars($language::get('reseller')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('line_user')); ?></th>
                            <th><?= htmlspecialchars($language::get('action')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('date')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rActivityRows as $rRow): ?>
                            <tr>
                                <td class="text-center"><a class="text-body" href="user?id=<?= intval($rRow['owner_id']); ?>"><?= htmlspecialchars($rRow['username']); ?></a></td>
                                <td class="text-center"><?= $rRow['target_html'] ?? '<span class="text-body-secondary">-</span>'; ?></td>
                                <td><?= $rRow['text']; ?></td>
                                <td class="text-center"><?= date($rDateFormat, $rRow['date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Expiring Lines -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-clock-exclamation icon-22px text-warning"></i>
                    <a href="lines" class="text-body"><?= htmlspecialchars($language::get('expiring_lines')); ?></a>
                </h5>
                <a href="lines" class="btn btn-sm btn-label-secondary">
                    <?= htmlspecialchars($language::get('view_all') ?: 'View All'); ?>
                </a>
            </div>
            <div class="card-datatable table-responsive pb-6">
                <table id="dashboard-expiring-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
                    <thead>
                        <tr>
                            <th class="text-center"><?= htmlspecialchars($language::get('type')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('identity')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('owner')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('expires')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rExpiringLines as $rUser): ?>
                            <tr>
                                <?php if ($rUser['is_mag']): ?>
                                    <td class="text-center"><?= htmlspecialchars($language::get('mag_device')); ?></td>
                                    <td class="text-center"><a class="text-body" href="mag?id=<?= intval($rUser['mag_id']); ?>"><?= htmlspecialchars($rUser['mag_mac']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                <?php elseif ($rUser['is_e2']): ?>
                                    <td class="text-center"><?= htmlspecialchars($language::get('enigma_device')); ?></td>
                                    <td class="text-center"><a class="text-body" href="enigma?id=<?= intval($rUser['e2_id']); ?>"><?= htmlspecialchars($rUser['e2_mac']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                <?php else: ?>
                                    <td class="text-center"><?= htmlspecialchars($language::get('line')); ?></td>
                                    <td class="text-center"><a class="text-body" href="line?id=<?= intval($rUser['line_id']); ?>"><?= htmlspecialchars($rUser['username']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                <?php endif; ?>
                                <td class="text-center"><a class="text-body" href="user?id=<?= intval($rUser['member_id']); ?>"><?= htmlspecialchars($rRegisteredUsers[$rUser['member_id']]['username'] ?? ''); ?></a></td>
                                <td class="text-center"><?= date($rDateFormat, $rUser['exp_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($rCanVod): ?>
    <!-- Dedicated Bottom Media Showcase Section -->
    <div class="card mb-4">
        <div class="card-header pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-player-play icon-22px text-danger"></i>
                <span><?= htmlspecialchars($language::get('recently_added_media') ?: 'Recently Added Media'); ?></span>
            </h5>
            <ul class="nav nav-tabs card-header-tabs" id="media-tabs" role="tablist">
                <li class="nav-item">
                    <button type="button" class="nav-link active d-flex align-items-center gap-1" data-bs-toggle="tab" data-bs-target="#tab-movies" role="tab" aria-selected="true">
                        <i class="icon-base ti tabler-movie"></i>
                        <span><?= htmlspecialchars($language::get('movies') ?: 'Movies'); ?></span>
                        <span class="badge rounded-pill bg-label-primary ms-1"><?= count($rLatestMovies); ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link d-flex align-items-center gap-1" data-bs-toggle="tab" data-bs-target="#tab-streams" role="tab" aria-selected="false">
                        <i class="icon-base ti tabler-broadcast"></i>
                        <span><?= htmlspecialchars($language::get('streams') ?: 'Live Streams'); ?></span>
                        <span class="badge rounded-pill bg-label-danger ms-1"><?= count($rLatestStreams); ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link d-flex align-items-center gap-1" data-bs-toggle="tab" data-bs-target="#tab-series" role="tab" aria-selected="false">
                        <i class="icon-base ti tabler-device-tv"></i>
                        <span><?= htmlspecialchars($language::get('series') ?: 'Series'); ?></span>
                        <span class="badge rounded-pill bg-label-info ms-1"><?= count($rLatestSeries); ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button" class="nav-link d-flex align-items-center gap-1" data-bs-toggle="tab" data-bs-target="#tab-episodes" role="tab" aria-selected="false">
                        <i class="icon-base ti tabler-video"></i>
                        <span><?= htmlspecialchars($language::get('episodes') ?: 'Episodes'); ?></span>
                        <span class="badge rounded-pill bg-label-warning ms-1"><?= count($rLatestEpisodes); ?></span>
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body p-0">
            <div class="tab-content">
                <!-- Movies Tab -->
                <div class="tab-pane fade show active" id="tab-movies" role="tabpanel">
                    <div class="card-datatable table-responsive pb-6">
                        <table id="dashboard-movies-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= htmlspecialchars($language::get('id') ?: 'ID'); ?></th>
                                    <th><?= htmlspecialchars($language::get('poster') ?: 'Poster'); ?></th>
                                    <th><?= htmlspecialchars($language::get('title') ?: 'Title'); ?></th>
                                    <th><?= htmlspecialchars($language::get('category') ?: 'Category'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('added') ?: 'Added'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('action') ?: 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rLatestMovies as $rMovie):
                                    $rCatName = $xmResolveCategoryName($rMovie['category_id'], $rCategories);
                                    $rIcon = $rMovie['stream_icon'];
                                ?>
                                    <tr>
                                        <td class="text-center"><span class="badge bg-label-secondary font-monospace"><?= intval($rMovie['id']); ?></span></td>
                                        <td>
                                            <?php if (!empty($rIcon)): ?>
                                                <img src="resize?maxh=40&maxw=40&url=<?= urlencode($rIcon); ?>" class="rounded me-2" loading="lazy" alt="">
                                            <?php else: ?>
                                                <span class="badge bg-label-primary rounded p-2 me-2"><i class="icon-base ti tabler-movie"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="stream_view?id=<?= intval($rMovie['id']); ?>" class="text-body fw-medium">
                                                <?= htmlspecialchars($rMovie['stream_display_name']); ?>
                                            </a>
                                        </td>
                                        <td><small class="text-body-secondary"><?= htmlspecialchars($rCatName); ?></small></td>
                                        <td class="text-center"><?= date($rDateFormat, $rMovie['added']); ?></td>
                                        <td class="text-center">
                                            <a href="stream_view?id=<?= intval($rMovie['id']); ?>" class="btn btn-sm btn-icon btn-label-primary" title="<?= htmlspecialchars($language::get('view') ?: 'View'); ?>">
                                                <i class="icon-base ti tabler-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Streams Tab -->
                <div class="tab-pane fade" id="tab-streams" role="tabpanel">
                    <div class="card-datatable table-responsive pb-6">
                        <table id="dashboard-streams-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= htmlspecialchars($language::get('id') ?: 'ID'); ?></th>
                                    <th><?= htmlspecialchars($language::get('icon') ?: 'Icon'); ?></th>
                                    <th><?= htmlspecialchars($language::get('stream') ?: 'Stream Name'); ?></th>
                                    <th><?= htmlspecialchars($language::get('category') ?: 'Category'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('added') ?: 'Added'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('action') ?: 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rLatestStreams as $rStream):
                                    $rCatName = $xmResolveCategoryName($rStream['category_id'], $rCategories);
                                    $rIcon = $rStream['stream_icon'];
                                ?>
                                    <tr>
                                        <td class="text-center"><span class="badge bg-label-secondary font-monospace"><?= intval($rStream['id']); ?></span></td>
                                        <td>
                                            <?php if (!empty($rIcon)): ?>
                                                <img src="resize?maxh=40&maxw=40&url=<?= urlencode($rIcon); ?>" class="rounded me-2" loading="lazy" alt="">
                                            <?php else: ?>
                                                <span class="badge bg-label-danger rounded p-2 me-2"><i class="icon-base ti tabler-broadcast"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="stream_view?id=<?= intval($rStream['id']); ?>" class="text-body fw-medium">
                                                <?= htmlspecialchars($rStream['stream_display_name']); ?>
                                            </a>
                                        </td>
                                        <td><small class="text-body-secondary"><?= htmlspecialchars($rCatName); ?></small></td>
                                        <td class="text-center"><?= date($rDateFormat, $rStream['added']); ?></td>
                                        <td class="text-center">
                                            <a href="stream_view?id=<?= intval($rStream['id']); ?>" class="btn btn-sm btn-icon btn-label-primary" title="<?= htmlspecialchars($language::get('view') ?: 'View'); ?>">
                                                <i class="icon-base ti tabler-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Series Tab -->
                <div class="tab-pane fade" id="tab-series" role="tabpanel">
                    <div class="card-datatable table-responsive pb-6">
                        <table id="dashboard-series-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= htmlspecialchars($language::get('id') ?: 'ID'); ?></th>
                                    <th><?= htmlspecialchars($language::get('cover') ?: 'Cover'); ?></th>
                                    <th><?= htmlspecialchars($language::get('series') ?: 'Series Title'); ?></th>
                                    <th><?= htmlspecialchars($language::get('category') ?: 'Category'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('rating') ?: 'Rating'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('action') ?: 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rLatestSeries as $rSeries):
                                    $rCatName = $xmResolveCategoryName($rSeries['category_id'], $rCategories);
                                    $rCover = $rSeries['cover'];
                                ?>
                                    <tr>
                                        <td class="text-center"><span class="badge bg-label-secondary font-monospace"><?= intval($rSeries['id']); ?></span></td>
                                        <td>
                                            <?php if (!empty($rCover)): ?>
                                                <img src="resize?maxh=40&maxw=40&url=<?= urlencode($rCover); ?>" class="rounded me-2" loading="lazy" alt="">
                                            <?php else: ?>
                                                <span class="badge bg-label-info rounded p-2 me-2"><i class="icon-base ti tabler-device-tv"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="episodes?series=<?= intval($rSeries['id']); ?>" class="text-body fw-medium">
                                                <?= htmlspecialchars($rSeries['title']); ?>
                                            </a>
                                        </td>
                                        <td><small class="text-body-secondary"><?= htmlspecialchars($rCatName); ?></small></td>
                                        <td class="text-center">
                                            <span class="badge bg-label-warning">
                                                <i class="ti tabler-star-filled me-1"></i>
                                                <?= htmlspecialchars($rSeries['rating'] ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="episodes?series=<?= intval($rSeries['id']); ?>" class="btn btn-sm btn-icon btn-label-info" title="<?= htmlspecialchars($language::get('view_episodes') ?: 'View Episodes'); ?>">
                                                <i class="icon-base ti tabler-list"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Episodes Tab -->
                <div class="tab-pane fade" id="tab-episodes" role="tabpanel">
                    <div class="card-datatable table-responsive pb-6">
                        <table id="dashboard-episodes-table" class="table table-hover table-striped dashboard-datatable w-100 pb-6">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= htmlspecialchars($language::get('id') ?: 'ID'); ?></th>
                                    <th><?= htmlspecialchars($language::get('series') ?: 'Series'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('season_episode') ?: 'Season / Ep'); ?></th>
                                    <th><?= htmlspecialchars($language::get('episode') ?: 'Episode Name'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('added') ?: 'Added'); ?></th>
                                    <th class="text-center"><?= htmlspecialchars($language::get('action') ?: 'Action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rLatestEpisodes as $rEpisode): ?>
                                    <tr>
                                        <td class="text-center"><span class="badge bg-label-secondary font-monospace"><?= intval($rEpisode['id']); ?></span></td>
                                        <td><span class="fw-medium text-body"><?= htmlspecialchars($rEpisode['series_title'] ?: '-'); ?></span></td>
                                        <td class="text-center">
                                            <span class="badge bg-label-info font-monospace">
                                                S<?= sprintf('%02d', intval($rEpisode['season_num'])); ?>E<?= sprintf('%02d', intval($rEpisode['episode_num'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="stream_view?id=<?= intval($rEpisode['id']); ?>" class="text-body">
                                                <?= htmlspecialchars($rEpisode['stream_display_name'] ?: '-'); ?>
                                            </a>
                                        </td>
                                        <td class="text-center"><?= date($rDateFormat, $rEpisode['added'] ?: time()); ?></td>
                                        <td class="text-center">
                                            <a href="stream_view?id=<?= intval($rEpisode['id']); ?>" class="btn btn-sm btn-icon btn-label-primary" title="<?= htmlspecialchars($language::get('view') ?: 'View'); ?>">
                                                <i class="icon-base ti tabler-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function () {
        // Live reseller stat tiles polling
        var nf = new Intl.NumberFormat('en-US');
        var creditsAssigned = <?= $xmCreditsAssigned ? 'true' : 'false'; ?>;
        function setTile(cls, value) {
            var e = document.querySelector('.' + cls + ' .entry');
            if (e) { e.textContent = nf.format(value || 0); }
        }
        function poll() {
            var start = Date.now();
            fetch('./api?action=dashboard', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    setTile('active-connections', d.open_connections);
                    setTile('online-users', d.online_users);
                    setTile('active-accounts', d.active_accounts);
                    setTile('credits', creditsAssigned ? d.credits_assigned : d.credits);
                })
                .catch(function () { /* keep last values */ })
                .finally(function () {
                    var wait = Math.max(0, 5000 - (Date.now() - start));
                    setTimeout(poll, wait);
                });
        }
        poll();

        // World Map (jsVectorMap)
        function renderMap() {
            var el = document.getElementById('map');
            if (!el || typeof jsVectorMap === 'undefined') return;
            var values = <?= json_encode($xmMapValues, JSON_UNESCAPED_SLASHES); ?>;
            var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var low = dark ? '#3b4253' : '#e7eaec';
            var map = new jsVectorMap({
                selector: '#map',
                map: 'world',
                backgroundColor: 'transparent',
                zoomButtons: false,
                regionStyle: {
                    initial: {
                        fill: low,
                        stroke: 'none'
                    },
                    hover: {
                        fillOpacity: 0.85
                    }
                }
            });
            Object.keys(values).forEach(function(code) {
                if (map.regions[code] && map.regions[code].element) {
                    map.regions[code].element.setStyle('fill', values[code]);
                }
            });
        }

        // Initialize DataTables & Map
        document.addEventListener('DOMContentLoaded', function() {
            renderMap();

            document.querySelectorAll('.dashboard-loc-progress .progress-bar').forEach(function(b) {
                b.style.width = (b.getAttribute('data-width') || 0) + '%';
            });

            if (window.jQuery && jQuery.fn.DataTable) {
                var dtOptions = {
                    responsive: true,
                    pageLength: 5,
                    lengthMenu: [5, 10, 25, 50],
                    language: {
                        paginate: {
                            next: '<i class="icon-base ti tabler-chevron-right"></i>',
                            previous: '<i class="icon-base ti tabler-chevron-left"></i>'
                        }
                    }
                };

                jQuery('.dashboard-datatable').each(function() {
                    jQuery(this).DataTable(dtOptions);
                });

                // Re-calculate responsive columns when switching tabs
                jQuery('button[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
                    if (jQuery.fn.dataTable.tables) {
                        jQuery(jQuery.fn.dataTable.tables(true)).DataTable().columns.adjust().responsive.recalc();
                    }
                });
            }

            // Kill connection handler
            jQuery(document).on('click', '.js-kill-btn', function() {
                var btn = jQuery(this);
                var uuid = btn.data('uuid');
                if (!uuid) return;
                btn.prop('disabled', true);
                fetch('./api?action=line_activity&sub=kill&uuid=' + encodeURIComponent(uuid), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.result === true) {
                        var tr = btn.closest('tr');
                        var dt = btn.closest('table').DataTable();
                        dt.row(tr).remove().draw(false);
                    } else {
                        btn.prop('disabled', false);
                        alert('<?= addslashes($language::get('error_occured') ?: 'An error occurred'); ?>');
                    }
                })
                .catch(function() {
                    btn.prop('disabled', false);
                    alert('<?= addslashes($language::get('error_occured') ?: 'An error occurred'); ?>');
                });
            });
        });
    })();
</script>
</body>

</html>
